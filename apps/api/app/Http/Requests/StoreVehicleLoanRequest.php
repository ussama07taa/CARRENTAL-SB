<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesPrivateDocumentUpload;
use Illuminate\Foundation\Http\FormRequest;

class StoreVehicleLoanRequest extends FormRequest
{
    use ValidatesPrivateDocumentUpload;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('purchase'))) {
            $this->merge(['purchase' => json_decode($this->input('purchase'), true)]);
        }
        if (is_string($this->input('installments'))) {
            $this->merge(['installments' => json_decode($this->input('installments'), true)]);
        }
    }

    public function rules(): array
    {
        return [
            'purchase' => ['nullable', 'array:seller,purchased_on,down_payment_due_on,price_centimes,down_payment_centimes'],
            'purchase.seller' => ['required_with:purchase', 'string', 'max:150'],
            'purchase.purchased_on' => ['required_with:purchase', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:'.now('Africa/Casablanca')->toDateString()],
            'purchase.down_payment_due_on' => ['required_with:purchase', 'date_format:Y-m-d', 'after_or_equal:purchase.purchased_on', 'before_or_equal:2199-12-31'],
            'purchase.price_centimes' => ['required_with:purchase', 'integer', 'between:1,1000000000'],
            'purchase.down_payment_centimes' => ['required_with:purchase', 'integer', 'min:0', 'lt:purchase.price_centimes'],
            'vehicle_id' => ['required', 'integer', 'min:1'], 'lender' => ['required', 'string', 'max:150'], 'reference' => ['required', 'string', 'max:100'], 'signed_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:'.now('Africa/Casablanca')->toDateString()], 'tracking_from' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:'.now('Africa/Casablanca')->toDateString()], 'financed_centimes' => ['nullable', 'integer', 'between:1,1000000000'], 'notes' => ['nullable', 'string', 'max:1000'], 'installments' => ['required', 'array', 'list', 'min:1', 'max:360'], 'installments.*' => ['required', 'array:due_on,amount_centimes,opening_paid_centimes'], 'installments.*.due_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:2199-12-31'], 'installments.*.amount_centimes' => ['required', 'integer', 'between:1,1000000000'], 'installments.*.opening_paid_centimes' => ['required', 'integer', 'min:0', 'max:1000000000'], 'schedule_confirmed' => ['required', 'accepted'], 'opening_confirmed' => ['nullable', 'boolean'], 'file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'], 'request_key' => ['required', 'uuid'], 'company_id' => ['prohibited'], 'actor_membership_id' => ['prohibited']];
    }

    public function messages(): array
    {
        return ['required' => 'Ce champ est obligatoire.', 'integer' => 'Saisissez un nombre entier.', 'schedule_confirmed.accepted' => 'Vérifiez le tableau puis confirmez les montants.', 'file.max' => 'Le contrat doit faire au maximum 5 Mo.', 'tracking_from.before_or_equal' => 'Le début du suivi ne peut pas être dans le futur.'];
    }
}
