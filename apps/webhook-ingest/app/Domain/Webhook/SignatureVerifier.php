<?php

namespace App\Domain\Webhook;

class SignatureVerifier
{
    public function verify(string $payload, string $signature, string $secret): bool
    {
        return hash_equals(hash_hmac('sha256', $payload, $secret), $signature);
    }
}
