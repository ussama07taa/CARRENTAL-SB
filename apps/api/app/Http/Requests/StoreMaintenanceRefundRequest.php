<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesPrivateDocumentUpload;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMaintenanceRefundRequest extends FormRequest
{
    use ValidatesPrivateDocumentUpload;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['amount_centimes' => ['required', 'integer', 'between:1,1000000000'], 'received_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Africa/Casablanca')->toDateString()], 'method' => ['required', Rule::in(['cash', 'transfer', 'card'])], 'reason' => ['required', 'string', 'max:500'], 'reference' => ['nullable', 'string', 'max:150'], 'file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'], 'work_revision' => ['required', 'integer', 'min:0'], 'cancellation_revision' => ['required', 'integer', 'min:0'], 'expected_paid_centimes' => ['required', 'integer', 'min:0'], 'expected_refunded_centimes' => ['required', 'integer', 'min:0'], 'request_key' => ['required', 'uuid'], 'company_id' => ['prohibited'], 'actor_membership_id' => ['prohibited']];
    }

    public function messages(): array
    {
        return ['required' => 'Ce champ est obligatoire.', 'amount_centimes.between' => 'Saisissez un montant reçu supérieur à 0.', 'received_on.before_or_equal' => 'Enregistrez uniquement des fonds déjà reçus, pas une promesse de remboursement.', 'file.max' => 'Le justificatif doit faire au maximum 5 Mo.'];
    }
}
