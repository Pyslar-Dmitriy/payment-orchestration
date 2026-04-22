<?php

declare(strict_types=1);

namespace App\Application\DeliverCallback;

final readonly class DeliverCallbackCommand
{
    public function __construct(
        public string $messageId,
        public string $correlationId,
        public string $callbackId,
        public string $merchantId,
        public string $paymentId,
        public string $endpointUrl,
        /** @var array<string, mixed> */
        public array $callbackPayload,
        public string $signature,
        public int $attemptNumber,
        public int $maxAttempts,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws \InvalidArgumentException if any required field is absent
     */
    public static function fromArray(array $data): self
    {
        $required = [
            'message_id', 'correlation_id', 'callback_id', 'merchant_id',
            'payment_id', 'endpoint_url', 'callback_payload', 'signature',
            'attempt_number', 'max_attempts',
        ];

        foreach ($required as $field) {
            if (! array_key_exists($field, $data)) {
                throw new \InvalidArgumentException("Missing required field: {$field}");
            }
        }

        return new self(
            messageId: (string) $data['message_id'],
            correlationId: (string) $data['correlation_id'],
            callbackId: (string) $data['callback_id'],
            merchantId: (string) $data['merchant_id'],
            paymentId: (string) $data['payment_id'],
            endpointUrl: (string) $data['endpoint_url'],
            callbackPayload: (array) $data['callback_payload'],
            signature: (string) $data['signature'],
            attemptNumber: (int) $data['attempt_number'],
            maxAttempts: (int) $data['max_attempts'],
        );
    }
}
