<?php

use App\Actions\Students\CreateStudent;
use App\EnrollmentStatus;
use App\Gender;
use App\Models\AcademicYear;
use App\Models\ClassLevel;
use App\Models\Enrollment;
use App\Models\Guardian;
use App\Models\SchoolSetting;
use App\Models\Student;
use App\Models\User;
use App\StudentStatus;
use App\Support\Authorization\Permissions;
use App\Support\Settings\SystemSettings;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

function studentActor(array $permissions): User
{
    $user = User::factory()->create();
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission);
    }
    $user->givePermissionTo($permissions);

    return $user;
}

function studentFixture(): array
{
    app(SystemSettings::class)->update(['school_initials' => 'AGA']);

    return [AcademicYear::factory()->create(['is_current' => true]), ClassLevel::factory()->create()];
}

function admitStudent(AcademicYear $year, ClassLevel $level, array $studentData = []): Student
{
    return app(CreateStudent::class)->handle(
        array_replace(['first_name' => 'Ama', 'last_name' => 'Mensah', 'gender' => Gender::Female, 'status' => StudentStatus::Active], $studentData),
        ['academic_year_id' => $year->id, 'class_level_id' => $level->id, 'enrollment_date' => now()->toDateString(), 'status' => EnrollmentStatus::Active],
        [['guardian_id' => null, 'data' => ['first_name' => 'Akosua', 'last_name' => 'Mensah', 'relationship' => 'Mother', 'phone' => fake()->unique()->phoneNumber()], 'is_primary' => true]],
    );
}

test('student routes enforce permissions', function () {
    $student = Student::factory()->create();
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('students.index'))->assertForbidden();
    $this->actingAs($user)->get(route('students.create'))->assertForbidden();
    $this->actingAs($user)->get(route('students.show', $student))->assertForbidden();
    $this->actingAs($user)->get(route('students.edit', $student))->assertForbidden();

    $viewer = studentActor([Permissions::STUDENTS_VIEW]);
    $this->actingAs($viewer)->get(route('students.index'))->assertOk();
    $this->actingAs($viewer)->get(route('students.show', $student))->assertOk();
});

test('a student is created atomically with a generated admission number, placement, and multiple guardians', function (?string $middleName) {
    $this->travelTo('2026-09-04 10:00:00');
    [$year, $level] = studentFixture();
    $creator = app(CreateStudent::class);

    $student = $creator->handle(
        ['first_name' => 'Ama', 'middle_name' => $middleName, 'last_name' => 'Mensah', 'gender' => Gender::Female, 'date_of_birth' => '2015-02-10', 'status' => StudentStatus::Active],
        ['academic_year_id' => $year->id, 'class_level_id' => $level->id, 'enrollment_date' => '2026-09-01', 'status' => EnrollmentStatus::Active],
        [
            ['guardian_id' => null, 'data' => ['first_name' => 'Akosua', 'last_name' => 'Mensah', 'relationship' => 'Mother', 'phone' => '0200000001'], 'is_primary' => true],
            ['guardian_id' => null, 'data' => ['first_name' => 'Kwame', 'last_name' => 'Mensah', 'relationship' => 'Father', 'phone' => '0200000002'], 'is_primary' => false],
        ],
    );

    expect($student->admission_number)->toBe('AGA/2026/0001')
        ->and($student->middle_name)->toBe($middleName)
        ->and($student->date_of_birth?->toDateString())->toBe('2015-02-10')
        ->and($student->guardians)->toHaveCount(2)
        ->and($student->primaryGuardians()->first()->phone)->toBe('0200000001');
    $this->assertDatabaseHas('enrollments', ['student_id' => $student->id, 'academic_year_id' => $year->id, 'class_level_id' => $level->id]);
})->with(['middle name' => 'Efua', 'no middle name' => null]);

test('an existing guardian can be shared by siblings without duplication', function () {
    [$year, $level] = studentFixture();
    $guardian = Guardian::factory()->create();
    $creator = app(CreateStudent::class);

    foreach (['First', 'Second'] as $lastName) {
        $creator->handle(
            ['first_name' => 'Child', 'last_name' => $lastName, 'gender' => Gender::Male, 'status' => StudentStatus::Active],
            ['academic_year_id' => $year->id, 'class_level_id' => $level->id, 'enrollment_date' => '2026-09-01', 'status' => EnrollmentStatus::Active],
            [['guardian_id' => $guardian->id, 'data' => [], 'is_primary' => true]],
        );
    }

    expect(Guardian::query()->count())->toBe(1)->and($guardian->students()->count())->toBe(2);
});

