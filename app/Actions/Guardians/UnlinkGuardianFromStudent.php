<?php

namespace App\Actions\Guardians;

use App\Models\Student;
use App\Models\StudentGuardian;
use Illuminate\Support\Facades\DB;

class UnlinkGuardianFromStudent
{
    public function handle(StudentGuardian $studentGuardian): void
    {
        DB::transaction(function () use ($studentGuardian): void {
            Student::query()->whereKey($studentGuardian->student_id)->lockForUpdate()->firstOrFail();
            StudentGuardian::query()->whereKey($studentGuardian->id)->delete();
        }, attempts: 5);
    }
}
