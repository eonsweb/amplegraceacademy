<?php

use App\Models\User;
use App\Support\Authorization\Permissions;
use Spatie\Permission\Models\Permission;

test('guests are redirected from academic setup to login', function () {
    $this->get(route('academic.years.index'))
        ->assertRedirect(route('home'));
});

test('users without academic permissions receive a forbidden response', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('academic.index'))
        ->assertForbidden();
});

test('the academic entry route selects the first page the user may view', function (string $permission, string $destination) {
    Permission::findOrCreate($permission);
    $user = User::factory()->create();
    $user->givePermissionTo($permission);

    $this->actingAs($user)
        ->get(route('academic.index'))
        ->assertRedirect(route($destination));
})->with([
    'class viewer' => [Permissions::CLASSES_VIEW, 'academic.years.index'],
    'subject viewer' => [Permissions::SUBJECTS_VIEW, 'academic.subjects.index'],
]);

test('each academic page renders the shared navigation and selected content', function (string $routeName, string $heading) {
    Permission::findOrCreate(Permissions::CLASSES_VIEW);
    Permission::findOrCreate(Permissions::SUBJECTS_VIEW);
    $user = User::factory()->create();
    $user->givePermissionTo([Permissions::CLASSES_VIEW, Permissions::SUBJECTS_VIEW]);

    $this->actingAs($user)
        ->get(route($routeName))
        ->assertSeeInOrder([
            'Academic Setup',
            'Academic Years',
            'Terms',
            'Class Levels',
            'Subjects',
            'Class Subjects',
            $heading,
        ])
        ->assertDontSee('Class Sections')
        ->assertSee('data-current', false);
})->with([
    'academic years' => ['academic.years.index', 'Configured academic years'],
    'terms' => ['academic.terms.index', 'Configured Terms'],
    'class levels' => ['academic.class-levels.index', 'Ordered class levels'],
    'subjects' => ['academic.subjects.index', 'Subject name'],
    'class subjects' => ['academic.class-subjects.index', 'Subject assignments'],
]);

test('academic pages enforce their domain view permission', function (string $grantedPermission, string $routeName) {
    Permission::findOrCreate($grantedPermission);
    $user = User::factory()->create();
    $user->givePermissionTo($grantedPermission);

    $this->actingAs($user)
        ->get(route($routeName))
        ->assertForbidden();
})->with([
    'subject viewer cannot view class setup' => [Permissions::SUBJECTS_VIEW, 'academic.years.index'],
    'class viewer cannot view subject setup' => [Permissions::CLASSES_VIEW, 'academic.subjects.index'],
]);

test('the main sidebar keeps academic setup active throughout the module', function () {
    Permission::findOrCreate(Permissions::CLASSES_VIEW);
    $user = User::factory()->create();
    $user->givePermissionTo(Permissions::CLASSES_VIEW);

    $this->actingAs($user)
        ->get(route('academic.terms.index'))
        ->assertSee('Academic Setup')
        ->assertSee('bg-white/15 text-white shadow-sm', false);
});

test('the removed class sections page is not routed', function () {
    Permission::findOrCreate(Permissions::CLASSES_VIEW);
    $user = User::factory()->create();
    $user->givePermissionTo(Permissions::CLASSES_VIEW);

    $this->actingAs($user)
        ->get('/academic/class-sections')
        ->assertNotFound();
});
