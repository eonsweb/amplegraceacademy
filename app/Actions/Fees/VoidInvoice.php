<?php

namespace App\Actions\Fees;

use App\Models\Invoice;
use App\Models\User;
use App\Support\Authorization\Permissions;
use App\Support\Fees\FeeLedger;
use App\Support\Fees\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class VoidInvoice
{
    public function handle(User $user, int $invoiceId, string $reason): void
    {
        Gate::forUser($user)->authorize(Permissions::INVOICES_VOID);
        $reason = trim($reason);
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'min:5', 'max:500']])->validate();
        DB::transaction(function () use ($user, $invoiceId, $reason): void {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoiceId);
            if ($invoice->voided_at !== null) {
                return;
            }
            if (Money::minor(FeeLedger::invoices()->findOrFail($invoiceId)->paid) > 0) {
                throw ValidationException::withMessages(['reason' => 'Void the payments before voiding this invoice.']);
            }
            $invoice->forceFill(['voided_at' => now(), 'voided_by_user_id' => $user->id, 'void_reason' => $reason])->save();
            FeeLedger::audit($user, 'invoice.voided', 'invoice', $invoice->id);
        }, 3);
    }
}
