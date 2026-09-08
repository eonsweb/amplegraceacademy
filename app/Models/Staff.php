<?php

namespace App\Models;

use App\EmploymentType;
use App\Gender;
use App\StaffStatus;
use Database\Factories\StaffFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property string $staff_number
 * @property string $first_name
 * @property string $last_name
 * @property Gender|null $gender
 * @property Carbon|null $date_of_birth
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $address
 * @property string $role_title
 * @property string|null $department
 * @property EmploymentType|null $employment_type
 * @property Carbon|null $employment_date
 * @property string|null $photo
 * @property StaffStatus $status
 */
#[Fillable(['first_name', 'last_name', 'gender', 'date_of_birth', 'email', 'phone', 'address', 'role_title', 'department', 'employment_type', 'employment_date', 'photo', 'status'])]
class Staff extends Model
{
    /** @use HasFactory<StaffFactory> */
    use HasFactory;

    /** @return HasOne<User, $this> */
    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    protected function casts(): array
    {
        return [
            'gender' => Gender::class,
            'date_of_birth' => 'date',
            'employment_type' => EmploymentType::class,
            'employment_date' => 'date',
            'status' => StaffStatus::class,
        ];
    }

    public function fullName(): string
    {
        return $this->first_name.' '.$this->last_name;
    }

    public function photoUrl(): ?string
    {
        return $this->photo === null ? null : Storage::disk('public')->url($this->photo);
    }
}
