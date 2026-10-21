<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CancelMaintenanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['scope' => ['required', Rule::in(['work', 'file'])], 'item_key' => ['present', 'nullable', 'uuid'], 'reason' => ['required', 'string', 'max:500'], 'revision' => ['required', 'integer', 'min:0'], 'cancellation_revision' => ['required', 'integer', 'min:0'], 'expected_paid_centimes' => ['required', 'integer', 'min:0'], 'request_key' => ['required', 'uuid'], 'company_id' => ['prohibited'], 'actor_membership_id' => ['prohibited']];
    }

    public function messages(): array
    {
        return ['reason.required' => 'Expliquez l’erreur de saisie à annuler.', 'reason.max' => 'Le motif ne doit pas dépasser 500 caractères.'];
    }
}
