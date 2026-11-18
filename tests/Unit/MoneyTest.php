<?php

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_parses_decimal_strings_exactly(): void
    {
        $this->assertSame(46_125_000, Money::fromDecimal('0.046125000'));
        $this->assertSame(5_000_000_000, Money::fromDecimal('5'));
        $this->assertSame(12_500_000_000, Money::fromDecimal('12.5'));
        $this->assertSame(1, Money::fromDecimal('0.000000001'));
        $this->assertSame(-40_000_000, Money::fromDecimal('-0.04'));
    }

    public function test_rejects_more_than_nine_decimals(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::fromDecimal('0.0000000001');
    }

    public function test_round_trips_to_pixelforge_format(): void
    {
        $this->assertSame('0.169080000', Money::toDecimal(Money::fromDecimal('0.169080000')));
        $this->assertSame('0.000000001', Money::toDecimal(1));
    }

    public function test_formats_without_rounding(): void
    {
        $this->assertSame('$0.04', Money::format(40_000_000));
        $this->assertSame('$0.046125', Money::format(46_125_000));
        $this->assertSame('$0.000000001', Money::format(1));
        $this->assertSame('$1,234.50', Money::format(1_234_500_000_000));
        $this->assertSame('-$0.0001', Money::format(-100_000));
        $this->assertSame('$0.00', Money::format(0));
    }

    public function test_token_cost_and_markup(): void
    {
        $this->assertSame(258_000_000, Money::tokenCost(6450, '40.00'));
        $this->assertSame(575_000, Money::tokenCost(115, '5.00'));
        $this->assertSame(100_000_000, Money::tokenCost(8000, '12.50'));

        $this->assertSame(125, Money::withMarkup(100, 25));
        $this->assertSame(4, Money::withMarkup(3, 25), '3.75 nanodollars rounds UP to 4');
        $this->assertSame(0, Money::withMarkup(0, 25));
    }
}
