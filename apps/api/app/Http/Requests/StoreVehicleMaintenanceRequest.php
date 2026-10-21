<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesPrivateDocumentUpload;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVehicleMaintenanceRequest extends FormRequest
{
    use ValidatesPrivateDocumentUpload;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $today = now('Africa/Casablanca')->toDateString();

        return [
            'kind' => ['required', Rule::in(['oil', 'repair', 'service'])], 'title' => ['required', 'string', 'max:150'],
            'performed_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today], 'kilometers' => ['nullable', 'integer', 'between:0,9999999'],
            'garage' => ['nullable', 'string', 'max:150'], 'notes' => ['nullable', 'string', 'max:2000'],
            'cost_centimes' => ['required', 'integer', 'between:0,1000000000'], 'paid_centimes' => ['required', 'integer', 'min:0', 'lte:cost_centimes'],
            'paid_on' => ['nullable', Rule::requiredIf((int) $this->input('paid_centimes') > 0), 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'method' => ['nullable', Rule::requiredIf((int) $this->input('paid_centimes') > 0), Rule::in(['cash', 'transfer', 'cheque', 'card'])],
            'payment_due_on' => ['nullable', 'date_format:Y-m-d'], 'next_on' => ['nullable', 'date_format:Y-m-d', 'after:performed_on'],
            'next_km' => ['nullable', 'integer', 'between:1,9999999'], 'in_garage' => ['required', 'boolean'],
            'replaces_id' => ['nullable', 'integer', 'min:1'], 'file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'],
            'request_key' => ['required', 'uuid'], 'company_id' => ['prohibited'], 'actor_membership_id' => ['prohibited'], 'path' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return ['required' => 'Ce champ est obligatoire.', 'integer' => 'Saisissez un nombre entier.', 'file.mimes' => 'Utilisez un PDF ou une image JPG, PNG ou WebP.', 'file.max' => 'Le fichier doit faire au maximum 5 Mo.', 'file.uploaded' => 'Le fichier n’a pas pu être reçu (5 Mo maximum).', 'before_or_equal' => 'Cette date ne peut pas être dans le futur.', 'lte' => 'Le paiement ne peut pas dépasser le coût.', 'after' => 'Le prochain entretien doit être après cette intervention.'];
    }
}
