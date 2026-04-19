<?php

declare(strict_types=1);

namespace App\Infrastructure\Outbox\Publisher\FakeBroker;

use App\Infrastructure\Outbox\Publisher\BrokerPublisherInterface;
use PHPUnit\Framework\Assert;

/**
 * In-memory publisher for use in tests.
 * Bound in AppServiceProvider when APP_ENV=testing so no live broker is required.
 */
final class FakeBrokerPublisher implements BrokerPublisherInterface
{
    /** @var array<int, array{destination: string, messageId: string, body: array<string, mixed>, headers: array<string, string>}> */
    private array $published = [];

    public function publish(string $destination, string $messageId, string $body, array $headers = []): void
    {
        $this->published[] = [
            'destination' => $destination,
            'messageId' => $messageId,
            'body' => json_decode($body, true) ?? [],
            'headers' => $headers,
        ];
    }

    public function assertPublished(string $destination, string $eventType): void
    {
        $matches = array_filter(
            $this->published,
            fn (array $msg) => $msg['destination'] === $destination
                && ($msg['body']['event_type'] ?? '') === $eventType,
        );

        Assert::assertNotEmpty(
            $matches,
            "Expected a message with event_type '{$eventType}' published to '{$destination}', but none was found."
        );
    }

    public function assertPublishedCount(int $count): void
    {
        Assert::assertCount(
            $count,
            $this->published,
            "Expected {$count} published message(s), got ".count($this->published).'.',
        );
    }

    public function assertNothingPublished(): void
    {
        Assert::assertEmpty(
            $this->published,
            'Expected no messages to be published, but '.count($this->published).' were found.',
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function getPublished(): array
    {
        return $this->published;
    }

    public function reset(): void
    {
        $this->published = [];
    }
}
