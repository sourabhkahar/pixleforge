<?php

namespace App\Enums;

/**
 * Mirrors Pixelforge's lifecycle: queued → processing → succeeded | failed.
 * A generation that hasn't been accepted by Pixelforge yet is also "queued"
 * (provider_id is null until it is).
 */
enum GenerationStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }

    /**
     * Position in the lifecycle. Updates may only move forward, which is how
     * out-of-order webhooks are made harmless.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Queued => 0,
            self::Processing => 1,
            self::Succeeded, self::Failed => 2,
        };
    }
}
