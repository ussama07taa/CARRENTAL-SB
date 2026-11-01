<?php

namespace App\Http\Requests;

use App\Support\Finance\Expenses;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        app(Expenses::class)->actor($this->user()->id);

        return true;
    }

    public function rules(): array
    {
        return ['title' => ['required', 'string', 'max:150'], 'category' => ['required', Rule::in(array_keys(Expenses::CATEGORIES))], 'supplier' => ['required', 'string', 'max:150'], 'invoice_reference' => ['nullable', 'string', 'max:100'], 'incurred_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Africa/Casablanca')->toDateString()], 'due_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:incurred_on'], 'amount_centimes' => ['required', 'integer', 'between:1,1000000000'], 'notes' => ['nullable', 'string', 'max:2000'], 'allocations' => ['required', 'array', 'min:1', 'max:30'], 'allocations.*.vehicle_id' => ['nullable', 'integer', 'min:1'], 'allocations.*.amount_centimes' => ['required', 'integer', 'between:1,1000000000'], 'paid_centimes' => ['required', 'integer', 'between:0,1000000000'], 'paid_on' => ['nullable', Rule::requiredIf((int) $this->input('paid_centimes') > 0), 'date_format:Y-m-d', 'after_or_equal:incurred_on', 'before_or_equal:'.now('Africa/Casablanca')->toDateString()], 'method' => ['nullable', Rule::requiredIf((int) $this->input('paid_centimes') > 0), Rule::in(Expenses::METHODS)], 'payment_reference' => ['nullable', 'string', 'max:150'], 'reason' => [$this->route('expense') ? 'required' : 'nullable', 'string', 'max:500'], 'expected_revision' => [$this->route('expense') ? 'required' : 'nullable', 'string', 'max:64'], 'duplicate_confirmed' => ['sometimes', 'boolean'], 'request_key' => ['required', 'uuid'], 'company_id' => ['prohibited'], 'maintenance_id' => ['prohibited'], 'source' => ['prohibited']];
    }

    public function messages(): array
    {
        return ['required' => 'Ce champ est obligatoire.', 'integer' => 'Saisissez un montant valide.', 'before_or_equal' => 'Cette date ne peut pas être dans le futur.', 'after_or_equal' => 'La date doit suivre celle de la dépense.', 'between' => 'Vérifiez le montant saisi.'];
    }
}
