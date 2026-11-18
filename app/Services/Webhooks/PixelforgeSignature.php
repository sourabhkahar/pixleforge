<?php

namespace App\Services\Webhooks;

/**
 * Verifies "Pixelforge-Signature: t=<unix>,v1=<hex>" headers:
 * HMAC-SHA256 over "<t>.<raw body>" with the key's signing secret,
 * compared in constant time, rejecting stale timestamps.
 */
class PixelforgeSignature
{
    public function __construct(
        private readonly ?string $secret,
        private readonly int $toleranceSeconds = 300,
    ) {}

    public function verify(string $rawBody, ?string $header, ?int $now = null): bool
    {
        if (empty($this->secret) || empty($header)) {
            return false;
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);
            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1' && $value !== null) {
                $signatures[] = $value; // tolerate several v1 entries (secret rotation)
            }
        }

        if ($timestamp === null || ! ctype_digit($timestamp) || $signatures === []) {
            return false;
        }

        if (abs(($now ?? time()) - (int) $timestamp) > $this->toleranceSeconds) {
            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$rawBody}", $this->secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    /** Build a header value — used by tests and local tooling. */
    public function sign(string $rawBody, int $timestamp): string
    {
        return "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$rawBody}", (string) $this->secret);
    }
}
