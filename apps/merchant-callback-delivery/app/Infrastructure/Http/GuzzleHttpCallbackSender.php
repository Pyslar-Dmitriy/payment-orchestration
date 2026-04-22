<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Domain\Callback\FailureReason;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class GuzzleHttpCallbackSender implements HttpCallbackSenderInterface
{
    private const int TIMEOUT_SECONDS = 10;

    private const int MAX_RESPONSE_BODY_BYTES = 4096;

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
        $startMs = (int) (microtime(true) * 1000);

        try {
            $response = Http::withHeaders([
                'X-Callback-Signature' => 'sha256='.$signature,
                'X-Callback-ID' => $callbackId,
                'X-Correlation-ID' => $correlationId,
            ])
                ->timeout(self::TIMEOUT_SECONDS)
                ->post($endpointUrl, $payload);

            $durationMs = (int) (microtime(true) * 1000) - $startMs;
            $statusCode = $response->status();
            $responseBody = mb_substr($response->body(), 0, self::MAX_RESPONSE_BODY_BYTES);
            $responseHeaders = $response->headers();

            if ($response->successful()) {
                return new HttpAttemptResult(
                    success: true,
                    statusCode: $statusCode,
                    responseBody: $responseBody,
                    responseHeaders: $responseHeaders,
                    durationMs: $durationMs,
                    isPermanentFailure: false,
                    failureReason: null,
                );
            }

            // 429 and 5xx are temporary; everything else (3xx, 4xx except 429) is permanent
            $isTemporary = $statusCode === 429 || $statusCode >= 500;

            return new HttpAttemptResult(
                success: false,
                statusCode: $statusCode,
                responseBody: $responseBody,
                responseHeaders: $responseHeaders,
                durationMs: $durationMs,
                isPermanentFailure: ! $isTemporary,
                failureReason: FailureReason::Non2xx,
            );
        } catch (ConnectionException $e) {
            $durationMs = (int) (microtime(true) * 1000) - $startMs;
            $message = $e->getMessage();

            // Detect TLS errors from the exception message
            if (str_contains($message, 'SSL') || str_contains($message, 'TLS') || str_contains($message, 'certificate')) {
                return new HttpAttemptResult(
                    success: false,
                    statusCode: null,
                    responseBody: null,
                    responseHeaders: null,
                    durationMs: $durationMs,
                    isPermanentFailure: true,
                    failureReason: FailureReason::TlsError,
                );
            }

            // Timeout
            if (str_contains($message, 'timed out') || str_contains($message, 'timeout')) {
                return new HttpAttemptResult(
                    success: false,
                    statusCode: null,
                    responseBody: null,
                    responseHeaders: null,
                    durationMs: $durationMs,
                    isPermanentFailure: false,
                    failureReason: FailureReason::Timeout,
                );
            }

            return new HttpAttemptResult(
                success: false,
                statusCode: null,
                responseBody: null,
                responseHeaders: null,
                durationMs: $durationMs,
                isPermanentFailure: false,
                failureReason: FailureReason::ConnectionError,
            );
        } catch (\Throwable $e) {
            $durationMs = (int) (microtime(true) * 1000) - $startMs;

            return new HttpAttemptResult(
                success: false,
                statusCode: null,
                responseBody: null,
                responseHeaders: null,
                durationMs: $durationMs,
                isPermanentFailure: true,
                failureReason: FailureReason::InvalidResponse,
            );
        }
    }
}
