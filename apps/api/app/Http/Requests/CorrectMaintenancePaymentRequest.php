<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CorrectMaintenancePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['amount_centimes' => ['required', 'integer', 'between:1,1000000000'], 'reason' => ['required', 'string', 'max:500'], 'correction_revision' => ['required', 'integer', 'min:0'], 'work_revision' => ['required', 'integer', 'min:0'], 'expected_paid_centimes' => ['required', 'integer', 'min:0'], 'request_key' => ['required', 'uuid'], 'company_id' => ['prohibited'], 'actor_membership_id' => ['prohibited']];
    }

    public function messages(): array
    {
        return ['required' => 'Ce champ est obligatoire.', 'integer' => 'Saisissez un montant valide.', 'amount_centimes.between' => 'Le montant corrigé doit être supérieur à 0. L’annulation complète n’est pas disponible ici.'];
    }
}
