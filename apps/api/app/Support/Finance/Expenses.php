<?php

namespace App\Support\Finance;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Vehicle;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Expenses
{
    public const CATEGORIES = ['fuel' => 'Carburant', 'wash' => 'Lavage', 'parking' => 'Parking / péage', 'insurance' => 'Assurance', 'documents' => 'Documents du véhicule', 'rent' => 'Loyer de l’agence', 'utilities' => 'Eau / électricité / internet', 'supplies' => 'Fournitures', 'other' => 'Autre dépense'];

    public const METHODS = ['cash', 'transfer', 'cheque', 'card'];

    public function query(string $table = 'expenses'): Builder
    {
        return DB::table($table)->where('company_id', Tenant::id());
    }

    public function actor(int $user): int
    {
        $m = CompanyMembership::where('user_id', $user)->sole();
        abort_unless($m->is_primary_responsible, 403, 'Les dépenses sont réservées au responsable.');

        return $m->id;
    }

    private function lock(): void
    {
        Company::whereKey(Tenant::id())->lockForUpdate()->firstOrFail();
    }

    private function hash(array $input): string
    {
        ksort($input);

        return hash('sha256', json_encode($input));
    }

    private function replay(string $table, string $key, string $hash): ?object
    {
        $old = $this->query($table)->where('request_key', $key)->first();
        abort_if($old && ! hash_equals($old->request_hash, $hash), 409, 'Cette opération a déjà été utilisée avec des informations différentes.');

        return $old;
    }

    public function records(?int $id = null): array
    {
        $entries = $this->query()->when($id, fn ($q) => $q->where('id', $id))->get();
        if ($id && $entries->isEmpty()) {
            abort(404);
        }$ids = $entries->pluck('id');
        $versions = $this->query('expense_versions')->whereIn('expense_id', $ids)->orderBy('version')->get()->groupBy('expense_id');
        $alloc = $this->query('expense_allocations')->whereIn('version_id', $versions->flatten(1)->pluck('id'))->get()->groupBy('version_id');
        $movements = $this->query('expense_movements')->whereIn('expense_id', $ids)->orderBy('id')->get()->groupBy('expense_id');
        $docs = $this->query('expense_documents')->whereIn('expense_id', $ids)->get(['id', 'expense_id', 'mime', 'checksum', 'created_at'])->groupBy('expense_id');
        $vehicles = Vehicle::get(['id', 'make', 'model', 'plate'])->keyBy('id');
        $actors = DB::table('company_memberships as m')->join('users as u', 'm.user_id', '=', 'u.id')->where('m.company_id', Tenant::id())->pluck('u.name', 'm.id');

        return $entries->map(function ($e) use ($versions, $alloc, $movements, $docs, $vehicles, $actors) {
            $history = $versions[$e->id];
            $v = $history->last();
            $all = $movements[$e->id] ?? collect();
            $current = $all->groupBy('movement_key')->map(fn ($g) => $g->last())->values();
            $gross = (int) $current->where('kind', 'payment')->sum('amount_centimes');
            $refund = (int) $current->where('kind', 'refund')->sum('amount_centimes');
            $net = $gross - $refund;
            $cost = $v->cancelled ? 0 : (int) $v->amount_centimes;
            $shares = ($alloc[$v->id] ?? collect())->map(fn ($a) => ['vehicle_id' => $a->vehicle_id, 'vehicle' => $vehicles[$a->vehicle_id] ?? null, 'amount_centimes' => (int) $a->amount_centimes])->all();
            $historyRows = $history->map(function ($row) use ($actors, $alloc) {
                return [...collect($row)->except(['company_id', 'request_hash', 'request_key', 'actor_membership_id'])->all(), 'actor' => $actors[$row->actor_membership_id] ?? '', 'allocations' => ($alloc[$row->id] ?? collect())->map(fn ($a) => ['vehicle_id' => $a->vehicle_id, 'amount_centimes' => (int) $a->amount_centimes])->all()];
            })->all();
            $movementRows = $current->map(fn ($m) => [...collect($m)->except(['company_id', 'request_hash', 'request_key', 'actor_membership_id'])->all(), 'amount_centimes' => (int) $m->amount_centimes, 'actor' => $actors[$m->actor_membership_id] ?? '', 'history' => $all->where('movement_key', $m->movement_key)->map(fn ($h) => ['version' => $h->version, 'amount_centimes' => (int) $h->amount_centimes, 'method' => $h->method, 'occurred_on' => $h->occurred_on, 'reference' => $h->reference, 'reason' => $h->reason, 'actor' => $actors[$h->actor_membership_id] ?? '', 'created_at' => $h->created_at])->values()->all()])->all();

            return ['key' => 'expense-'.$e->id, 'id' => $e->id, 'source' => 'manual', 'title' => $v->title, 'category' => $v->category, 'supplier' => $v->supplier, 'invoice_reference' => $v->invoice_reference, 'date' => $v->incurred_on, 'due_on' => $v->due_on, 'notes' => $v->notes, 'version' => $v->version, 'revision' => hash('sha256', $v->id.':'.($all->max('id') ?? 0)), 'cancelled' => (bool) $v->cancelled, 'original_amount_centimes' => (int) $v->amount_centimes, 'cost_centimes' => $cost, 'confirmed_cost_centimes' => $cost, 'cost_final' => true, 'unknown_count' => 0, 'gross_paid_centimes' => $gross, 'refunded_centimes' => $refund, 'paid_centimes' => $net, 'balance_centimes' => max(0, $cost - $net), 'credit_centimes' => max(0, $net - $cost), 'allocations' => $shares, 'movements' => $movementRows, 'history' => $historyRows, 'documents' => ($docs[$e->id] ?? collect())->all()];
        })->all();
    }

