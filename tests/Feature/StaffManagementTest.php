<?php

use App\Actions\Staff\CreateStaff;
use App\EmploymentType;
use App\Gender;
use App\Models\Staff;
use App\Models\Student;
use App\Models\User;
use App\Policies\StaffPolicy;
use App\StaffStatus;
use App\Support\Authorization\Permissions;
use App\Support\Authorization\Roles;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

function staffActor(array $permissions): User
{
    $user = User::factory()->create();
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $user->givePermissionTo($permissions);

    return $user;
}

function createStaffRecord(array $data = []): Staff
{
    return app(CreateStaff::class)->handle(array_replace([
        'first_name' => 'Ama', 'last_name' => 'Mensah', 'role_title' => 'Teacher', 'status' => StaffStatus::Active,
    ], $data));
}

function seedStaffAuthorization(): void
{
    test()->seed([PermissionSeeder::class, RoleSeeder::class, RolePermissionSeeder::class]);
}

test('staff routes enforce view create and update permissions', function () {
    $staff = Staff::factory()->create();
    $unauthorized = User::factory()->create();

    $this->actingAs($unauthorized)->get(route('staff.index'))->assertForbidden();
    $this->actingAs($unauthorized)->get(route('staff.create'))->assertForbidden();
    $this->actingAs($unauthorized)->get(route('staff.show', $staff))->assertForbidden();
    $this->actingAs($unauthorized)->get(route('staff.edit', $staff))->assertForbidden();

    $viewer = staffActor([Permissions::STAFF_VIEW]);
    $this->actingAs($viewer)->get(route('staff.index'))->assertOk();
    $this->actingAs($viewer)->get(route('staff.show', $staff))->assertOk();
});

test('the staff policy maps every capability to its dedicated permission', function () {
    $staff = Staff::factory()->create();
    $user = staffActor(array_keys(Permissions::grouped()['Staff']));
    $policy = app(StaffPolicy::class);

    expect($policy->viewAny($user))->toBeTrue()
        ->and($policy->view($user, $staff))->toBeTrue()
        ->and($policy->create($user))->toBeTrue()
        ->and($policy->update($user, $staff))->toBeTrue()
        ->and($policy->delete($user, $staff))->toBeTrue()
        ->and($policy->manageStatus($user, $staff))->toBeTrue()
        ->and($policy->manageUserAccount($user, $staff))->toBeTrue();
});

test('staff numbers are generated uniquely and cannot be supplied by callers', function () {
    $first = createStaffRecord(['staff_number' => 'FORGED']);
    $second = createStaffRecord(['first_name' => 'Kojo']);

    expect($first->staff_number)->toBe('STF000001')
        ->and($second->staff_number)->toBe('STF000002')
        ->and(DB::table('staff_number_sequences')->where('key', 'staff')->value('current_value'))->toBe(2);
});

test('authorized users can create staff without creating a user account', function () {
    $actor = staffActor([Permissions::STAFF_CREATE]);

    Livewire::actingAs($actor)->test('pages::staff.create')
        ->set('firstName', 'Akosua')->set('lastName', 'Boateng')->set('roleTitle', 'Accountant')
        ->set('gender', Gender::Female->value)->set('employmentType', EmploymentType::Permanent->value)
        ->set('email', 'AKOSUA@example.com')->set('phone', ' 0240000000 ')->call('save')
        ->assertHasNoErrors();

    $staff = Staff::query()->where('first_name', 'Akosua')->firstOrFail();
    expect($staff->staff_number)->toBe('STF000001')->and($staff->email)->toBe('akosua@example.com')
        ->and($staff->phone)->toBe('0240000000')->and($staff->user)->toBeNull();
});

test('staff creation validates required fields', function () {
    $actor = staffActor([Permissions::STAFF_CREATE]);

    Livewire::actingAs($actor)->test('pages::staff.create')->set('employmentDate', '')->call('save')
        ->assertHasErrors(['firstName' => 'required', 'lastName' => 'required', 'roleTitle' => 'required']);
});

test('staff creation rejects invalid values', function (string $field, string $value) {
    $actor = staffActor([Permissions::STAFF_CREATE]);

    Livewire::actingAs($actor)->test('pages::staff.create')
        ->set('firstName', 'Ama')->set('lastName', 'Mensah')->set('roleTitle', 'Teacher')
        ->set($field, $value)->call('save')->assertHasErrors([$field]);
})->with([
    'invalid email' => ['email', 'not-an-email'],
    'future date of birth' => ['dateOfBirth', '2999-01-01'],
    'invalid status' => ['status', 'dismissed'],
    'invalid gender' => ['gender', 'unknown'],
    'invalid employment type' => ['employmentType', 'volunteer'],
]);

test('duplicate staff numbers remain protected by the database', function () {
    Staff::factory()->create(['staff_number' => 'STF000999']);

    expect(fn () => Staff::factory()->create(['staff_number' => 'STF000999']))->toThrow(QueryException::class);
});

test('staff email is not globally unique', function () {
    Staff::factory()->create(['email' => 'shared@example.com']);

    $second = Staff::factory()->create(['email' => 'shared@example.com']);

    $this->assertModelExists($second);
});

