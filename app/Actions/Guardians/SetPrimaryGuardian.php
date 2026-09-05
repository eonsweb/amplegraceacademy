<?php

namespace App\Actions\Guardians;

use App\Models\Student;
use App\Models\StudentGuardian;
use Illuminate\Support\Facades\DB;

class SetPrimaryGuardian
{
    public function handle(StudentGuardian $studentGuardian): void
    {
        DB::transaction(function () use ($studentGuardian): void {
            Student::query()->whereKey($studentGuardian->student_id)->lockForUpdate()->firstOrFail();

            StudentGuardian::query()
                ->where('student_id', $studentGuardian->student_id)
                ->where('is_primary', true)
                ->update(['is_primary' => false]);

            StudentGuardian::query()->whereKey($studentGuardian->id)->update(['is_primary' => true]);
        }, attempts: 5);
    }
}
