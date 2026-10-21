<?php

namespace App\Jobs;

use App\Models\Company;
use App\Support\Reservations\ForeignAuthorization;
use App\Support\Reservations\ForeignTravel;
use App\Support\Tenancy\Tenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RenderForeignAuthorization implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $companyId, public int $reservationId, public int $issueId) {}

    public function handle(ForeignAuthorization $renderer, ForeignTravel $travel): void
    {
        Tenant::run($this->companyId, function () use ($renderer, $travel): void {
            if ($travel->events($this->reservationId)->where('action', 'foreign_authorization_generated')->where('data->issue_id', $this->issueId)->exists()) {
                return;
            }
            $issue = $travel->events($this->reservationId)->where('action', 'foreign_authorization_requested')->where('id', $this->issueId)->firstOrFail();
            $snapshot = data_get(json_decode($issue->data, true), 'snapshot');
            $snapshot['reservation_id'] = $this->reservationId;
            $ref = 'AUT-'.str_pad((string) $this->reservationId, 6, '0', STR_PAD_LEFT).'-'.$this->issueId;
            $files = [];
            try {
                foreach (['pdf', 'docx'] as $format) {
                    $bytes = $renderer->$format($snapshot, $ref);
                    $path = $this->companyId.'/'.$this->reservationId.'/foreign/'.Str::uuid().'.'.$format;
                    if (! Storage::disk('rental_contracts')->put($path, $bytes)) {
                        throw new \RuntimeException('Authorization storage unavailable.');
                    }
                    $files[$format] = ['path' => $path, 'sha256' => hash('sha256', $bytes), 'size' => strlen($bytes)];
                }
                $kept = DB::transaction(function () use ($travel, $issue, $files): bool {
                    Company::whereKey($this->companyId)->lockForUpdate()->firstOrFail();
                    if ($travel->events($this->reservationId)->where('action', 'foreign_authorization_generated')->where('data->issue_id', $this->issueId)->exists()) {
                        return false;
                    }
                    $travel->event($this->reservationId, $issue->actor_membership_id, 'foreign_authorization_generated', ['issue_id' => $this->issueId, 'files' => $files]);

                    return true;
                });
                if (! $kept) {
                    foreach ($files as $file) {
                        Storage::disk('rental_contracts')->delete($file['path']);
                    }
                }
            } catch (\Throwable $e) {
                foreach ($files as $file) {
                    Storage::disk('rental_contracts')->delete($file['path']);
                }throw $e;
            }
        });
    }

    public function failed(?\Throwable $e): void
    {
        Tenant::run($this->companyId, function (): void {
            $travel = app(ForeignTravel::class);
            $issue = $travel->events($this->reservationId)->where('id', $this->issueId)->firstOrFail();
            if (! $travel->events($this->reservationId)->where('action', 'foreign_authorization_generated')->where('data->issue_id', $this->issueId)->exists()) {
                $travel->event($this->reservationId, $issue->actor_membership_id, 'foreign_authorization_failed', ['issue_id' => $this->issueId]);
            }
        });
    }
}
