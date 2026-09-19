<?php

namespace App\Models;

use App\InvoiceStatus;
use App\Support\Fees\Money;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/** @property Carbon $issue_date
 * @property Carbon|null $due_date
 * @property string $total
 * @property string $paid
 * @property string $outstanding
 */
#[Fillable([])]
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (Invoice $record): void {
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
        return ['issue_date' => 'date', 'due_date' => 'date', 'voided_at' => 'datetime', 'total' => 'decimal:2', 'paid' => 'decimal:2', 'outstanding' => 'decimal:2'];
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<Enrollment, $this> */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
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

    /** @return BelongsTo<ClassLevel, $this> */
    public function classLevel(): BelongsTo
    {
        return $this->belongsTo(ClassLevel::class);
    }

    /** @return HasMany<InvoiceItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    /** @return HasMany<PaymentAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function status(): InvoiceStatus
    {
        if ($this->voided_at !== null) {
            return InvoiceStatus::Void;
        }
        if ($this->getAttribute('total') === null || $this->getAttribute('paid') === null) {
            throw new \LogicException('Load invoices through FeeLedger before reading their status.');
        }

        return Money::minor($this->outstanding) === 0 ? InvoiceStatus::Paid : (Money::minor($this->paid) > 0 ? InvoiceStatus::PartiallyPaid : InvoiceStatus::Unpaid);
    }
}
