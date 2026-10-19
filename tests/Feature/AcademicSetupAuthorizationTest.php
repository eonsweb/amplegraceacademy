<?php

use App\Models\AcademicYear;
use App\Models\ClassLevel;
use App\Models\ClassSubject;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use App\Support\Authorization\Permissions;
use App\Support\Authorization\Roles;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

function setupAccessUser(array $permissions): User
{
    test()->seed(PermissionSeeder::class);
    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

test('academic access alone opens the landing page without unrelated permissions', function () {
    $user = setupAccessUser([Permissions::ACADEMIC_SETUP_VIEW]);

    $this->actingAs($user)->get(route('academic.index'))->assertOk()
        ->assertSee('No academic sections assigned');
});

test('domain permissions alone cannot open academic URLs or components', function (string $page) {
    $user = setupAccessUser([Permissions::CLASSES_VIEW, Permissions::SUBJECTS_VIEW, Permissions::CLASSES_UPDATE]);

    $this->actingAs($user)->get(route('academic.'.$page.'.index'))->assertForbidden();
    Livewire::actingAs($user)->test('pages::academic.'.$page.'.index')->assertForbidden();
})->with(['years', 'terms', 'class-levels', 'subjects', 'class-subjects']);

test('read only users cannot execute academic mutations or change records', function (string $page, string $action, string $target, bool $editing) {
    $user = setupAccessUser([Permissions::ACADEMIC_SETUP_VIEW, Permissions::CLASSES_VIEW, Permissions::SUBJECTS_VIEW]);
    $year = AcademicYear::factory()->create(['is_current' => true]);
    $term = Term::factory()->create(['academic_year_id' => $year->id]);
    $level = ClassLevel::factory()->create();
    $subject = Subject::factory()->create();
    $assignment = ClassSubject::factory()->create(['academic_year_id' => $year->id, 'class_level_id' => $level->id, 'subject_id' => $subject->id]);
    $records = collect([$year, $term, $level, $subject, $assignment]);
    $before = $records->map(fn ($record) => $record->fresh()->getAttributes())->all();
    $id = match ($target) {
        'year' => $year->id, 'term' => $term->id, 'level' => $level->id, default => $subject->id
    };
    Role::findOrCreate(Roles::TEACHER, 'web');
    $component = Livewire::actingAs($user)->test('pages::academic.'.$page.'.index');

    if ($editing) {
        $component->set('editingId', $id);
    }

    $arguments = match ($action) {
        'create', 'save' => [],
        'saveAssignments' => [[['subject_id' => $subject->id, 'assigned' => false, 'staff_id' => null]]],
        default => [$id],
    };
    $component->call($action, ...$arguments)->assertForbidden();

    expect($records->map(fn ($record) => $record->fresh()?->getAttributes())->all())->toBe($before);
    $this->assertDatabaseCount('academic_years', 1);
    $this->assertDatabaseCount('class_levels', 1);
    $this->assertDatabaseCount('subjects', 1);
})->with([
    ['years', 'create', 'year', false],
    ['years', 'save', 'year', false],
    ['years', 'edit', 'year', false],
    ['years', 'save', 'year', true],
    ['years', 'makeCurrent', 'year', false],
    ['terms', 'makeCurrent', 'term', false],
    ['class-levels', 'create', 'level', false],
    ['class-levels', 'save', 'level', false],
    ['class-levels', 'edit', 'level', false],
    ['class-levels', 'save', 'level', true],
    ['class-levels', 'toggle', 'level', false],
    ['class-levels', 'delete', 'level', false],
    ['subjects', 'create', 'subject', false],
    ['subjects', 'save', 'subject', false],
    ['subjects', 'edit', 'subject', false],
    ['subjects', 'save', 'subject', true],
    ['subjects', 'toggle', 'subject', false],
    ['class-subjects', 'saveAssignments', 'subject', false],
]);

test('revoking access blocks subsequent Livewire requests without a new login', function (string $permission) {
    $user = setupAccessUser([Permissions::ACADEMIC_SETUP_VIEW, Permissions::CLASSES_VIEW, Permissions::CLASSES_CREATE]);
    $component = Livewire::actingAs($user)->test('pages::academic.years.index')->set('name', '2030/2031');
    $user->revokePermissionTo($permission);

    $component->call('save')->assertForbidden();
    $this->assertDatabaseCount('academic_years', 0);
})->with([Permissions::ACADEMIC_SETUP_VIEW, Permissions::CLASSES_VIEW, Permissions::CLASSES_CREATE]);

test('the sidebar follows academic access grants and revocations', function () {
    $user = setupAccessUser([Permissions::DASHBOARD_VIEW, Permissions::CLASSES_VIEW]);
    $this->actingAs($user)->get(route('dashboard'))->assertDontSee('Academic Setup');
    $user->givePermissionTo(Permissions::ACADEMIC_SETUP_VIEW);
    $this->get(route('dashboard'))->assertSee('Academic Setup');
    $user->revokePermissionTo(Permissions::ACADEMIC_SETUP_VIEW);
    $this->get(route('dashboard'))->assertDontSee('Academic Setup');
});

test('academic role group supports select clear and saving while retaining other permissions', function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class, RolePermissionSeeder::class]);
    $actor = User::factory()->create();
    $actor->assignRole(Roles::ADMIN);
    $role = Role::create(['name' => 'Academic reader', 'guard_name' => 'web']);
    $role->givePermissionTo(Permissions::SUBJECTS_VIEW);
    $member = User::factory()->create();
    $member->assignRole($role);
    $this->actingAs($member)->get(route('academic.index'))->assertForbidden();

    Livewire::actingAs($actor)->test('pages::settings.roles.edit', ['role' => $role])
        ->assertSee('Academic Setup')->assertSee('academic-setup.view')
        ->call('selectModule', 'Academic Setup')
        ->assertSet('permissionNames', [Permissions::SUBJECTS_VIEW, Permissions::ACADEMIC_SETUP_VIEW])
        ->call('clearModule', 'Academic Setup')->assertSet('permissionNames', [Permissions::SUBJECTS_VIEW])
        ->call('selectModule', 'Academic Setup')->call('save')->assertHasNoErrors();

    expect($role->fresh()->hasAllPermissions([Permissions::SUBJECTS_VIEW, Permissions::ACADEMIC_SETUP_VIEW]))->toBeTrue();
    $this->actingAs($member->fresh())->get(route('academic.index'))->assertRedirect(route('academic.subjects.index'));
});