    public function detail(int $id): array
    {
        return $this->records($id)[0];
    }

    private function stale(array $row, array $input): void
    {
        abort_unless(hash_equals($row['revision'], $input['expected_revision']), 409, 'Ce dossier a changé. Actualisez-le avant de reprendre la modification.');
    }

    private function allocations(array $input): void
    {
        $parts = $input['allocations'];
        $keys = array_map(fn ($a) => ($a['vehicle_id'] ?? null) === null ? 'agency' : (string) $a['vehicle_id'], $parts);
        if (count(array_unique($keys)) !== count($keys) || array_sum(array_column($parts, 'amount_centimes')) !== (int) $input['amount_centimes']) {
            throw ValidationException::withMessages(['allocations' => 'La répartition doit correspondre au total, avec une seule ligne par voiture ou agence.']);
        }$ids = array_values(array_filter(array_column($parts, 'vehicle_id')));
        abort_unless(Vehicle::whereIn('id', $ids)->count() === count($ids), 404, 'Voiture inaccessible.');
    }

    private function duplicate(array $input, ?int $exclude): void
    {
        app(ExpenseDuplicates::class)->check($input, $exclude);
    }

    private function version(int $id, int $actor, array $input, int $number, string $hash, bool $cancelled = false): void
    {
        $version = DB::table('expense_versions')->insertGetId(['company_id' => Tenant::id(), 'expense_id' => $id, 'version' => $number, 'actor_membership_id' => $actor, 'title' => trim($input['title']), 'category' => $input['category'], 'supplier' => trim($input['supplier']), 'invoice_reference' => $input['invoice_reference'] ?? null, 'incurred_on' => $input['incurred_on'], 'due_on' => $input['due_on'] ?? null, 'amount_centimes' => (int) $input['amount_centimes'], 'notes' => $input['notes'] ?? null, 'cancelled' => $cancelled, 'reason' => ($input['reason'] ?? 'Dépense enregistrée.').(($input['duplicate_confirmed'] ?? false) ? ' Facture distincte vérifiée malgré le signalement de similitude.' : ''), 'request_key' => $input['request_key'], 'request_hash' => $hash, 'created_at' => now()]);
        foreach ($input['allocations'] as $a) {
            DB::table('expense_allocations')->insert(['company_id' => Tenant::id(), 'version_id' => $version, 'vehicle_id' => $a['vehicle_id'] ?? null, 'amount_centimes' => (int) $a['amount_centimes']]);
        }
    }

    public function save(?int $id, int $actor, array $input): int
    {
        $hash = $this->hash([...$input, 'expense_id' => $id]);

        return DB::transaction(function () use ($id, $actor, $input, $hash) {
            $this->lock();
            $old = $this->replay($id ? 'expense_versions' : 'expenses', $input['request_key'], $hash);
            if ($old) {
                return $id ?? $old->id;
            }$this->allocations($input);
            $this->duplicate($input, $id);
            if ($id) {
                $row = $this->detail($id);
                $this->stale($row, $input);
                abort_if($row['cancelled'], 409, 'Cette dépense est annulée.');
                abort_if((int) $input['paid_centimes'] !== 0, 422, 'Enregistrez le paiement depuis le bouton Payer.');
                abort_if(collect($row['movements'])->where('amount_centimes', '>', 0)->contains(fn ($m) => $m['occurred_on'] < $input['incurred_on']), 422, 'Cette date serait postérieure à un mouvement déjà enregistré.');
                $number = $row['version'] + 1;
            } else {
                abort_if((int) $input['paid_centimes'] > (int) $input['amount_centimes'], 422, 'Le paiement dépasse le coût de la dépense.');
                $id = DB::table('expenses')->insertGetId(['company_id' => Tenant::id(), 'actor_membership_id' => $actor, 'request_key' => $input['request_key'], 'request_hash' => $hash, 'created_at' => now()]);
                $number = 1;
            }
            $this->version($id, $actor, $input, $number, $hash);
            if ($number === 1 && (int) $input['paid_centimes'] > 0) {
                $this->insertMovement($id, $actor, ['kind' => 'payment', 'amount_centimes' => $input['paid_centimes'], 'method' => $input['method'], 'occurred_on' => $input['paid_on'], 'reference' => $input['payment_reference'] ?? null, 'reason' => 'Paiement enregistré avec la dépense.', 'request_key' => $input['request_key']], $hash, (string) Str::uuid(), 1);
            }

            return $id;
        });
    }

