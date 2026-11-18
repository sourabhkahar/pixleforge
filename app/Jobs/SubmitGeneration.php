<?php

namespace App\Jobs;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\Generations\GenerationSync;
use App\Services\Pixelforge\PixelforgeApiException;
use App\Services\Pixelforge\PixelforgeClient;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Sends a (paid-for) generation to Pixelforge.
 *
 * - Always uses the generation's UUID as Idempotency-Key, so retries after a
 *   timeout / 503 can never create a second generation.
 * - Rate, concurrency and overload errors are retried after Retry-After
 *   (via release()), until submit_give_up_after minutes have passed.
 * - Definite rejections (content policy, validation, daily limit…) fail the
 *   generation immediately, which releases the user's reserved credits.
 */
class SubmitGeneration implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;          // bounded by retryUntil() instead

    public int $maxExceptions = 3;  // unexpected exceptions (bugs, DB down)

    public function __construct(public readonly int $generationId) {}

    public function retryUntil(): DateTimeInterface
    {
        $generation = Generation::find($this->generationId);

        return ($generation?->created_at ?? now())->copy()->addMinutes(config('pixelforge.submit_give_up_after'));
    }

    public function handle(PixelforgeClient $pixelforge, GenerationSync $sync): void
    {
        $generation = Generation::find($this->generationId);
        if ($generation === null || $generation->status !== GenerationStatus::Queued || $generation->provider_id !== null) {
            return; // already accepted (maybe via an early webhook) or finished
        }

        $generation->increment('submit_attempts');

        try {
            $remote = $pixelforge->createGeneration($this->payload($generation), $generation->uuid);
        } catch (PixelforgeApiException $e) {
            if ($e->isRetryable() && now()->lt($this->retryUntil())) {
                $generation->forceFill(['status_detail' => $e->userMessage()])->save();
                $this->release($e->retryDelay($generation->submit_attempts));

                return;
            }

            $sync->failLocally(
                $generation,
                $e->errorCode ?? 'submission_failed',
                $e->isRetryable() ? 'Pixelforge stayed busy for too long, so we gave up. You were not charged.' : $e->userMessage(),
            );

            return;
        }

        $generation->forceFill(['submitted_at' => now(), 'status_detail' => null])->save();
        $sync->apply($generation, $remote);

        // Webhooks normally finish the job; polling is the safety net.
        if (! $generation->fresh()->status->isTerminal()) {
            PollGeneration::dispatch($generation->id)->delay(now()->addSeconds(config('pixelforge.poll_interval')));
        }
    }

    public function failed(?Throwable $exception): void
    {
        $generation = Generation::find($this->generationId);
        if ($generation !== null && $generation->provider_id === null) {
            app(GenerationSync::class)->failLocally(
                $generation,
                'submission_failed',
                'We could not submit this generation to Pixelforge. You were not charged.',
            );
        }
    }

    /**
     * Only parameters Pixelforge knows: unknown ones are rejected with 422.
     */
    private function payload(Generation $generation): array
    {
        $payload = [
            'prompt' => $generation->prompt,
            'size' => $generation->size,
            'quality' => $generation->quality,
            'metadata' => [
                'generation_uuid' => $generation->uuid,
                'user_id' => (string) $generation->user_id,
                'app_env' => (string) config('app.env'),
            ],
        ];

        if (! empty($generation->reference_images)) {
            $payload['reference_images'] = array_values($generation->reference_images);
        }

        return $payload;
    }
}
