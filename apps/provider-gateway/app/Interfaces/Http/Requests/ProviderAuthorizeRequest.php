<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ProviderAuthorizeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payment_uuid' => ['required', 'uuid'],
            'provider_key' => ['required', 'string', 'max:64'],
            'correlation_id' => ['required', 'uuid'],
            // Optional enrichment fields; passed when the orchestrator includes full payment context.
            'amount' => ['sometimes', 'integer', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'country' => ['sometimes', 'string', 'size:2'],
        ];
    }
}
