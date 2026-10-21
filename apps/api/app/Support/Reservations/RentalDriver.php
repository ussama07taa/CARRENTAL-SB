<?php

namespace App\Support\Reservations;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Customer;
use App\Models\CustomerDocument;
use App\Models\Reservation;
use App\Support\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RentalDriver
{
    public function createAndAssign(int $id, CompanyMembership $actor, array $input): bool
    {
        abort_unless($this->ready(), 503, 'Les conducteurs supplémentaires attendent l’activation de la mise à jour.');
        return DB::transaction(function () use ($id, $actor, $input): bool {
            Company::whereKey(Tenant::id())->lockForUpdate()->firstOrFail();
            $r = Reservation::whereKey($id)->lockForUpdate()->firstOrFail();
            $old = DB::table('rental_driver_entries')->where('company_id', Tenant::id())->where('request_key', $input['creation_key'])->first();
            if (! $old) {
                abort_unless($r->status === 'confirmee', 422, 'Ajoutez le conducteur dans une réservation confirmée avant la remise des clés.');
                $current = $this->summary($r);
                abort_unless((int) $current['revision'] === (int) $input['expected_revision'], 409, 'Le conducteur a changé. Actualisez le dossier.');
                abort_if($current['customer'] !== null, 422, 'Un conducteur supplémentaire est déjà lié à ce dossier.');
                $departure = ($r->assignment_starts_at ?? $r->starts_at)->setTimezone(RequestPeriod::TIMEZONE)->toDateString();
                if ($input['licence_expires_on'] < max($departure, now(RequestPeriod::TIMEZONE)->toDateString())) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['licence_expires_on' => 'Le permis doit être valide au jour du départ.']);
                }
            }
            $customer = app(\App\Actions\Customers\SaveCustomer::class)->handle($input, $actor->user_id);
            return $this->save($id, $actor, ['action' => 'assigned', 'expected_revision' => $input['expected_revision'], 'customer_id' => $customer->id, 'reason' => $input['reason'], 'request_key' => $input['creation_key']]);
        });
    }

    public function ready(): bool
    {
        return Schema::hasTable('rental_driver_entries');
    }

    private function entries(int $id)
    {
        return DB::table('rental_driver_entries')->where('company_id', Tenant::id())->where('reservation_id', $id);
    }

    public function summary(Reservation $r): array
    {
        $latest = $this->ready() ? $this->entries($r->id)->latest('id')->first() : null;
        $customer = $latest?->customer_id ? Customer::findOrFail($latest->customer_id) : null;
        $snapshot = $customer ? [
            'customer' => $customer->only(['id', 'first_name', 'last_name', 'phone', 'identity_type', 'identity_number', 'licence_number', 'licence_expires_on']),
            'documents' => CustomerDocument::where('customer_id', $customer->id)->orderByDesc('version')->get()->unique('slot')->sortBy('slot')->map->only(['id', 'slot', 'version'])->values()->all(),
        ] : null;
        $fingerprint = hash('sha256', json_encode($snapshot));
        $missing = [];
        if ($customer) {
            if (! $customer->identity_type || ! $customer->identity_number) {
                $missing[] = 'Compléter l’identité du conducteur supplémentaire dans son fichier client.';
            }
            $departure = ($r->assignment_starts_at ?? $r->starts_at)->setTimezone(RequestPeriod::TIMEZONE)->toDateString();
            $checkDate = max($departure, now(RequestPeriod::TIMEZONE)->toDateString());
            if (! $customer->licence_number || ! $customer->licence_expires_on || substr((string) $customer->licence_expires_on, 0, 10) < $checkDate) {
                $missing[] = 'Compléter un permis valide pour le départ du conducteur supplémentaire.';
            }
        }
        $verified = ! $customer || ($latest->action === 'verified' && $missing === [] && json_decode($latest->snapshot, true) == $snapshot);

        return ['ready' => $this->ready(), 'revision' => $latest?->id, 'editable' => $r->status === 'confirmee', 'customer' => $snapshot['customer'] ?? null, 'fingerprint' => $fingerprint, 'snapshot' => $snapshot, 'verified' => $verified, 'missing' => $missing, 'checked_at' => $latest?->action === 'verified' ? $latest->created_at : null];
    }

    public function contractFields(Reservation $r): array
    {
        $data = $this->summary($r);
        // Preserve fingerprints of every pre-feature contract when no driver was recorded.
        return $data['revision'] ? ['additional_driver' => $data['customer'], 'additional_driver_revision' => $data['revision'], 'additional_driver_documents' => $data['snapshot']['documents'] ?? []] : [];
    }

    public function save(int $id, CompanyMembership $actor, array $input): bool
    {
        abort_unless($this->ready(), 503, 'Les conducteurs supplémentaires attendent l’activation de la mise à jour.');

        return DB::transaction(function () use ($id, $actor, $input): bool {
            Company::whereKey(Tenant::id())->lockForUpdate()->firstOrFail();
            $r = Reservation::whereKey($id)->lockForUpdate()->firstOrFail();
            $hash = hash('sha256', json_encode([$id, $actor->id, $input]));
            $old = DB::table('rental_driver_entries')->where('company_id', Tenant::id())->where('request_key', $input['request_key'])->first();
            if ($old) {
                abort_unless(hash_equals($old->request_hash, $hash), 409, 'Cette opération correspond à une autre saisie.');
                return true;
            }
            abort_unless($r->status === 'confirmee', 422, 'La gestion du conducteur se fait avant la remise des clés, dans une réservation confirmée.');
            $current = $this->summary($r);
            abort_unless((int) $current['revision'] === (int) $input['expected_revision'], 409, 'Le conducteur a changé. Actualisez le dossier.');
            $action = $input['action'];
            $customerId = null;
            $snapshot = [];
            if ($action === 'assigned') {
                $customer = Customer::whereKey($input['customer_id'])->lockForUpdate()->firstOrFail();
                abort_if($customer->id === $r->customer_id, 422, 'Le titulaire du contrat est déjà le conducteur principal.');
                abort_if($current['customer'] !== null, 422, 'Retirez le conducteur actuel avec un motif avant d’en ajouter un autre.');
                $customerId = $customer->id;
                $snapshot = ['customer' => $customer->only(['id', 'first_name', 'last_name'])];
            } elseif ($action === 'verified') {
                abort_unless($current['customer'], 422, 'Ajoutez d’abord le conducteur.');
                Customer::whereKey($current['customer']['id'])->lockForUpdate()->firstOrFail();
                $current = $this->summary($r);
                abort_unless(hash_equals($current['fingerprint'], $input['fingerprint']), 409, 'Les documents ont changé. Reprenez le contrôle des originaux.');
                abort_if($current['missing'] !== [], 422, implode(' ', $current['missing']));
                $customerId = $current['customer']['id'];
                $snapshot = $current['snapshot'];
            } else {
                abort_unless($current['customer'], 422, 'Aucun conducteur supplémentaire à retirer.');
                $snapshot = ['previous_customer' => $current['customer']];
            }
            $entry = DB::table('rental_driver_entries')->insertGetId([
                'company_id' => Tenant::id(), 'reservation_id' => $id, 'customer_id' => $customerId,
                'actor_membership_id' => $actor->id, 'action' => $action, 'snapshot' => json_encode($snapshot),
                'reason' => $input['reason'], 'request_key' => $input['request_key'], 'request_hash' => $hash, 'created_at' => now(),
            ]);
            app(RentalContract::class)->event($id, $actor->id, 'additional_driver_'.$action, ['entry_id' => $entry, 'customer_id' => $customerId, 'reason' => $input['reason']]);
            return false;
        });
    }
}
