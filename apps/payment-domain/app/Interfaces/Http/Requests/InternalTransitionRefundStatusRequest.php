<?php

namespace App\Interfaces\Http\Requests;

use App\Domain\Refund\RefundStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class InternalTransitionRefundStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in([
                RefundStatus::PENDING_PROVIDER->value,
                RefundStatus::SUCCEEDED->value,
                RefundStatus::FAILED->value,
                RefundStatus::REQUIRES_RECONCILIATION->value,
            ])],
            'correlation_id' => ['required', 'uuid'],
            'failed_step' => ['nullable', 'string', 'max:100'],
            'failure_reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
