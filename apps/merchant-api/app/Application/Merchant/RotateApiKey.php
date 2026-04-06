<?php

namespace App\Application\Merchant;

use App\Domain\Merchant\ApiKey;
use Illuminate\Support\Carbon;

final class RotateApiKey
{
    public function __construct(private readonly IssueApiKey $issue) {}

    /**
     * Issue a new key for the merchant and expire the current key.
     *
     * A grace period may be provided (in minutes) so the old key remains valid
     * briefly while merchants update their configuration. The grace period is
     * read from KEY_ROTATION_GRACE_MINUTES when not explicitly passed.
     *
     * @return array{id: string, key: string, key_prefix: string, created_at: string}
     */
    public function execute(ApiKey $currentKey, ?int $gracePeriodMinutes = null): array
    {
        $result = $this->issue->execute($currentKey->merchant);

        $grace = $gracePeriodMinutes ?? (int) config('auth.key_rotation_grace_minutes', 0);
        $expiry = $grace > 0 ? Carbon::now()->addMinutes($grace) : Carbon::now();

        $currentKey->updateQuietly(['expires_at' => $expiry]);

        return $result;
    }
}
