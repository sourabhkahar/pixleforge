<?php

namespace App\Services\Pricing;

use App\Support\Money;
use InvalidArgumentException;

/**
 * Turns token counts into money. Two jobs:
 *
 *  estimate() — before submitting. Output tokens are exact; text tokens are
 *               ceil(chars / 4) ± variance; reasoning tokens have a min/max
 *               range. We show the expected price and HOLD the maximum.
 *
 *  actual()   — after success, from Pixelforge's reported usage and the
 *               rates locked onto the generation. Exact, never rounded.
 *
 * Customer price = Pixelforge cost × (1 + markup), rounded up to the next
 * whole nanodollar.
 */
class CostCalculator
{
    public const TOKEN_TYPES = ['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'];

    public function __construct(private readonly int $markupPercent) {}

    public function markupPercent(): int
    {
        return $this->markupPercent;
    }

    public function priceFor(int $providerNanos): int
    {
        return Money::withMarkup($providerNanos, $this->markupPercent);
    }

    /**
     * Pixelforge's cost for a set of token counts at the given per-1M rates.
     *
     * @param  array<string,int>  $usage
     * @param  array<string,string>  $rates
     */
    public function providerCost(array $usage, array $rates): int
    {
        $total = 0;
        foreach (self::TOKEN_TYPES as $type) {
            if (! isset($rates[$type])) {
                throw new InvalidArgumentException("Missing rate for {$type}.");
            }
            $total += Money::tokenCost((int) ($usage[$type] ?? 0), (string) $rates[$type]);
        }

        return $total;
    }

    /**
     * Final numbers for a succeeded generation.
     *
     * @return array{provider_nanos: int, charged_nanos: int}
     */
    public function actual(array $usage, array $rates): array
    {
        $provider = $this->providerCost($usage, $rates);

        return ['provider_nanos' => $provider, 'charged_nanos' => $this->priceFor($provider)];
    }

    /**
     * @param  array{rates: array<string,string>, tokens: array}  $pricing  from PricingCatalog
     * @param  int  $promptCharacters  Unicode characters (mb_strlen) of the prompt as it will be sent
     */
    public function estimate(array $pricing, int $promptCharacters, string $size, string $quality, int $referenceImages): Estimate
    {
        $tokens = $pricing['tokens'];
        $output = $tokens['output'][$quality][$size] ?? null;
        $reasoning = $tokens['reasoning'][$quality] ?? null;
        if ($output === null || $reasoning === null) {
            throw new InvalidArgumentException("No token rules for {$quality} / {$size}.");
        }

        $textEstimate = (int) ceil(max(0, $promptCharacters) / max(1, (int) $tokens['characters_per_text_token']));
        $variance = (float) $tokens['text_token_variance'];

        $scenarios = [
            'min' => [
                'input_text_tokens' => (int) floor($textEstimate * (1 - $variance)),
                'reasoning_tokens' => (int) $reasoning['min'],
            ],
            'expected' => [
                'input_text_tokens' => $textEstimate,
                'reasoning_tokens' => (int) round(((int) $reasoning['min'] + (int) $reasoning['max']) / 2),
            ],
            'max' => [
                'input_text_tokens' => (int) ceil($textEstimate * (1 + $variance)),
                'reasoning_tokens' => (int) $reasoning['max'],
            ],
        ];

        $fixed = [
            'input_image_tokens' => $referenceImages * (int) $tokens['per_reference_image'],
            'output_tokens' => (int) $output,
        ];

        $usage = [];
        $provider = [];
        foreach ($scenarios as $name => $variable) {
            $usage[$name] = $fixed + $variable;
            $provider[$name] = $this->providerCost($usage[$name], $pricing['rates']);
        }

        return new Estimate(
            providerMin: $provider['min'],
            providerExpected: $provider['expected'],
            providerMax: $provider['max'],
            priceMin: $this->priceFor($provider['min']),
            priceExpected: $this->priceFor($provider['expected']),
            priceMax: $this->priceFor($provider['max']),
            tokens: $usage,
            rates: $pricing['rates'],
            markupPercent: $this->markupPercent,
        );
    }
}
