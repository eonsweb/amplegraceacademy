<?php

use App\Actions\Attendance\SaveClassAttendance;
use App\AttendanceStatus;
use App\EnrollmentStatus;
use App\Models\AcademicYear;
use App\Models\Attendance;
use App\Models\ClassLevel;
use App\Models\ClassSubject;
use App\Models\Enrollment;
use App\Models\Term;
use App\Models\User;
use App\Policies\AttendancePolicy;
use App\Support\Authorization\Permissions;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/** @param list<string> $permissions */
function attendanceActor(array $permissions = [Permissions::ATTENDANCE_VIEW, Permissions::ATTENDANCE_RECORD, Permissions::ATTENDANCE_EDIT]): User
{
    $user = User::factory()->create();
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $user->givePermissionTo($permissions);

    return $user;
}

/** @return array{AcademicYear, Term, ClassLevel, Enrollment} */
function attendanceClass(): array
{
    $year = AcademicYear::factory()->create(['is_current' => true]);
    $term = Term::factory()->for($year)->create(['is_current' => true]);
    $class = ClassLevel::factory()->create();
    $enrollment = Enrollment::factory()->for($year)->for($class)->create(['enrollment_date' => today()->subMonth()]);

    return [$year, $term, $class, $enrollment];
}

/** @return array<string, mixed> */
function attendancePayload(Enrollment $enrollment, Term $term): array
{
    return ['academicYearId' => (string) $enrollment->academic_year_id, 'termId' => (string) $term->id, 'classLevelId' => (string) $enrollment->class_level_id, 'attendanceDate' => today()->toDateString(), 'rows' => [['enrollment_id' => $enrollment->id, 'status' => 'present', 'remark' => null]]];
}

test('attendance routes require authentication and view permission', function () {
    $this->get(route('attendance.index'))->assertRedirect(route('home'));
    $this->actingAs(User::factory()->create())->get(route('attendance.index'))->assertForbidden();
    $this->get(route('attendance.history'))->assertForbidden();
    $this->actingAs(attendanceActor([Permissions::ATTENDANCE_VIEW]))->get(route('attendance.index'))->assertSee('Class Attendance');
    $this->get(route('attendance.history'))->assertSee('Attendance History');
});

test('attendance policy maps recording and editing to separate capabilities', function (array $permissions, bool $view, bool $create, bool $edit) {
    $policy = new AttendancePolicy;
    $user = attendanceActor($permissions);
    expect($policy->viewAny($user))->toBe($view);
    expect($policy->create($user))->toBe($create);
    expect($policy->update($user))->toBe($edit);
})->with([
    'none' => [[], false, false, false],
    'viewer' => [[Permissions::ATTENDANCE_VIEW], true, false, false],
    'recorder' => [[Permissions::ATTENDANCE_VIEW, Permissions::ATTENDANCE_RECORD], true, true, false],
    'editor' => [[Permissions::ATTENDANCE_VIEW, Permissions::ATTENDANCE_EDIT], true, false, true],
    'record without view' => [[Permissions::ATTENDANCE_RECORD], false, false, false],
]);

test('class roster defaults to present and excludes other classes years and future enrollments', function () {
    [$year, $term, $class, $enrollment] = attendanceClass();
    Enrollment::factory()->for($year)->create();
    Enrollment::factory()->for($class)->create();
    Enrollment::factory()->for($year)->for($class)->create(['enrollment_date' => today()->addDay()]);
    Enrollment::factory()->for($year)->for($class)->create(['status' => EnrollmentStatus::Withdrawn]);

    Livewire::actingAs(attendanceActor())->test('pages::attendance.class-attendance')
        ->assertSet('academicYearId', (string) $year->id)->assertSet('termId', (string) $term->id)
        ->assertSet('attendanceDate', today()->toDateString())->set('classLevelId', (string) $class->id)
        ->assertSet('rows', [$enrollment->id => ['enrollment_id' => $enrollment->id, 'status' => 'present', 'remark' => null]])
        ->assertSee($enrollment->student->admission_number);
});

