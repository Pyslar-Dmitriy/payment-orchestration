<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class PostCaptureEntriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'payment_id' => ['required', 'string', 'max:26'],
            'merchant_id' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string', 'size:3'],
            'fee_amount' => ['sometimes', 'integer', 'min:0'],
            'correlation_id' => ['required', 'uuid'],
            'causation_id' => ['sometimes', 'nullable', 'uuid'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->has('fee_amount') && (int) $this->input('fee_amount', 0) >= (int) $this->input('amount', 0)) {
                $validator->errors()->add('fee_amount', 'The fee amount must be less than the total amount.');
            }
        });
    }
}
