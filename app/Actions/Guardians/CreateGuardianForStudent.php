<?php

namespace App\Actions\Guardians;

use App\Models\Guardian;
use App\Models\Student;
use Illuminate\Support\Facades\DB;

class CreateGuardianForStudent
{
    public function __construct(private LinkGuardianToStudent $linkGuardianToStudent) {}

    /** @param array<string, mixed> $guardianData */
    public function handle(Student $student, array $guardianData, string $relationship, bool $isPrimary): Guardian
    {
        return DB::transaction(function () use ($student, $guardianData, $relationship, $isPrimary): Guardian {
            $guardian = Guardian::query()->create($guardianData);
            $this->linkGuardianToStudent->handle($student, $guardian, $relationship, $isPrimary);

            return $guardian;
        }, attempts: 5);
    }
}
