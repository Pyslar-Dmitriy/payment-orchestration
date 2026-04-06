<?php

namespace App\Interfaces\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RotateApiKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'grace_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],
        ];
    }
}
