<?php

namespace App\Http\Requests;

use App\Support\Finance\Expenses;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveExpenseMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        app(Expenses::class)->actor($this->user()->id);

        return true;
    }

    public function rules(): array
    {
        return ['kind' => ['required', Rule::in(['payment', 'refund'])], 'movement_key' => ['nullable', 'uuid'], 'amount_centimes' => ['required', 'integer', 'between:0,1000000000'], 'method' => ['required', Rule::in(Expenses::METHODS)], 'occurred_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Africa/Casablanca')->toDateString()], 'reference' => ['nullable', 'string', 'max:150'], 'reason' => ['required', 'string', 'max:500'], 'confirmed' => ['required', 'accepted'], 'expected_revision' => ['required', 'string', 'max:64'], 'request_key' => ['required', 'uuid'], 'company_id' => ['prohibited']];
    }
}
