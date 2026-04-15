<?php

namespace App\Interfaces\Http\Requests;

use App\Domain\Payment\PaymentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class InternalTransitionPaymentStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in([
                PaymentStatus::PENDING_PROVIDER->value,
                PaymentStatus::AUTHORIZED->value,
                PaymentStatus::CAPTURED->value,
                PaymentStatus::FAILED->value,
                PaymentStatus::REQUIRES_RECONCILIATION->value,
            ])],
            'correlation_id' => ['required', 'uuid'],
            'failed_step' => ['nullable', 'string', 'max:100'],
            'failure_code' => ['nullable', 'string', 'max:100'],
            'failure_reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