test('staff photos are validated and stored safely', function () {
    Storage::fake('public');
    $actor = staffActor([Permissions::STAFF_CREATE]);

    Livewire::actingAs($actor)->test('pages::staff.create')
        ->set('firstName', 'Ama')->set('lastName', 'Photo')->set('roleTitle', 'Teacher')
        ->set('photo', UploadedFile::fake()->image('portrait.jpg', 300, 300))->call('save')->assertHasNoErrors();

    $staff = Staff::query()->where('last_name', 'Photo')->firstOrFail();
    Storage::disk('public')->assertExists($staff->photo);
});

test('the staff directory searches by staff number name phone email and position', function (string $search) {
    $matching = Staff::factory()->create(['staff_number' => 'STF123456', 'first_name' => 'Unique', 'last_name' => 'Person', 'phone' => '0249999999', 'email' => 'unique@example.com', 'role_title' => 'Librarian']);
    Staff::factory()->create(['first_name' => 'Hidden', 'last_name' => 'Record']);
    $actor = staffActor([Permissions::STAFF_VIEW]);

    Livewire::actingAs($actor)->test('pages::staff.index')->set('search', $search)
        ->assertSee($matching->staff_number)->assertDontSee('Hidden Record');
})->with(['staff number' => '123456', 'full name' => 'Unique Person', 'phone' => '024999', 'email' => 'unique@example', 'position' => 'Librarian']);

test('the staff directory filters status department and application access', function () {
    $visible = Staff::factory()->active()->create(['first_name' => 'Visible', 'department' => 'Finance']);
    $hidden = Staff::factory()->inactive()->create(['first_name' => 'Hidden', 'department' => 'Teaching']);
    User::factory()->create(['staff_id' => $visible->id]);
    $actor = staffActor([Permissions::STAFF_VIEW]);

    Livewire::actingAs($actor)->test('pages::staff.index')
        ->set('statusFilter', 'active')->set('departmentFilter', 'Finance')->set('accessFilter', 'yes')
        ->assertSee('Visible')->assertDontSee('Hidden');
});

test('the staff directory remains paginated at 25 records', function () {
    Staff::factory()->count(26)->sequence(fn ($sequence): array => ['last_name' => sprintf('Person %02d', $sequence->index + 1)])->create();
    $actor = staffActor([Permissions::STAFF_VIEW]);

    Livewire::actingAs($actor)->test('pages::staff.index')->assertSee('Person 25')->assertDontSee('Person 26');
});

test('authorized users can update staff without changing the staff number or status', function () {
    $staff = Staff::factory()->inactive()->create(['staff_number' => 'STF000777', 'first_name' => 'Old']);
    $actor = staffActor([Permissions::STAFF_UPDATE]);

    Livewire::actingAs($actor)->test('pages::staff.edit', ['staff' => $staff])->set('firstName', 'New')->set('roleTitle', 'Head Teacher')->call('save')->assertHasNoErrors();

    expect($staff->fresh()->first_name)->toBe('New')->and($staff->staff_number)->toBe('STF000777')->and($staff->status)->toBe(StaffStatus::Inactive);
});

test('editing one staff member does not affect another or linked user authorization', function () {
    seedStaffAuthorization();
    $actor = User::factory()->create();
    $actor->assignRole(Roles::ADMIN);
    $staff = Staff::factory()->create();
    $other = Staff::factory()->create(['first_name' => 'Untouched']);
    $linkedUser = User::factory()->create(['staff_id' => $staff->id]);
    $linkedUser->assignRole(Roles::TEACHER);

    Livewire::actingAs($actor)->test('pages::staff.edit', ['staff' => $staff])->set('firstName', 'Changed')->call('save')->assertHasNoErrors();

    expect($other->fresh()->first_name)->toBe('Untouched')->and($linkedUser->fresh()->hasRole(Roles::TEACHER))->toBeTrue();
});

test('only status managers can activate or deactivate staff', function () {
    $staff = Staff::factory()->active()->create();
    $viewer = staffActor([Permissions::STAFF_VIEW]);
    Livewire::actingAs($viewer)->test('pages::staff.show', ['staff' => $staff])->call('toggleStatus')->assertForbidden();

    $manager = staffActor([Permissions::STAFF_VIEW, Permissions::STAFF_MANAGE_STATUS]);
    Livewire::actingAs($manager)->test('pages::staff.show', ['staff' => $staff])->call('toggleStatus')->assertHasNoErrors();

    expect($staff->fresh()->status)->toBe(StaffStatus::Inactive);
    $this->assertModelExists($staff);
});

test('changing employment status does not modify the linked user account', function () {
    $staff = Staff::factory()->active()->create();
    $user = User::factory()->create(['staff_id' => $staff->id, 'is_active' => true]);
    $manager = staffActor([Permissions::STAFF_VIEW, Permissions::STAFF_MANAGE_STATUS]);

    Livewire::actingAs($manager)->test('pages::staff.show', ['staff' => $staff])->call('toggleStatus');

    expect($user->fresh()->is_active)->toBeTrue()->and($user->staff_id)->toBe($staff->id);
});

