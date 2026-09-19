<?php

namespace Database\Factories;

use App\Models\FeeType;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InvoiceItem> */
class InvoiceItemFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['invoice_id' => Invoice::factory(), 'fee_type_id' => FeeType::factory(), 'description' => 'Tuition', 'quantity' => 1, 'unit_amount' => '100.00', 'amount' => '100.00'];
    }
}
