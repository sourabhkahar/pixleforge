<?php

namespace Tests\Unit;

use App\Services\Pricing\CostCalculator;
use App\Support\Money;
use Tests\TestCase;

class CostCalculatorTest extends TestCase
{
    public function test_matches_the_worked_maximum_from_the_pixelforge_docs(): void
    {
        // high, 1536x1024, 400-char prompt, 2 reference images → $0.373875000 max
        $estimate = (new CostCalculator(25))->estimate(config('pixelforge.fallback_pricing'), 400, '1536x1024', 'high', 2);

        $this->assertSame('0.373875000', Money::toDecimal($estimate->providerMax));
        $this->assertSame(Money::withMarkup($estimate->providerMax, 25), $estimate->priceMax);
        $this->assertSame(115, $estimate->tokens['max']['input_text_tokens']);
        $this->assertSame(1530, $estimate->tokens['max']['input_image_tokens']);
        $this->assertSame(8000, $estimate->tokens['max']['reasoning_tokens']);
        $this->assertTrue($estimate->priceMin <= $estimate->priceExpected && $estimate->priceExpected <= $estimate->priceMax);
    }

    public function test_actual_cost_uses_reported_usage_and_locked_rates(): void
    {
        $result = (new CostCalculator(25))->actual(
            ['input_text_tokens' => 12, 'input_image_tokens' => 765, 'output_tokens' => 6450, 'reasoning_tokens' => 3184],
            ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
        );

        // 60,000 + 7,650,000 + 258,000,000 + 39,800,000 nanodollars
        $this->assertSame(305_510_000, $result['provider_nanos']);
        $this->assertSame(381_887_500, $result['charged_nanos']);
    }
}
