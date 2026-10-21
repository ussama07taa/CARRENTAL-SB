<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
            'company_id' => ['prohibited'],
            'user_id' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'Saisissez votre adresse e-mail.',
            'email.email' => 'Saisissez une adresse e-mail valide.',
            'email.max' => 'Adresse e-mail trop longue.',
            'password.required' => 'Saisissez votre mot de passe.',
            'password.max' => 'Mot de passe trop long.',
            '*.prohibited' => 'Ce champ ne peut pas être choisi lors de la connexion.',
        ];
    }
}
