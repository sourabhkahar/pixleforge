<?php

namespace App\Enums;

enum CreditTransactionType: string
{
    case Grant = 'grant';       // free credits (signup bonus, admin top-up)
    case Purchase = 'purchase'; // "Buy credits"
    case Hold = 'hold';         // maximum possible price reserved when a generation is submitted
    case Release = 'release';   // that reservation returned once the generation finishes or fails
    case Charge = 'charge';     // the actual price of a succeeded generation

    /** Types that may appear at most once per generation. */
    public static function perGeneration(): array
    {
        return [self::Hold, self::Release, self::Charge];
    }
}
