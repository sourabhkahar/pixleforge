<?php

namespace App\Actions;

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\Credits\CreditLedger;
use App\Services\Credits\InsufficientCreditsException;
use App\Services\Pricing\CostCalculator;
use App\Services\Pricing\PricingCatalog;
use Illuminate\Support\Facades\DB;

/**
 * estimate → record + reserve the worst-case price → queue the submission.
 *
 * The generation row and the credit hold are written in one transaction:
 * the user can never be charged for a generation that doesn't exist, and a
 * generation can never start without its money being reserved.
 */
class StartGeneration
{
    public function __construct(
        private readonly PricingCatalog $pricing,
        private readonly CostCalculator $calculator,
        private readonly CreditLedger $ledger,
    ) {}

    /**
     * @param  list<string>  $referenceImages
     *
     * @throws InsufficientCreditsException
     */
    public function handle(User $user, string $prompt, string $size, string $quality, array $referenceImages = []): Generation
    {
        $estimate = $this->calculator->estimate($this->pricing->current(), mb_strlen($prompt), $size, $quality, count($referenceImages));

        $generation = DB::transaction(function () use ($user, $prompt, $size, $quality, $referenceImages, $estimate) {
            $generation = $user->generations()->create([
                'prompt' => $prompt,
                'size' => $size,
                'quality' => $quality,
                'reference_images' => array_values($referenceImages),
                'status' => GenerationStatus::Queued,
                'estimated_cost_nanos' => $estimate->priceExpected,
                'max_cost_nanos' => $estimate->priceMax,
                'estimate_breakdown' => $estimate->toArray(),
            ]);
            $generation->setRelation('user', $user);

            $this->ledger->hold($generation);

            return $generation;
        });

        SubmitGeneration::dispatch($generation->id)->afterCommit();

        return $generation;
    }
}
