<?php

namespace App\Http\Controllers;

use App\Services\Credits\CreditLedger;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Fake "Buy credits": no payment provider, it just records a purchase.
 */
class CreditsController extends Controller
{
    public function purchase(Request $request, CreditLedger $ledger): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'string', Rule::in(config('pixelforge.purchase_options'))],
        ]);

        $transaction = $ledger->purchase($request->user(), Money::fromDecimal($data['amount']));

        return back()->with('success', 'Added '.Money::format($transaction->amount_nanos).' of credits (demo purchase — no payment taken).');
    }
}
