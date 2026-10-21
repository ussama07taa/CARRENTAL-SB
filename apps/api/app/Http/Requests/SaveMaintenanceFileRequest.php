<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesPrivateDocumentUpload;
use App\Support\Vehicles\MaintenanceWork;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveMaintenanceFileRequest extends FormRequest
{
    use ValidatesPrivateDocumentUpload;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('items'))) {
            $this->merge(['items' => json_decode($this->input('items'), true)]);
        }
    }

    public function rules(): array
    {
        $today = now('Africa/Casablanca')->toDateString();

        return ['mode' => ['required', Rule::in(['completed', 'garage'])], 'performed_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today], 'kilometers' => ['nullable', 'integer', 'between:0,9999999'], 'garage' => ['nullable', 'string', 'max:150'], 'notes' => ['nullable', 'string', 'max:2000'], 'paid_centimes' => ['required', 'integer', 'between:0,1000000000'], 'paid_on' => ['nullable', Rule::requiredIf((int) $this->input('paid_centimes') > 0), 'date_format:Y-m-d', 'before_or_equal:'.$today], 'method' => ['nullable', Rule::requiredIf((int) $this->input('paid_centimes') > 0), Rule::in(['cash', 'transfer', 'cheque', 'card'])], 'payment_due_on' => ['nullable', 'date_format:Y-m-d'], 'items' => ['required', 'array', 'min:1', 'max:30'], ...(new MaintenanceWork)->rules('items.*.'), 'file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'], 'request_key' => ['required', 'uuid'], 'company_id' => ['prohibited']];
    }

    public function messages(): array
    {
        return ['required' => 'Ce champ est obligatoire.', 'required_if' => 'Ce champ est obligatoire.', 'date_format' => 'Saisissez une date valide.', 'before_or_equal' => 'Cette date ne peut pas être dans le futur.', 'integer' => 'Saisissez un nombre entier.', 'file.max' => 'La facture doit faire au maximum 5 Mo.', 'items.min' => 'Ajoutez au moins un travail.'];
    }
}
