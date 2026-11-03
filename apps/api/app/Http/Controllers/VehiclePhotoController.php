<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreVehiclePhotoRequest;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Vehicle;
use App\Models\VehiclePhoto;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VehiclePhotoController extends Controller
{
    public function store(StoreVehiclePhotoRequest $request, int $vehicle): JsonResponse
    {
        Vehicle::findOrFail($vehicle);
        $input = $request->validated();
        $file = $request->file('image');
        $hash = hash_file('sha256', $file->getRealPath());
        $disk = 'vehicle_photos';
        $storedPath = null;
        try {
            $result = DB::transaction(function () use ($request, $vehicle, $input, $file, $hash, $disk, &$storedPath): VehiclePhoto|JsonResponse {
                // Match the vehicle-edit lock order and serialize upload-key retries.
                Company::whereKey(Tenant::id())->lockForUpdate()->firstOrFail();
                Vehicle::whereKey($vehicle)->lockForUpdate()->firstOrFail();
                $existing = VehiclePhoto::where('upload_key', $input['upload_key'])->first();
                if ($existing) {
                    if ((int) $existing->vehicle_id !== $vehicle || $existing->slot !== $input['slot'] || ! hash_equals($existing->sha256, $hash)) {
                        return response()->json(['code' => 'UPLOAD_KEY_USED', 'message' => 'Cette demande a déjà été utilisée. Choisissez à nouveau l’image.'], 409);
                    }

                    return $existing;
                }
                $previous = VehiclePhoto::where('vehicle_id', $vehicle)->where('slot', $input['slot'])->latest('version')->first();
                $visiblePrevious = VehiclePhoto::current()->where('vehicle_id', $vehicle)->where('slot', $input['slot'])->first();
                if (($visiblePrevious?->id ?? null) !== (isset($input['previous_id']) ? (int) $input['previous_id'] : null)) {
                    return response()->json(['code' => 'PHOTO_CHANGED', 'message' => 'Cette photo a été remplacée ailleurs. Fermez et rouvrez le fichier pour voir la dernière version avant de la remplacer.'], 409);
                }
                $actor = CompanyMembership::where('user_id', $request->user()->id)->firstOrFail();
                $path = Tenant::id().'/'.$vehicle.'/'.Str::uuid().'.'.$file->extension();
                if (! Storage::disk($disk)->putFileAs(dirname($path), $file, basename($path), ['visibility' => 'private'])) {
                    throw new \RuntimeException('Private document storage failed.');
                }
                $storedPath = $path;
                $document = VehiclePhoto::create([
                    'vehicle_id' => $vehicle, 'actor_membership_id' => $actor->id,
                    'slot' => $input['slot'], 'version' => ($previous?->version ?? 0) + 1,
                    'upload_key' => $input['upload_key'], 'disk' => $disk, 'path' => $path,
                    'mime' => $file->getMimeType(), 'size' => $file->getSize(), 'sha256' => $hash,
                    'created_at' => now(),
                ]);
                DB::table('vehicle_events')->insert([
                    'company_id' => Tenant::id(), 'vehicle_id' => $vehicle, 'actor_membership_id' => $actor->id,
                    'action' => 'photo_added', 'before' => null,
                    'after' => json_encode(['photo_id' => $document->id, 'slot' => $document->slot, 'version' => $document->version]),
                    'created_at' => now(),
                ]);

                return $document;
            });
        } catch (\Throwable $error) {
            if ($storedPath !== null) {
                Storage::disk($disk)->delete($storedPath);
            }
            throw $error;
        }

        return $result instanceof JsonResponse ? $result : response()->json(['data' => $result], 201);
    }

    public function manage(Request $request, int $vehicle): JsonResponse
    {
        $input = $request->validate([
            'action' => ['required', 'in:swap,remove'],
            'slot' => ['required', \Illuminate\Validation\Rule::in(VehiclePhoto::SLOTS)],
            'target' => ['required_if:action,swap', 'nullable', \Illuminate\Validation\Rule::in(VehiclePhoto::SLOTS)],
            'expected' => ['present', 'array'], 'expected.*' => ['integer', 'min:1'],
            'request_key' => ['required', 'uuid'],
        ]);
        return DB::transaction(function () use ($request, $vehicle, $input) {
            Company::whereKey(Tenant::id())->lockForUpdate()->firstOrFail();
            Vehicle::whereKey($vehicle)->lockForUpdate()->firstOrFail();
            $actor = CompanyMembership::where('user_id', $request->user()->id)->firstOrFail();
            $events = DB::table('vehicle_events')->where('company_id', Tenant::id())->where('vehicle_id', $vehicle);
            $retry = (clone $events)->where('action', 'photo_gallery_changed')->whereRaw("after::jsonb->>'request_key' = ?", [$input['request_key']])->first();
            if ($retry) {
                abort_unless(json_decode($retry->after, true) == $input, 409, 'Cette demande a déjà été utilisée.');
                return response()->json(['photos' => VehiclePhoto::current()->where('vehicle_id', $vehicle)->get()]);
            }
            $photos = VehiclePhoto::current()->where('vehicle_id', $vehicle)->get()->keyBy('slot');
            $actual = $photos->map(fn ($p) => (int) $p->id)->all();
            $expected = array_map('intval', $input['expected']); ksort($actual); ksort($expected);
            if ($actual !== $expected) return response()->json(['code' => 'PHOTO_CHANGED', 'message' => 'Les photos ont changé. Fermez et rouvrez le fichier avant de réessayer.'], 409);
            $source = $photos->get($input['slot']);
            abort_unless($source, 422, 'Choisissez une photo enregistrée.');
            $audit = function (string $action, array $after) use ($actor, $vehicle): void {
                DB::table('vehicle_events')->insert(['company_id' => Tenant::id(), 'vehicle_id' => $vehicle,
                    'actor_membership_id' => $actor->id, 'action' => $action, 'before' => null, 'after' => json_encode($after), 'created_at' => now()]);
            };
            if ($input['action'] === 'remove') {
                $audit('photo_removed', ['photo_id' => $source->id, 'slot' => $source->slot]);
            } else {
                abort_if($input['slot'] === $input['target'], 422, 'Choisissez un autre emplacement.');
                $target = $photos->get($input['target']);
                foreach ([[$source, $input['target']], [$target, $input['slot']]] as [$photo, $slot]) {
                    if (! $photo) { $audit('photo_removed', ['photo_id' => $source->id, 'slot' => $source->slot]); continue; }
                    VehiclePhoto::create(['vehicle_id' => $vehicle, 'actor_membership_id' => $actor->id, 'slot' => $slot,
                        'version' => ((int) VehiclePhoto::where('vehicle_id', $vehicle)->where('slot', $slot)->max('version')) + 1,
                        'upload_key' => (string) Str::uuid(), 'disk' => $photo->disk, 'path' => $photo->path,
                        'mime' => $photo->mime, 'size' => $photo->size, 'sha256' => $photo->sha256, 'created_at' => now()]);
                }
            }
            $audit('photo_gallery_changed', $input);
            return response()->json(['photos' => VehiclePhoto::current()->where('vehicle_id', $vehicle)->get()]);
        });
    }

    public function content(Request $request, int $vehicle, int $document): StreamedResponse
    {
        abort_unless(CompanyMembership::where('user_id', $request->user()->id)->exists(), 403);
        Vehicle::findOrFail($vehicle);
        $record = VehiclePhoto::where('vehicle_id', $vehicle)->findOrFail($document);
        $storage = Storage::disk($record->disk);
        abort_unless($storage->exists($record->path), 404, 'Image indisponible.');

        return $storage->response($record->path, 'document.'.(match ($record->mime) {
            'image/png' => 'png', 'image/webp' => 'webp', default => 'jpg'
        }), [
            'Content-Type' => $record->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }
}
