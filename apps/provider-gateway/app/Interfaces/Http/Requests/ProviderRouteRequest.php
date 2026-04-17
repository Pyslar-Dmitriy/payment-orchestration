<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ProviderRouteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'currency' => ['required', 'string', 'size:3'],
            'country' => ['required', 'string', 'size:2'],
            'merchant_type' => ['sometimes', 'nullable', 'string', 'max:64'],
            'excluded_provider_keys' => ['sometimes', 'array'],
            'excluded_provider_keys.*' => ['string', 'max:64'],
        ];
    }
}
