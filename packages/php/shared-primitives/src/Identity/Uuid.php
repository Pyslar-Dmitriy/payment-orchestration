<?php

declare(strict_types=1);

namespace PaymentOrchestration\SharedPrimitives\Identity;

use InvalidArgumentException;

/**
 * UUID helper. Generates RFC 4122 v4 UUIDs using cryptographically random bytes.
 */
final class Uuid
{
    private const PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    private function __construct()
    {
    }

    public static function generate(): string
    {
        $bytes = random_bytes(16);

        // Set version 4 (bits 12-15 of time_hi_and_version to 0100)
        $bytes[6] = chr(ord($bytes[6]) & 0x0f | 0x40);

        // Set variant bits (bits 6-7 of clock_seq_hi_and_reserved to 10)
        $bytes[8] = chr(ord($bytes[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    public static function isValid(string $uuid): bool
    {
        return (bool) preg_match(self::PATTERN, $uuid);
    }

    public static function assertValid(string $uuid): void
    {
        if (!self::isValid($uuid)) {
            throw new InvalidArgumentException("Invalid UUID: '{$uuid}'");
        }
    }
}