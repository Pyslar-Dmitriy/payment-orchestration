<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Domain\Callback\FailureReason;

final class FakeHttpCallbackSender implements HttpCallbackSenderInterface
{
    private HttpAttemptResult $nextResult;

    public function __construct()
    {
        $this->nextResult = new HttpAttemptResult(
            success: true,
            statusCode: 200,
            responseBody: 'OK',
            responseHeaders: [],
            durationMs: 5,
            isPermanentFailure: false,
            failureReason: null,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function send(
        string $endpointUrl,
        array $payload,
        string $signature,
        string $callbackId,
        string $correlationId,
    ): HttpAttemptResult {
        return $this->nextResult;
    }

    public function willSucceed(): void
    {
        $this->nextResult = new HttpAttemptResult(
            success: true,
            statusCode: 200,
            responseBody: 'OK',
            responseHeaders: [],
            durationMs: 5,
            isPermanentFailure: false,
            failureReason: null,
        );
    }

    public function willFailTemporarily(int $statusCode = 503): void
    {
        $this->nextResult = new HttpAttemptResult(
            success: false,
            statusCode: $statusCode,
            responseBody: 'Service Unavailable',
            responseHeaders: [],
            durationMs: 5,
            isPermanentFailure: false,
            failureReason: FailureReason::Non2xx,
        );
    }

    public function willFailPermanently(int $statusCode = 400): void
    {
        $this->nextResult = new HttpAttemptResult(
            success: false,
            statusCode: $statusCode,
            responseBody: 'Bad Request',
            responseHeaders: [],
            durationMs: 5,
            isPermanentFailure: true,
            failureReason: FailureReason::Non2xx,
        );
    }

    public function willTimeout(): void
    {
        $this->nextResult = new HttpAttemptResult(
            success: false,
            statusCode: null,
            responseBody: null,
            responseHeaders: null,
            durationMs: 10000,
            isPermanentFailure: false,
            failureReason: FailureReason::Timeout,
        );
    }
}
