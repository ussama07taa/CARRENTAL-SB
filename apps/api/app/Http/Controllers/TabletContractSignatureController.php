<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Reservation;
use App\Support\Reservations\RentalContract;
use App\Support\Reservations\TabletContractPdf;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class TabletContractSignatureController extends Controller
{
    private function actor(Request $request): CompanyMembership
    {
        abort_unless(config('rental_contracts.tablet_signing_enabled', false)
            && (app()->environment('testing')
                || (app()->environment('local') && config('database.connections.pgsql.database') === 'bensebih_pickup_sandbox_20261019')
                || (app()->environment('demo') && config('database.connections.pgsql.database') === 'bensebih_demo')), 403, 'Signature tablette de test disponible uniquement dans la démo ou l’espace test.');
        $actor = CompanyMembership::where('user_id', $request->user()->id)->firstOrFail();
        abort_unless($actor->is_primary_responsible, 403);

        return $actor;
    }

    private function cacheKey(int $actor, string $token): string
    {
        return 'tablet-contract:'.Tenant::id().':'.$actor.':'.$token;
    }

    private function original(int $reservation, int $contract, RentalContract $contracts): object
    {
        $file = $contracts->table('rental_contract_files')->where('reservation_id', $reservation)->where('contract_id', $contract)->where('kind', 'original')->firstOrFail();
        abort_unless(Storage::disk('rental_contracts')->exists($file->path)
            && hash_equals($file->sha256, hash('sha256', Storage::disk('rental_contracts')->get($file->path))), 409, 'L’original est indisponible ou altéré.');

        return $file;
    }

    public function preview(Request $request, int $reservation, int $contract, RentalContract $contracts, TabletContractPdf $renderer): JsonResponse
    {
        $actor = $this->actor($request);
        $input = $request->validate([
            'client_signature' => ['required', 'string', 'max:180000'], 'agency_signature' => ['required', 'string', 'max:180000'],
            'expected_signed_id' => ['present', 'nullable', 'integer', 'min:1'], 'original_id' => ['required', 'integer', 'min:1'],
            'original_sha256' => ['required', 'string', 'size:64'], 'contract_read' => ['required', 'accepted'], 'company_id' => ['prohibited'],
        ]);
        foreach (['client_signature', 'agency_signature'] as $field) {
            abort_unless(str_starts_with($input[$field], 'data:image/png;base64,'), 422, 'Signez dans les deux cadres.');
            $bytes = base64_decode(substr($input[$field], 22), true);
            $size = $bytes === false ? false : @getimagesizefromstring($bytes);
            abort_unless($size && $size[2] === IMAGETYPE_PNG && $size[0] >= 120 && $size[1] >= 60 && $size[0] <= 1200 && $size[1] <= 500, 422, 'Image de signature invalide.');
        }
        $version = $contracts->assertCurrent(Reservation::findOrFail($reservation), $contract);
        $original = $this->original($reservation, $contract, $contracts);
        abort_unless($original->id === (int) $input['original_id'] && hash_equals($original->sha256, $input['original_sha256']), 409, 'Ouvrez le PDF original de cette version avant de signer.');
        abort_unless((int) $contracts->table('rental_contract_files')->where('contract_id', $contract)->where('kind', 'signed')->max('id') === (int) ($input['expected_signed_id'] ?? 0), 409, 'Une autre copie signée existe. Actualisez.');
        $snapshot = json_decode($version->snapshot, true, 512, JSON_THROW_ON_ERROR);
        $names = ['client_name' => trim($snapshot['customer']['first_name'].' '.$snapshot['customer']['last_name']), 'agency_name' => $actor->user->name];
        $reference = 'CTR-'.str_pad((string) $reservation, 6, '0', STR_PAD_LEFT).'-V'.$version->version;
        $signedAt = now()->setTimezone('Africa/Casablanca')->format('Y-m-d H:i:s');
        $lock = Cache::lock('tablet-contract-pdf-render', 90);
        abort_unless($lock->get(), 429, 'Une prévisualisation est en cours. Réessayez dans un instant.');
        try {
            $pdf = $renderer->render(Storage::disk('rental_contracts')->get($original->path), [...$names, 'client' => $input['client_signature'], 'agency' => $input['agency_signature']], $reference, $original->sha256, $signedAt);
        } finally {
            $lock->release();
        }
        abort_unless(str_starts_with($pdf, '%PDF-') && strlen($pdf) <= 5 * 1024 * 1024, 422, 'La copie dépasse la limite de 5 Mo.');
        $token = (string) Str::uuid();
        $hash = hash('sha256', $pdf);
        Cache::put($this->cacheKey($actor->id, $token), ['pdf' => base64_encode($pdf), 'sha256' => $hash, 'reservation' => $reservation, 'contract' => $contract, 'original_id' => $original->id, 'original_sha256' => $original->sha256, 'expected_signed_id' => $input['expected_signed_id'], 'signed_at' => $signedAt, 'names' => $names], now()->addMinutes(15));

        return response()->json(['preview_token' => $token, 'sha256' => $hash, 'expires_in' => 900]);
    }

    public function content(Request $request, int $reservation, int $contract, string $token): Response
    {
        $actor = $this->actor($request);
        Reservation::findOrFail($reservation);
        $preview = Cache::get($this->cacheKey($actor->id, $token));
        abort_unless($preview && $preview['reservation'] === $reservation && $preview['contract'] === $contract, 410, 'La prévisualisation a expiré. Recréez-la.');

        return response(base64_decode($preview['pdf']), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="contrat-signatures-test.pdf"', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }

    public function confirm(Request $request, int $reservation, int $contract, RentalContract $contracts): JsonResponse
    {
        $actor = $this->actor($request);
        $input = $request->validate(['preview_token' => ['required', 'uuid'], 'preview_sha256' => ['required', 'string', 'size:64'], 'preview_checked' => ['required', 'accepted'], 'both_consented' => ['required', 'accepted'], 'reason' => ['required', 'string', 'min:3', 'max:1000'], 'request_key' => ['required', 'uuid'], 'company_id' => ['prohibited']]);
        $hash = hash('sha256', json_encode([$reservation, $contract, $input]));
        $path = null;
        try {
            $replayed = DB::transaction(function () use ($input, $hash, $reservation, $contract, $contracts, $actor, &$path): bool {
                Company::whereKey(Tenant::id())->lockForUpdate()->firstOrFail();
                $record = Reservation::whereKey($reservation)->lockForUpdate()->firstOrFail();
                $old = $contracts->table('rental_contract_files')->where('request_key', $input['request_key'])->first();
                if ($old) {
                    abort_unless($old->reservation_id === $reservation && $old->contract_id === $contract && hash_equals($old->request_hash, $hash), 409, 'Cette opération correspond à une autre copie.');

                    return true;
                }
                $contracts->assertCurrent($record, $contract);
                $preview = Cache::get($this->cacheKey($actor->id, $input['preview_token']));
                abort_unless($preview && $preview['reservation'] === $reservation && $preview['contract'] === $contract, 410, 'La prévisualisation a expiré. Recréez-la.');
                abort_unless(hash_equals($preview['sha256'], $input['preview_sha256']), 409, 'La prévisualisation ne correspond pas.');
                $original = $this->original($reservation, $contract, $contracts);
                abort_unless($original->id === $preview['original_id'] && hash_equals($original->sha256, $preview['original_sha256']), 409, 'L’original ne correspond plus.');
                $previous = $contracts->table('rental_contract_files')->where('contract_id', $contract)->where('kind', 'signed')->max('id');
                abort_unless((int) $previous === (int) ($preview['expected_signed_id'] ?? 0), 409, 'Une autre copie a été ajoutée. Recommencez après actualisation.');
                $pdf = base64_decode($preview['pdf']);
                abort_unless(hash_equals($preview['sha256'], hash('sha256', $pdf)), 409, 'Copie indisponible. Recréez la prévisualisation.');
                $path = Tenant::id().'/'.$reservation.'/'.Str::uuid().'.pdf';
                abort_unless(Storage::disk('rental_contracts')->put($path, $pdf), 503, 'Enregistrement impossible.');
                $id = $contracts->table('rental_contract_files')->insertGetId(['company_id' => Tenant::id(), 'reservation_id' => $reservation, 'contract_id' => $contract, 'actor_membership_id' => $actor->id, 'kind' => 'signed', 'path' => $path, 'mime' => 'application/pdf', 'size' => strlen($pdf), 'sha256' => $preview['sha256'], 'reason' => $input['reason'], 'request_key' => $input['request_key'], 'request_hash' => $hash, 'created_at' => now()]);
                $contracts->event($reservation, $actor->id, 'contract_tablet_copy_saved', ['contract_id' => $contract, 'file_id' => $id, 'original_id' => $original->id, 'original_sha256' => $original->sha256, 'signed_sha256' => $preview['sha256'], 'signed_at' => $preview['signed_at'], 'signers' => $preview['names'], 'test_only' => true, 'reason' => $input['reason']]);

                return false;
            });
        } catch (\Throwable $exception) {
            if ($path) {
                Storage::disk('rental_contracts')->delete($path);
            }
            throw $exception;
        }

        return response()->json(['data' => $contracts->summary(Reservation::findOrFail($reservation)), 'replayed' => $replayed], $replayed ? 200 : 201);
    }
}