test('repeated permission backfills preserve customized and fully revoked roles', function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class, RolePermissionSeeder::class]);
    $admin = Role::findByName(Roles::ADMIN);
    $admin->revokePermissionTo(Permissions::ACADEMIC_SETUP_VIEW);
    $headmaster = Role::findByName(Roles::HEADMASTER);
    $headmaster->syncPermissions([]);
    $custom = Role::create(['name' => 'Custom', 'guard_name' => 'web']);
    $custom->givePermissionTo(Permissions::SUBJECTS_VIEW);
    $before = Role::with('permissions')->get()->mapWithKeys(fn (Role $role) => [$role->name => $role->permissions->pluck('name')->sort()->values()->all()])->all();

    $this->seed([PermissionSeeder::class, RoleSeeder::class, RolePermissionSeeder::class]);
    $after = Role::with('permissions')->get()->mapWithKeys(fn (Role $role) => [$role->name => $role->permissions->pluck('name')->sort()->values()->all()])->all();

    expect($after)->toBe($before);
});

test('fresh leadership defaults have full academic access and teachers do not', function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class, RolePermissionSeeder::class]);
    $permissions = [Permissions::ACADEMIC_SETUP_VIEW, Permissions::CLASSES_VIEW, Permissions::CLASSES_CREATE, Permissions::CLASSES_UPDATE, Permissions::CLASSES_DELETE, Permissions::SUBJECTS_VIEW, Permissions::SUBJECTS_CREATE, Permissions::SUBJECTS_UPDATE, Permissions::SUBJECTS_DELETE];
    foreach ([Roles::ADMIN, Roles::PROPRIETOR, Roles::HEADMASTER] as $role) {
        expect(Role::findByName($role)->hasAllPermissions($permissions))->toBeTrue();
    }
    expect(Role::findByName(Roles::TEACHER)->hasPermissionTo(Permissions::ACADEMIC_SETUP_VIEW))->toBeFalse();
});

test('introducing academic access does not grant permissions to existing roles', function () {
    $role = Role::create(['name' => Roles::ADMIN, 'guard_name' => 'web']);

    $this->seed([PermissionSeeder::class, RoleSeeder::class, RolePermissionSeeder::class]);

    expect($role->fresh()->permissions)->toBeEmpty();
});

test('read only academic pages hide modification forms and buttons', function () {
    $user = setupAccessUser([Permissions::ACADEMIC_SETUP_VIEW, Permissions::CLASSES_VIEW, Permissions::SUBJECTS_VIEW]);

    foreach (['years', 'class-levels', 'subjects'] as $page) {
        Livewire::actingAs($user)->test('pages::academic.'.$page.'.index')
            ->assertDontSeeHtml('wire:submit="save"')
            ->assertDontSeeHtml('wire:click="create"');
    }

    Role::findOrCreate(Roles::TEACHER, 'web');
    $year = AcademicYear::factory()->create();
    $level = ClassLevel::factory()->create();
    $subject = Subject::factory()->create();
    ClassSubject::factory()->create(['academic_year_id' => $year->id, 'class_level_id' => $level->id, 'subject_id' => $subject->id]);

    Livewire::actingAs($user)->test('pages::academic.class-subjects.index')
        ->set('academicYearId', $year->id)->set('classLevelId', $level->id)
        ->assertSee($subject->name)->assertSee('Yes')
        ->assertDontSeeHtml('x-model="assignments[')
        ->assertDontSee('Save assignments');
});
