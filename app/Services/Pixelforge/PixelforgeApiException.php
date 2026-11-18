<?php

namespace App\Services\Pixelforge;

use RuntimeException;

/**
 * A Pixelforge API error, classified so callers can decide to retry or give up.
 */
class PixelforgeApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $errorCode = null,   // Pixelforge "code", e.g. rate_limit_exceeded
        public readonly ?int $httpStatus = null,     // null = network error / timeout
        public readonly ?int $retryAfter = null,     // seconds, from Retry-After
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Temporary problems where trying again later is the right move. Nothing was
     * created on Pixelforge's side for these, and we always resend with the
     * same Idempotency-Key, so a retry can never create a second generation.
     */
    public function isRetryable(): bool
    {
        if ($this->httpStatus === null) {
            return true; // connection error / timeout — outcome unknown, idempotent retry is safe
        }

        return in_array($this->errorCode, ['rate_limit_exceeded', 'concurrency_limit_exceeded', 'overloaded'], true)
            || $this->httpStatus >= 500;
    }

    /**
     * Seconds to wait before retrying: honour Retry-After, else exponential backoff.
     */
    public function retryDelay(int $attempt): int
    {
        if ($this->retryAfter !== null && $this->retryAfter > 0) {
            return min($this->retryAfter + random_int(0, 2), 300);
        }

        return min(2 ** max(1, $attempt) + random_int(0, 3), 120);
    }

    /**
     * A message that is safe and useful to show the end user.
     */
    public function userMessage(): string
    {
        return match ($this->errorCode) {
            'content_policy_violation' => 'Pixelforge rejected this prompt under its content policy. You were not charged.',
            'validation_failed' => 'Pixelforge rejected the request: '.$this->getMessage(),
            'daily_limit_exceeded' => 'Pixelforge\'s daily generation limit has been reached. Please try again tomorrow (UTC).',
            'invalid_api_key' => 'The image service is misconfigured. Please contact support.',
            'rate_limit_exceeded', 'concurrency_limit_exceeded' => 'Pixelforge is handling too many requests — waiting for a free slot.',
            'overloaded' => 'Pixelforge is temporarily overloaded — retrying shortly.',
            default => $this->httpStatus === null
                ? 'Could not reach Pixelforge — retrying.'
                : 'Pixelforge returned an error: '.$this->getMessage(),
        };
    }
}
