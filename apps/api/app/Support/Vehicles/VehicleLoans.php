<?php

namespace App\Support\Vehicles;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class VehicleLoans
{
    public function actor(int $user): int
    {
        $member = CompanyMembership::where('user_id', $user)->firstOrFail();
        abort_unless($member->is_primary_responsible, 403, 'Les contrats de financement et leurs paiements sont réservés au responsable.');

        return $member->id;
    }

    public function query(string $table = 'vehicle_loans'): Builder
    {
        return DB::table($table)->where('company_id', Tenant::id());
    }

    public function lock(): void
    {
        Company::whereKey(Tenant::id())->lockForUpdate()->firstOrFail();
    }

    public function hash(array $input): string
    {
        ksort($input);

        return hash('sha256', json_encode($input));
    }

    public function replay(string $table, string $key, string $hash): ?object
    {
        $old = $this->query($table)->where('request_key', $key)->first();
        abort_if($old && ! hash_equals($old->request_hash, $hash), 409, 'Cette demande a déjà été utilisée avec des données différentes.');

        return $old;
    }

    public function loan(int $id): object
    {
        return $this->query()->where('id', $id)->firstOrFail();
    }

    public function ensureActive(int $loan): void
    {
        abort_if($this->query('vehicle_loan_cancellations')->where('loan_id', $loan)->exists(), 409, 'Ce contrat est annulé. Son historique est consultable, mais aucun nouveau mouvement ne peut y être enregistré.');
    }

    public function audit(object $loan, int $actor, string $action, array $data): void
    {
        DB::table('vehicle_events')->insert(['company_id' => Tenant::id(), 'vehicle_id' => $loan->vehicle_id, 'actor_membership_id' => $actor, 'action' => $action, 'before' => null, 'after' => json_encode(['loan_id' => $loan->id, ...$data]), 'created_at' => now()]);
    }

    public function payments(): Builder
    {
        $latest = $this->query('vehicle_loan_payment_corrections')->selectRaw('payment_key, MAX(id) AS latest_id')->groupBy('payment_key');

        return DB::table('vehicle_loan_payments as p')->leftJoinSub($latest, 'latest', 'latest.payment_key', '=', 'p.request_key')->leftJoin('vehicle_loan_payment_corrections as c', function ($j) {
            $j->on('c.id', '=', 'latest.latest_id')->on('c.company_id', '=', 'p.company_id');
        })->leftJoin('vehicle_loan_payment_cancellations as x', function ($j) {
            $j->on('x.company_id', '=', 'p.company_id')->on('x.payment_key', '=', 'p.request_key');
        })->where('p.company_id', Tenant::id());
    }

    public function financialRevision(int $loan): string
    {
        $rows = $this->payments()->where('p.loan_id', $loan)->orderBy('p.id')->select('p.id')->selectRaw('COALESCE(c.id,0) AS correction_id, COALESCE(x.id,0) AS cancellation_id')->get();

        return hash('sha256', $rows->toJson());
    }

    public function installments(): Builder
    {
        $paid = $this->payments()->selectRaw('p.installment_id, SUM(CASE WHEN x.id IS NULL THEN COALESCE(c.amount_centimes,p.amount_centimes) ELSE 0 END) AS paid_centimes')->groupBy('p.installment_id');

        return DB::table('vehicle_loan_installments as i')->leftJoinSub($paid, 'p', 'p.installment_id', '=', 'i.id')->where('i.company_id', Tenant::id())->select('i.id', 'i.loan_id', 'i.position', 'i.due_on', 'i.amount_centimes', 'i.opening_paid_centimes')->selectRaw('COALESCE(p.paid_centimes,0) AS recorded_paid_centimes, i.opening_paid_centimes + COALESCE(p.paid_centimes,0) AS settled_centimes, i.amount_centimes-i.opening_paid_centimes-COALESCE(p.paid_centimes,0) AS remaining_centimes');
    }

    public function statistics(): Builder
    {
        $today = now('Africa/Casablanca')->toDateString();

        return DB::query()->fromSub($this->installments(), 's')->selectRaw('loan_id, COUNT(*) AS installment_count, SUM(amount_centimes) AS total_centimes, SUM(opening_paid_centimes) AS opening_paid_centimes, SUM(recorded_paid_centimes) AS recorded_paid_centimes, SUM(remaining_centimes) AS remaining_centimes, COUNT(*) FILTER (WHERE remaining_centimes > 0) AS remaining_count, MIN(due_on) FILTER (WHERE remaining_centimes > 0) AS next_due_on')->selectRaw('COALESCE(SUM(remaining_centimes) FILTER (WHERE due_on < ?),0) AS overdue_centimes, COALESCE(SUM(remaining_centimes) FILTER (WHERE due_on BETWEEN ? AND ?),0) AS upcoming_centimes, COALESCE(SUM(remaining_centimes) FILTER (WHERE due_on BETWEEN ? AND ?),0) AS month_centimes', [$today, $today, now('Africa/Casablanca')->addDays(30)->toDateString(), now('Africa/Casablanca')->startOfMonth()->toDateString(), now('Africa/Casablanca')->endOfMonth()->toDateString()])->groupBy('loan_id');
    }

