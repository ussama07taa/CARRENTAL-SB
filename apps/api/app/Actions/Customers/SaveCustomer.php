<?php

namespace App\Actions\Customers;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Customer;
use App\Support\Customers\CustomerFields;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

class SaveCustomer
{
    public function handle(array $input, int $userId, ?int $id = null): Customer
    {
        return DB::transaction(function () use ($input, $userId, $id): Customer {
            $company = Tenant::id();
            $actor = CompanyMembership::where('user_id', $userId)->firstOrFail();
            // Serialize identity checks and creation retries inside this agency only.
            Company::whereKey($company)->lockForUpdate()->firstOrFail();
            $values = array_intersect_key($input, array_flip(CustomerFields::EDITABLE));
            ksort($values);
            $hash = hash('sha256', json_encode($values));
            if ($id === null) {
                $existing = Customer::where('creation_key', $input['creation_key'])->first();
                if ($existing) {
                    if (! hash_equals($existing->creation_hash, $hash)) {
                        $this->conflict('REQUEST_ALREADY_USED', 'Cette demande a déjà été enregistrée. Ouvrez le fichier existant.', ['customer_id' => $existing->id]);
                    }

                    return $existing;
                }
            }
            $customer = $id ? Customer::whereKey($id)->lockForUpdate()->firstOrFail() : new Customer;
            if ($id && (int) $customer->version !== (int) $input['version']) {
                $this->conflict('CUSTOMER_CHANGED', 'Ce fichier a été modifié ailleurs. Rechargez-le avant de reprendre la modification.');
            }
            $identity = $values['identity_number'] ?? null;
            if ($identity) {
                $duplicate = Customer::where('identity_type', $values['identity_type'])->where('identity_number', $identity)->when($id, fn ($query) => $query->where('id', '<>', $id))->first();
                if ($duplicate) {
                    $this->conflict('IDENTITY_ALREADY_USED', 'Cette pièce d’identité appartient déjà à un fichier client.', ['customer_id' => $duplicate->id, 'reference' => $duplicate->reference]);
                }
            }
            if (! $id && ! ($input['acknowledge_duplicates'] ?? false)) {
                $duplicates = Customer::where(function ($query) use ($values): void {
                    $query->where('phone', $values['phone'])->orWhere(function ($name) use ($values): void {
                        $name->whereRaw('lower(first_name) = lower(?)', [$values['first_name']])->whereRaw('lower(last_name) = lower(?)', [$values['last_name']]);
                    });
                })->limit(5)->get(['id', 'first_name', 'last_name', 'phone']);
                if ($duplicates->isNotEmpty()) {
                    $this->conflict('POSSIBLE_DUPLICATE', 'Un nom ou un téléphone correspond à un client existant. Vérifiez avant de créer un autre fichier.', ['duplicates' => $duplicates]);
                }
            }
            $before = $customer->exists ? $customer->only(CustomerFields::EDITABLE) : null;
            $customer->fill($values);
            if ($customer->exists && ! $customer->isDirty()) {
                return $customer;
            }
            if (! $customer->exists) {
                $customer->creation_key = $input['creation_key'];
                $customer->creation_hash = $hash;
                $customer->version = 1;
            } else {
                $customer->version++;
            }
            $customer->save();
            DB::table('customer_events')->insert([
                'company_id' => $company, 'customer_id' => $customer->id, 'actor_membership_id' => $actor->id,
                'action' => $before === null ? 'created' : 'updated',
                'before' => $before === null ? null : json_encode($before),
                'after' => json_encode($customer->only(CustomerFields::EDITABLE)), 'created_at' => now(),
            ]);

            return $customer;
        });
    }

    private function conflict(string $code, string $message, array $extra = []): never
    {
        throw new HttpResponseException(response()->json(['code' => $code, 'message' => $message, ...$extra], 409)->header('Cache-Control', 'private, no-store'));
    }
}
