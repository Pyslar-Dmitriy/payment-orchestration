<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SignalPaymentWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'signal_name' => ['required', 'string', Rule::in([
                'provider.authorization_result',
                'provider.capture_result',
            ])],
            'provider_event_id' => ['required', 'string', 'max:255'],
            'provider_status' => ['required', 'string', 'max:100'],
            'provider_reference' => ['nullable', 'string', 'max:255'],
            'correlation_id' => ['required', 'uuid'],
        ];
    }
}
