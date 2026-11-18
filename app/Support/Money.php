<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * All money in this app is an integer number of NANODOLLARS
 * (1 USD = 1,000,000,000) — the same precision Pixelforge uses for
 * "cost_usd" (nine decimal places), so nothing is ever rounded away.
 *
 * Decimal strings are parsed digit-by-digit; floats never touch money.
 */
final class Money
{
    public const NANOS_PER_DOLLAR = 1_000_000_000;

    /**
     * "0.046125000" / "5" / "12.5" → nanodollars. More than 9 decimals is rejected.
     */
    public static function fromDecimal(string|int $amount): int
    {
        $amount = trim((string) $amount);
        if (! preg_match('/^(-)?(\d+)(?:\.(\d{1,9}))?$/', $amount, $m)) {
            throw new InvalidArgumentException("Not a decimal dollar amount with at most 9 decimals: [{$amount}]");
        }

        $nanos = ((int) $m[2]) * self::NANOS_PER_DOLLAR + (int) str_pad($m[3] ?? '', 9, '0');

        return $m[1] === '-' ? -$nanos : $nanos;
    }

    /**
     * Nanodollars → exact 9-decimal string, the format Pixelforge uses ("0.169080000").
     */
    public static function toDecimal(int $nanos): string
    {
        $sign = $nanos < 0 ? '-' : '';
        $abs = abs($nanos);

        return $sign.intdiv($abs, self::NANOS_PER_DOLLAR).'.'.str_pad((string) ($abs % self::NANOS_PER_DOLLAR), 9, '0', STR_PAD_LEFT);
    }

    /**
     * Human format: "$0.046125", "$12.50", "-$0.0001". Never rounds — trailing
     * zeros are trimmed but at least two decimals are kept.
     */
    public static function format(int $nanos): string
    {
        $sign = $nanos < 0 ? '-' : '';
        [$whole, $fraction] = explode('.', self::toDecimal(abs($nanos)));
        $fraction = str_pad(rtrim($fraction, '0'), 2, '0');

        return $sign.'$'.number_format((int) $whole).'.'.$fraction;
    }

    /**
     * Cost of $tokens at a rate quoted in USD per 1M tokens (e.g. "40.00").
     * Exact for any rate with up to 3 decimals; otherwise rounded half-up to
     * the nanodollar.
     */
    public static function tokenCost(int $tokens, string $ratePerMillion): int
    {
        $rateNanosPerMillion = self::fromDecimal($ratePerMillion);
        $numerator = $tokens * $rateNanosPerMillion;

        return intdiv($numerator + 500_000, 1_000_000);
    }

    /**
     * Apply a percentage markup, rounding UP to the next whole nanodollar
     * (so the business never under-charges by a fraction of a nanodollar).
     */
    public static function withMarkup(int $nanos, int $markupPercent): int
    {
        $numerator = $nanos * (100 + $markupPercent);

        return intdiv($numerator + 99, 100);
    }
}
