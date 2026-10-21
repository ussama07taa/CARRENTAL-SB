<?php

namespace App\Http\Controllers;

use App\Models\CompanyMembership;
use App\Models\Reservation;
use App\Support\Reservations\RentalReturn;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReservationReturnController extends Controller
{
    private function actor(Request $request): CompanyMembership
    {
        return CompanyMembership::where('user_id', $request->user()->id)->firstOrFail();
    }

    public function show(Request $request, int $reservation, RentalReturn $returns): JsonResponse
    {
        return DB::transaction(function () use ($request, $reservation, $returns): JsonResponse {
            if (DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }

            return response()->json(['data' => $returns->summary(Reservation::findOrFail($reservation), $this->actor($request), $request->validate(['return_id' => ['nullable', 'integer', 'min:1']])['return_id'] ?? null)]);
        });
    }

    public function receive(Request $request, int $reservation, RentalReturn $returns): JsonResponse
    {
        $input = $request->validate(['returned_local' => ['required', 'date_format:Y-m-d\TH:i'], 'vehicle_received' => ['required', 'accepted'], 'reason' => ['required', 'string', 'min:3', 'max:1000'], 'request_key' => ['required', 'uuid'], 'company_id' => ['prohibited'], 'status' => ['prohibited'], 'demo_simulation' => ['prohibited']]);
        $actor = $this->actor($request);
        $replayed = $returns->receive($reservation, $actor, $input);

        return response()->json(['data' => $returns->summary(Reservation::findOrFail($reservation), $actor, isset($input['return_id']) ? (int) $input['return_id'] : null), 'replayed' => $replayed], $replayed ? 200 : 201);
    }

    public function inspect(Request $request, int $reservation, RentalReturn $returns): JsonResponse
    {
        abort_unless(Schema::hasTable('rental_returns'), 503, 'Le retour attend l’activation de la mise à jour.');
        $input = $request->validate(['return_id' => ['nullable', 'integer', 'min:1'], 'expected_inspection_id' => ['present', 'nullable', 'integer', 'min:1'], 'vehicle_version' => ['required', 'integer', 'min:1'], 'kilometers' => ['required', 'integer', 'between:0,9999999'], 'fuel_eighths' => ['required', 'integer', 'between:0,8'], 'condition' => ['required', Rule::in(['bon', 'dommages_existants', 'anomalie_bloquante'])], 'notes' => ['nullable', 'required_unless:condition,bon', 'string', 'min:3', 'max:4000'], 'reason' => ['required', 'string', 'min:3', 'max:1000'], 'signature_state' => ['required', Rule::in(['signed', 'pending', 'client_refused', 'client_absent'])], 'signature_reason' => ['nullable', 'required_unless:signature_state,signed', 'string', 'min:3', 'max:1000'], 'client_signature' => ['nullable', 'required_if:signature_state,signed', 'string', 'max:180000'], 'agency_signature' => ['nullable', 'required_if:signature_state,signed', 'string', 'max:180000'], 'photos' => ['sometimes', 'array', 'max:12'], 'photos.*' => ['file', 'mimetypes:image/jpeg,image/png,image/webp,video/mp4,video/webm,video/quicktime', 'max:20480'], 'captions' => ['sometimes', 'array', 'max:12'], 'captions.*' => ['nullable', 'string', 'max:120'], 'retained_file_ids' => ['sometimes', 'array', 'max:12'], 'retained_file_ids.*' => ['integer', 'min:1', 'distinct'], 'checked' => ['required', 'accepted'], 'request_key' => ['required', 'uuid'], 'company_id' => ['prohibited'], 'status' => ['prohibited'], 'amount_centimes' => ['prohibited']], ['required' => 'Ce champ est obligatoire.', 'accepted' => 'Confirmez le contrôle effectué.', 'required_unless' => 'Précisez le motif ou les observations.', 'required_if' => 'Dessinez les deux nouvelles signatures.', 'photos.*.mimetypes' => 'Photos JPG, PNG, WebP ou vidéos MP4, WebM, MOV uniquement.', 'photos.*.max' => '20 Mo maximum par vidéo.']);
        Reservation::findOrFail($reservation);
        $uploads = $request->file('photos', []);
        abort_if(array_sum(array_map(fn ($file) => $file->getSize(), $uploads)) > 60 * 1024 * 1024, 422, 'Total maximum : 60 Mo de médias.');
        foreach ($uploads as $file) {
            if (str_starts_with($file->getMimeType(), 'image/')) {
                validator(['photo' => $file], ['photo' => ['image', 'max:5120', 'dimensions:max_width=6000,max_height=6000']])->validate();
            }
        }
        $signatures = [];
        foreach (['client_signature', 'agency_signature'] as $field) {
            if (empty($input[$field])) {
                continue;
            }$raw = $input[$field];
            abort_unless(str_starts_with($raw, 'data:image/png;base64,'), 422, 'Signature PNG invalide.');
            $bytes = base64_decode(substr($raw, 22), true);
            $size = $bytes === false ? false : @getimagesizefromstring($bytes);
            abort_unless($size && $size[2] === IMAGETYPE_PNG && $size[0] >= 120 && $size[1] >= 60 && $size[0] <= 1200 && $size[1] <= 500, 422, 'Signature invalide.');
            $signatures[$field] = $bytes;
        }
        $actor = $this->actor($request);
        $replayed = $returns->inspect($reservation, $actor, $input, $uploads, $signatures);

        return response()->json(['data' => $returns->summary(Reservation::findOrFail($reservation), $actor, isset($input['return_id']) ? (int) $input['return_id'] : null), 'replayed' => $replayed], $replayed ? 200 : 201);
    }

    public function approve(Request $request, int $reservation, RentalReturn $returns): JsonResponse
    {
        $input = $request->validate(['return_id' => ['nullable', 'integer', 'min:1'], 'inspection_id' => ['required', 'integer', 'min:1'], 'vehicle_ready' => ['required', 'accepted'], 'reason' => ['required', 'string', 'min:3', 'max:1000'], 'request_key' => ['required', 'uuid'], 'company_id' => ['prohibited']]);
        $actor = $this->actor($request);
        $replayed = $returns->approve($reservation, $actor, $input);

        return response()->json(['data' => $returns->summary(Reservation::findOrFail($reservation), $actor, isset($input['return_id']) ? (int) $input['return_id'] : null), 'replayed' => $replayed], $replayed ? 200 : 201);
    }

    public function content(int $reservation, int $file): StreamedResponse
    {
        Reservation::findOrFail($reservation);
        abort_unless(Schema::hasTable('rental_return_files'), 404);
        $record = DB::table('rental_return_files')->where('company_id', Tenant::id())->where('reservation_id', $reservation)->where('id', $file)->first();
        abort_unless($record && Storage::disk($record->disk)->exists($record->path), 404);
        $extension = match ($record->mime) {
            'image/png' => 'png','image/webp' => 'webp','video/mp4' => 'mp4','video/webm' => 'webm','video/quicktime' => 'mov',default => 'jpg'
        };

        return Storage::disk($record->disk)->response($record->path, 'retour-'.$record->id.'.'.$extension, ['Content-Type' => $record->mime, 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox", 'Cache-Control' => 'private, no-store, max-age=0']);
    }
}
