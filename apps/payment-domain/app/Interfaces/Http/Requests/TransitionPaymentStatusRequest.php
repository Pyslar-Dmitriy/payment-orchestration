<?php

namespace App\Interfaces\Http\Requests;

use App\Domain\Payment\PaymentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class TransitionPaymentStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'merchant_id' => ['required', 'uuid'],
            'status' => ['required', 'string', Rule::in([
                PaymentStatus::PENDING_PROVIDER->value,
                PaymentStatus::AUTHORIZED->value,
                PaymentStatus::CAPTURED->value,
                PaymentStatus::FAILED->value,
                PaymentStatus::REFUNDING->value,
                PaymentStatus::REFUNDED->value,
            ])],
            'correlation_id' => ['required', 'uuid'],
            'reason' => ['nullable', 'string', 'max:500'],
            'failure_code' => ['nullable', 'string', 'max:100'],
            'failure_reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
