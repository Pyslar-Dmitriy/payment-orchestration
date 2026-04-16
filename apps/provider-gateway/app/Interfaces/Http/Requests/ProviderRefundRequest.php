<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ProviderRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'refund_uuid' => ['required', 'uuid'],
            'payment_uuid' => ['required', 'uuid'],
            'provider_key' => ['required', 'string', 'max:64'],
            'correlation_id' => ['required', 'uuid'],
            // Optional enrichment fields.
            'provider_reference' => ['sometimes', 'string', 'max:255'],
            'amount' => ['sometimes', 'integer', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
        ];
    }
}
