<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Reservation;
use App\Support\Reservations\RentalContract;
use App\Support\Reservations\RentalExtension;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReservationContractController extends Controller
{
    public function show(Request $request, int $reservation, RentalContract $contracts): JsonResponse
    {
        return DB::transaction(function () use ($request, $reservation, $contracts): JsonResponse {
            if (DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }

            $input = $request->validate(['extension_id' => ['nullable', 'integer', 'min:1']]);
            $record = Reservation::findOrFail($reservation);

            return response()->json(['data' => isset($input['extension_id']) ? app(RentalExtension::class)->contractSummary($record, (int) $input['extension_id']) : $contracts->summary($record)]);
        });
    }

    public function issue(Request $r, int $reservation, RentalContract $contracts): JsonResponse
    {
        $input = $r->validate(['extension_id' => ['nullable', 'integer', 'min:1'], 'expected_latest_id' => ['present', 'nullable', 'integer', 'min:1'], 'fingerprint' => ['required', 'string', 'size:64'], 'reason' => ['required', 'string', 'min:3', 'max:1000'], 'reviewed' => ['required', 'accepted'], 'duration_rectification' => ['sometimes', 'boolean'], 'request_key' => ['required', 'uuid'], 'company_id' => ['prohibited'], 'snapshot' => ['prohibited']]);
        $actor = CompanyMembership::where('user_id', $r->user()->id)->firstOrFail();
        $result = $contracts->issue($reservation, $actor, $input);

        return response()->json(['data' => $this->summaryFor($r, $reservation, $contracts), 'replayed' => $result['replayed']], $result['replayed'] ? 200 : 201);
    }

    private function summaryFor(Request $request, int $reservation, RentalContract $contracts): array
    {
        $record = Reservation::findOrFail($reservation);

        return $request->integer('extension_id') ? app(RentalExtension::class)->contractSummary($record, $request->integer('extension_id')) : $contracts->summary($record);
    }

    public function retry(Request $r, int $reservation, int $contract, RentalContract $contracts): JsonResponse
    {
        abort_unless(Schema::hasTable('rental_contract_versions'), 503, 'La mise à jour attend son activation.');
        DB::transaction(function () use ($reservation, $contract, $contracts): void {
            Company::whereKey(Tenant::id())->lockForUpdate()->firstOrFail();
            $v = $contracts->table('rental_contract_versions')->where('reservation_id', $reservation)->where('id', $contract)->firstOrFail();
            if (! $contracts->table('rental_contract_files')->where('contract_id', $v->id)->where('kind', 'original')->exists()) {
                $contracts->enqueue($v->id);
            }
        });

        return response()->json(['data' => $this->summaryFor($r, $reservation, $contracts)], 202);
    }

    public function upload(Request $r, int $reservation, int $contract, RentalContract $contracts): JsonResponse
    {
        abort_unless(Schema::hasTable('rental_contract_versions'), 503, 'La mise à jour attend son activation.');
        $input = $r->validate(['file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'], 'expected_signed_id' => ['present', 'nullable', 'integer', 'min:1'], 'reason' => ['required', 'string', 'min:3', 'max:1000'], 'same_version' => ['required', 'accepted'], 'request_key' => ['required', 'uuid'], 'company_id' => ['prohibited']]);
        $file = $r->file('file');
        $hash = hash('sha256', json_encode([$reservation, $contract, hash_file('sha256', $file->getRealPath()), collect($input)->except('file')->all()]));
        $path = null;
        try {
            $replayed = DB::transaction(function () use ($r, $reservation, $contract, $contracts, $input, $file, $hash, &$path): bool {
                Company::whereKey(Tenant::id())->lockForUpdate()->firstOrFail();
                $record = Reservation::whereKey($reservation)->lockForUpdate()->firstOrFail();
                $old = $contracts->table('rental_contract_files')->where('request_key', $input['request_key'])->first();
                if ($old) {
                    abort_unless(hash_equals($old->request_hash, $hash), 409, 'Cette opération correspond à un autre fichier.');

                    return true;
                }$contracts->assertCurrent($record, $contract);
                abort_unless($contracts->table('rental_contract_files')->where('contract_id', $contract)->where('kind', 'original')->exists(), 422, 'Attendez la génération du PDF original.');
                $previous = $contracts->table('rental_contract_files')->where('contract_id', $contract)->where('kind', 'signed')->max('id');
                abort_unless((int) $previous === (int) ($input['expected_signed_id'] ?? 0), 409, 'Une autre copie signée a été ajoutée. Actualisez.');
                $actor = CompanyMembership::where('user_id', $r->user()->id)->firstOrFail();
                $path = Tenant::id().'/'.$reservation.'/'.Str::uuid().'.'.$file->extension();
                abort_unless(Storage::disk('rental_contracts')->putFileAs(dirname($path), $file, basename($path)), 503, 'Enregistrement du fichier impossible.');
                $id = $contracts->table('rental_contract_files')->insertGetId(['company_id' => Tenant::id(), 'reservation_id' => $reservation, 'contract_id' => $contract, 'actor_membership_id' => $actor->id, 'kind' => 'signed', 'path' => $path, 'mime' => $file->getMimeType(), 'size' => $file->getSize(), 'sha256' => hash_file('sha256', $file->getRealPath()), 'reason' => $input['reason'], 'request_key' => $input['request_key'], 'request_hash' => $hash, 'created_at' => now()]);
                $contracts->event($reservation, $actor->id, 'contract_copy_uploaded', ['contract_id' => $contract, 'file_id' => $id, 'reason' => $input['reason']]);

                return false;
            });
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('rental_contracts')->delete($path);
            }throw $e;
        }

        return response()->json(['data' => $this->summaryFor($r, $reservation, $contracts), 'replayed' => $replayed], $replayed ? 200 : 201);
    }

    public function verify(Request $r, int $reservation, int $contract, RentalContract $contracts): JsonResponse
    {
        abort_unless(Schema::hasTable('rental_contract_versions'), 503, 'La mise à jour attend son activation.');
        $input = $r->validate(['file_id' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'min:3', 'max:1000'], 'signatures_checked' => ['required', 'accepted'], 'request_key' => ['required', 'uuid'], 'company_id' => ['prohibited']]);
        $hash = hash('sha256', json_encode([$reservation, $contract, $input]));
        $replayed = DB::transaction(function () use ($r, $reservation, $contract, $contracts, $input, $hash): bool {
            Company::whereKey(Tenant::id())->lockForUpdate()->firstOrFail();
            $record = Reservation::whereKey($reservation)->lockForUpdate()->firstOrFail();
            $old = $contracts->table('rental_contract_verifications')->where('request_key', $input['request_key'])->first();
            if ($old) {
                abort_unless(hash_equals($old->request_hash, $hash), 409, 'Cette opération correspond à d’autres données.');

                return true;
            }$contracts->assertCurrent($record, $contract);
            $file = $contracts->table('rental_contract_files')->where('reservation_id', $reservation)->where('contract_id', $contract)->where('kind', 'signed')->latest('id')->first();
            abort_unless($file && $file->id === (int) $input['file_id'], 409, 'Vérifiez la dernière copie signée de cette version.');
            abort_if($contracts->table('rental_contract_verifications')->where('file_id', $file->id)->exists(), 409, 'Cette copie est déjà vérifiée.');
            abort_unless(Storage::disk('rental_contracts')->exists($file->path) && hash_equals($file->sha256, hash('sha256', Storage::disk('rental_contracts')->get($file->path))), 422, 'Le fichier est indisponible ou altéré. Ajoutez une copie intacte.');
            $actor = CompanyMembership::where('user_id', $r->user()->id)->firstOrFail();
            $contracts->table('rental_contract_verifications')->insert(['company_id' => Tenant::id(), 'reservation_id' => $reservation, 'contract_id' => $contract, 'file_id' => $file->id, 'actor_membership_id' => $actor->id, 'reason' => $input['reason'], 'request_key' => $input['request_key'], 'request_hash' => $hash, 'created_at' => now()]);
            $contracts->event($reservation, $actor->id, 'contract_copy_verified', ['contract_id' => $contract, 'file_id' => $file->id, 'reason' => $input['reason']]);

            return false;
        });

        return response()->json(['data' => $this->summaryFor($r, $reservation, $contracts), 'replayed' => $replayed], $replayed ? 200 : 201);
    }

    public function content(int $reservation, int $file, RentalContract $contracts): StreamedResponse
    {
        Reservation::findOrFail($reservation);
        abort_unless(Schema::hasTable('rental_contract_files'), 404);
        $f = $contracts->table('rental_contract_files')->where('reservation_id', $reservation)->where('id', $file)->firstOrFail();
        abort_unless(Storage::disk('rental_contracts')->exists($f->path), 404, 'Fichier indisponible.');
        abort_unless(hash_equals($f->sha256, hash('sha256', Storage::disk('rental_contracts')->get($f->path))), 409, 'Le fichier est altéré. L’original ne sera pas régénéré silencieusement.');
        $ext = match ($f->mime) {
            'application/pdf' => 'pdf','image/png' => 'png','image/webp' => 'webp',default => 'jpg'
        };

        return Storage::disk('rental_contracts')->response($f->path, 'contrat-'.$f->contract_id.'-'.$f->kind.'.'.$ext, ['Content-Type' => $f->mime, 'Cache-Control' => 'private, no-store, max-age=0', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }
}
