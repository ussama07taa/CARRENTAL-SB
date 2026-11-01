<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesPrivateDocumentUpload;
use Illuminate\Foundation\Http\FormRequest;

class SaveRentalPaymentProofRequest extends FormRequest
{
    use ValidatesPrivateDocumentUpload;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'], 'request_key' => ['required', 'uuid'], 'company_id' => ['prohibited']];
    }

    public function messages(): array
    {
        return ['file.required' => 'Choisissez un justificatif.', 'file.mimes' => 'Choisissez un PDF ou une image JPG, PNG ou WebP.', 'file.max' => 'Le fichier dépasse 5 Mo.'];
    }
}
