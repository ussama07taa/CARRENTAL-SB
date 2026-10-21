<?php

namespace App\Http\Requests;

use App\Models\CompanyMembership;
use App\Models\VehiclePhoto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVehiclePhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return CompanyMembership::where('user_id', $this->user()->id)->exists();
    }

    public function rules(): array
    {
        return [
            'image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=6000,max_height=6000'],
            'slot' => ['required', Rule::in(VehiclePhoto::SLOTS)],
            'upload_key' => ['required', 'uuid'],
            'previous_id' => ['present', 'nullable', 'integer', 'min:1'],
            'company_id' => ['prohibited'], 'actor_membership_id' => ['prohibited'], 'path' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'image.required' => 'Choisissez une image.',
            'image.image' => 'Le fichier doit être une image JPG, PNG ou WebP.',
            'image.mimes' => 'Formats acceptés : JPG, PNG et WebP.',
            'image.max' => 'L’image doit faire au maximum 5 Mo.',
            'image.uploaded' => 'L’image n’a pas pu être reçue. Vérifiez sa taille (5 Mo maximum).',
            'image.dimensions' => 'L’image ne doit pas dépasser 6000 pixels de largeur ou de hauteur.',
        ];
    }
}
