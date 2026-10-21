<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMaintenanceRefundRequest;
use App\Support\Tenancy\Tenant;
use App\Support\Vehicles\MaintenancePayments;
use App\Support\Vehicles\VehicleMaintenance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MaintenanceRefundController extends Controller
{
    public function store(StoreMaintenanceRefundRequest $request, int $vehicle, int $entry, VehicleMaintenance $maintenance, MaintenancePayments $payments): JsonResponse
    {
        $actor = $maintenance->actor($request->user()->id);
        $input = $request->validated();
        $file = $request->file('file');
        unset($input['file']);
        foreach (['amount_centimes', 'work_revision', 'cancellation_revision', 'expected_paid_centimes', 'expected_refunded_centimes'] as $name) {
            $input[$name] = (int) $input[$name];
        }
        $hash = $maintenance->hash([...$input, 'vehicle' => $vehicle, 'entry' => $entry, 'file_hash' => $file ? hash_file('sha256', $file->getRealPath()) : null]);
        $path = null;
        try {
            $id = DB::transaction(function () use ($vehicle, $entry, $actor, $input, $hash, $file, &$path, $maintenance, $payments): int {
                $maintenance->lock($vehicle);
                $e = $maintenance->entry($vehicle, $entry);
                if ($old = $maintenance->replay('maintenance_refunds', $input['request_key'], $hash)) {
                    return $old->id;
                }
                $maintenance->assertActive($e);
                $cash = $payments->cash([$entry])[$entry] ?? null;
                $net = (int) ($cash->net_centimes ?? 0);
                $refunded = (int) ($cash->refunded_centimes ?? 0);
                abort_if($e->revision !== $input['work_revision'] || $e->cancellation_revision !== $input['cancellation_revision'] || $net !== $input['expected_paid_centimes'] || $refunded !== $input['expected_refunded_centimes'], 409, 'Les montants ont changé. Fermez puis rouvrez le dossier pour vérifier le remboursement.');
                if (! trim($input['reason'])) {
                    throw ValidationException::withMessages(['reason' => 'Expliquez pourquoi le garage a rendu cet argent.']);
                }
                if ($input['amount_centimes'] > $net) {
                    throw ValidationException::withMessages(['amount_centimes' => 'Le montant dépasse les fonds encore détenus par le garage.']);
                }
                $available = $payments->query()->where('p.maintenance_id', $entry)->where('p.paid_on', '<=', $input['received_on'])->orderBy('p.paid_on')->orderBy('p.id')->get();
                $left = $input['amount_centimes'];
                $allocations = [];
                foreach ($available as $p) {
                    $amount = min($left, (int) $p->effective_amount_centimes - (int) $p->refunded_centimes);
                    if ($amount <= 0) {
                        continue;
                    }
                    $allocations[] = ['company_id' => Tenant::id(), 'payment_key' => $p->request_key, 'amount_centimes' => $amount];
                    $left -= $amount;
                    if ($left === 0) {
                        break;
                    }
                }
                if ($left !== 0) {
                    throw ValidationException::withMessages(['received_on' => 'Cette date précède des paiements nécessaires à ce remboursement. Vérifiez la date réelle de réception.']);
                }
                if ($file) {
                    $path = Tenant::id().'/'.$vehicle.'/maintenance-refunds/'.Str::uuid().'.'.$file->extension();
                    if (! Storage::disk('vehicle_documents')->putFileAs(dirname($path), $file, basename($path), ['visibility' => 'private'])) {
                        throw new \RuntimeException('Private storage failed');
                    }
                }
                $id = DB::table('maintenance_refunds')->insertGetId(['company_id' => Tenant::id(), 'maintenance_id' => $entry, 'actor_membership_id' => $actor, 'amount_centimes' => $input['amount_centimes'], 'received_on' => $input['received_on'], 'method' => $input['method'], 'reason' => trim($input['reason']), 'reference' => $input['reference'] ?? null, 'path' => $path, 'mime' => $file?->getMimeType(), 'sha256' => $file ? hash_file('sha256', $file->getRealPath()) : null, 'request_key' => $input['request_key'], 'request_hash' => $hash, 'created_at' => now()]);
                foreach ($allocations as $allocation) {
                    DB::table('maintenance_refund_allocations')->insert([...$allocation, 'refund_id' => $id]);
                }
                $maintenance->audit($vehicle, $actor, 'maintenance_refund_received', ['maintenance_id' => $entry, 'refund_id' => $id, 'amount_centimes' => $input['amount_centimes'], 'reason' => trim($input['reason'])]);

                return $id;
            });
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('vehicle_documents')->delete($path);
            }
            throw $e;
        }

        return response()->json(['id' => $id], 201);
    }

    public function content(Request $request, int $vehicle, int $entry, int $refund, VehicleMaintenance $maintenance): StreamedResponse
    {
        $maintenance->actor($request->user()->id);
        $maintenance->entry($vehicle, $entry);
        $row = $maintenance->query('maintenance_refunds')->where('maintenance_id', $entry)->where('id', $refund)->firstOrFail();
        abort_unless($row->path && Storage::disk('vehicle_documents')->exists($row->path), 404);
        $ext = match ($row->mime) {
            'application/pdf' => 'pdf', 'image/png' => 'png', 'image/webp' => 'webp', default => 'jpg'
        };

        return Storage::disk('vehicle_documents')->download($row->path, 'remboursement-'.$refund.'.'.$ext, ['Content-Type' => $row->mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }
}