test('class attendance saves all statuses reloads existing values and remains idempotent', function () {
    [$year, $term, $class, $enrollment] = attendanceClass();
    $others = Enrollment::factory()->count(3)->for($year)->for($class)->create(['enrollment_date' => today()->subMonth()]);
    $enrollments = collect([$enrollment])->concat($others);
    $component = Livewire::actingAs(attendanceActor())->test('pages::attendance.class-attendance')->set('classLevelId', (string) $class->id);
    foreach ($enrollments as $index => $student) {
        $component->set('rows.'.$student->id.'.status', ['absent', 'late', 'excused', 'present'][$index]);
    }
    $component->set('rows.'.$enrollment->id.'.remark', 'Appointment');
    $this->assertDatabaseCount('attendances', 0);
    $component->call('save')->assertHasNoErrors()->assertSet('message', 'Attendance saved successfully.');
    $component->call('save')->assertHasNoErrors();
    $this->assertDatabaseCount('attendances', 4);
    Livewire::test('pages::attendance.class-attendance')->set('classLevelId', (string) $class->id)
        ->assertSet('rows.'.$enrollment->id.'.status', 'absent')->assertSet('rows.'.$enrollment->id.'.remark', 'Appointment');
});

test('recording users require a class assignment and cannot change saved attendance', function () {
    [$year, $term, $class, $enrollment] = attendanceClass();
    $user = attendanceActor([Permissions::ATTENDANCE_VIEW, Permissions::ATTENDANCE_RECORD]);
    Livewire::actingAs($user)->test('pages::attendance.class-attendance')->set('classLevelId', (string) $class->id)->assertForbidden();
    ClassSubject::factory()->for($year)->for($class)->create(['staff_id' => $user->id]);
    $component = Livewire::test('pages::attendance.class-attendance')->set('classLevelId', (string) $class->id)->call('save')->assertHasNoErrors();
    $component->call('save')->assertHasNoErrors();
    $component->set('rows.'.$enrollment->id.'.status', 'absent')->call('save')->assertForbidden();
    $this->assertDatabaseHas('attendances', ['enrollment_id' => $enrollment->id, 'status' => 'present']);
});

test('edit permission allows corrections but does not allow creating new records', function () {
    [$year, $term, $class, $enrollment] = attendanceClass();
    $user = attendanceActor([Permissions::ATTENDANCE_VIEW, Permissions::ATTENDANCE_EDIT]);
    Livewire::actingAs($user)->test('pages::attendance.class-attendance')->set('classLevelId', (string) $class->id)->call('save')->assertForbidden();
    Attendance::factory()->for($enrollment)->for($term)->create();
    Livewire::test('pages::attendance.class-attendance')->set('classLevelId', (string) $class->id)
        ->set('rows.'.$enrollment->id.'.status', 'excused')->call('save')->assertHasNoErrors();
    $this->assertDatabaseHas('attendances', ['enrollment_id' => $enrollment->id, 'status' => 'excused']);
});

