<?php

use App\Events\UserManagementChanged;
use App\Models\User;
use App\Support\Authorization\Permissions;
use App\Support\Authorization\Roles;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

function seedUserManagement(): void
{
    test()->seed([PermissionSeeder::class, RoleSeeder::class, RolePermissionSeeder::class]);
}

test('management roles can access the users page', function (string $roleName) {
    seedUserManagement();
    $user = User::factory()->create();
    $user->assignRole($roleName);

    $this->actingAs($user)
        ->get(route('users.index'))
        ->assertSee('Add User');
})->with([
    'admin' => Roles::ADMIN,
    'proprietor' => Roles::PROPRIETOR,
    'headmaster' => Roles::HEADMASTER,
]);

test('teachers cannot access user management without an explicit permission', function () {
    seedUserManagement();
    $teacher = User::factory()->create();
    $teacher->assignRole(Roles::TEACHER);

    $this->actingAs($teacher)
        ->get(route('users.index'))
        ->assertForbidden();
});

test('authorized users can create an account with a temporary password and roles', function () {
    seedUserManagement();
    $actor = User::factory()->create();
    $actor->assignRole(Roles::ADMIN);
    Event::fake([UserManagementChanged::class]);

    Livewire::actingAs($actor)
        ->test('pages::settings.users.index')
        ->set('name', 'New Teacher')
        ->set('username', '  New.Teacher  ')
        ->set('email', 'NEW.TEACHER@example.com')
        ->set('roleNames', [Roles::TEACHER])
        ->call('saveUser')
        ->assertHasNoErrors();

    $user = User::query()->where('username', 'new.teacher')->firstOrFail();

    expect($user->name)->toBe('New Teacher')
        ->and($user->email)->toBe('new.teacher@example.com')
        ->and($user->must_change_password)->toBeTrue()
        ->and($user->is_active)->toBeTrue()
        ->and(Hash::check('password', $user->password))->toBeTrue()
        ->and($user->hasRole(Roles::TEACHER))->toBeTrue();
    Event::assertDispatched(UserManagementChanged::class, fn (UserManagementChanged $event): bool => $event->action === 'user.created');
});

test('username must remain unique when creating users', function () {
    seedUserManagement();
    $actor = User::factory()->create();
    $actor->assignRole(Roles::ADMIN);
    User::factory()->create(['username' => 'existing.user']);

    Livewire::actingAs($actor)
        ->test('pages::settings.users.index')
        ->set('name', 'Duplicate User')
        ->set('username', 'EXISTING.USER')
        ->set('email', 'duplicate@example.com')
        ->set('roleNames', [Roles::TEACHER])
        ->call('saveUser')
        ->assertHasErrors(['username' => 'unique']);

    expect(User::query()->where('email', 'duplicate@example.com')->exists())->toBeFalse();
});

test('authorized users can update details and roles without changing the password', function () {
    seedUserManagement();
    $actor = User::factory()->create();
    $actor->assignRole(Roles::ADMIN);
    $subject = User::factory()->create();
    $subject->assignRole(Roles::TEACHER);
    $passwordHash = $subject->password;

    Livewire::actingAs($actor)
        ->test('pages::settings.users.index')
        ->call('editUser', $subject->id)
        ->set('name', 'Updated User')
        ->set('username', 'updated.user')
        ->set('email', 'updated@example.com')
        ->set('roleNames', [Roles::PROPRIETOR])
        ->call('saveUser')
        ->assertHasNoErrors();

    $subject = $subject->fresh();

    expect($subject->name)->toBe('Updated User')
        ->and($subject->username)->toBe('updated.user')
        ->and($subject->password)->toBe($passwordHash)
        ->and($subject->hasRole(Roles::PROPRIETOR))->toBeTrue()
        ->and($subject->hasRole(Roles::TEACHER))->toBeFalse();
});

test('editing a user may keep the existing username and email', function () {
    seedUserManagement();
    $actor = User::factory()->create();
    $actor->assignRole(Roles::ADMIN);
    $subject = User::factory()->create();
    $subject->assignRole(Roles::TEACHER);

    Livewire::actingAs($actor)
        ->test('pages::settings.users.index')
        ->call('editUser', $subject->id)
        ->call('saveUser')
        ->assertHasNoErrors();

    $this->assertModelExists($subject);
});

