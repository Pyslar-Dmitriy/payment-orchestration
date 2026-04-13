<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\DTO;

use App\Domain\DTO\ProviderStatusResult;
use PHPUnit\Framework\TestCase;

class ProviderStatusResultTest extends TestCase
{
    public function test_captured_result_exposes_correct_flags(): void
    {
        $result = new ProviderStatusResult(
            providerStatus: 'captured',
            isCaptured: true,
            isAuthorized: false,
            isFailed: false,
        );

        $this->assertSame('captured', $result->providerStatus);
        $this->assertTrue($result->isCaptured);
        $this->assertFalse($result->isAuthorized);
        $this->assertFalse($result->isFailed);
    }

    public function test_authorized_result_exposes_correct_flags(): void
    {
        $result = new ProviderStatusResult(
            providerStatus: 'authorized',
            isCaptured: false,
            isAuthorized: true,
            isFailed: false,
        );

        $this->assertSame('authorized', $result->providerStatus);
        $this->assertFalse($result->isCaptured);
        $this->assertTrue($result->isAuthorized);
        $this->assertFalse($result->isFailed);
    }

    public function test_failed_result_exposes_correct_flags(): void
    {
        $result = new ProviderStatusResult(
            providerStatus: 'failed',
            isCaptured: false,
            isAuthorized: false,
            isFailed: true,
        );

        $this->assertSame('failed', $result->providerStatus);
        $this->assertFalse($result->isCaptured);
        $this->assertFalse($result->isAuthorized);
        $this->assertTrue($result->isFailed);
    }

    public function test_unknown_result_has_all_flags_false(): void
    {
        $result = new ProviderStatusResult(
            providerStatus: 'pending',
            isCaptured: false,
            isAuthorized: false,
            isFailed: false,
        );

        $this->assertFalse($result->isCaptured);
        $this->assertFalse($result->isAuthorized);
        $this->assertFalse($result->isFailed);
    }
}