test('server rejects invalid attendance data without persisting it', function (string $key, mixed $value, string $error) {
    [$year, $term, $class, $enrollment] = attendanceClass();
    $payload = attendancePayload($enrollment, $term);
    data_set($payload, $key, $value);
    try {
        app(SaveClassAttendance::class)->handle(attendanceActor(), $payload);
        $this->fail('Invalid attendance was accepted.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($error);
    }
    $this->assertDatabaseCount('attendances', 0);
})->with([
    'status' => ['rows.0.status', 'invalid', 'rows.0.status'],
    'remark length' => ['rows.0.remark', str_repeat('a', 501), 'rows.0.remark'],
    'unknown enrollment' => ['rows.0.enrollment_id', 999999, 'rows'],
    'malformed enrollment' => ['rows.0.enrollment_id', 'oops', 'rows.0.enrollment_id'],
    'unknown year' => ['academicYearId', 999999, 'academicYearId'],
    'unknown term' => ['termId', 999999, 'termId'],
    'unknown class' => ['classLevelId', 999999, 'classLevelId'],
    'invalid date' => ['attendanceDate', 'not-a-date', 'attendanceDate'],
    'future date' => ['attendanceDate', '2999-01-01', 'attendanceDate'],
    'empty rows' => ['rows', [], 'rows'],
    'unexpected row key' => ['rows.0.created_by', 12, 'rows.0'],
]);

test('server rejects a different class enrollment and a term from another year', function () {
    [$year, $term, $class, $enrollment] = attendanceClass();
    $other = Enrollment::factory()->for($year)->create();
    Livewire::actingAs(attendanceActor())->test('pages::attendance.class-attendance')->set('classLevelId', (string) $class->id)
        ->set('rows.'.$enrollment->id.'.enrollment_id', $other->id)->call('save')->assertHasErrors('rows');
    $otherTerm = Term::factory()->create();
    Livewire::test('pages::attendance.class-attendance')->set('termId', (string) $otherTerm->id)
        ->set('classLevelId', (string) $class->id)->assertHasErrors('termId');
    $this->assertDatabaseCount('attendances', 0);
});

test('conflicting terms cannot move an existing attendance record', function () {
    [$year, $term, $class, $enrollment] = attendanceClass();
    $second = Term::factory()->for($year)->second()->create();
    Attendance::factory()->for($enrollment)->for($term)->create();
    $payload = attendancePayload($enrollment, $second);
    expect(fn () => app(SaveClassAttendance::class)->handle(attendanceActor(), $payload))->toThrow(ValidationException::class);
    $this->assertDatabaseHas('attendances', ['enrollment_id' => $enrollment->id, 'term_id' => $term->id]);
});

test('history filters by student admission number year term class dates and status', function () {
    [$year, $term, $class, $enrollment] = attendanceClass();
    $enrollment->student->update(['first_name' => 'Ama', 'middle_name' => null, 'last_name' => 'Mensah']);
    $record = Attendance::factory()->for($enrollment)->for($term)->create(['status' => AttendanceStatus::Absent, 'remark' => '<script>alert(1)</script>']);
    Attendance::factory()->create();
    Livewire::actingAs(attendanceActor())->test('pages::attendance.attendance-history')
        ->set('academicYearId', (string) $year->id)->set('termId', (string) $term->id)->set('classLevelId', (string) $class->id)
        ->set('dateFrom', today()->toDateString())->set('dateTo', today()->toDateString())->set('statusFilter', 'absent')
        ->set('search', 'Ama Mensah')->assertSee($enrollment->student->admission_number)
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSet('attendances', fn ($records): bool => $records->total() === 1 && $records->first()->id === $record->id)
        ->set('search', $enrollment->student->admission_number)->assertSee('Ama Mensah')
        ->set('statusFilter', 'late')->assertSet('attendances', fn ($records): bool => $records->total() === 0);
});

test('history paginates and resets its page when filtering', function () {
    [$year, $term, $class, $enrollment] = attendanceClass();
    foreach (range(1, 26) as $days) {
        Attendance::factory()->for($enrollment)->for($term)->create(['attendance_date' => today()->subDays($days)]);
    }
    Livewire::actingAs(attendanceActor())->test('pages::attendance.attendance-history')
        ->assertSet('attendances', fn ($records): bool => $records->count() === 25 && $records->total() === 26)
        ->call('setPage', 2)->assertSet('attendances', fn ($records): bool => $records->count() === 1)
        ->set('statusFilter', 'present')->assertSet('paginators.page', 1);
});

test('historical attendance remains attached to the original enrollment after transfer', function () {
    [$year, $term, $class, $enrollment] = attendanceClass();
    $record = Attendance::factory()->for($enrollment)->for($term)->create();
    $enrollment->update(['status' => EnrollmentStatus::Transferred]);
    Enrollment::factory()->for($year)->for($enrollment->student)->create();
    expect($record->fresh()->enrollment->class_level_id)->toBe($class->id);
    expect($enrollment->attendances->modelKeys())->toBe([$record->id]);
    expect($term->attendances->modelKeys())->toBe([$record->id]);
    Livewire::actingAs(attendanceActor())->test('pages::attendance.class-attendance')->set('classLevelId', (string) $class->id)
        ->assertSet('rows.'.$enrollment->id.'.status', 'present');
});

test('database prevents duplicate enrollment and date pairs', function () {
    [$year, $term, $class, $enrollment] = attendanceClass();
    Attendance::factory()->for($enrollment)->for($term)->create();
    expect(fn () => Attendance::factory()->for($enrollment)->for($term)->create())->toThrow(QueryException::class);
});

test('saving a large class uses a bounded number of queries and one bulk write', function () {
    [$year, $term, $class, $enrollment] = attendanceClass();
    $others = Enrollment::factory()->count(39)->for($year)->for($class)->create(['enrollment_date' => today()->subMonth()]);
    $payload = attendancePayload($enrollment, $term);
    foreach ($others as $other) {
        $payload['rows'][] = ['enrollment_id' => $other->id, 'status' => 'present', 'remark' => null];
    }
    $user = attendanceActor();
    DB::enableQueryLog();
    DB::flushQueryLog();
    app(SaveClassAttendance::class)->handle($user, $payload);
    $queries = collect(DB::getQueryLog());
    DB::disableQueryLog();
    expect($queries->count())->toBeLessThan(20);
    expect($queries->filter(fn (array $query): bool => str_starts_with($query['query'], 'insert into "attendances"'))->count())->toBe(1);
    $this->assertDatabaseCount('attendances', 40);
});

test('recording users cannot view history outside their assigned class and year', function () {
    [$year, $term, $class, $enrollment] = attendanceClass();
    $record = Attendance::factory()->for($enrollment)->for($term)->create();
    Attendance::factory()->create();
    $user = attendanceActor([Permissions::ATTENDANCE_VIEW, Permissions::ATTENDANCE_RECORD]);
    ClassSubject::factory()->for($year)->for($class)->create(['staff_id' => $user->id]);
    Livewire::actingAs($user)->test('pages::attendance.attendance-history')->assertSet('attendances', fn ($records): bool => $records->total() === 1 && $records->first()->id === $record->id);
});

test('permission migration preserves existing role and direct user grants', function () {
    $user = attendanceActor(['attendance.update']);
    $role = Role::findOrCreate('Attendance supervisor', 'web');
    $role->givePermissionTo('attendance.update');
    $migration = require database_path('migrations/2026_09_08_103201_rename_attendance_update_permission_to_edit.php');
    $migration->up();
    expect($user->fresh()->can(Permissions::ATTENDANCE_EDIT))->toBeTrue();
    expect($role->fresh()->hasPermissionTo(Permissions::ATTENDANCE_EDIT))->toBeTrue();
    $this->assertDatabaseMissing('permissions', ['name' => 'attendance.update']);
});

test('a changed roster cannot silently save a partial class', function () {
    [$year, $term, $class, $enrollment] = attendanceClass();
    $component = Livewire::actingAs(attendanceActor())->test('pages::attendance.class-attendance')->set('classLevelId', (string) $class->id);
    Enrollment::factory()->for($year)->for($class)->create(['enrollment_date' => today()->subDay()]);

    $component->call('save')->assertHasErrors('rows');

    $this->assertDatabaseCount('attendances', 0);
});

test('a concurrent recording cannot be overwritten by a recording only user', function () {
    [$year, $term, $class, $enrollment] = attendanceClass();
    $user = attendanceActor([Permissions::ATTENDANCE_VIEW, Permissions::ATTENDANCE_RECORD]);
    ClassSubject::factory()->for($year)->for($class)->create(['staff_id' => $user->id]);
    $component = Livewire::actingAs($user)->test('pages::attendance.class-attendance')->set('classLevelId', (string) $class->id);
    Attendance::factory()->for($enrollment)->for($term)->create(['status' => AttendanceStatus::Absent]);

    $component->call('save')->assertForbidden();

    $this->assertDatabaseHas('attendances', ['enrollment_id' => $enrollment->id, 'status' => 'absent']);
});

test('history loads relationships without per student queries', function () {
    [$year, $term, $class, $enrollment] = attendanceClass();
    $others = Enrollment::factory()->count(9)->for($year)->for($class)->create();
    foreach (collect([$enrollment])->concat($others) as $student) {
        Attendance::factory()->for($student)->for($term)->create();
    }
    $user = attendanceActor();
    $this->actingAs($user);
    DB::enableQueryLog();
    DB::flushQueryLog();

    $this->get(route('attendance.history'))->assertSee($enrollment->student->admission_number);

    $studentQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'from "students"'));
    DB::disableQueryLog();
    expect($studentQueries->count())->toBeLessThanOrEqual(2);
});

