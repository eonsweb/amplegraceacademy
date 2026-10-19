<?php

use App\Models\User;
use App\Support\Authorization\Permissions;
use App\Support\Authorization\Roles;
use Database\Seeders\PermissionSeeder;
use Spatie\Permission\Models\Role;

test('operator recovery grants only academic access to a verified manager and is idempotent', function () {
    $this->seed(PermissionSeeder::class);
    $manager = User::factory()->create();
    $manager->givePermissionTo([...Permissions::critical(), Permissions::CLASSES_VIEW]);
    $role = Role::create(['name' => 'Customized manager', 'guard_name' => 'web']);
    $role->givePermissionTo(Permissions::SUBJECTS_VIEW);
    $manager->assignRole($role);
    $before = $manager->getAllPermissions()->pluck('name')->all();

    $this->artisan('authorization:grant-academic-setup', ['username' => $manager->username])->assertSuccessful();
    $this->artisan('authorization:grant-academic-setup', ['username' => $manager->username])->assertSuccessful();

    expect($manager->fresh()->getAllPermissions()->pluck('name')->all())
        ->toEqualCanonicalizing([...$before, Permissions::ACADEMIC_SETUP_VIEW]);
    expect($role->fresh()->permissions->pluck('name')->all())->toBe([Permissions::SUBJECTS_VIEW]);
    $this->actingAs($manager->fresh())->get(route('academic.index'))->assertRedirect(route('academic.years.index'));
});

test('operator recovery rejects inactive or incomplete managers even with an Admin role', function (bool $active) {
    $this->seed(PermissionSeeder::class);
    $user = User::factory()->create(['is_active' => $active]);
    $role = Role::create(['name' => Roles::ADMIN, 'guard_name' => 'web']);
    $role->givePermissionTo($active ? [Permissions::ROLES_UPDATE] : Permissions::critical());
    $user->assignRole($role);

    $this->artisan('authorization:grant-academic-setup', ['username' => $user->username])->assertFailed();

    expect($user->fresh()->hasPermissionTo(Permissions::ACADEMIC_SETUP_VIEW))->toBeFalse();
})->with([true, false]);

test('operator recovery rejects an unknown username', function () {
    $this->artisan('authorization:grant-academic-setup', ['username' => 'missing-manager'])->assertFailed();
    $this->assertDatabaseCount('model_has_permissions', 0);
});
