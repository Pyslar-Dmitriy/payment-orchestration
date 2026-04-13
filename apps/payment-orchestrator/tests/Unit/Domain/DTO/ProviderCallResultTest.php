<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\DTO;

use App\Domain\DTO\ProviderCallResult;
use PHPUnit\Framework\TestCase;

class ProviderCallResultTest extends TestCase
{
    public function test_async_result_exposes_correct_fields(): void
    {
        $result = new ProviderCallResult(
            providerReference: 'txn-abc-123',
            providerStatus: 'pending',
            isAsync: true,
        );

        $this->assertSame('txn-abc-123', $result->providerReference);
        $this->assertSame('pending', $result->providerStatus);
        $this->assertTrue($result->isAsync);
    }

    public function test_sync_result_exposes_correct_fields(): void
    {
        $result = new ProviderCallResult(
            providerReference: 'txn-xyz-789',
            providerStatus: 'captured',
            isAsync: false,
        );

        $this->assertSame('txn-xyz-789', $result->providerReference);
        $this->assertSame('captured', $result->providerStatus);
        $this->assertFalse($result->isAsync);
    }
}
