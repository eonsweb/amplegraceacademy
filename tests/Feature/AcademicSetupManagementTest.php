<?php

use App\Models\AcademicYear;
use App\Models\ClassLevel;
use App\Models\ClassSubject;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use App\Support\Academic\AcademicContext;
use App\Support\Authorization\Permissions;
use App\Support\Authorization\Roles;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    app(AcademicContext::class)->forget();
});

function academicUser(array $permissions): User
{
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission);
    }

    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

test('creating a current academic year creates the three standard terms and selects the first term', function () {
    $user = academicUser([Permissions::CLASSES_VIEW, Permissions::CLASSES_CREATE, Permissions::CLASSES_UPDATE]);

    Livewire::actingAs($user)->test('pages::academic.years.index')
        ->set('name', '2026/2027')->set('isCurrent', true)
        ->call('save')->assertHasNoErrors();

    $year = AcademicYear::query()->sole();

    expect($year->name)->toBe('2026/2027')
        ->and($year->is_current)->toBeTrue()
        ->and($year->terms()->orderBy('term_order')->pluck('name', 'term_order')->all())->toBe([
            1 => '1st Term',
            2 => '2nd Term',
            3 => '3rd Term',
        ])
        ->and($year->terms()->where('is_current', true)->sole()->name)->toBe('1st Term')
        ->and(app(AcademicContext::class)->currentYearId())->toBe($year->id);
});

test('invalid academic year formats are rejected', function (string $name) {
    $user = academicUser([Permissions::CLASSES_VIEW, Permissions::CLASSES_CREATE]);

    Livewire::actingAs($user)->test('pages::academic.years.index')
        ->set('name', $name)
        ->call('save')
        ->assertHasErrors(['name']);

    expect(AcademicYear::query()->exists())->toBeFalse();
})->with([
    'single year' => '2026',
    'hyphen separated' => '2026-2027',
    'short second year' => '2026/28',
    'non-consecutive years' => '2026/2028',
    'date' => '01/09/2026',
    'words' => 'September 2026',
]);

test('duplicate academic year names are rejected', function () {
    AcademicYear::factory()->create(['name' => '2026/2027']);
    $user = academicUser([Permissions::CLASSES_VIEW, Permissions::CLASSES_CREATE]);

    Livewire::actingAs($user)->test('pages::academic.years.index')
        ->set('name', '2026/2027')
        ->call('save')
        ->assertHasErrors(['name']);
});

test('changing the current academic year clears the previous current term', function () {
    $firstYear = AcademicYear::factory()->create(['name' => '2026/2027', 'is_current' => true]);
    $currentTerm = Term::factory()->create(['academic_year_id' => $firstYear->id, 'is_current' => true]);
    $secondYear = AcademicYear::factory()->create(['name' => '2027/2028']);
    $user = academicUser([Permissions::CLASSES_VIEW, Permissions::CLASSES_UPDATE]);

    Livewire::actingAs($user)->test('pages::academic.years.index')
        ->call('makeCurrent', $secondYear->id)
        ->assertHasNoErrors();

    expect(AcademicYear::query()->where('is_current', true)->count())->toBe(1)
        ->and($firstYear->fresh()->is_current)->toBeNull()
        ->and($secondYear->fresh()->is_current)->toBeTrue()
        ->and($currentTerm->fresh()->is_current)->toBeNull()
        ->and(Term::query()->where('is_current', true)->exists())->toBeFalse();
});

test('only one term in the current academic year can be current', function () {
    $year = AcademicYear::factory()->create(['is_current' => true]);
    $firstTerm = Term::factory()->create(['academic_year_id' => $year->id, 'is_current' => true]);
    $secondTerm = Term::factory()->second()->create(['academic_year_id' => $year->id]);
    $user = academicUser([Permissions::CLASSES_VIEW, Permissions::CLASSES_UPDATE]);

    Livewire::actingAs($user)->test('pages::academic.terms.index')
        ->set('academicYearId', $year->id)
        ->call('makeCurrent', $secondTerm->id)
        ->assertHasNoErrors();

    expect($firstTerm->fresh()->is_current)->toBeNull()
        ->and($secondTerm->fresh()->is_current)->toBeTrue()
        ->and(Term::query()->where('is_current', true)->count())->toBe(1);
});

test('a term from a non-current academic year cannot be made current', function () {
    $firstYear = AcademicYear::factory()->create(['is_current' => true]);
    $secondYear = AcademicYear::factory()->create();
    $currentTerm = Term::factory()->create(['academic_year_id' => $firstYear->id, 'is_current' => true]);
    $newTerm = Term::factory()->create(['academic_year_id' => $secondYear->id]);
    $user = academicUser([Permissions::CLASSES_VIEW, Permissions::CLASSES_UPDATE]);

    Livewire::actingAs($user)->test('pages::academic.terms.index')
        ->set('academicYearId', $secondYear->id)
        ->call('makeCurrent', $newTerm->id)
        ->assertHasErrors(['term']);

    expect(AcademicYear::query()->where('is_current', true)->sole()->is($firstYear))->toBeTrue()
        ->and(Term::query()->where('is_current', true)->sole()->is($currentTerm))->toBeTrue();
});

test('structural terms cannot be added, renamed, reordered, or deleted', function () {
    $year = AcademicYear::factory()->create();
    $term = Term::factory()->create(['academic_year_id' => $year->id]);

    expect(fn () => Term::query()->create([
        'academic_year_id' => $year->id,
        'name' => '4th Term',
        'term_order' => 4,
    ]))->toThrow(LogicException::class)
        ->and(fn () => $term->update(['name' => 'Summer Term']))->toThrow(LogicException::class)
        ->and(fn () => $term->update(['term_order' => 2]))->toThrow(LogicException::class)
        ->and(fn () => $term->delete())->toThrow(LogicException::class);

    expect($term->fresh()->only(['name', 'term_order']))->toBe(['name' => '1st Term', 'term_order' => 1]);
});

