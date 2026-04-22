<?php

declare(strict_types=1);

namespace App\Application\DispatchCallback;

final class DispatchedDelivery
{
    public function __construct(
        public readonly string $deliveryId,
        public readonly string $endpointUrl,
    ) {}
}
