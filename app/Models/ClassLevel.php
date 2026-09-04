<?php

namespace App\Models;

use Database\Factories\ClassLevelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'level_order', 'description', 'is_active'])]
class ClassLevel extends Model
{
    /** @use HasFactory<ClassLevelFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['level_order' => 'integer', 'is_active' => 'boolean'];
    }

    /** @return HasMany<ClassSubject, $this> */
    public function classSubjects(): HasMany
    {
        return $this->hasMany(ClassSubject::class);
    }
}
