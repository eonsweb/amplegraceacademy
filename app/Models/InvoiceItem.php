<?php

namespace App\Models;

use Database\Factories\InvoiceItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

/** @property string $amount
 * @property string $unit_amount
 */
#[Fillable([])]
class InvoiceItem extends Model
{
    /** @use HasFactory<InvoiceItemFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (InvoiceItem $record): void {
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
        return ['quantity' => 'integer', 'unit_amount' => 'decimal:2', 'amount' => 'decimal:2'];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<FeeType, $this> */
    public function feeType(): BelongsTo
    {
        return $this->belongsTo(FeeType::class);
    }
}