test('password reset restores the temporary credential and requires a password change', function () {
    seedUserManagement();
    $actor = User::factory()->create();
    $actor->assignRole(Roles::ADMIN);
    $subject = User::factory()->create([
        'password' => Hash::make('old-password'),
        'must_change_password' => false,
    ]);
    DB::table('sessions')->insert([
        'id' => 'subject-session',
        'user_id' => $subject->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Pest',
        'payload' => '',
        'last_activity' => now()->timestamp,
    ]);

    Livewire::actingAs($actor)
        ->test('pages::settings.users.index')
        ->call('resetUserPassword', $subject->id)
        ->assertHasNoErrors();

    $subject = $subject->fresh();

    expect(Hash::check('password', $subject->password))->toBeTrue()
        ->and($subject->must_change_password)->toBeTrue()
        ->and(DB::table('sessions')->where('user_id', $subject->id)->exists())->toBeFalse();
});

test('users without a mutation permission cannot forge user actions', function () {
    seedUserManagement();
    $actor = User::factory()->create();
    $actor->givePermissionTo(Permissions::USERS_VIEW);
    $subject = User::factory()->create();
    $passwordHash = $subject->password;

    Livewire::actingAs($actor)
        ->test('pages::settings.users.index')
        ->call('resetUserPassword', $subject->id)
        ->assertForbidden();

    expect($subject->fresh()->password)->toBe($passwordHash);
});

test('users without create permission cannot forge account creation', function () {
    seedUserManagement();
    $actor = User::factory()->create();
    $actor->givePermissionTo(Permissions::USERS_VIEW);

    Livewire::actingAs($actor)
        ->test('pages::settings.users.index')
        ->set('name', 'Forged User')
        ->set('username', 'forged.user')
        ->set('email', 'forged@example.com')
        ->set('roleNames', [Roles::TEACHER])
        ->call('saveUser')
        ->assertForbidden();

    expect(User::query()->where('username', 'forged.user')->exists())->toBeFalse();
});

test('authorized users can deactivate and reactivate accounts', function () {
    seedUserManagement();
    $actor = User::factory()->create();
    $actor->assignRole(Roles::ADMIN);
    $subject = User::factory()->create();

    $component = Livewire::actingAs($actor)->test('pages::settings.users.index');

    $component->call('toggleUserStatus', $subject->id)->assertHasNoErrors();
    expect($subject->fresh()->is_active)->toBeFalse();

    $component->call('toggleUserStatus', $subject->id)->assertHasNoErrors();
    expect($subject->fresh()->is_active)->toBeTrue();
});

test('users cannot deactivate their own account', function () {
    seedUserManagement();
    $actor = User::factory()->create();
    $actor->assignRole(Roles::ADMIN);

    Livewire::actingAs($actor)
        ->test('pages::settings.users.index')
        ->call('toggleUserStatus', $actor->id)
        ->assertHasErrors(['status']);

    expect($actor->fresh()->is_active)->toBeTrue();
});

test('the final active authorization manager cannot be deactivated', function () {
    seedUserManagement();
    $actor = User::factory()->create();
    $actor->givePermissionTo([
        Permissions::USERS_VIEW,
        Permissions::USERS_CHANGE_STATUS,
    ]);
    $administrator = User::factory()->create();
    $administrator->assignRole(Roles::ADMIN);

    Livewire::actingAs($actor)
        ->test('pages::settings.users.index')
        ->call('toggleUserStatus', $administrator->id)
        ->assertHasErrors(['status']);

    expect($administrator->fresh()->is_active)->toBeTrue();
});

test('inactive users can be permanently deleted but active users cannot', function () {
    seedUserManagement();
    $actor = User::factory()->create();
    $actor->assignRole(Roles::ADMIN);
    $activeUser = User::factory()->create();
    $inactiveUser = User::factory()->inactive()->create();

    $component = Livewire::actingAs($actor)->test('pages::settings.users.index');

    $component->call('deleteUser', $activeUser->id)->assertHasErrors(['user']);
    $component->call('deleteUser', $inactiveUser->id)->assertHasNoErrors();

    $this->assertModelExists($activeUser);
    $this->assertModelMissing($inactiveUser);
});

test('search and filters are applied by the user listing', function () {
    seedUserManagement();
    $actor = User::factory()->create(['name' => 'Managing Admin']);
    $actor->assignRole(Roles::ADMIN);
    $activeTeacher = User::factory()->create(['name' => 'Visible Teacher', 'is_active' => true]);
    $activeTeacher->assignRole(Roles::TEACHER);
    $inactiveTeacher = User::factory()->inactive()->create(['name' => 'Hidden Teacher']);
    $inactiveTeacher->assignRole(Roles::TEACHER);

    Livewire::actingAs($actor)
        ->test('pages::settings.users.index')
        ->set('search', 'Teacher')
        ->set('roleFilter', Roles::TEACHER)
        ->set('statusFilter', 'active')
        ->assertSee('Visible Teacher')
        ->assertDontSee('Hidden Teacher')
        ->assertDontSee('Managing Admin');
});
