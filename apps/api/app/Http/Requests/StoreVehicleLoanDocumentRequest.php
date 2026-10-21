<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesPrivateDocumentUpload;
use Illuminate\Foundation\Http\FormRequest;

class StoreVehicleLoanDocumentRequest extends FormRequest
{
    use ValidatesPrivateDocumentUpload;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'], 'request_key' => ['required', 'uuid'], 'company_id' => ['prohibited'], 'actor_membership_id' => ['prohibited']];
    }

    public function messages(): array
    {
        return ['file.required' => 'Choisissez le contrat ou son échéancier.', 'file.max' => 'Le document doit faire au maximum 5 Mo.'];
    }
}