test('permission migration merges grants when the edit permission already exists', function () {
    $user = attendanceActor(['attendance.update']);
    $editor = attendanceActor([Permissions::ATTENDANCE_EDIT]);
    $migration = require database_path('migrations/2026_09_08_103201_rename_attendance_update_permission_to_edit.php');

    $migration->up();

    expect($user->fresh()->can(Permissions::ATTENDANCE_EDIT))->toBeTrue();
    expect($editor->fresh()->can(Permissions::ATTENDANCE_EDIT))->toBeTrue();
    $this->assertDatabaseMissing('permissions', ['name' => 'attendance.update']);
});

test('each history filter independently excludes nonmatching attendance', function (string $filter, string $value) {
    $this->freezeTime();
    [$year, $term, $class, $enrollment] = attendanceClass();
    Attendance::factory()->for($enrollment)->for($term)->create();

    Livewire::actingAs(attendanceActor())->test('pages::attendance.attendance-history')
        ->assertSet('attendances', fn ($records): bool => $records->total() === 1)
        ->set($filter, $value)
        ->assertSet('attendances', fn ($records): bool => $records->total() === 0)
        ->call('resetFilters')
        ->assertSet('attendances', fn ($records): bool => $records->total() === 1);
})->with([
    'year' => ['academicYearId', '999999'],
    'term' => ['termId', '999999'],
    'class' => ['classLevelId', '999999'],
    'student' => ['search', 'NoMatchingStudent999999'],
    'from date' => ['dateFrom', '2999-01-01'],
    'to date' => ['dateTo', '1900-01-01'],
    'status' => ['statusFilter', 'absent'],
]);