test('class levels can be created with progression order and description', function () {
    $user = academicUser([Permissions::CLASSES_VIEW, Permissions::CLASSES_CREATE]);

    Livewire::actingAs($user)->test('pages::academic.class-levels.index')
        ->set('name', 'Primary 5')
        ->set('levelOrder', 9)
        ->set('description', 'Fifth primary level')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('class_levels', [
        'name' => 'Primary 5',
        'level_order' => 9,
        'description' => 'Fifth primary level',
    ]);
});

test('class levels are displayed in progression order instead of alphabetically', function () {
    ClassLevel::factory()->create(['name' => 'Primary 1', 'level_order' => 3]);
    ClassLevel::factory()->create(['name' => 'KG 2', 'level_order' => 2]);
    ClassLevel::factory()->create(['name' => 'Nursery 1', 'level_order' => 1]);
    $user = academicUser([Permissions::CLASSES_VIEW]);

    $this->actingAs($user)->get(route('academic.class-levels.index'))
        ->assertSeeInOrder(['Nursery 1', 'KG 2', 'Primary 1']);
});

test('class levels with subject assignments cannot be deleted', function () {
    $level = ClassLevel::factory()->create();
    ClassSubject::factory()->create(['class_level_id' => $level->id]);
    $user = academicUser([Permissions::CLASSES_VIEW, Permissions::CLASSES_DELETE]);

    Livewire::actingAs($user)->test('pages::academic.class-levels.index')
        ->call('delete', $level->id)->assertHasErrors(['level']);

    expect($level->fresh())->not->toBeNull();
});

test('subjects are deactivated without removing existing assignments', function () {
    $subject = Subject::factory()->create();
    ClassSubject::factory()->create(['subject_id' => $subject->id]);
    $user = academicUser([Permissions::SUBJECTS_VIEW, Permissions::SUBJECTS_UPDATE]);

    Livewire::actingAs($user)->test('pages::academic.subjects.index')->call('toggle', $subject->id)->assertHasNoErrors();

    expect($subject->fresh()->is_active)->toBeFalse()
        ->and(ClassSubject::query()->where('subject_id', $subject->id)->exists())->toBeTrue();
});

test('class subject changes are saved as one bounded batch', function () {
    Role::findOrCreate(Roles::TEACHER);
    $teacher = User::factory()->create();
    $teacher->assignRole(Roles::TEACHER);
    $year = AcademicYear::factory()->create(['is_current' => true]);
    $level = ClassLevel::factory()->create();
    [$mathematics, $science, $history] = Subject::factory()->count(3)->create()->values()->all();
    ClassSubject::factory()->create(['academic_year_id' => $year->id, 'class_level_id' => $level->id, 'subject_id' => $history->id]);
    $user = academicUser([Permissions::CLASSES_VIEW, Permissions::SUBJECTS_VIEW, Permissions::CLASSES_UPDATE]);

    Livewire::actingAs($user)->test('pages::academic.class-subjects.index')
        ->set('academicYearId', $year->id)->set('classLevelId', $level->id)
        ->call('saveAssignments', [
            ['subject_id' => $mathematics->id, 'assigned' => true, 'staff_id' => $teacher->id],
            ['subject_id' => $science->id, 'assigned' => true, 'staff_id' => null],
            ['subject_id' => $history->id, 'assigned' => false, 'staff_id' => null],
        ])->assertHasNoErrors();

    expect(ClassSubject::query()->where('academic_year_id', $year->id)->where('class_level_id', $level->id)->count())->toBe(2)
        ->and(ClassSubject::query()->where('subject_id', $mathematics->id)->value('staff_id'))->toBe($teacher->id)
        ->and(ClassSubject::query()->where('subject_id', $history->id)->exists())->toBeFalse();
});

test('class subjects belong directly to class levels and reject duplicate assignments', function () {
    $year = AcademicYear::factory()->create();
    $level = ClassLevel::factory()->create();
    $subject = Subject::factory()->create();
    $assignment = ClassSubject::factory()->create([
        'academic_year_id' => $year->id,
        'class_level_id' => $level->id,
        'subject_id' => $subject->id,
    ]);

    expect($assignment->classLevel->is($level))->toBeTrue()
        ->and(Schema::hasColumn('class_subjects', 'class_level_id'))->toBeTrue()
        ->and(Schema::hasColumn('class_subjects', 'class_section_id'))->toBeFalse()
        ->and(fn () => ClassSubject::factory()->create([
            'academic_year_id' => $year->id,
            'class_level_id' => $level->id,
            'subject_id' => $subject->id,
        ]))->toThrow(QueryException::class);
});

test('batch assignment rejects inactive subjects and non teacher users', function () {
    Role::findOrCreate(Roles::TEACHER);
    $userWithoutTeacherRole = User::factory()->create();
    $year = AcademicYear::factory()->create();
    $level = ClassLevel::factory()->create();
    $inactiveSubject = Subject::factory()->create(['is_active' => false]);
    $user = academicUser([Permissions::CLASSES_VIEW, Permissions::CLASSES_UPDATE]);

    Livewire::actingAs($user)->test('pages::academic.class-subjects.index')
        ->set('academicYearId', $year->id)->set('classLevelId', $level->id)
        ->call('saveAssignments', [['subject_id' => $inactiveSubject->id, 'assigned' => true, 'staff_id' => $userWithoutTeacherRole->id]])
        ->assertHasErrors(['assignment']);

    expect(ClassSubject::query()->exists())->toBeFalse();
});
