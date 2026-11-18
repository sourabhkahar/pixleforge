<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Services\Generations\GenerationSync;
use App\Services\Pixelforge\PixelforgeApiException;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Safety net for lost or delayed webhooks: fetches the generation from
 * Pixelforge and applies it, re-queuing itself until the generation is
 * finished. Uses the same GenerationSync as webhooks, so whichever arrives
 * first wins and the other is a harmless no-op.
 */
class PollGeneration implements ShouldQueue
{
    use Queueable;

    public int $tries = 1; // each poll is a fresh job

    public function __construct(public readonly int $generationId) {}

    public function handle(PixelforgeClient $pixelforge, GenerationSync $sync): void
    {
        $generation = Generation::find($this->generationId);
        if ($generation === null || $generation->status->isTerminal() || $generation->provider_id === null) {
            return;
        }

        $delay = (int) config('pixelforge.poll_interval');

        try {
            $sync->apply($generation, $pixelforge->getGeneration($generation->provider_id));
        } catch (PixelforgeApiException $e) {
            if ($e->httpStatus === 404) {
                $sync->failLocally($generation, 'not_found', 'Pixelforge has no record of this generation. You were not charged.');

                return;
            }
            if (! $e->isRetryable()) {
                throw $e;
            }
            $delay = max($delay, $e->retryDelay(1));
        }

        $generation->refresh();
        $deadline = $generation->created_at->copy()->addMinutes(config('pixelforge.poll_give_up_after'));

        // After the deadline, the scheduled pixelforge:reconcile command keeps checking at a slower pace.
        if (! $generation->status->isTerminal() && now()->lt($deadline)) {
            self::dispatch($generation->id)->delay(now()->addSeconds($delay));
        }
    }
}
