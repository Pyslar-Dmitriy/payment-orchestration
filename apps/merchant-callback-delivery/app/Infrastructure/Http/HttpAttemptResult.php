<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Domain\Callback\FailureReason;

final readonly class HttpAttemptResult
{
    public function __construct(
        public bool $success,
        public ?int $statusCode,
        public ?string $responseBody,
        /** @var array<string, mixed>|null */
        public ?array $responseHeaders,
        public int $durationMs,
        public bool $isPermanentFailure,
        public ?FailureReason $failureReason,
    ) {}
}
