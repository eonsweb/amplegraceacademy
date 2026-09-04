<?php

namespace App\Models;

use Database\Factories\TermFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['academic_year_id', 'name', 'term_order', 'is_current'])]
class Term extends Model
{
    /** @use HasFactory<TermFactory> */
    use HasFactory;

    /** @var array<int, string> */
    public const STANDARD_TERMS = [
        1 => '1st Term',
        2 => '2nd Term',
        3 => '3rd Term',
    ];

    protected function casts(): array
    {
        return ['term_order' => 'integer', 'is_current' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(function (Term $term): void {
            if ((self::STANDARD_TERMS[$term->term_order] ?? null) !== $term->name) {
                throw new LogicException('Terms must be one of the three standard school terms.');
            }
        });

        static::updating(function (Term $term): void {
            if ($term->isDirty(['academic_year_id', 'name', 'term_order'])) {
                throw new LogicException('A term name, order, and academic year cannot be changed.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Structural academic terms cannot be deleted individually.');
        });
    }

    /** @return BelongsTo<AcademicYear, $this> */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }
}
