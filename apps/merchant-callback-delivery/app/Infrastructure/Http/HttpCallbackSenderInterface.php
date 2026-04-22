<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

interface HttpCallbackSenderInterface
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function send(
        string $endpointUrl,
        array $payload,
        string $signature,
        string $callbackId,
        string $correlationId,
    ): HttpAttemptResult;
}
