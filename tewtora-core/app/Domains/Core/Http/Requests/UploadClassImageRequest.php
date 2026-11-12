<?php

namespace App\Domains\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST /internal/classes/{id}/image (multipart). Same rules as the image on create. */
class UploadClassImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'image' => ['required', ...StoreTewtoraClassRequest::imageRules()],
        ];
    }
}
