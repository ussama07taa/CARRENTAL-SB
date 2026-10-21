<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMaintenancePaymentRequest;
use App\Http\Requests\StoreVehicleMaintenanceRequest;
use App\Models\Vehicle;
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

class VehicleMaintenanceController extends Controller
{
    public function __construct(private VehicleMaintenance $maintenance) {}

    public function index(Request $request, int $vehicle): JsonResponse
    {
        $this->maintenance->actor($request->user()->id);
        $v = Vehicle::findOrFail($vehicle);
        $p = $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        return response()->json($this->maintenance->listing($v, (int) ($p['page'] ?? 1)));
    }

    public function store(StoreVehicleMaintenanceRequest $request, int $vehicle): JsonResponse
    {
        $actor = $this->maintenance->actor($request->user()->id);
        Vehicle::findOrFail($vehicle);
        $input = $request->validated();
        $file = $request->file('file');
        unset($input['file']);
        foreach (['kilometers', 'next_km', 'cost_centimes', 'paid_centimes', 'replaces_id'] as $k) {
            if (isset($input[$k])) {
                $input[$k] = (int) $input[$k];
            }
        }
        $input['in_garage'] = (bool) $input['in_garage'];
        $hash = $this->maintenance->hash([...$input, 'vehicle' => $vehicle, 'file_hash' => $file ? hash_file('sha256', $file->getRealPath()) : null]);
        $path = null;
        try {
            $id = DB::transaction(function () use ($vehicle, $actor, $input, $hash, $file, &$path): int {
                $v = $this->maintenance->lock($vehicle);
                if ($old = $this->maintenance->replay('vehicle_maintenance', $input['request_key'], $hash)) {
                    return $old->id;
                }
                if (($input['next_km'] ?? null) !== null && $input['next_km'] <= ($input['kilometers'] ?? 0)) {
                    throw ValidationException::withMessages(['next_km' => 'Le prochain compteur doit dépasser celui de l’intervention.']);
                }
                if (isset($input['replaces_id'])) {
                    $old = $this->maintenance->entry($vehicle, $input['replaces_id']);
                    $this->maintenance->assertActive($old);
                    abort_if($old->items[0]->status === 'cancelled', 409, 'Ce rappel a été annulé.');
                    if ($this->maintenance->query()->where('replaces_id', $old->id)->exists() || (! $old->next_on && ! $old->next_km) || $input['performed_on'] < $old->performed_on) {
                        throw ValidationException::withMessages(['replaces_id' => 'Ce rappel a déjà été traité ou cette intervention ne peut pas le remplacer.']);
                    }
                }
                if ($file) {
                    $path = Tenant::id().'/'.$vehicle.'/maintenance/'.Str::uuid().'.'.$file->extension();
                    if (! Storage::disk('vehicle_documents')->putFileAs(dirname($path), $file, basename($path), ['visibility' => 'private'])) {
                        throw new \RuntimeException('Private storage failed');
                    }
                }
                $row = array_intersect_key($input, array_flip(['kind', 'title', 'performed_on', 'kilometers', 'garage', 'notes', 'cost_centimes', 'payment_due_on', 'next_on', 'next_km', 'in_garage', 'replaces_id', 'request_key']));
                $id = DB::table('vehicle_maintenance')->insertGetId([...$row, 'company_id' => Tenant::id(), 'vehicle_id' => $vehicle, 'actor_membership_id' => $actor, 'request_hash' => $hash, 'path' => $path, 'mime' => $file?->getMimeType(), 'sha256' => $file ? hash_file('sha256', $file->getRealPath()) : null, 'created_at' => now()]);
                if (isset($input['kilometers']) && (! $v->current_odometer_date || $input['performed_on'] >= $v->current_odometer_date)) {
                    if ($v->current_odometer !== null && $input['kilometers'] < $v->current_odometer) {
                        throw ValidationException::withMessages(['kilometers' => 'Le compteur est inférieur au dernier relevé. Vérifiez la date ou corrigez le compteur dans le fichier véhicule.']);
                    }
                    DB::table('vehicle_odometer_readings')->insert(['company_id' => Tenant::id(), 'vehicle_id' => $vehicle, 'actor_membership_id' => $actor, 'kilometers' => $input['kilometers'], 'recorded_on' => $input['performed_on'], 'kind' => 'reading', 'reason' => 'Intervention #'.$id, 'request_key' => $input['request_key'], 'request_hash' => $hash, 'created_at' => now()]);
                    $v->current_odometer = $input['kilometers'];
                    $v->current_odometer_date = $input['performed_on'];
                    $v->version++;
                    $v->save();
                }
                $this->maintenance->audit($vehicle, $actor, 'maintenance_created', ['maintenance_id' => $id, 'cost_centimes' => $input['cost_centimes']]);
                if ($input['paid_centimes'] > 0) {
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

    public function pay(StoreMaintenancePaymentRequest $request, int $vehicle, int $entry): JsonResponse
    {
        $actor = $this->maintenance->actor($request->user()->id);
        $input = $request->validated();
        $input['amount_centimes'] = (int) $input['amount_centimes'];
        $hash = $this->maintenance->hash([...$input, 'vehicle' => $vehicle, 'entry' => $entry]);

        return DB::transaction(function () use ($vehicle, $entry, $actor, $input, $hash): JsonResponse {
            $this->maintenance->lock($vehicle);
            $e = $this->maintenance->entry($vehicle, $entry);
            if ($old = $this->maintenance->replay('maintenance_payments', $input['request_key'], $hash)) {
                return response()->json(['id' => $old->id]);
            }

            return response()->json(['id' => $this->maintenance->pay($e, $actor, $input, $hash)], 201);
        });
    }

    public function release(Request $request, int $vehicle, int $entry): JsonResponse
    {
        $actor = $this->maintenance->actor($request->user()->id);
        $input = $request->validate(['returned_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Africa/Casablanca')->toDateString()], 'note' => ['required', 'string', 'max:500'], 'ready' => ['required', 'accepted'], 'request_key' => ['required', 'uuid']]);
        $hash = $this->maintenance->hash([...$input, 'vehicle' => $vehicle, 'entry' => $entry]);

        return DB::transaction(function () use ($vehicle, $entry, $actor, $input, $hash): JsonResponse {
            $this->maintenance->lock($vehicle);
            $e = $this->maintenance->entry($vehicle, $entry);
            if ($old = $this->maintenance->replay('maintenance_returns', $input['request_key'], $hash)) {
                return response()->json(['id' => $old->id]);
            }
            $this->maintenance->assertActive($e);
            if (! $e->in_garage || $this->maintenance->query('maintenance_returns')->where('maintenance_id', $entry)->exists() || $input['returned_on'] < $e->performed_on) {
                throw ValidationException::withMessages(['returned_on' => 'Vérifiez la date : cette intervention doit être encore ouverte au garage.']);
            }
            if (! ($e->items[0]->legacy ?? false)) {
                foreach ($e->items as $work) {
                    if (in_array($work->status, ['pending', 'progress'], true)) {
                        throw ValidationException::withMessages(['ready' => 'Terminez les travaux ou reportez-les avec un motif avant de confirmer le retour.']);
                    }
                }
            }
            $id = DB::table('maintenance_returns')->insertGetId(['company_id' => Tenant::id(), 'maintenance_id' => $entry, 'actor_membership_id' => $actor, 'returned_on' => $input['returned_on'], 'note' => $input['note'], 'request_key' => $input['request_key'], 'request_hash' => $hash, 'created_at' => now()]);
            $this->maintenance->audit($vehicle, $actor, 'maintenance_returned', ['maintenance_id' => $entry, 'returned_on' => $input['returned_on'], 'note' => $input['note']]);

            return response()->json(['id' => $id], 201);
        });
    }

    public function show(Request $request, int $vehicle, int $entry): JsonResponse
    {
        $this->maintenance->actor($request->user()->id);
        Vehicle::findOrFail($vehicle);
        $e = $this->maintenance->entry($vehicle, $entry);
        $payments = app(MaintenancePayments::class)->rows($entry);

        return response()->json(['payments' => $payments, 'return' => $this->maintenance->query('maintenance_returns')->where('maintenance_id', $entry)->first(['returned_on', 'note', 'created_at'])]);
    }

    public function content(Request $request, int $vehicle, int $entry): StreamedResponse
    {
        $this->maintenance->actor($request->user()->id);
        Vehicle::findOrFail($vehicle);
        $e = $this->maintenance->entry($vehicle, $entry);
        abort_unless($e->path && Storage::disk('vehicle_documents')->exists($e->path), 404);
        $ext = match ($e->mime) {
            'application/pdf' => 'pdf','image/png' => 'png','image/webp' => 'webp',default => 'jpg'
        };

        return Storage::disk('vehicle_documents')->download($e->path, 'intervention-'.$entry.'.'.$ext, ['Content-Type' => $e->mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }
}