test('duplicate admission numbers and guardian links are rejected', function () {
    $student = Student::factory()->create(['admission_number' => 'AGA-DUP']);
    expect(fn () => Student::factory()->create(['admission_number' => 'AGA-DUP']))->toThrow(QueryException::class);

    $guardian = Guardian::factory()->create();
    $student->guardians()->attach($guardian, ['is_primary' => true]);
    expect(fn () => $student->guardians()->attach($guardian))->toThrow(QueryException::class);
});

test('a failed placement rolls back the student and newly created guardian', function () {
    [$year] = studentFixture();

    expect(fn () => app(CreateStudent::class)->handle(
        ['first_name' => 'Kojo', 'last_name' => 'Boateng', 'gender' => Gender::Male, 'status' => StudentStatus::Active],
        ['academic_year_id' => $year->id, 'class_level_id' => 999999, 'enrollment_date' => '2026-09-01', 'status' => EnrollmentStatus::Active],
        [['guardian_id' => null, 'data' => ['first_name' => 'Esi', 'last_name' => 'Boateng', 'relationship' => 'Mother', 'phone' => '0240000000'], 'is_primary' => true]],
    ))->toThrow(QueryException::class);

    expect(Student::query()->count())->toBe(0);
    $this->assertDatabaseMissing('guardians', ['phone' => '0240000000']);
    $this->assertDatabaseMissing('admission_number_sequences', ['key' => 'student_admission']);
});

test('admission generation fails clearly when school initials are missing', function () {
    SchoolSetting::factory()->create(['id' => 1, 'school_initials' => null]);
    app(SystemSettings::class)->forget();
    $year = AcademicYear::factory()->create();
    $level = ClassLevel::factory()->create();
    $this->actingAs(studentActor([Permissions::STUDENTS_CREATE, Permissions::GUARDIANS_CREATE]));

    Livewire::test('pages::students.create')
        ->set('firstName', 'Ama')->set('lastName', 'Mensah')->set('gender', Gender::Female->value)
        ->set('academicYearId', (string) $year->id)->set('classLevelId', (string) $level->id)
        ->set('guardians.0.first_name', 'Akosua')->set('guardians.0.last_name', 'Mensah')->set('guardians.0.relationship', 'Mother')->set('guardians.0.phone', '0200000001')
        ->call('save')
        ->assertHasErrors(['admissionNumber'])
        ->assertSee('School initials have not been configured');

    expect(Student::query()->exists())->toBeFalse();
});

test('the create form shows a non reserving generated admission preview', function () {
    $this->travelTo('2026-09-04 10:00:00');
    studentFixture();
    $this->actingAs(studentActor([Permissions::STUDENTS_CREATE]));

    Livewire::test('pages::students.create')
        ->assertSet('admissionNumberPreview', 'AGA/2026/XXXX')
        ->assertSee('Generated automatically');

    expect(DB::table('admission_number_sequences')->exists())->toBeFalse();
});

test('the locked year sequence generates unique zero padded admission numbers', function () {
    $this->travelTo('2026-09-04 10:00:00');
    [$year, $level] = studentFixture();

    $students = collect(range(1, 5))->map(fn (int $number): Student => admitStudent($year, $level, ['first_name' => 'Pupil '.$number]));

    expect($students->pluck('admission_number')->all())->toBe([
        'AGA/2026/0001', 'AGA/2026/0002', 'AGA/2026/0003', 'AGA/2026/0004', 'AGA/2026/0005',
    ])->and($students->pluck('admission_number')->unique())->toHaveCount(5);
    $this->assertDatabaseHas('admission_number_sequences', ['key' => 'student_admission', 'year' => 2026, 'current_value' => 5]);
});

test('admission sequences reset for a new calendar year', function () {
    $this->travelTo('2026-12-31 10:00:00');
    [$year, $level] = studentFixture();
    $firstStudent = admitStudent($year, $level);

    $this->travelTo('2027-01-01 10:00:00');
    $secondStudent = admitStudent($year, $level);

    expect($firstStudent->admission_number)->toBe('AGA/2026/0001')
        ->and($secondStudent->admission_number)->toBe('AGA/2027/0001');
});

