<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CancelMaintenancePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:500'], 'correction_revision' => ['required', 'integer', 'min:0'], 'work_revision' => ['required', 'integer', 'min:0'], 'expected_paid_centimes' => ['required', 'integer', 'min:0'], 'request_key' => ['required', 'uuid'], 'company_id' => ['prohibited'], 'actor_membership_id' => ['prohibited'], 'amount_centimes' => ['prohibited']];
    }

    public function messages(): array
    {
        return ['reason.required' => 'Expliquez pourquoi ce paiement a été enregistré par erreur.', 'reason.max' => 'Le motif ne doit pas dépasser 500 caractères.'];
    }
}
