<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveCashEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['kind' => ['required', Rule::in(['opening', 'cash_in', 'cash_out', 'bank_deposit', 'bank_withdrawal'])], 'occurred_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Africa/Casablanca')->toDateString()], 'amount_centimes' => ['required', 'integer', 'between:0,99999999999'], 'reason' => ['required', 'string', 'min:3', 'max:500'], 'expected_version' => ['nullable', 'integer', 'min:1', Rule::requiredIf((bool) $this->route('entry'))], 'confirmed' => ['required', 'accepted'], 'request_key' => ['required', 'uuid'], 'company_id' => ['prohibited']];
    }
}
