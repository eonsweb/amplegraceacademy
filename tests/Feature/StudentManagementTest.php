<?php

use App\Actions\Students\CreateStudent;
use App\EnrollmentStatus;
use App\Gender;
use App\Models\AcademicYear;
use App\Models\ClassLevel;
use App\Models\Enrollment;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use App\StudentStatus;
use App\Support\Authorization\Permissions;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
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
    return [AcademicYear::factory()->create(['is_current' => true]), ClassLevel::factory()->create()];
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

test('a student is created atomically with placement and multiple guardians', function (?string $middleName) {
    [$year, $level] = studentFixture();
    $creator = app(CreateStudent::class);

    $student = $creator->handle(
        ['admission_number' => 'AGA-001', 'first_name' => 'Ama', 'middle_name' => $middleName, 'last_name' => 'Mensah', 'gender' => Gender::Female, 'date_of_birth' => '2015-02-10', 'status' => StudentStatus::Active],
        ['academic_year_id' => $year->id, 'class_level_id' => $level->id, 'enrollment_date' => '2026-09-01', 'status' => EnrollmentStatus::Active],
        [
            ['guardian_id' => null, 'data' => ['first_name' => 'Akosua', 'last_name' => 'Mensah', 'relationship' => 'Mother', 'phone' => '0200000001'], 'is_primary' => true],
            ['guardian_id' => null, 'data' => ['first_name' => 'Kwame', 'last_name' => 'Mensah', 'relationship' => 'Father', 'phone' => '0200000002'], 'is_primary' => false],
        ],
    );

    expect($student->middle_name)->toBe($middleName)
        ->and($student->guardians)->toHaveCount(2)
        ->and($student->primaryGuardians()->first()->phone)->toBe('0200000001');
    $this->assertDatabaseHas('enrollments', ['student_id' => $student->id, 'academic_year_id' => $year->id, 'class_level_id' => $level->id]);
})->with(['middle name' => 'Efua', 'no middle name' => null]);

test('an existing guardian can be shared by siblings without duplication', function () {
    [$year, $level] = studentFixture();
    $guardian = Guardian::factory()->create();
    $creator = app(CreateStudent::class);

    foreach (['AGA-101', 'AGA-102'] as $admissionNumber) {
        $creator->handle(
            ['admission_number' => $admissionNumber, 'first_name' => 'Child', 'last_name' => $admissionNumber, 'gender' => Gender::Male, 'status' => StudentStatus::Active],
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
        ['admission_number' => 'AGA-ROLLBACK', 'first_name' => 'Kojo', 'last_name' => 'Boateng', 'gender' => Gender::Male, 'status' => StudentStatus::Active],
        ['academic_year_id' => $year->id, 'class_level_id' => 999999, 'enrollment_date' => '2026-09-01', 'status' => EnrollmentStatus::Active],
        [['guardian_id' => null, 'data' => ['first_name' => 'Esi', 'last_name' => 'Boateng', 'relationship' => 'Mother', 'phone' => '0240000000'], 'is_primary' => true]],
    ))->toThrow(QueryException::class);

    $this->assertDatabaseMissing('students', ['admission_number' => 'AGA-ROLLBACK']);
    $this->assertDatabaseMissing('guardians', ['phone' => '0240000000']);
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

test('duplicate admission number is reported by the create workflow', function () {
    [$year, $level] = studentFixture();
    Student::factory()->create(['admission_number' => 'AGA-USED']);
    $this->actingAs(studentActor([Permissions::STUDENTS_CREATE, Permissions::GUARDIANS_CREATE]));

    Livewire::test('pages::students.create')
        ->set('admissionNumber', 'AGA-USED')->set('firstName', 'Ama')->set('lastName', 'Mensah')->set('gender', Gender::Female->value)
        ->set('academicYearId', (string) $year->id)->set('classLevelId', (string) $level->id)
        ->set('guardians.0.first_name', 'Akosua')->set('guardians.0.last_name', 'Mensah')->set('guardians.0.relationship', 'Mother')->set('guardians.0.phone', '0200000001')
        ->call('save')->assertHasErrors(['admissionNumber' => 'unique']);
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

test('student photos are stored on the public disk', function () {
    Storage::fake('public');
    [$year, $level] = studentFixture();
    $this->actingAs(studentActor([Permissions::STUDENTS_CREATE, Permissions::GUARDIANS_CREATE]));

    Livewire::test('pages::students.create')
        ->set('admissionNumber', 'AGA-PHOTO')->set('firstName', 'Ama')->set('lastName', 'Mensah')->set('gender', Gender::Female->value)
        ->set('academicYearId', (string) $year->id)->set('classLevelId', (string) $level->id)->set('photo', UploadedFile::fake()->image('student.jpg'))
        ->set('guardians.0.first_name', 'Akosua')->set('guardians.0.last_name', 'Mensah')->set('guardians.0.relationship', 'Mother')->set('guardians.0.phone', '0200000001')
        ->call('save')->assertHasNoErrors();

    $photo = Student::query()->where('admission_number', 'AGA-PHOTO')->value('photo');
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