    public function summaries(bool $includeCancelled = false): Builder
    {
        $query = DB::table('vehicle_loans as l')->leftJoin('vehicle_loan_cancellations as lc', function ($j) {
            $j->on('lc.company_id', '=', 'l.company_id')->on('lc.loan_id', '=', 'l.id');
        })->join('vehicles as v', function ($j) {
            $j->on('v.company_id', '=', 'l.company_id')->on('v.id', '=', 'l.vehicle_id');
        })->joinSub($this->statistics(), 's', 's.loan_id', '=', 'l.id')->where('l.company_id', Tenant::id())->select('lc.id as cancellation_id', 'l.id', 'l.vehicle_id', 'l.lender', 'l.reference', 'l.tracking_from', 'l.signed_on', 'l.financed_centimes', 'l.notes', 'l.created_at', 'v.plate', 'v.make', 'v.model', 's.installment_count', 's.total_centimes', 's.opening_paid_centimes', 's.recorded_paid_centimes', 's.remaining_centimes', 's.remaining_count', 's.next_due_on', 's.overdue_centimes', 's.upcoming_centimes', 's.month_centimes');

        return $includeCancelled ? $query : $query->whereNull('lc.id');
    }

    public function numbers(object $row): object
    {
        foreach (get_object_vars($row) as $key => $value) {
            if ($value !== null && (str_ends_with($key, '_centimes') || in_array($key, ['installment_count', 'remaining_count', 'loan_count', 'position', 'correction_revision', 'version'], true))) {
                $row->$key = (int) $value;
            }
        }

        return $row;
    }

    public function schedule(int $loan): Collection
    {
        $today = now('Africa/Casablanca')->toDateString();

        return $this->installments()->where('i.loan_id', $loan)->orderBy('i.position')->get()->map(function ($row) use ($today) {
            $this->numbers($row);
            $row->status = $row->remaining_centimes === 0 ? 'paid' : ($row->due_on < $today ? 'overdue' : ($row->due_on === $today ? 'due' : ($row->settled_centimes > 0 ? 'partial' : 'scheduled')));

            return $row;
        });
    }

    public function pay(object $loan, int $installment, int $actor, array $input): int
    {
        $this->ensureActive($loan->id);
        $hash = $this->hash([...$input, 'loan_id' => $loan->id, 'installment_id' => $installment]);
        $row = $this->installments()->where('i.loan_id', $loan->id)->where('i.id', $installment)->firstOrFail();
        if ($old = $this->replay('vehicle_loan_payments', $input['request_key'], $hash)) {
            return $old->id;
        }
        if ($input['paid_on'] < $loan->tracking_from) {
            throw ValidationException::withMessages(['paid_on' => 'Cette date précède le début du suivi. Les montants antérieurs sont déjà repris séparément dans le tableau.']);
        }
        abort_if((int) $row->remaining_centimes !== (int) $input['expected_remaining_centimes'], 409, 'Le solde de cette échéance a changé. Actualisez le dossier avant de confirmer.');
        if ((int) $input['amount_centimes'] > (int) $row->remaining_centimes) {
            throw ValidationException::withMessages(['amount_centimes' => 'Le paiement dépasse le reste à payer de cette échéance.']);
        }
        $id = DB::table('vehicle_loan_payments')->insertGetId(['company_id' => Tenant::id(), 'loan_id' => $loan->id, 'installment_id' => $installment, 'actor_membership_id' => $actor, 'amount_centimes' => (int) $input['amount_centimes'], 'paid_on' => $input['paid_on'], 'method' => $input['method'], 'reference' => $input['reference'] ?? null, 'request_key' => $input['request_key'], 'request_hash' => $hash, 'created_at' => now()]);
        $this->audit($loan, $actor, 'loan_payment_recorded', ['payment_id' => $id, 'installment_id' => $installment, 'amount_centimes' => (int) $input['amount_centimes']]);

        return $id;
    }

    public function document(object $loan, int $actor, UploadedFile $file, string $key, string $hash, ?string &$path): int
    {
        $this->ensureActive($loan->id);
        $path = Tenant::id().'/'.$loan->vehicle_id.'/loans/'.$loan->id.'/'.Str::uuid().'.'.$file->extension();
        if (! Storage::disk('vehicle_documents')->putFileAs(dirname($path), $file, basename($path), ['visibility' => 'private'])) {
            throw new \RuntimeException('Private storage failed');
        }
        $id = DB::table('vehicle_loan_documents')->insertGetId(['company_id' => Tenant::id(), 'loan_id' => $loan->id, 'actor_membership_id' => $actor, 'path' => $path, 'mime' => $file->getMimeType(), 'sha256' => hash_file('sha256', $file->getRealPath()), 'request_key' => $key, 'request_hash' => $hash, 'created_at' => now()]);
        $this->audit($loan, $actor, 'loan_document_attached', ['document_id' => $id]);

        return $id;
    }
}
