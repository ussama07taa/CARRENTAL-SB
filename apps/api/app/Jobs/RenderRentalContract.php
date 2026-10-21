<?php

namespace App\Jobs;

use App\Models\Company;
use App\Support\Reservations\ContractPdf;
use App\Support\Reservations\RentalContract;
use App\Support\Tenancy\Tenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RenderRentalContract implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $companyId, public int $contractId) {}

    public function handle(ContractPdf $pdf, RentalContract $contracts): void
    {
        Tenant::run($this->companyId, function () use ($pdf, $contracts): void {
            $v = $contracts->table('rental_contract_versions')->where('id', $this->contractId)->firstOrFail();
            if ($contracts->table('rental_contract_files')->where('contract_id', $v->id)->where('kind', 'original')->exists()) {
                return;
            }$path = $this->companyId.'/'.$v->reservation_id.'/'.Str::uuid().'.pdf';
            try {
                $bytes = $pdf->render(json_decode($v->snapshot, true), 'CTR-'.str_pad((string) $v->reservation_id, 6, '0', STR_PAD_LEFT).'-V'.$v->version);
                if (! Storage::disk('rental_contracts')->put($path, $bytes)) {
                    throw new \RuntimeException('Contract storage failed.');
                }
                $kept = DB::transaction(function () use ($contracts, $v, $path, $bytes): bool {
                    Company::whereKey($this->companyId)->lockForUpdate()->firstOrFail();
                    if ($contracts->table('rental_contract_files')->where('contract_id', $v->id)->where('kind', 'original')->exists()) {
                        return false;
                    }$contracts->table('rental_contract_files')->insert(['company_id' => $this->companyId, 'reservation_id' => $v->reservation_id, 'contract_id' => $v->id, 'actor_membership_id' => $v->actor_membership_id, 'kind' => 'original', 'path' => $path, 'mime' => 'application/pdf', 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'reason' => 'Original généré depuis la copie conservée', 'request_key' => (string) Str::uuid(), 'request_hash' => $v->fingerprint, 'created_at' => now()]);
                    $contracts->event($v->reservation_id, $v->actor_membership_id, 'contract_generated', ['contract_id' => $v->id]);

                    return true;
                });
                if (! $kept) {
                    Storage::disk('rental_contracts')->delete($path);
                }
            } catch (\Throwable $e) {
                Storage::disk('rental_contracts')->delete($path);
                throw $e;
            }
        });
    }

    public function failed(?\Throwable $exception): void
    {
        Tenant::run($this->companyId, function (): void {
            DB::table('rental_contract_render_events')->insert(['company_id' => $this->companyId, 'contract_id' => $this->contractId, 'action' => 'failed', 'created_at' => now()]);
        });
    }
}
