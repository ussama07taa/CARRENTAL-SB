<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePurchasePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'amount_centimes' => ['required', 'integer', 'between:0,1000000000'],
            'paid_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:'.now('Africa/Casablanca')->toDateString()],
            'method' => ['required', Rule::in(['cash', 'transfer', 'card'])],
            'reference' => ['nullable', 'string', 'max:150'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'expected_remaining_centimes' => ['required', 'integer', 'between:0,1000000000'],
            'expected_version' => ['nullable', 'integer', 'min:1'],
            'receipt_confirmed' => ['required', 'accepted'],
            'request_key' => ['required', 'uuid'],
            'company_id' => ['prohibited'], 'actor_membership_id' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return ['required' => 'Ce champ est obligatoire.', 'integer' => 'Indiquez un montant valide.', 'accepted' => 'Confirmez le versement réellement effectué.', 'paid_on.before_or_equal' => 'La date du paiement ne peut pas être future.'];
    }
}
