<?php

use App\Events\SystemSettingsChanged;
use App\Models\SchoolSetting;
use App\Models\User;
use App\Support\Authorization\Permissions;
use App\Support\Settings\SystemSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

test('system settings use Ghana defaults and centralized formatting', function () {
    Cache::forget(SystemSettings::CACHE_KEY);

    $settings = app(SystemSettings::class);

    expect($settings->currency())->toBe('GHS')
        ->and($settings->timezone())->toBe('Africa/Accra')
        ->and($settings->recordsPerPage())->toBe(25)
        ->and($settings->formatMoney(1250))->toBe('GH₵1,250.00')
        ->and($settings->formatMoney(0))->toBe('GH₵0.00')
        ->and($settings->formatMoney(-250))->toBe('-GH₵250.00')
        ->and($settings->formatMoney(null))->toBe('—');
});

test('updating settings invalidates the cached value', function () {
    Cache::forget(SystemSettings::CACHE_KEY);
    SchoolSetting::factory()->create(['id' => 1, 'currency_code' => 'GHS']);
    $settings = app(SystemSettings::class);

    expect($settings->currency())->toBe('GHS');

    SchoolSetting::query()->findOrFail(1)->update(['currency_code' => 'USD']);

    expect($settings->currency())->toBe('GHS');

    $changedKeys = $settings->update(['currency_code' => 'EUR']);

    expect($changedKeys)->toBe(['currency_code'])
        ->and($settings->currency())->toBe('EUR');
});

test('branding resolves custom and fallback assets in order', function () {
    Cache::forget(SystemSettings::CACHE_KEY);
    SchoolSetting::factory()->create([
        'id' => 1,
        'dashboard_logo' => 'branding/dashboard.webp',
        'login_logo' => null,
        'favicon' => 'branding/favicon.png',
    ]);
    $settings = app(SystemSettings::class);

    expect($settings->dashboardLogoUrl())->toEndWith('/storage/branding/dashboard.webp')
        ->and($settings->loginLogoUrl())->toEndWith('/storage/branding/dashboard.webp')
        ->and($settings->faviconUrl())->toEndWith('/storage/branding/favicon.png');

    $settings->update(['dashboard_logo' => null, 'favicon' => null]);

    expect($settings->dashboardLogoUrl())->toEndWith('/images/branding/ample-grace-logo.png')
        ->and($settings->loginLogoUrl())->toEndWith('/images/branding/ample-grace-logo.png')
        ->and($settings->faviconUrl())->toEndWith('/favicon.ico');
});

test('a custom login logo takes priority over the dashboard logo', function () {
    SchoolSetting::factory()->create([
        'id' => 1,
        'dashboard_logo' => 'branding/dashboard.webp',
        'login_logo' => 'branding/login.webp',
    ]);
    $settings = app(SystemSettings::class);

    expect($settings->loginLogoUrl())->toEndWith('/storage/branding/login.webp');
});

test('dates and times use the configured formats and timezone', function () {
    SchoolSetting::factory()->create([
        'id' => 1,
        'date_format' => 'DD/MM/YYYY',
        'time_format' => '12-hour',
        'timezone' => 'Africa/Accra',
    ]);
    $settings = app(SystemSettings::class);

    expect($settings->formatDate('2026-09-04 22:30:00 UTC'))->toBe('04/09/2026');
    expect($settings->formatTime('2026-09-04 22:30:00 UTC'))->toBe('10:30 PM');
});

test('authorized users can upload branding with generated storage names', function () {
    Storage::fake('public');
    Event::fake([SystemSettingsChanged::class]);
    Permission::findOrCreate(Permissions::SETTINGS_UPDATE);
    $user = User::factory()->create();
    $user->givePermissionTo(Permissions::SETTINGS_UPDATE);
    $upload = UploadedFile::fake()->image('school-logo.png')->size(300);

    Livewire::actingAs($user)
        ->test('pages::settings.system')
        ->set('dashboardLogoUpload', $upload)
        ->call('saveBranding')
        ->assertHasNoErrors();

    $path = SchoolSetting::query()->value('dashboard_logo');

    expect($path)->toBeString()
        ->not->toContain('school-logo');
    Storage::disk('public')->assertExists($path);
    Event::assertDispatched(SystemSettingsChanged::class, fn (SystemSettingsChanged $event): bool => $event->action === 'settings.branding_updated'
        && $event->actorId === $user->id
        && $event->changedKeys === ['dashboard_logo']);
});

test('branding rejects unsupported and oversized uploads', function () {
    Storage::fake('public');
    Permission::findOrCreate(Permissions::SETTINGS_UPDATE);
    $user = User::factory()->create();
    $user->givePermissionTo(Permissions::SETTINGS_UPDATE);

    Livewire::actingAs($user)
        ->test('pages::settings.system')
        ->set('dashboardLogoUpload', UploadedFile::fake()->create('logo.pdf', 100, 'application/pdf'))
        ->call('saveBranding')
        ->assertHasErrors(['dashboardLogoUpload']);

    Livewire::actingAs($user)
        ->test('pages::settings.system')
        ->set('dashboardLogoUpload', UploadedFile::fake()->image('logo.png')->size(2049))
        ->call('saveBranding')
        ->assertHasErrors(['dashboardLogoUpload']);

    expect(SchoolSetting::query()->whereNotNull('dashboard_logo')->exists())->toBeFalse();
});

test('localization and usability reject unsupported values', function () {
    Permission::findOrCreate(Permissions::SETTINGS_UPDATE);
    $user = User::factory()->create();
    $user->givePermissionTo(Permissions::SETTINGS_UPDATE);

    Livewire::actingAs($user)
        ->test('pages::settings.system')
        ->set('currencyCode', 'BTC')
        ->set('timezone', 'Not/A_Timezone')
        ->call('saveLocalization')
        ->assertHasErrors(['currencyCode', 'timezone']);

    Livewire::actingAs($user)
        ->test('pages::settings.system')
        ->set('recordsPerPage', 500)
        ->call('saveUsability')
        ->assertHasErrors(['recordsPerPage']);
});
