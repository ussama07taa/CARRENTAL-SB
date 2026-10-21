<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVehicleLoanPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['amount_centimes' => ['required', 'integer', 'between:1,1000000000'], 'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Africa/Casablanca')->toDateString()], 'method' => ['required', Rule::in(['cash', 'transfer', 'card'])], 'reference' => ['nullable', 'string', 'max:150'], 'expected_remaining_centimes' => ['required', 'integer', 'min:0'], 'receipt_confirmed' => ['required', 'accepted'], 'request_key' => ['required', 'uuid'], 'company_id' => ['prohibited'], 'actor_membership_id' => ['prohibited']];
    }

    public function messages(): array
    {
        return ['required' => 'Ce champ est obligatoire.', 'amount_centimes.between' => 'Saisissez un montant réellement payé supérieur à 0.', 'paid_on.before_or_equal' => 'Enregistrez uniquement un paiement déjà effectué.', 'receipt_confirmed.accepted' => 'Confirmez que ce paiement a bien été effectué.'];
    }
}
