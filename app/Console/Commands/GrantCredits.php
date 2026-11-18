<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Credits\CreditLedger;
use App\Support\Money;
use Illuminate\Console\Command;

class GrantCredits extends Command
{
    protected $signature = 'credits:grant {email : The user\'s email address} {amount : Amount in US dollars, e.g. 10 or 2.50} {--note=Manual top-up : Description shown in the user\'s usage history}';

    protected $description = 'Add credits (US dollars) to a user\'s balance';

    public function handle(CreditLedger $ledger): int
    {
        $user = User::where('email', $this->argument('email'))->first();
        if ($user === null) {
            $this->error('No user with that email.');

            return self::FAILURE;
        }

        $amount = $this->argument('amount');
        if (! is_string($amount) || ! preg_match('/^\d+(\.\d{1,9})?$/', $amount) || Money::fromDecimal($amount) <= 0) {
            $this->error('Amount must be a positive number of dollars.');

            return self::FAILURE;
        }

        $transaction = $ledger->grant($user, Money::fromDecimal((string) $amount), (string) $this->option('note'));

        $this->info(sprintf(
            'Granted %s to %s. New balance: %s',
            Money::format($transaction->amount_nanos),
            $user->email,
            Money::format($transaction->balance_after_nanos),
        ));

        return self::SUCCESS;
    }
}
