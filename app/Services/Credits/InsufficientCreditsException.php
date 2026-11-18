<?php

namespace App\Services\Credits;

use App\Support\Money;
use RuntimeException;

class InsufficientCreditsException extends RuntimeException
{
    public function __construct(public readonly int $requiredNanos, public readonly int $balanceNanos)
    {
        parent::__construct(sprintf(
            'This generation could cost up to %s and your balance is %s. Buy credits or choose a cheaper size/quality.',
            Money::format($requiredNanos),
            Money::format($balanceNanos),
        ));
    }
}
