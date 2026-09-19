<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PaymentAllocation> */
class PaymentAllocationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'amount' => '100.00',
            'invoice_id' => fn (array $attributes) => InvoiceItem::factory()->create(['amount' => $attributes['amount'], 'unit_amount' => $attributes['amount']])->invoice_id,
            'payment_id' => fn (array $attributes) => Payment::factory()->create([
                'student_id' => Invoice::query()->whereKey($attributes['invoice_id'])->firstOrFail()->student_id,
                'amount' => $attributes['amount'],
            ])->id,
        ];
    }
}
