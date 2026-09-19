<?php

namespace App\Actions\Fees;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\PaymentMethod;
use App\Support\Authorization\Permissions;
use App\Support\Fees\FeeLedger;
use App\Support\Fees\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RecordPayment
{
    /** One allocation per entry today; separate immutable allocations preserve the path to split payments.
     * @param  array<string, mixed>  $data
     */
    public function handle(User $user, array $data): Payment
    {
        Gate::forUser($user)->authorize(Permissions::PAYMENTS_RECORD);
        $values = Validator::make($data, [
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'invoice_id' => ['required', 'integer', 'exists:invoices,id'],
            'amount' => Money::rules(),
            'payment_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'reference' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'submission_key' => ['required', 'uuid'],
        ])->validate();

        return DB::transaction(function () use ($user, $values): Payment {
            $invoice = Invoice::query()->lockForUpdate()->whereKey($values['invoice_id'])->firstOrFail();
            if ($invoice->student_id !== (int) $values['student_id']) {
                throw ValidationException::withMessages(['invoice_id' => 'Select an invoice belonging to this student.']);
            }
            $existing = Payment::query()->where('submission_key', $values['submission_key'])->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->received_by_user_id !== $user->id || $existing->student_id !== $invoice->student_id
                    || Money::minor($existing->amount) !== Money::minor($values['amount'])
                    || $existing->payment_date->toDateString() !== $values['payment_date']
                    || $existing->payment_method->value !== $values['payment_method']
                    || ($existing->reference ?? '') !== ($values['reference'] ?? '')
                    || ($existing->notes ?? '') !== ($values['notes'] ?? '')
                    || ! $existing->allocations()->where('invoice_id', $invoice->id)->exists()) {
                    throw ValidationException::withMessages(['submission_key' => 'This submission was already used. Start a new payment.']);
                }

                return $existing;
            }
            if ($invoice->voided_at !== null) {
                throw ValidationException::withMessages(['invoice_id' => 'A void invoice cannot receive payments.']);
            }
            $balance = FeeLedger::invoices()->findOrFail($invoice->id);
            if (Money::minor($values['amount']) > Money::minor($balance->outstanding)) {
                throw ValidationException::withMessages(['amount' => 'Payment exceeds the outstanding invoice balance.']);
            }
            if ($values['payment_date'] < $invoice->issue_date->toDateString()) {
                throw ValidationException::withMessages(['payment_date' => 'Payment date cannot precede the invoice issue date.']);
            }
            $payment = new Payment;
            $payment->forceFill([
                'student_id' => $invoice->student_id, 'receipt_number' => 'RCT-'.Str::ulid(), 'submission_key' => $values['submission_key'],
                'payment_date' => $values['payment_date'], 'amount' => Money::decimal(Money::minor($values['amount'])),
                'payment_method' => $values['payment_method'], 'reference' => $values['reference'] ?? null, 'notes' => $values['notes'] ?? null,
                'received_by_user_id' => $user->id, 'received_by_name' => $user->name,
            ])->save();
            DB::table('payment_allocations')->insert(['payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'amount' => $payment->amount, 'created_at' => now(), 'updated_at' => now()]);
            FeeLedger::audit($user, 'payment.recorded', 'payment', $payment->id, ['invoice_id' => $invoice->id]);

            return $payment;
        }, 3);
    }
}