    private function insertMovement(int $id, int $actor, array $input, string $hash, string $key, int $version): int
    {
        return DB::table('expense_movements')->insertGetId(['company_id' => Tenant::id(), 'expense_id' => $id, 'movement_key' => $key, 'version' => $version, 'actor_membership_id' => $actor, 'kind' => $input['kind'], 'amount_centimes' => (int) $input['amount_centimes'], 'method' => $input['method'], 'occurred_on' => $input['occurred_on'], 'reference' => $input['reference'] ?? null, 'reason' => $input['reason'], 'request_key' => $input['request_key'], 'request_hash' => $hash, 'created_at' => now()]);
    }

    public function movement(int $id, int $actor, array $input): int
    {
        $hash = $this->hash([...$input, 'expense_id' => $id]);

        return DB::transaction(function () use ($id, $actor, $input, $hash) {
            $this->lock();
            $row = $this->detail($id);
            if ($old = $this->replay('expense_movements', $input['request_key'], $hash)) {
                return $old->id;
            }$this->stale($row, $input);
            $amount = (int) $input['amount_centimes'];
            $old = null;
            if (! empty($input['movement_key'])) {
                $old = collect($row['movements'])->firstWhere('movement_key', $input['movement_key']);
                abort_unless($old && $old['kind'] === $input['kind'], 404, 'Mouvement inaccessible.');
            } else {
                abort_if($amount === 0, 422, 'Saisissez un montant supérieur à zéro.');
            }
            abort_if($input['occurred_on'] < $row['date'], 422, 'La date du mouvement doit suivre celle de la dépense.');
            $gross = $row['gross_paid_centimes'];
            $refund = $row['refunded_centimes'];
            if ($input['kind'] === 'payment') {
                $gross += $amount - ($old['amount_centimes'] ?? 0);
                abort_if($row['cancelled'] && $amount > ($old['amount_centimes'] ?? 0), 409, 'Cette dépense est annulée.');
            } else {
                $refund += $amount - ($old['amount_centimes'] ?? 0);
            }
            abort_if($gross < $refund, 422, 'Les remboursements dépasseraient les sommes réellement versées.');
            $net = $gross - $refund;
            if ($input['kind'] === 'payment' && $amount > ($old['amount_centimes'] ?? 0)) {
                abort_if($net > $row['cost_centimes'], 422, 'Le paiement dépasse le reste à payer.');
            }
            $timeline = collect($row['movements'])->reject(fn ($m) => $old && $m['movement_key'] === $old['movement_key'])->push(['kind' => $input['kind'], 'amount_centimes' => $amount, 'occurred_on' => $input['occurred_on']])->groupBy('occurred_on')->sortKeys();
            $held = 0;
            foreach ($timeline as $day) {
                $held += (int) $day->where('kind', 'payment')->sum('amount_centimes') - (int) $day->where('kind', 'refund')->sum('amount_centimes');
                abort_if($held < 0, 422, 'Un remboursement ne peut pas précéder les sommes versées.');
            }

            return $this->insertMovement($id, $actor, $input, $hash, $old['movement_key'] ?? (string) Str::uuid(), ($old['version'] ?? 0) + 1);
        });
    }

    public function cancel(int $id, int $actor, array $input): int
    {
        $hash = $this->hash([...$input, 'expense_id' => $id, 'action' => 'cancel']);

        return DB::transaction(function () use ($id, $actor, $input, $hash) {
            $this->lock();
            $row = $this->detail($id);
            if ($old = $this->replay('expense_versions', $input['request_key'], $hash)) {
                return $id;
            }$this->stale($row, $input);
            abort_if($row['cancelled'], 409, 'Cette dépense est déjà annulée.');
            $this->version($id, $actor, [...$row, 'incurred_on' => $row['date'], 'amount_centimes' => $row['original_amount_centimes'], ...$input], $row['version'] + 1, $hash, true);

            return $id;
        });
    }

    public function document(int $id, int $actor, UploadedFile $file, string $key): int
    {
        $checksum = hash_file('sha256', $file->getRealPath());
        $path = null;
        try {
            return DB::transaction(function () use ($id, $actor, $file, $key, $checksum, &$path) {
                $this->lock();
                $this->detail($id);
                $old = $this->query('expense_documents')->where('request_key', $key)->first();
                if ($old) {
                    abort_unless($old->expense_id === $id && hash_equals($old->checksum, $checksum), 409, 'Ce justificatif correspond à une autre opération.');

                    return $old->id;
                }$path = $file->store(Tenant::id().'/'.$id, 'expense_documents');
                abort_unless($path, 500, 'Le justificatif n’a pas été enregistré.');

                return DB::table('expense_documents')->insertGetId(['company_id' => Tenant::id(), 'expense_id' => $id, 'actor_membership_id' => $actor, 'path' => $path, 'mime' => $file->getMimeType(), 'checksum' => $checksum, 'request_key' => $key, 'created_at' => now()]);
            });
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('expense_documents')->delete($path);
            }throw $e;
        }
    }
}
