<?php

namespace App\Http\Requests;

use App\Support\Customers\CustomerFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $data = [];
        foreach (CustomerFields::EDITABLE as $field) {
            $value = $this->input($field);
            $data[$field] = is_string($value) ? (trim($value) ?: null) : $value;
        }
        foreach (['phone', 'secondary_phone'] as $field) {
            if (is_string($data[$field])) {
                $data[$field] = CustomerFields::phone($data[$field]);
            }
        }
        if (is_string($data['identity_number'])) {
            $data['identity_number'] = CustomerFields::identity($data['identity_number']);
        }
        $data['language'] ??= 'fr';
        $this->merge($data);
    }

    public function rules(): array
    {
        $phone = ['string', 'regex:/^\+[1-9][0-9]{7,14}$/'];

        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'phone' => ['required', ...$phone],
            'secondary_phone' => ['nullable', ...$phone],
            'email' => ['nullable', 'email:rfc', 'max:254'],
            'city' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:500'],
            'language' => ['required', Rule::in(['fr', 'ar'])],
            'identity_type' => ['nullable', 'required_with:identity_number', Rule::in(['cin', 'passport'])],
            'identity_number' => ['nullable', 'required_with:identity_type', 'string', 'max:50', 'regex:/^[\pL\pN]+$/u'],
            'licence_number' => ['nullable', 'string', 'max:50'],
            'licence_expires_on' => ['nullable', 'date_format:Y-m-d'],
            'creation_key' => [$this->isMethod('POST') ? 'required' : 'prohibited', 'uuid'],
            'version' => [$this->isMethod('PUT') ? 'required' : 'prohibited', 'integer', 'min:1'],
            'acknowledge_duplicates' => ['sometimes', 'boolean'],
            'company_id' => ['prohibited'], 'actor_membership_id' => ['prohibited'], 'id' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'Ce champ est obligatoire.',
            'required_with' => 'Renseignez le type et le numéro de la pièce ensemble.',
            'string' => 'Saisissez du texte.', 'max' => 'Ce champ est trop long (maximum :max caractères).',
            'email' => 'Saisissez une adresse e-mail valide.', 'in' => 'Choisissez une valeur proposée.',
            'phone.regex' => 'Saisissez un numéro marocain à 10 chiffres ou un numéro international avec +.',
            'secondary_phone.regex' => 'Saisissez un numéro marocain à 10 chiffres ou un numéro international avec +.',
            'identity_number.regex' => 'Utilisez uniquement des lettres et des chiffres.',
            'date_format' => 'Saisissez une date valide.', 'prohibited' => 'Ce champ ne peut pas être fourni.',
        ];
    }
}
