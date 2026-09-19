<?php

namespace App\Models;

use App\PaymentMethod;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/** @property string $amount
 * @property Carbon $payment_date
 * @property PaymentMethod $payment_method
 */
#[Fillable([])]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (Payment $record): void {
            if (array_diff(array_keys($record->getDirty()), ['voided_at', 'voided_by_user_id', 'void_reason', 'updated_at']) !== []) {
                throw ValidationException::withMessages(['record' => 'Financial history cannot be edited. Use an audited void.']);
            }
        });
        static::deleting(function (): never {
            throw ValidationException::withMessages(['record' => 'Financial history cannot be deleted.']);
        });
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'payment_date' => 'date', 'voided_at' => 'datetime', 'payment_method' => PaymentMethod::class];
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<User, $this> */
    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }

    /** @return HasMany<PaymentAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }
}
