<?php

namespace App\Http\Requests;

use App\Support\Vehicles\MaintenanceAccess;
use App\Support\Vehicles\MaintenanceWork;
use Illuminate\Foundation\Http\FormRequest;

class SaveMaintenanceWorkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $rules = [...(new MaintenanceWork)->rules(), 'legacy_edit' => ['sometimes', 'boolean'], 'item_key' => ['present', 'nullable', 'uuid'], 'revision' => ['required', 'integer', 'min:0'], 'request_key' => ['required', 'uuid'], 'company_id' => ['prohibited']];
        if (! app(MaintenanceAccess::class)->financial($this->user()->id)) {
            foreach (['cost_status', 'amount_centimes'] as $field) {
                $rules[$field] = ['prohibited'];
            }
        }

        return $rules;
    }
}
