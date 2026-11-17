<?php

use App\Models\Staff;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

test('date inputs render editable comboboxes in year month day order', function () {
    $this->blade('<x-app.date-input label="Birth date" value="1988-05-07" required />')
        ->assertSeeInOrder(['Birth date: Year', 'Birth date: Month', 'Birth date: Day'])
        ->assertSee('role="combobox"', false)
        ->assertSee('role="listbox"', false)
        ->assertSee('popover="manual"', false)
        ->assertSee('inputmode="numeric"', false)
        ->assertSee('maxlength="4"', false)
        ->assertSee('maxlength="2"', false)
        ->assertSee('pattern="[0-9]*"', false)
        ->assertDontSee('type="number"', false)
        ->assertSee('required', false)
        ->assertDontSee('type="date"', false);
});

test('disabled and readonly dates disable the whole selection group', function (string $state) {
    $this->blade('<x-app.date-input label="Date" '.$state.' />')
        ->assertSee('disabled', false)
        ->assertDontSee('Clear date');
})->with(['disabled', 'readonly']);

test('staff edit preserves and saves ISO dates using the existing date properties', function (string $date) {
    $user = User::factory()->create();
    Permission::findOrCreate(Permissions::STAFF_UPDATE, 'web');
    $user->givePermissionTo(Permissions::STAFF_UPDATE);
    $staff = Staff::factory()->create(['date_of_birth' => '1988-05-07']);
    $this->actingAs($user);

    $component = Livewire::test('pages::staff.edit', ['staff' => $staff])
        ->assertSet('dateOfBirth', '1988-05-07')
        ->assertSeeInOrder(['Date of birth: Year', 'Date of birth: Month', 'Date of birth: Day']);
    if ($date !== '1988-05-07') {
        $component->set('dateOfBirth', $date);
    }
    $component->call('save')->assertHasNoErrors();

    $this->assertDatabaseHas('staff', ['id' => $staff->id, 'date_of_birth' => $date ?: null]);
})->with(['unchanged' => '1988-05-07', 'updated leap day' => '2000-02-29', 'cleared optional date' => '']);

test('invalid partial and future staff dates retain the existing value in storage', function (string $date) {
    $this->travelTo('2026-11-16 12:00:00');
    $user = User::factory()->create();
    Permission::findOrCreate(Permissions::STAFF_UPDATE, 'web');
    $user->givePermissionTo(Permissions::STAFF_UPDATE);
    $staff = Staff::factory()->create(['date_of_birth' => '1988-05-07']);
    $this->actingAs($user);

    Livewire::test('pages::staff.edit', ['staff' => $staff])
        ->set('dateOfBirth', $date)->call('save')
        ->assertHasErrors('dateOfBirth')
        ->assertSet('dateOfBirth', $date);

    $this->assertDatabaseHas('staff', ['id' => $staff->id, 'date_of_birth' => '1988-05-07']);
})->with(['invalid leap day' => '1900-02-29', 'partial date' => 'incomplete:1988-05-', 'future date' => '2027-01-01']);
