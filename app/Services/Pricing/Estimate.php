<?php

namespace App\Services\Pricing;

use App\Support\Money;

/**
 * Pre-submission price range. "price*" includes our markup; "provider*" is
 * what Pixelforge would bill us. All values are nanodollars.
 */
final class Estimate
{
    public function __construct(
        public readonly int $providerMin,
        public readonly int $providerExpected,
        public readonly int $providerMax,
        public readonly int $priceMin,
        public readonly int $priceExpected,
        public readonly int $priceMax,
        public readonly array $tokens,
        public readonly array $rates,
        public readonly int $markupPercent,
    ) {}

    public function toArray(): array
    {
        return [
            'price_min' => Money::format($this->priceMin),
            'price_expected' => Money::format($this->priceExpected),
            'price_max' => Money::format($this->priceMax),
            'price_min_nanos' => $this->priceMin,
            'price_expected_nanos' => $this->priceExpected,
            'price_max_nanos' => $this->priceMax,
            'provider_expected_nanos' => $this->providerExpected,
            'provider_max_nanos' => $this->providerMax,
            'tokens' => $this->tokens,
            'rates' => $this->rates,
            'markup_percent' => $this->markupPercent,
        ];
    }
}
