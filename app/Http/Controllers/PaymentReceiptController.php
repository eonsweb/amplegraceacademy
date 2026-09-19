<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Support\Fees\FeeLedger;
use App\Support\Settings\SystemSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

class PaymentReceiptController extends Controller
{
    public function __invoke(Payment $payment, SystemSettings $settings): View
    {
        Gate::authorize('print', $payment);
        $payment->load('allocations.invoice');
        $balances = FeeLedger::invoices()->whereIn('id', $payment->allocations->pluck('invoice_id'))->get()->keyBy('id');

        return view('fees.receipt', compact('payment', 'settings', 'balances'));
    }
}
