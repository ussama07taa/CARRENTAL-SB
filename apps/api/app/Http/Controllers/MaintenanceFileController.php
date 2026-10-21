<?php

namespace App\Http\Controllers;

use App\Http\Requests\MaintenanceInvoiceRequest;
use App\Http\Requests\SaveMaintenanceFileRequest;
use App\Http\Requests\SaveMaintenanceWorkRequest;
use App\Models\Vehicle;
use App\Support\Tenancy\Tenant;
use App\Support\Vehicles\MaintenanceFollowups;
use App\Support\Vehicles\MaintenancePayments;
use App\Support\Vehicles\MaintenanceWork;
use App\Support\Vehicles\VehicleMaintenance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MaintenanceFileController extends Controller
{
    public function __construct(private VehicleMaintenance $maintenance, private MaintenanceWork $work) {}

    public function store(SaveMaintenanceFileRequest $request, int $vehicle): JsonResponse
    {
        $actor = $this->maintenance->actor($request->user()->id);
        Vehicle::findOrFail($vehicle);
        $input = $request->validated();
        $file = $request->file('file');
        unset($input['file']);
        $hash = $this->maintenance->hash([...$input, 'vehicle' => $vehicle, 'file_hash' => $file ? hash_file('sha256', $file->getRealPath()) : null]);
        $path = null;
        try {
            $id = DB::transaction(function () use ($vehicle, $actor, $input, $file, $hash, &$path): int {
                $v = $this->maintenance->lock($vehicle);
                if ($old = $this->maintenance->replay('vehicle_maintenance', $input['request_key'], $hash)) {
                    return $old->id;
                }
                $base = (object) ['performed_on' => $input['performed_on']];
                $items = [];
                $total = 0;
                $final = true;
                foreach ($input['items'] as $raw) {
                    if ($input['mode'] === 'completed') {
                        $raw['status'] = 'done';
                        $raw['completed_on'] = $input['performed_on'];
                        $raw['kilometers'] = $input['kilometers'] ?? null;
                    }$item = $this->work->validateItem($raw, $base);
                    $total += (int) ($item['amount_centimes'] ?? 0);
                    $final = $final && $item['cost_status'] === 'confirmed';
                    $items[] = $item;
                }
                if ($total > 1000000000) {
                    throw ValidationException::withMessages(['items' => 'Le total du dossier dépasse la limite autorisée.']);
                }
                if ($final && (int) $input['paid_centimes'] > $total) {
                    throw ValidationException::withMessages(['paid_centimes' => 'L’avance dépasse le coût confirmé.']);
                }
                if ($file) {
                    $path = Tenant::id().'/'.$vehicle.'/maintenance/'.Str::uuid().'.'.$file->extension();
                    Storage::disk('vehicle_documents')->putFileAs(dirname($path), $file, basename($path), ['visibility' => 'private']);
                }
                $id = DB::table('vehicle_maintenance')->insertGetId(['company_id' => Tenant::id(), 'vehicle_id' => $vehicle, 'actor_membership_id' => $actor, 'kind' => count($items) === 1 ? $items[0]['kind'] : 'service', 'title' => count($items) === 1 ? $items[0]['title'] : 'Visite au garage · '.count($items).' travaux', 'performed_on' => $input['performed_on'], 'kilometers' => $input['kilometers'] ?? null, 'garage' => $input['garage'] ?? null, 'notes' => $input['notes'] ?? null, 'cost_centimes' => $total, 'payment_due_on' => $input['payment_due_on'] ?? null, 'in_garage' => $input['mode'] === 'garage', 'request_key' => $input['request_key'], 'request_hash' => $hash, 'path' => $path, 'mime' => $file?->getMimeType(), 'sha256' => $file ? hash_file('sha256', $file->getRealPath()) : null, 'created_at' => now()]);
                $base->id = $id;
                foreach ($items as $item) {
                    $this->work->append($base, $item, $actor, (string) Str::uuid(), 1, (string) Str::uuid(), $hash);
                }
                $this->maintenance->recordReading($v, isset($input['kilometers']) ? (int) $input['kilometers'] : null, $input['performed_on'], $actor);
                $this->maintenance->audit($vehicle, $actor, 'maintenance_file_created', ['maintenance_id' => $id, 'items' => $items, 'mode' => $input['mode']]);
                if ((int) $input['paid_centimes'] > 0) {
                    $this->maintenance->pay($this->maintenance->entry($vehicle, $id), $actor, ['amount_centimes' => $input['paid_centimes'], 'paid_on' => $input['paid_on'], 'method' => $input['method'], 'request_key' => $input['request_key']], $hash);
                }

                return $id;
            });
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('vehicle_documents')->delete($path);
            }throw $e;
        }

        return response()->json(['id' => $id], 201);
    }

    public function saveWork(SaveMaintenanceWorkRequest $request, int $vehicle, int $entry): JsonResponse
    {
        $actor = $this->maintenance->actor($request->user()->id);
        $input = $request->validated();
        $hash = $this->maintenance->hash([...$input, 'entry' => $entry, 'vehicle' => $vehicle]);

        return DB::transaction(function () use ($vehicle, $entry, $actor, $input, $hash): JsonResponse {
            $v = $this->maintenance->lock($vehicle);
            $e = $this->maintenance->entry($vehicle, $entry);
            if ($old = $this->maintenance->replay('maintenance_work_items', $input['request_key'], $hash)) {
                return response()->json(['id' => $old->id]);
            }
            $this->maintenance->assertActive($e);
            abort_if($e->revision !== (int) $input['revision'], 409, 'Le dossier a changé. Fermez puis rouvrez-le pour actualiser les travaux.');
            $closed = ! $e->in_garage || $this->maintenance->query('maintenance_returns')->where('maintenance_id', $entry)->exists();
            if ($closed && in_array($input['status'], ['pending', 'progress'], true) && ! (app(MaintenanceFollowups::class)->tracking($e)[$input['item_key'] ?? '']['visit'] ?? null)) {
                throw ValidationException::withMessages(['status' => 'Ce véhicule est déjà revenu. Créez un nouveau dossier pour de nouveaux travaux à réaliser.']);
            }
            $legacy = $e->items[0]->legacy ?? false;
            if ($legacy) {
                $this->work->preserveLegacy($e, $actor);
                $e = $this->maintenance->entry($vehicle, $entry);
            }
            $previous = null;
            foreach ($e->items as $item) {
                if ($item->item_key === $input['item_key'] || ($legacy && $input['item_key'] === null && ($input['legacy_edit'] ?? false))) {
                    $previous = $item;
                }
            }
            if ($input['item_key'] !== null && ! $previous) {
                abort(404);
            }
            abort_if($previous?->cancellation !== null, 409, 'Ce travail est annulé et ne peut plus être modifié.');
            if ($previous && (app(MaintenanceFollowups::class)->tracking($e)[$previous->item_key]['visit'] ?? null) && $input['status'] !== $previous->status) {
                throw ValidationException::withMessages(['status' => 'Utilisez le suivi du travail repris pour enregistrer sa réalisation ou son report.']);
            }
            $item = $this->work->validateItem($input, $e);
            if ($previous && ! trim($item['reason'] ?? '')) {
                throw ValidationException::withMessages(['reason' => 'Expliquez cette mise à jour pour conserver un historique clair.']);
            }
            $total = (int) $item['amount_centimes'];
            foreach ($e->items as $other) {
                if ($other->status !== 'cancelled' && $other->item_key !== $previous?->item_key) {
                    $total += (int) $other->amount_centimes;
                }
            }if ($total > 1000000000) {
                throw ValidationException::withMessages(['amount_centimes' => 'Le total du dossier dépasse la limite autorisée.']);
            }
            $id = $this->work->append($e, $item, $actor, $previous?->item_key ?? (string) Str::uuid(), ($previous?->version ?? 0) + 1, $input['request_key'], $hash);
            if ($item['status'] === 'done' && ($previous?->completed_on !== $item['completed_on'] || $previous?->kilometers !== $item['kilometers'])) {
                $this->maintenance->recordReading($v, $item['kilometers'] === null ? null : (int) $item['kilometers'], $item['completed_on'], $actor);
            }
            $this->maintenance->audit($vehicle, $actor, 'maintenance_work_saved', ['maintenance_id' => $entry, 'before' => $previous, 'after' => $item, 'work_id' => $id]);

            return response()->json(['id' => $id], 201);
        });
    }

    public function detail(Request $request, int $vehicle, int $entry): JsonResponse
    {
        $this->maintenance->actor($request->user()->id);
        Vehicle::findOrFail($vehicle);
        $e = $this->maintenance->entry($vehicle, $entry);
        $cash = app(MaintenancePayments::class)->cash([$entry])[$entry] ?? null;
        $paid = (int) ($cash->net_centimes ?? 0);
        $e->gross_paid_centimes = (int) ($cash->gross_centimes ?? 0);
        $e->refunded_centimes = (int) ($cash->refunded_centimes ?? 0);
        $e->paid_centimes = $paid;
        $e->balance_centimes = max(0, $e->cost_centimes - $paid);
        $e->credit_centimes = max(0, $paid - $e->cost_centimes);
        $e->has_file = $e->path !== null;
        $e->returned = $this->maintenance->query('maintenance_returns')->where('maintenance_id', $entry)->first(['returned_on', 'note']);
        $e->garage_open = $e->in_garage && ! $e->returned;
        $e->followups = app(MaintenanceFollowups::class)->tracking($e);
        $payments = app(MaintenancePayments::class)->rows($entry);
        $attachments = $this->maintenance->query('maintenance_attachments')->where('maintenance_id', $entry)->orderByDesc('id')->get(['id', 'mime', 'created_at']);
        $history = $this->maintenance->query('maintenance_work_items')->where('maintenance_id', $entry)->orderByDesc('id')->get(['id', 'item_key', 'version', 'title', 'status', 'cost_status', 'amount_centimes', 'reason', 'created_at']);
        unset($e->company_id,$e->path,$e->sha256,$e->request_hash,$e->request_key,$e->actor_membership_id);

        return response()->json(['data' => $e, 'payments' => $payments, 'refunds' => app(MaintenancePayments::class)->refunds($entry), 'attachments' => $attachments, 'history' => $history]);
    }

    public function invoice(MaintenanceInvoiceRequest $request, int $vehicle, int $entry): JsonResponse
    {
        $actor = $this->maintenance->actor($request->user()->id);
        Vehicle::findOrFail($vehicle);
        $this->maintenance->entry($vehicle, $entry);
        $file = $request->file('file');
        $hash = hash_file('sha256', $file->getRealPath());
        $path = null;
        try {
            $id = DB::transaction(function () use ($request, $vehicle, $entry, $actor, $file, $hash, &$path): int {
                $this->maintenance->lock($vehicle);
                $old = $this->maintenance->query('maintenance_attachments')->where('request_key', $request->input('request_key'))->first();
                if ($old) {
                    abort_if($old->maintenance_id !== $entry || $old->sha256 !== $hash, 409);

                    return $old->id;
                }$this->maintenance->assertActive($this->maintenance->entry($vehicle, $entry));
                $path = Tenant::id().'/'.$vehicle.'/maintenance/'.Str::uuid().'.'.$file->extension();
                Storage::disk('vehicle_documents')->putFileAs(dirname($path), $file, basename($path), ['visibility' => 'private']);
                $id = DB::table('maintenance_attachments')->insertGetId(['company_id' => Tenant::id(), 'maintenance_id' => $entry, 'actor_membership_id' => $actor, 'path' => $path, 'mime' => $file->getMimeType(), 'sha256' => $hash, 'request_key' => $request->input('request_key'), 'created_at' => now()]);
                $this->maintenance->audit($vehicle, $actor, 'maintenance_invoice_added', ['maintenance_id' => $entry, 'attachment_id' => $id]);

                return $id;
            });
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('vehicle_documents')->delete($path);
            }throw $e;
        }

        return response()->json(['id' => $id], 201);
    }

    public function content(Request $request, int $vehicle, int $entry, int $attachment): StreamedResponse
    {
        $this->maintenance->actor($request->user()->id);
        Vehicle::findOrFail($vehicle);
        $this->maintenance->entry($vehicle, $entry);
        $a = $this->maintenance->query('maintenance_attachments')->where('maintenance_id', $entry)->where('id', $attachment)->firstOrFail();
        abort_unless(Storage::disk('vehicle_documents')->exists($a->path), 404);
        $ext = match ($a->mime) {
            'application/pdf' => 'pdf','image/png' => 'png','image/webp' => 'webp',default => 'jpg'
        };

        return Storage::disk('vehicle_documents')->download($a->path, 'facture-'.$attachment.'.'.$ext, ['Content-Type' => $a->mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }
}
