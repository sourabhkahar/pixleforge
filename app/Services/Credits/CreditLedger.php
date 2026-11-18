<?php

namespace App\Services\Credits;

use App\Enums\CreditTransactionType;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only code that changes a user's balance.
 *
 * Each change locks the user row, writes an append-only ledger row carrying
 * the resulting balance, and updates the cached balance — in one transaction.
 *
 * Generation money flow:
 *   hold     −max price    when submitted (blocks if the user can't afford the worst case)
 *   release  +max price    when it finishes, either way
 *   charge   −actual price only if it succeeded
 * The unique (generation_id, type) index makes each step happen at most once.
 */
class CreditLedger
{
    public function grant(User $user, int $nanos, string $description = 'Free credits'): CreditTransaction
    {
        $this->assertPositive($nanos);

        return $this->apply($user, CreditTransactionType::Grant, $nanos, $description);
    }

    public function purchase(User $user, int $nanos): CreditTransaction
    {
        $this->assertPositive($nanos);

        return $this->apply($user, CreditTransactionType::Purchase, $nanos, 'Bought '.Money::format($nanos).' of credits');
    }

    /**
     * @throws InsufficientCreditsException
     */
    public function hold(Generation $generation): CreditTransaction
    {
        return $this->apply(
            $generation->user,
            CreditTransactionType::Hold,
            -$generation->max_cost_nanos,
            "Reserved up to {$this->fmt($generation->max_cost_nanos)} for generation #{$generation->id}",
            $generation,
            requireFunds: true,
        );
    }

    /**
     * Return the reservation. Idempotent: returns null if already released.
     */
    public function release(Generation $generation, string $why): ?CreditTransaction
    {
        return $this->applyOnce(
            $generation,
            CreditTransactionType::Release,
            $generation->max_cost_nanos,
            "Released reservation for generation #{$generation->id} ({$why})",
        );
    }

    /**
     * Charge the final price. Idempotent: returns null if already charged.
     */
    public function charge(Generation $generation, int $nanos): ?CreditTransaction
    {
        return $this->applyOnce(
            $generation,
            CreditTransactionType::Charge,
            -$nanos,
            "Generation #{$generation->id} — actual cost",
        );
    }

    private function applyOnce(Generation $generation, CreditTransactionType $type, int $nanos, string $description): ?CreditTransaction
    {
        $exists = CreditTransaction::query()
            ->where('generation_id', $generation->id)
            ->where('type', $type)
            ->exists();

        if ($exists) {
            return null;
        }

        try {
            return $this->apply($generation->user, $type, $nanos, $description, $generation);
        } catch (UniqueConstraintViolationException) {
            return null; // lost a race with a concurrent writer: it is already recorded
        }
    }

    private function apply(
        User $user,
        CreditTransactionType $type,
        int $nanos,
        string $description,
        ?Generation $generation = null,
        bool $requireFunds = false,
    ): CreditTransaction {
        return DB::transaction(function () use ($user, $type, $nanos, $description, $generation, $requireFunds) {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $newBalance = (int) $locked->balance_nanos + $nanos;

            if ($requireFunds && $newBalance < 0) {
                throw new InsufficientCreditsException(-$nanos, (int) $locked->balance_nanos);
            }

            $transaction = CreditTransaction::create([
                'user_id' => $locked->id,
                'generation_id' => $generation?->id,
                'type' => $type,
                'amount_nanos' => $nanos,
                'balance_after_nanos' => $newBalance,
                'description' => $description,
            ]);

            $locked->forceFill(['balance_nanos' => $newBalance])->save();
            $user->balance_nanos = $newBalance;

            return $transaction;
        });
    }

    private function assertPositive(int $nanos): void
    {
        if ($nanos <= 0) {
            throw new InvalidArgumentException('Amount must be positive.');
        }
    }

    private function fmt(int $nanos): string
    {
        return Money::format($nanos);
    }
}