test('changing school initials affects only newly admitted students', function () {
    $this->travelTo('2026-09-04 10:00:00');
    [$year, $level] = studentFixture();
    $existingStudent = admitStudent($year, $level);

    app(SystemSettings::class)->update(['school_initials' => 'BFAS']);
    $newStudent = admitStudent($year, $level);

    expect($existingStudent->fresh()->admission_number)->toBe('AGA/2026/0001')
        ->and($newStudent->admission_number)->toBe('BFAS/2026/0002');
});

test('a student cannot have two active enrollments in one academic year', function () {
    [$year, $level] = studentFixture();
    $student = Student::factory()->create();
    Enrollment::factory()->create(['student_id' => $student, 'academic_year_id' => $year, 'class_level_id' => $level]);

    expect(fn () => Enrollment::factory()->create([
        'student_id' => $student,
        'academic_year_id' => $year,
        'class_level_id' => ClassLevel::factory(),
    ]))->toThrow(ValidationException::class, 'already has an active enrollment');
});

test('the directory searches and filters students', function () {
    [$year, $level] = studentFixture();
    $otherLevel = ClassLevel::factory()->create();
    $matching = Student::factory()->create(['admission_number' => 'AGA-FIND', 'first_name' => 'Unique', 'gender' => Gender::Female, 'status' => StudentStatus::Active]);
    $hidden = Student::factory()->create(['admission_number' => 'AGA-HIDE', 'first_name' => 'Other', 'gender' => Gender::Male]);
    Enrollment::factory()->create(['student_id' => $matching, 'academic_year_id' => $year, 'class_level_id' => $level]);
    Enrollment::factory()->create(['student_id' => $hidden, 'academic_year_id' => $year, 'class_level_id' => $otherLevel]);
    $this->actingAs(studentActor([Permissions::STUDENTS_VIEW]));

    Livewire::test('pages::students.index')
        ->set('search', 'AGA-FIND')->set('classLevelFilter', (string) $level->id)->set('genderFilter', Gender::Female->value)
        ->assertSee('Unique')->assertDontSee('Other');
});

test('browser supplied admission numbers cannot override generated values', function () {
    $this->travelTo('2026-09-04 10:00:00');
    [$year, $level] = studentFixture();
    $student = app(CreateStudent::class)->handle(
        ['admission_number' => 'HACKED/2099/9999', 'age' => 99, 'first_name' => 'Ama', 'last_name' => 'Mensah', 'gender' => Gender::Female, 'status' => StudentStatus::Active],
        ['academic_year_id' => $year->id, 'class_level_id' => $level->id, 'enrollment_date' => '2026-09-01', 'status' => EnrollmentStatus::Active],
        [['guardian_id' => null, 'data' => ['first_name' => 'Akosua', 'last_name' => 'Mensah', 'relationship' => 'Mother', 'phone' => '0200000001'], 'is_primary' => true]],
    );

    expect($student->admission_number)->toBe('AGA/2026/0001');
});

test('editing personal and guardian details preserves enrollment history and admission number', function () {
    [$year, $level] = studentFixture();
    $student = Student::factory()->create(['admission_number' => 'AGA-KEEP', 'first_name' => 'Old']);
    $guardian = Guardian::factory()->create(['phone' => '111']);
    $student->guardians()->attach($guardian, ['is_primary' => true]);
    $enrollment = Enrollment::factory()->create(['student_id' => $student, 'academic_year_id' => $year, 'class_level_id' => $level]);
    $this->actingAs(studentActor([Permissions::STUDENTS_UPDATE, Permissions::GUARDIANS_UPDATE]));

    Livewire::test('pages::students.edit', ['student' => $student])
        ->set('firstName', 'New')->set('guardians.0.phone', '222')->call('save')->assertHasNoErrors();

    expect($student->fresh()->first_name)->toBe('New')->and($student->admission_number)->toBe('AGA-KEEP')
        ->and($guardian->fresh()->phone)->toBe('222')->and($enrollment->fresh()->class_level_id)->toBe($level->id)
        ->and($student->enrollments()->count())->toBe(1);
});

test('a later enrollment does not change the permanent admission number', function () {
    $this->travelTo('2026-09-04 10:00:00');
    [$year, $level] = studentFixture();
    $student = admitStudent($year, $level);
    $student->enrollments()->firstOrFail()->update(['status' => EnrollmentStatus::Promoted]);
    $nextYear = AcademicYear::factory()->create();

    Enrollment::factory()->create(['student_id' => $student, 'academic_year_id' => $nextYear, 'class_level_id' => ClassLevel::factory()]);

    expect($student->fresh()->admission_number)->toBe('AGA/2026/0001')
        ->and($student->enrollments()->count())->toBe(2);
});

