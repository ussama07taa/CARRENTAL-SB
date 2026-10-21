<?php

namespace App\Http\Requests;

class CreateRentalDriverRequest extends SaveCustomerRequest
{
    public function rules(): array
    {
        return [...parent::rules(),
            'identity_type' => ['required', 'in:cin,passport'],
            'identity_number' => ['required', 'string', 'max:50', 'regex:/^[\pL\pN]+$/u'],
            'licence_number' => ['required', 'string', 'max:50'],
            'licence_expires_on' => ['required', 'date_format:Y-m-d'],
            'expected_revision' => ['present', 'nullable', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
