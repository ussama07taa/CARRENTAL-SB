<?php

namespace App\Http\Requests;

use App\Models\CompanyMembership;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVehicleOdometerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->input('kind') !== 'correction' || CompanyMembership::where('user_id', $this->user()->id)->where('is_primary_responsible', true)->exists();
    }

    public function rules(): array
    {
        return ['kilometers' => ['required', 'integer', 'between:0,9999999'], 'recorded_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'kind' => ['required', Rule::in(['reading', 'correction'])], 'reason' => ['nullable', 'required_if:kind,correction', 'string', 'max:500'],
            'version' => ['required', 'integer', 'min:1'], 'request_key' => ['required', 'uuid'], 'company_id' => ['prohibited'], 'actor_membership_id' => ['prohibited']];
    }

    public function messages(): array
    {
        return ['required' => 'Ce champ est obligatoire.', 'integer' => 'Saisissez un nombre entier.', 'between' => 'Kilométrage attendu entre 0 et 9999999.', 'required_if' => 'Expliquez la correction.', 'before_or_equal' => 'La date ne peut pas être dans le futur.', 'date_format' => 'Saisissez une date valide.'];
    }
}
