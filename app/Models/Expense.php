<?php

namespace App\Models;

use App\ExpenseStatus;
use App\PaymentMethod;
use Database\Factories\ExpenseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * @property string $amount
 * @property Carbon $expense_date
 * @property ExpenseStatus $status
 * @property PaymentMethod|null $payment_method
 * @property Carbon|null $recorded_at
 * @property Carbon|null $voided_at
 */
#[Fillable([])]
class Expense extends Model
{
    /** @use HasFactory<ExpenseFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'expense_date' => 'date', 'status' => ExpenseStatus::class,
            'payment_method' => PaymentMethod::class, 'recorded_at' => 'datetime', 'voided_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (Expense $expense): void {
            $original = $expense->getRawOriginal('status');
            if ($original === ExpenseStatus::Voided->value
                || ($original === ExpenseStatus::Recorded->value
                    && ($expense->status !== ExpenseStatus::Voided
                        || array_diff(array_keys($expense->getDirty()), ['status', 'voided_at', 'voided_by_user_id', 'void_reason', 'updated_at']) !== []))
                || ($original === ExpenseStatus::Draft->value && $expense->status === ExpenseStatus::Voided)) {
                throw ValidationException::withMessages(['expense' => 'Recorded financial history cannot be edited. Void it and record a replacement.']);
            }
        });
        static::deleting(function (Expense $expense): void {
            if ($expense->status !== ExpenseStatus::Draft) {
                throw ValidationException::withMessages(['expense' => 'Recorded expenses cannot be deleted. Void the expense instead.']);
            }
        });
    }

    /** @return BelongsTo<ExpenseCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    /** @return BelongsTo<AcademicYear, $this> */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /** @return BelongsTo<Term, $this> */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by_user_id');
    }
}