test('age is calculated in completed years and is never stored', function (string $dateOfBirth, ?int $expectedAge) {
    $this->travelTo('2026-09-04 10:00:00');
    $student = Student::factory()->create(['date_of_birth' => $dateOfBirth ?: null]);

    expect($student->age())->toBe($expectedAge)
        ->and(Schema::hasColumn('students', 'age'))->toBeFalse();
})->with([
    'birthday has occurred' => ['2018-05-14', 8],
    'birthday has not occurred' => ['2018-12-10', 7],
    'date of birth is missing' => ['', null],
]);

test('future dates of birth are rejected by the create workflow', function () {
    [$year, $level] = studentFixture();
    $this->actingAs(studentActor([Permissions::STUDENTS_CREATE, Permissions::GUARDIANS_CREATE]));

    Livewire::test('pages::students.create')
        ->set('firstName', 'Ama')->set('lastName', 'Mensah')->set('gender', Gender::Female->value)
        ->set('dateOfBirth', now()->addDay()->toDateString())
        ->set('academicYearId', (string) $year->id)->set('classLevelId', (string) $level->id)
        ->set('guardians.0.first_name', 'Akosua')->set('guardians.0.last_name', 'Mensah')->set('guardians.0.relationship', 'Mother')->set('guardians.0.phone', '0200000001')
        ->call('save')
        ->assertHasErrors(['dateOfBirth']);
});

test('student profile displays the date of birth and calculated age', function () {
    $this->travelTo('2026-09-04 10:00:00');
    $student = Student::factory()->create(['date_of_birth' => '2018-05-14']);
    $this->actingAs(studentActor([Permissions::STUDENTS_VIEW]));

    Livewire::test('pages::students.show', ['student' => $student])
        ->assertSee('14 May 2018')
        ->assertSee('8 years');
});

test('student photos are stored on the public disk', function () {
    Storage::fake('public');
    [$year, $level] = studentFixture();
    $this->actingAs(studentActor([Permissions::STUDENTS_CREATE, Permissions::GUARDIANS_CREATE]));

    Livewire::test('pages::students.create')
        ->set('firstName', 'Ama')->set('lastName', 'Mensah')->set('gender', Gender::Female->value)
        ->set('academicYearId', (string) $year->id)->set('classLevelId', (string) $level->id)->set('photo', UploadedFile::fake()->image('student.jpg'))
        ->set('guardians.0.first_name', 'Akosua')->set('guardians.0.last_name', 'Mensah')->set('guardians.0.relationship', 'Mother')->set('guardians.0.phone', '0200000001')
        ->call('save')->assertHasNoErrors();

    $photo = Student::query()->where('first_name', 'Ama')->value('photo');
    expect($photo)->not->toBeNull();
    Storage::disk('public')->assertExists($photo);
});

test('livewire actions reauthorize forged requests', function () {
    studentFixture();
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::students.create')->assertForbidden();
});

test('guardian identifiers cannot be forged while editing a student', function () {
    $student = Student::factory()->create();
    $attachedGuardian = Guardian::factory()->create();
    $otherGuardian = Guardian::factory()->create();
    $student->guardians()->attach($attachedGuardian, ['is_primary' => true]);
    $this->actingAs(studentActor([Permissions::STUDENTS_UPDATE, Permissions::GUARDIANS_UPDATE]));

    Livewire::test('pages::students.edit', ['student' => $student])
        ->set('guardians.0.guardian_id', $otherGuardian->id)
        ->call('save')
        ->assertHasErrors(['guardians.0.guardian_id']);

    expect($otherGuardian->fresh()->phone)->toBe($otherGuardian->phone);
});

test('attached guardians cannot be omitted from a forged edit payload', function () {
    $student = Student::factory()->create();
    $guardian = Guardian::factory()->create();
    $student->guardians()->attach($guardian, ['is_primary' => true]);
    $this->actingAs(studentActor([Permissions::STUDENTS_UPDATE, Permissions::GUARDIANS_UPDATE]));

    Livewire::test('pages::students.edit', ['student' => $student])
        ->set('guardians', [])
        ->call('save')
        ->assertHasErrors(['guardians.0.mode', 'guardians.0.guardian_id']);

    expect($student->guardians()->whereKey($guardian->id)->exists())->toBeTrue();
});
