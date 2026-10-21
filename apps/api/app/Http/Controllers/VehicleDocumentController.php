<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreVehicleDocumentRequest;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VehicleDocumentController extends Controller
{
    public function index(int $vehicle): JsonResponse
    {
        Vehicle::findOrFail($vehicle);
        $groups = [];
        foreach (VehicleDocument::KINDS as $kind) {
            $rows = VehicleDocument::where('vehicle_id', $vehicle)->where('kind', $kind)->latest('version')->limit(20)->get();
            $actorIds = $rows->pluck('actor_membership_id');
            $actors = CompanyMembership::whereIn('id', $actorIds)->with('user')->get()->keyBy('id');
            foreach ($rows as $row) {
                $row->setAttribute('actor', $actors->get($row->actor_membership_id)?->user?->name);
            }
            $active = $rows->first();
            // A future renewal must not hide the previous policy's deadline, including a gap.
            $current = null;
            if (in_array($kind, ['insurance', 'circulation'], true) && $active && $active->effective_on > now('Africa/Casablanca')->toDateString()) {
                $current = VehicleDocument::where('vehicle_id', $vehicle)->where('kind', $kind)->where('effective_on', '<=', now('Africa/Casablanca')->toDateString())
                    ->latest('version')->first();
            }
            $groups[$kind] = ['latest' => $active, 'current' => $current, 'history' => $rows, 'total' => VehicleDocument::where('vehicle_id', $vehicle)->where('kind', $kind)->count()];
        }

        return response()->json(['data' => $groups, 'today' => now('Africa/Casablanca')->toDateString()]);
    }

    public function history(Request $request, int $vehicle): JsonResponse
    {
        Vehicle::findOrFail($vehicle);
        $input = $request->validate(['kind' => ['required', Rule::in(VehicleDocument::KINDS)], 'before_version' => ['required', 'integer', 'min:1']]);
        $rows = VehicleDocument::where('vehicle_id', $vehicle)->where('kind', $input['kind'])->where('version', '<', $input['before_version'])->latest('version')->limit(20)->get();
        $actors = CompanyMembership::whereIn('id', $rows->pluck('actor_membership_id'))->with('user')->get()->keyBy('id');
        foreach ($rows as $row) {
            $row->setAttribute('actor', $actors->get($row->actor_membership_id)?->user?->name);
        }

        return response()->json(['data' => $rows]);
    }

    public function store(StoreVehicleDocumentRequest $request, int $vehicle): JsonResponse
    {
        Vehicle::findOrFail($vehicle);
        $input = $request->validated();
        $file = $request->file('file');
        $fileHash = $file ? hash_file('sha256', $file->getRealPath()) : null;
        $meta = array_intersect_key($input, array_flip(VehicleDocument::METADATA));
        ksort($meta);
        $retain = (bool) ($input['retain_file'] ?? false);
        $hash = hash('sha256', json_encode([$vehicle, $meta, $fileHash, $retain]));
        $storedPath = null;
        $disk = 'vehicle_documents';
        try {
            $result = DB::transaction(function () use ($request, $vehicle, $input, $meta, $file, $fileHash, $retain, $hash, $disk, &$storedPath): VehicleDocument|JsonResponse {
                Company::whereKey(Tenant::id())->lockForUpdate()->firstOrFail();
                Vehicle::whereKey($vehicle)->lockForUpdate()->firstOrFail();
                $existing = VehicleDocument::where('request_key', $input['request_key'])->first();
                if ($existing) {
                    if (! hash_equals($existing->request_hash, $hash)) {
                        return response()->json(['message' => 'Cette demande a déjà été utilisée. Rouvrez les documents avant de recommencer.', 'code' => 'REQUEST_ALREADY_USED'], 409);
                    }

                    return $existing;
                }
                $previous = VehicleDocument::where('vehicle_id', $vehicle)->where('kind', $input['kind'])->latest('version')->first();
                if (($previous?->id ?? null) !== (isset($input['previous_id']) ? (int) $input['previous_id'] : null)) {
                    return response()->json(['message' => 'Cette pièce a été mise à jour ailleurs. Fermez puis rouvrez les documents.', 'code' => 'DOCUMENT_CHANGED'], 409);
                }
                if (($previous === null) !== ($input['change_kind'] === 'initial')) {
                    throw ValidationException::withMessages(['change_kind' => 'Choisissez une opération adaptée à la version existante.']);
                }
                if ($input['kind'] === 'registration' && $previous && $input['change_kind'] === 'renewal') {
                    throw ValidationException::withMessages(['change_kind' => 'Utilisez une mise à jour pour la carte grise.']);
                }
                if ($retain && ($file || ! $previous?->has_file || $input['change_kind'] !== 'correction')) {
                    throw ValidationException::withMessages(['retain_file' => 'Un fichier existant ne peut être conservé que pour une correction sans nouveau fichier.']);
                }
                if ($input['kind'] === 'registration' && ! $file && ! $retain) {
                    throw ValidationException::withMessages(['file' => 'Ajoutez la carte grise.']);
                }
                if ($previous && $input['change_kind'] === 'renewal') {
                    if ($input['kind'] === 'tax' && (int) $input['tax_year'] <= (int) $previous->tax_year) {
                        throw ValidationException::withMessages(['tax_year' => 'Choisissez une année plus récente, ou utilisez une correction.']);
                    }
                    if (in_array($input['kind'], ['insurance', 'inspection', 'circulation'], true) && $input['expires_on'] <= $previous->expires_on) {
                        throw ValidationException::withMessages(['expires_on' => 'La nouvelle échéance doit être après la précédente. Sinon, utilisez une correction.']);
                    }
                }
                $attachment = ['disk' => null, 'path' => null, 'mime' => null, 'size' => null, 'sha256' => null];
                if ($retain) {
                    $attachment = $previous->only(array_keys($attachment));
                }
                if ($file) {
                    $path = Tenant::id().'/'.$vehicle.'/'.Str::uuid().'.'.$file->extension();
                    if (! Storage::disk($disk)->putFileAs(dirname($path), $file, basename($path), ['visibility' => 'private'])) {
                        throw new \RuntimeException('Private file storage failed.');
                    }
                    $storedPath = $path;
                    $attachment = ['disk' => $disk, 'path' => $path, 'mime' => $file->getMimeType(), 'size' => $file->getSize(), 'sha256' => $fileHash];
                }
                $actor = CompanyMembership::where('user_id', $request->user()->id)->firstOrFail();
                $record = VehicleDocument::create([...$meta, ...$attachment, 'vehicle_id' => $vehicle, 'actor_membership_id' => $actor->id,
                    'version' => ($previous?->version ?? 0) + 1, 'request_key' => $input['request_key'], 'request_hash' => $hash, 'created_at' => now()]);
                DB::table('vehicle_events')->insert(['company_id' => Tenant::id(), 'vehicle_id' => $vehicle, 'actor_membership_id' => $actor->id,
                    'action' => 'document_'.$input['change_kind'], 'before' => $previous ? json_encode(['document_id' => $previous->id]) : null,
                    'after' => json_encode(['document_id' => $record->id, 'kind' => $record->kind, 'version' => $record->version]), 'created_at' => now()]);

                return $record;
            });
        } catch (\Throwable $e) {
            if ($storedPath !== null) {
                Storage::disk($disk)->delete($storedPath);
            } throw $e;
        }

        return $result instanceof JsonResponse ? $result : response()->json(['data' => $result], 201);
    }

    public function content(int $vehicle, int $document): StreamedResponse
    {
        Vehicle::findOrFail($vehicle);
        $record = VehicleDocument::where('vehicle_id', $vehicle)->findOrFail($document);
        abort_unless($record->has_file, 404);
        $storage = Storage::disk($record->disk);
        abort_unless($storage->exists($record->path), 404);
        $extension = match ($record->mime) {
            'application/pdf' => 'pdf','image/png' => 'png','image/webp' => 'webp',default => 'jpg'
        };

        return $storage->download($record->path, $record->kind.'-v'.$record->version.'.'.$extension, [
            'Content-Type' => $record->mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store, max-age=0',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
