<?php

declare(strict_types=1);

namespace App\Domain\Provider\DTO;

final class AuthorizeRequest
{
    /**
     * @param  int  $amount  Amount in smallest currency unit (e.g. cents). 0 when not provided by caller.
     * @param  string  $currency  ISO 4217 three-letter code, empty when not provided by caller.
     * @param  string  $country  ISO 3166-1 alpha-2 code, empty when not provided by caller.
     */
    public function __construct(
        public readonly string $paymentUuid,
        public readonly string $correlationId,
        public readonly int $amount = 0,
        public readonly string $currency = '',
        public readonly string $country = '',
    ) {}
}
