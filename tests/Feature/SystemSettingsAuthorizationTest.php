<?php

use App\Models\SchoolSetting;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

test('a user without settings permission receives a forbidden response', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('settings.system'))
        ->assertForbidden();
});

test('a user with view permission can view system settings', function () {
    Permission::findOrCreate(Permissions::SETTINGS_VIEW);
    $user = User::factory()->create();
    $user->givePermissionTo(Permissions::SETTINGS_VIEW);

    $this->actingAs($user)
        ->get(route('settings.system'))
        ->assertSee('Read-only access');
});

test('a user without update permission cannot forge a settings save', function () {
    Permission::findOrCreate(Permissions::SETTINGS_VIEW);
    Permission::findOrCreate(Permissions::SETTINGS_UPDATE);
    $user = User::factory()->create();
    $user->givePermissionTo(Permissions::SETTINGS_VIEW);

    Livewire::actingAs($user)
        ->test('pages::settings.system')
        ->set('schoolName', 'Changed School')
        ->call('save')
        ->assertForbidden();

    expect(SchoolSetting::query()->exists())->toBeFalse();
});

test('a direct update permission allows system settings changes', function () {
    Permission::findOrCreate(Permissions::SETTINGS_UPDATE);
    $user = User::factory()->create();
    $user->givePermissionTo(Permissions::SETTINGS_UPDATE);

    Livewire::actingAs($user)
        ->test('pages::settings.system')
        ->set('schoolName', 'Ample Grace Academy')
        ->set('schoolInitials', 'AGA')
        ->set('contactEmail', 'office@example.com')
        ->call('save')
        ->assertHasNoErrors();

    expect(SchoolSetting::query()->value('contact_email'))->toBe('office@example.com')
        ->and(SchoolSetting::query()->value('school_initials'))->toBe('AGA');
});
