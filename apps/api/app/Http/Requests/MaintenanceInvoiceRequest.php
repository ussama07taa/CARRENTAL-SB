<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesPrivateDocumentUpload;
use Illuminate\Foundation\Http\FormRequest;

class MaintenanceInvoiceRequest extends FormRequest
{
    use ValidatesPrivateDocumentUpload;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'], 'request_key' => ['required', 'uuid']];
    }
}