test('removing a class assignment prevents saving an already loaded roster', function () {
    [$year, $term, $class, $enrollment] = attendanceClass();
    $user = attendanceActor([Permissions::ATTENDANCE_VIEW, Permissions::ATTENDANCE_RECORD]);
    $assignment = ClassSubject::factory()->for($year)->for($class)->create(['staff_id' => $user->id]);
    $component = Livewire::actingAs($user)->test('pages::attendance.class-attendance')
        ->set('classLevelId', (string) $class->id);
    $assignment->delete();

    $component->call('save')->assertForbidden();

    $this->assertDatabaseCount('attendances', 0);
});

test('loading a class roster batches student and existing attendance queries', function () {
    [$year, $term, $class, $enrollment] = attendanceClass();
    $others = Enrollment::factory()->count(39)->for($year)->for($class)
        ->create(['enrollment_date' => today()->subMonth()]);
    Attendance::factory()->for($enrollment)->for($term)->create(['status' => AttendanceStatus::Late]);
    $component = Livewire::actingAs(attendanceActor())->test('pages::attendance.class-attendance');
    DB::enableQueryLog();
    DB::flushQueryLog();

    try {
        $component->set('classLevelId', (string) $class->id)
            ->assertSet('rows.'.$enrollment->id.'.status', 'late')
            ->assertSet('rows.'.$others->last()->id.'.status', 'present');
        $queries = collect(DB::getQueryLog());
    } finally {
        DB::disableQueryLog();
    }

    expect($queries->filter(fn (array $query): bool => str_starts_with($query['query'], 'select') && str_contains($query['query'], 'from "students"'))->count())->toBe(1);
    expect($queries->filter(fn (array $query): bool => str_starts_with($query['query'], 'select * from "attendances"'))->count())->toBe(1);
    $this->assertDatabaseCount('attendances', 1);
});
