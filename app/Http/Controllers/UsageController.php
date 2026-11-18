<?php

namespace App\Http\Controllers;

use App\Enums\CreditTransactionType;
use App\Enums\GenerationStatus;
use App\Models\CreditTransaction;
use App\Support\Money;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UsageController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $sum = fn (CreditTransactionType $type) => (int) $user->creditTransactions()->where('type', $type)->sum('amount_nanos');

        $reserved = -($sum(CreditTransactionType::Hold) + $sum(CreditTransactionType::Release)); // still held for unfinished generations

        $transactions = $user->creditTransactions()
            ->latest('id')
            ->paginate(25)
            ->through(fn (CreditTransaction $transaction) => $transaction->toFrontend());

        return Inertia::render('Usage/Index', [
            'summary' => [
                'balance' => Money::format((int) $user->balance_nanos),
                'reserved' => Money::format($reserved),
                'spent' => Money::format(-$sum(CreditTransactionType::Charge)),
                'added' => Money::format($sum(CreditTransactionType::Grant) + $sum(CreditTransactionType::Purchase)),
                'generations' => $user->generations()->count(),
                'succeeded' => $user->generations()->where('status', GenerationStatus::Succeeded)->count(),
                'failed' => $user->generations()->where('status', GenerationStatus::Failed)->count(),
            ],
            'purchaseOptions' => config('pixelforge.purchase_options'),
            'transactions' => $transactions,
        ]);
    }
}
