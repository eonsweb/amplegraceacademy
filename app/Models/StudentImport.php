<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/** @property Carbon $expires_at */
#[Fillable(['user_id', 'status', 'enrollment_date', 'expires_at'])]
class StudentImport extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    /** @return HasMany<StudentImportRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(StudentImportRow::class);
    }
}
