<?php

namespace App\Models;

use App\EnrollmentStatus;
use App\Gender;
use App\StudentStatus;
use Database\Factories\StudentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

#[Fillable(['admission_number', 'first_name', 'middle_name', 'last_name', 'gender', 'date_of_birth', 'photo', 'status'])]
class Student extends Model
{
    /** @use HasFactory<StudentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'gender' => Gender::class,
            'date_of_birth' => 'date',
            'status' => StudentStatus::class,
        ];
    }

    /** @return BelongsToMany<Guardian, $this> */
    public function guardians(): BelongsToMany
    {
        return $this->belongsToMany(Guardian::class, 'student_guardian')
            ->withPivot(['id', 'is_primary'])
            ->withTimestamps();
    }

    /** @return BelongsToMany<Guardian, $this> */
    public function primaryGuardians(): BelongsToMany
    {
        return $this->guardians()->wherePivot('is_primary', true);
    }

    /** @return HasMany<Enrollment, $this> */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    /** @return HasOne<Enrollment, $this> */
    public function currentEnrollment(): HasOne
    {
        return $this->hasOne(Enrollment::class)
            ->where('enrollments.status', EnrollmentStatus::Active->value)
            ->latestOfMany();
    }

    public function fullName(): string
    {
        return collect([$this->first_name, $this->middle_name, $this->last_name])->filter()->implode(' ');
    }

    public function photoUrl(): ?string
    {
        return $this->photo === null ? null : Storage::disk('public')->url($this->photo);
    }
}
