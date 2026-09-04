<?php

namespace App\Models;

use Database\Factories\GuardianFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['title', 'first_name', 'middle_name', 'last_name', 'relationship', 'phone', 'email', 'address'])]
class Guardian extends Model
{
    /** @use HasFactory<GuardianFactory> */
    use HasFactory;

    /** @return BelongsToMany<Student, $this> */
    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'student_guardian')
            ->withPivot(['id', 'is_primary'])
            ->withTimestamps();
    }

    public function fullName(): string
    {
        return collect([$this->title, $this->first_name, $this->middle_name, $this->last_name])->filter()->implode(' ');
    }
}
