<?php

use App\Models\User;
use App\Support\Authorization\Permissions;
use Spatie\Permission\Models\Permission;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('home'));
});

test('authorized users can visit the dashboard', function () {
    Permission::findOrCreate(Permissions::DASHBOARD_VIEW);
    $user = User::factory()->create();
    $user->givePermissionTo(Permissions::DASHBOARD_VIEW);
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertSee([
        'Dashboard',
        'GH₵4,250,000.00',
        'Fee Collection Overview',
        'Student Attendance Overview',
        'Recent Notices',
        'Recent Payments',
        'Upcoming Events',
    ]);
});
