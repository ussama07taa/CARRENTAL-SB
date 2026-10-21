<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveMaintenanceFollowupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['plan', 'start', 'complete', 'defer', 'return'])],
            'revision' => ['required', 'integer', 'min:0'], 'tracking_revision' => ['required', 'integer', 'min:0'],
            'occurred_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Africa/Casablanca')->toDateString()],
            'follow_up_on' => ['nullable', 'date_format:Y-m-d'],
            'kilometers' => ['nullable', 'integer', 'between:0,9999999'],
            'next_on' => ['nullable', 'date_format:Y-m-d'], 'next_km' => ['nullable', 'integer', 'between:1,9999999'],
            'ready' => ['exclude_unless:action,return', 'required', 'accepted'],
            'note' => ['required', 'string', 'max:500'], 'request_key' => ['required', 'uuid'],
            'amount_centimes' => ['prohibited'], 'paid_centimes' => ['prohibited'], 'cost_status' => ['prohibited'],
        ];
    }
}