test('staff may be linked to and unlinked from an existing user without deleting either', function () {
    $staff = Staff::factory()->create();
    $user = User::factory()->create();
    $actor = staffActor([Permissions::STAFF_VIEW, Permissions::STAFF_MANAGE_USER_ACCOUNT, Permissions::USERS_UPDATE]);
    $component = Livewire::actingAs($actor)->test('pages::staff.show', ['staff' => $staff]);

    $component->call('openLinkAccount')->call('selectUser', $user->id)->call('linkAccount')->assertHasNoErrors();
    expect($user->fresh()->staff_id)->toBe($staff->id);

    $component->call('unlinkAccount')->assertHasNoErrors();
    expect($user->fresh()->staff_id)->toBeNull();
    $this->assertModelExists($staff);
    $this->assertModelExists($user);
});

test('one staff member cannot be linked to multiple users', function () {
    $staff = Staff::factory()->create();
    User::factory()->create(['staff_id' => $staff->id]);

    expect(fn () => User::factory()->create(['staff_id' => $staff->id]))->toThrow(QueryException::class);
});

test('deleting a linked user preserves the staff record', function () {
    $staff = Staff::factory()->create();
    $user = User::factory()->create(['staff_id' => $staff->id]);

    $user->delete();

    $this->assertModelExists($staff);
    expect($staff->fresh()->user)->toBeNull();
});

test('creating a staff user reuses managed account rules and keeps roles separate from position', function () {
    seedStaffAuthorization();
    $actor = User::factory()->create();
    $actor->assignRole(Roles::ADMIN);
    $staff = Staff::factory()->create(['role_title' => 'Accountant', 'email' => 'worker@example.com']);

    Livewire::actingAs($actor)->test('pages::staff.show', ['staff' => $staff])
        ->call('openCreateAccount')->set('username', 'worker')->set('userEmail', 'worker@example.com')
        ->set('roleNames', [Roles::TEACHER])->call('createAccount')->assertHasNoErrors();

    $user = User::query()->where('staff_id', $staff->id)->firstOrFail();
    expect(Hash::check('password', $user->password))->toBeTrue()->and($user->must_change_password)->toBeTrue()
        ->and($user->hasRole(Roles::TEACHER))->toBeTrue()->and($staff->fresh()->role_title)->toBe('Accountant');
});

test('unauthorized users cannot forge staff account linking', function () {
    $staff = Staff::factory()->create();
    $user = User::factory()->create();
    $viewer = staffActor([Permissions::STAFF_VIEW]);

    Livewire::actingAs($viewer)->test('pages::staff.show', ['staff' => $staff])->call('selectUser', $user->id)->assertForbidden();

    expect($user->fresh()->staff_id)->toBeNull();
});

test('forged modal state does not expose unlinked user search results', function () {
    $staff = Staff::factory()->create();
    $user = User::factory()->create(['name' => 'Sensitive Account Name']);
    $viewer = staffActor([Permissions::STAFF_VIEW]);

    Livewire::actingAs($viewer)->test('pages::staff.show', ['staff' => $staff])
        ->set('showLinkAccount', true)
        ->set('userSearch', 'Sensitive')
        ->assertDontSee($user->name);
});

test('deletion requires an inactive unlinked staff record and never affects students', function () {
    $student = Student::factory()->create();
    $active = Staff::factory()->active()->create();
    $linked = Staff::factory()->inactive()->create();
    User::factory()->create(['staff_id' => $linked->id]);
    $deletable = Staff::factory()->inactive()->create();
    $actor = staffActor([Permissions::STAFF_VIEW, Permissions::STAFF_DELETE]);

    Livewire::actingAs($actor)->test('pages::staff.show', ['staff' => $active])->call('delete')->assertHasErrors(['delete']);
    Livewire::actingAs($actor)->test('pages::staff.show', ['staff' => $linked])->call('delete')->assertHasErrors(['delete']);
    Livewire::actingAs($actor)->test('pages::staff.show', ['staff' => $deletable])->call('delete')->assertRedirect(route('staff.index'));

    $this->assertModelExists($active);
    $this->assertModelExists($linked);
    $this->assertModelMissing($deletable);
    $this->assertModelExists($student);
});

test('the application access indicator uses one bounded query without loading users', function () {
    $staffMembers = Staff::factory()->count(25)->create();
    $staffMembers->take(10)->each(fn (Staff $staff): User => User::factory()->create(['staff_id' => $staff->id]));
    DB::flushQueryLog();
    DB::enableQueryLog();

    $page = Staff::query()->select(['id', 'staff_number', 'first_name', 'last_name', 'role_title', 'phone', 'status'])
        ->withExists('user')->orderBy('id')->limit(25)->get();
    $accessIndicators = $page->map(fn (Staff $staff): bool => $staff->user_exists);
    $queryCount = count(DB::getQueryLog());

    expect($page)->toHaveCount(25)->and($queryCount)->toBe(1)
        ->and($accessIndicators)->toHaveCount(25)
        ->and($page->every(fn (Staff $staff): bool => ! $staff->relationLoaded('user')))->toBeTrue();
});
