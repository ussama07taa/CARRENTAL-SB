<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMaintenancePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['amount_centimes' => ['required', 'integer', 'between:1,1000000000'], 'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Africa/Casablanca')->toDateString()], 'method' => ['required', Rule::in(['cash', 'transfer', 'cheque', 'card'])], 'reference' => ['nullable', 'string', 'max:150'], 'request_key' => ['required', 'uuid'], 'company_id' => ['prohibited'], 'actor_membership_id' => ['prohibited']];
    }

    public function messages(): array
    {
        return ['required' => 'Ce champ est obligatoire.', 'integer' => 'Saisissez un montant valide.', 'between' => 'Le montant doit être supérieur à 0.', 'before_or_equal' => 'Le paiement ne peut pas être daté dans le futur.'];
    }
}
