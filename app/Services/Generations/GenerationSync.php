<?php

namespace App\Services\Generations;

use App\Enums\GenerationStatus;
use App\Jobs\StoreGenerationImage;
use App\Models\Generation;
use App\Services\Credits\CreditLedger;
use App\Services\Pricing\CostCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The single place where a Pixelforge generation object is applied to our
 * record — whether it came from the create response, a webhook or a poll.
 *
 * Safe under duplicates, reordering and concurrency because it:
 *   1. locks the generation row,
 *   2. only ever moves the status forward (queued → processing → terminal),
 *   3. never leaves a terminal state, and
 *   4. settles money through CreditLedger, whose unique index makes each
 *      release/charge happen at most once.
 */
class GenerationSync
{
    public function __construct(
        private readonly CreditLedger $ledger,
        private readonly CostCalculator $calculator,
    ) {}

    /**
     * @param  array  $remote  a Pixelforge generation object
     * @return bool whether anything changed
     */
    public function apply(Generation $generation, array $remote): bool
    {
        $changed = DB::transaction(function () use ($generation, $remote) {
            /** @var Generation $local */
            $local = Generation::query()->lockForUpdate()->findOrFail($generation->id);

            $local->last_synced_at = now();
            $local->provider_id ??= $remote['id'] ?? null;
            $local->rates ??= $remote['rates'] ?? null;

            $incoming = GenerationStatus::tryFrom((string) ($remote['status'] ?? ''));

            // Out-of-order or duplicate: nothing newer to learn.
            if ($incoming === null || $local->status->isTerminal() || $incoming->rank() <= $local->status->rank()) {
                // A fresher signed URL may still be useful while we have no stored copy.
                if ($local->status === GenerationStatus::Succeeded && ! $local->image_path && ! empty($remote['image_url'])) {
                    $local->image_url = $remote['image_url'];
                    $local->image_url_expires_at = $this->time($remote['image_url_expires_at'] ?? null);
                }
                $local->save();

                return false;
            }

            $local->started_at ??= $this->time($remote['started_at'] ?? null);

            match ($incoming) {
                GenerationStatus::Processing => $this->markProcessing($local),
                GenerationStatus::Succeeded => $this->markSucceeded($local, $remote),
                GenerationStatus::Failed => $this->markFailed(
                    $local,
                    $remote['error']['code'] ?? 'generation_failed',
                    $remote['error']['message'] ?? 'Pixelforge could not generate this image.',
                    $this->time($remote['completed_at'] ?? null),
                ),
                default => null,
            };

            return true;
        });

        $generation->refresh();

        if ($changed && $generation->status === GenerationStatus::Succeeded && $generation->image_url && ! $generation->image_path) {
            StoreGenerationImage::dispatch($generation->id)->afterCommit();
        }

        return $changed;
    }

    /**
     * Fail a generation that never made it to Pixelforge (or that we gave up
     * on). No-op if it already finished.
     */
    public function failLocally(Generation $generation, string $code, string $message): void
    {
        DB::transaction(function () use ($generation, $code, $message) {
            $local = Generation::query()->lockForUpdate()->findOrFail($generation->id);
            if (! $local->status->isTerminal()) {
                $this->markFailed($local, $code, $message, now());
            }
        });

        $generation->refresh();
    }

    private function markProcessing(Generation $local): void
    {
        $local->status = GenerationStatus::Processing;
        $local->status_detail = null;
        $local->save();
    }

    private function markSucceeded(Generation $local, array $remote): void
    {
        $usage = $remote['usage'] ?? null;
        $rates = $remote['rates'] ?? $local->rates;
        if (! is_array($usage) || ! is_array($rates)) {
            // A succeeded object always carries usage; if not, wait for a complete one.
            return;
        }

        $cost = $this->calculator->actual($usage, $rates);

        $local->fill([
            'status' => GenerationStatus::Succeeded,
            'status_detail' => null,
            'usage' => $usage,
            'rates' => $rates,
            'provider_cost_nanos' => $cost['provider_nanos'],
            'charged_nanos' => $cost['charged_nanos'],
            'image_url' => $remote['image_url'] ?? null,
            'image_url_expires_at' => $this->time($remote['image_url_expires_at'] ?? null),
            'completed_at' => $this->time($remote['completed_at'] ?? null) ?? now(),
            'error_code' => null,
            'error_message' => null,
        ])->save();

        $this->ledger->release($local, 'finished');
        $this->ledger->charge($local, $cost['charged_nanos']);
    }

    private function markFailed(Generation $local, string $code, string $message, ?Carbon $completedAt): void
    {
        $local->fill([
            'status' => GenerationStatus::Failed,
            'status_detail' => null,
            'error_code' => $code,
            'error_message' => mb_strimwidth($message, 0, 1000, '…'),
            'completed_at' => $completedAt ?? now(),
            'provider_cost_nanos' => 0,
            'charged_nanos' => 0,
        ])->save();

        // Failed generations are free: just give the reservation back.
        $this->ledger->release($local, 'failed — not charged');
    }

    private function time(?string $value): ?Carbon
    {
        return $value ? Carbon::parse($value)->utc() : null;
    }
}
