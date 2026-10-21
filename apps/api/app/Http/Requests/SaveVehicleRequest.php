<?php

namespace App\Http\Requests;

use App\Support\Vehicles\VehicleFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $data = [];
        foreach (VehicleFields::EDITABLE as $field) {
            $v = $this->input($field);
            $data[$field] = is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v;
        }
        if (is_string($data['plate'])) {
            $data['plate'] = mb_strtoupper($data['plate']);
        }
        if (is_string($data['vin'])) {
            $data['vin'] = VehicleFields::identifier($data['vin']);
        }
        $data['equipment'] ??= [];
        $this->merge($data);
    }

    public function rules(): array
    {
        $initial = $this->isMethod('POST');

        return [
            'vehicle_type' => ['nullable', Rule::in(VehicleFields::TYPES)],
            'plate' => ['required', 'string', 'max:30', 'regex:/^[\pL\pN][\pL\pN\s\-]*$/u'],
            'make' => ['required', 'string', 'max:60'], 'model' => ['required', 'string', 'max:80'],
            'trim' => ['nullable', 'string', 'max:100'], 'year' => ['nullable', 'integer', 'between:1950,'.(now()->year + 1)],
            'fuel' => ['nullable', Rule::in(VehicleFields::FUELS)], 'transmission' => ['nullable', Rule::in(VehicleFields::TRANSMISSIONS)],
            'color' => ['nullable', 'string', 'max:40'], 'seats' => ['nullable', 'integer', 'between:1,99'], 'doors' => ['nullable', 'integer', 'between:2,5'],
            'vin' => ['nullable', 'string', 'regex:/^[A-HJ-NPR-Z0-9]{17}$/'], 'equipment' => ['array', 'max:'.count(VehicleFields::EQUIPMENT)],
            'equipment.*' => ['string', 'distinct', Rule::in(VehicleFields::EQUIPMENT)],
            'initial_odometer' => [$initial ? 'nullable' : 'prohibited', 'integer', 'between:0,9999999', 'required_with:initial_odometer_date'],
            'initial_daily_centimes' => [$initial ? 'nullable' : 'prohibited', 'integer', 'min:1', 'max:999999999'],
            'initial_odometer_date' => [$initial ? 'nullable' : 'prohibited', 'date_format:Y-m-d', 'before_or_equal:today', 'required_with:initial_odometer'],
            'creation_key' => [$initial ? 'required' : 'prohibited', 'uuid'], 'version' => [$initial ? 'prohibited' : 'required', 'integer', 'min:1'],
            'company_id' => ['prohibited'], 'actor_membership_id' => ['prohibited'], 'id' => ['prohibited'],
            'current_odometer' => ['prohibited'], 'current_odometer_date' => ['prohibited'], 'plate_key' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return ['required' => 'Ce champ est obligatoire.', 'required_with' => 'Renseignez le kilométrage et la date ensemble.', 'in' => 'Choisissez une valeur proposée.',
            'integer' => 'Saisissez un nombre entier.', 'between' => 'La valeur doit être comprise entre :min et :max.', 'max' => 'Valeur trop longue.',
            'vin.regex' => 'Le numéro de châssis doit contenir 17 lettres et chiffres, sans I, O ni Q.', 'plate.regex' => 'Utilisez des lettres, chiffres, espaces ou tirets.',
            'date_format' => 'Saisissez une date valide.', 'before_or_equal' => 'La date ne peut pas être dans le futur.', 'prohibited' => 'Ce champ ne peut pas être modifié ici.'];
    }
}
