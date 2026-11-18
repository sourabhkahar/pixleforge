<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\Credits\CreditLedger;
use App\Support\Money;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Demo accounts (password: "password"):
     *   admin@example.com — can open /admin/report
     *   test@example.com  — regular user
     */
    public function run(CreditLedger $ledger): void
    {
        foreach ([
            ['email' => 'admin@example.com', 'name' => 'Admin', 'admin' => true],
            ['email' => 'test@example.com', 'name' => 'Test User', 'admin' => false],
        ] as $account) {
            $user = User::firstOrCreate(
                ['email' => $account['email']],
                ['name' => $account['name'], 'password' => 'password'],
            );
            $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now(), 'is_admin' => $account['admin']])->save();

            if (! $user->creditTransactions()->exists()) {
                $ledger->grant($user, Money::fromDecimal((string) config('pixelforge.signup_grant')), 'Welcome credits');
            }
        }
    }
}
