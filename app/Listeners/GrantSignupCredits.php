<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\Credits\CreditLedger;
use App\Support\Money;
use Illuminate\Auth\Events\Registered;

class GrantSignupCredits
{
    public function __construct(private readonly CreditLedger $ledger) {}

    public function handle(Registered $event): void
    {
        $amount = Money::fromDecimal((string) config('pixelforge.signup_grant'));

        if ($amount > 0 && $event->user instanceof User) {
            $this->ledger->grant($event->user, $amount, 'Welcome credits');
        }
    }
}
