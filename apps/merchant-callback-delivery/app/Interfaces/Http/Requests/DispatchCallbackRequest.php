<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class DispatchCallbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'payment_id' => ['required', 'uuid'],
            'merchant_id' => ['required', 'uuid'],
            'event_type' => ['required', 'string', 'in:payment.captured,payment.failed,refund.completed,refund.failed'],
            'amount_value' => ['required', 'integer', 'min:0'],
            'amount_currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'status' => ['required', 'string', 'max:64'],
            'occurred_at' => ['required', 'date'],
            'correlation_id' => ['required', 'uuid'],
            'refund_id' => ['nullable', 'uuid'],
            'idempotency_key' => ['nullable', 'string', 'max:255'],
        ];
    }
}
