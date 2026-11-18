<?php

namespace App\Console\Commands;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\Generations\GenerationSync;
use App\Services\Pixelforge\PixelforgeApiException;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Console\Command;

/**
 * Scheduled safety net (every minute): catches generations whose webhooks
 * never arrived AND whose polling chain has ended or was lost.
 */
class ReconcileGenerations extends Command
{
    protected $signature = 'pixelforge:reconcile {--limit=20 : Max generations to check per run}';

    protected $description = 'Re-sync unfinished Pixelforge generations that have gone quiet';

    public function handle(PixelforgeClient $pixelforge, GenerationSync $sync): int
    {
        // Accepted by Pixelforge but not heard from for a while.
        $stale = Generation::query()
            ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            ->whereNotNull('provider_id')
            ->where(fn ($q) => $q->whereNull('last_synced_at')->orWhere('last_synced_at', '<', now()->subMinutes(2)))
            ->oldest('id')
            ->limit((int) $this->option('limit'))
            ->get();

        foreach ($stale as $generation) {
            try {
                $sync->apply($generation, $pixelforge->getGeneration($generation->provider_id));
            } catch (PixelforgeApiException $e) {
                if ($e->httpStatus === 404) {
                    $sync->failLocally($generation, 'not_found', 'Pixelforge has no record of this generation. You were not charged.');
                } elseif ($e->isRetryable()) {
                    break; // Pixelforge is busy; try again next minute
                } else {
                    $this->warn("#{$generation->id}: {$e->getMessage()}");
                }
            }
        }

        // Never accepted, and the submit job's retry window is long over (job lost).
        $abandoned = Generation::query()
            ->where('status', GenerationStatus::Queued)
            ->whereNull('provider_id')
            ->where('created_at', '<', now()->subMinutes(config('pixelforge.submit_give_up_after') + 5))
            ->get();

        foreach ($abandoned as $generation) {
            $sync->failLocally($generation, 'submission_failed', 'We could not submit this generation to Pixelforge. You were not charged.');
        }

        $this->info("Checked {$stale->count()} unfinished, failed {$abandoned->count()} abandoned.");

        return self::SUCCESS;
    }
}
