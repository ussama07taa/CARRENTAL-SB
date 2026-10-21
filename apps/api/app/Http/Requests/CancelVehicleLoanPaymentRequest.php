<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CancelVehicleLoanPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['amount_centimes' => ['prohibited'], 'reason' => ['required', 'string', 'max:500'], 'correction_revision' => ['required', 'integer', 'min:0'], 'financial_revision' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'], 'expected_remaining_centimes' => ['required', 'integer', 'min:0'], 'expected_loan_remaining_centimes' => ['required', 'integer', 'min:0'], 'confirmed' => ['required', 'accepted'], 'request_key' => ['required', 'uuid'], 'company_id' => ['prohibited'], 'actor_membership_id' => ['prohibited']];
    }

    public function messages(): array
    {
        return ['required' => 'Ce champ est obligatoire.', 'integer' => 'Saisissez un montant valide.', 'reason.max' => 'Le motif doit faire au maximum 500 caractères.', 'confirmed.accepted' => 'Confirmez que ce paiement a été enregistré par erreur ou en double.'];
    }
}
