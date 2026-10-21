<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Validator;

trait ValidatesPrivateDocumentUpload
{
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $file = $this->file('file');
            if (! $file || $validator->errors()->has('file')) {
                return;
            }
            $mime = mime_content_type($file->getRealPath());
            if (! in_array($mime, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], true)) {
                $validator->errors()->add('file', 'Le contenu du fichier ne correspond pas à un PDF ou à une image acceptée.');

                return;
            }
            if ($mime !== 'application/pdf') {
                $dimensions = @getimagesize($file->getRealPath());
                if (! $dimensions || $dimensions[0] > 6000 || $dimensions[1] > 6000) {
                    $validator->errors()->add('file', 'Image invalide ou supérieure à 6000 pixels.');
                }
            }
        });
    }
}
