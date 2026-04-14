<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StartRefundWorkflowRequest extends FormRequest
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
            'merchant_id' => ['required', 'uuid'],
            'amount' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string', 'size:3'],
            'provider_key' => ['required', 'string', 'max:64'],
            'correlation_id' => ['required', 'uuid'],
        ];
    }
}
