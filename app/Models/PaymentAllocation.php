<?php

namespace App\Models;

use Database\Factories\PaymentAllocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

/** @property string $amount */
#[Fillable([])]
class PaymentAllocation extends Model
{
    /** @use HasFactory<PaymentAllocationFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (PaymentAllocation $record): void {
            if (array_diff(array_keys($record->getDirty()), []) !== []) {
                throw ValidationException::withMessages(['record' => 'Financial history cannot be edited. Use an audited void.']);
            }
        });
        static::deleting(function (): never {
            throw ValidationException::withMessages(['record' => 'Financial history cannot be deleted.']);
        });
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
