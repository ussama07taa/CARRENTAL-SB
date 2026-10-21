<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesPrivateDocumentUpload;
use App\Models\VehicleDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVehicleDocumentRequest extends FormRequest
{
    use ValidatesPrivateDocumentUpload;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $data = [];
        foreach (VehicleDocument::METADATA as $key) {
            $v = $this->input($key);
            $data[$key] = is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v;
        }
        $this->merge($data);
    }

    public function rules(): array
    {
        $kind = $this->input('kind');
        $dated = in_array($kind, ['insurance', 'inspection', 'circulation'], true);
        $today = now('Africa/Casablanca')->toDateString();

        return [
            'kind' => ['required', Rule::in(VehicleDocument::KINDS)],
            'change_kind' => ['required', Rule::in(['initial', 'renewal', 'correction'])],
            'reason' => ['nullable', 'required_if:change_kind,correction', 'string', 'max:500'],
            'effective_on' => [$dated ? 'required' : 'prohibited', 'nullable', 'date_format:Y-m-d', ...($kind === 'inspection' ? ['before_or_equal:'.$today] : [])],
            'expires_on' => [$dated ? 'required' : ($kind === 'tax' ? 'nullable' : 'prohibited'), 'nullable', 'date_format:Y-m-d', ...($dated ? ['after:effective_on'] : [])],
            'provider' => [$kind === 'insurance' ? 'required' : 'prohibited', 'nullable', 'string', 'max:120'],
            'policy_number' => [$kind === 'insurance' ? 'nullable' : 'prohibited', 'nullable', 'string', 'max:100'],
            'tax_year' => [$kind === 'tax' ? 'required' : 'prohibited', 'nullable', 'integer', 'between:1950,'.(now('Africa/Casablanca')->year + 1)],
            'paid_on' => [$kind === 'tax' ? 'required' : 'prohibited', 'nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'],
            'retain_file' => ['sometimes', 'boolean'],
            'previous_id' => ['present', 'nullable', 'integer', 'min:1'],
            'request_key' => ['required', 'uuid'],
            'company_id' => ['prohibited'], 'actor_membership_id' => ['prohibited'], 'path' => ['prohibited'], 'version' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return ['required' => 'Ce champ est obligatoire.', 'required_if' => 'Expliquez la correction.', 'in' => 'Choisissez une valeur proposée.',
            'date_format' => 'Saisissez une date valide.', 'after' => 'L’échéance doit être après la date de début ou du contrôle.',
            'before_or_equal' => 'Cette date ne peut pas être dans le futur.', 'file.mimes' => 'Utilisez un PDF ou une image JPG, PNG ou WebP.',
            'file.max' => 'Le fichier doit faire au maximum 5 Mo.', 'file.uploaded' => 'Le fichier n’a pas pu être reçu (5 Mo maximum).',
            'integer' => 'Saisissez un nombre entier.', 'prohibited' => 'Ce champ ne correspond pas à cette opération.'];
    }
}
