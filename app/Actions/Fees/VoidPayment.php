<?php

namespace App\Actions\Fees;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Support\Authorization\Permissions;
use App\Support\Fees\FeeLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class VoidPayment
{
    public function handle(User $user, int $paymentId, string $reason): void
    {
        Gate::forUser($user)->authorize(Permissions::PAYMENTS_VOID);
        $reason = trim($reason);
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'min:5', 'max:500']])->validate();
        $invoiceIds = Payment::query()->findOrFail($paymentId)->allocations()->orderBy('invoice_id')->pluck('invoice_id');
        DB::transaction(function () use ($user, $paymentId, $reason, $invoiceIds): void {
            Invoice::query()->whereIn('id', $invoiceIds)->orderBy('id')->lockForUpdate()->get();
            $payment = Payment::query()->lockForUpdate()->findOrFail($paymentId);
            if ($payment->voided_at !== null) {
                return;
            }
            $payment->forceFill(['voided_at' => now(), 'voided_by_user_id' => $user->id, 'void_reason' => $reason])->save();
            FeeLedger::audit($user, 'payment.voided', 'payment', $payment->id);
        }, 3);
    }
}
