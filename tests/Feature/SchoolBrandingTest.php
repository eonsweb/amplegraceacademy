<?php

use App\Models\SchoolSetting;
use App\Models\User;
use App\Support\Authorization\Permissions;
use App\Support\Settings\SystemSettings;
use Database\Seeders\SchoolSettingSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

function brandingAdministrator(): User
{
    Permission::findOrCreate(Permissions::SETTINGS_UPDATE);
    $user = User::factory()->create();
    $user->givePermissionTo(Permissions::SETTINGS_UPDATE);

    return $user;
}

test('saving general settings preserves saved branding and pending branding edits', function () {
    SchoolSetting::factory()->create(['id' => 1, 'school_name' => 'Saved Academy', 'school_initials' => 'SA']);

    Livewire::actingAs(brandingAdministrator())->test('pages::settings.system')
        ->set('schoolName', '')
        ->set('contactEmail', 'office@example.test')
        ->call('save')->assertHasNoErrors()
        ->assertSet('schoolName', '');

    $this->assertDatabaseHas('school_settings', [
        'id' => 1, 'school_name' => 'Saved Academy', 'contact_email' => 'office@example.test',
    ]);
});

test('missing branding values resolve defaults without creating a school row', function () {
    $settings = app(SystemSettings::class);

    expect($settings->primaryColor())->toBe('blue')
        ->and($settings->brandingText('dashboard_motto'))->toBe('Shaping Futures, Transforming Lives')
        ->and($settings->brandingText('copyright_company'))->toBe('eonsweb solution')
        ->and($settings->darkLogoUrl())->toBe($settings->dashboardLogoUrl());
    $this->get('/')->assertSee('Welcome Back')->assertSee('Sign in to your school management account.');
    expect(SchoolSetting::query()->exists())->toBeFalse();
});

test('branding save persists identity and each preset without changing personal appearance', function (string $color) {
    $user = brandingAdministrator();
    $user->update(['theme' => 'dark']);

    Livewire::actingAs($user)->test('pages::settings.system')
        ->set('schoolName', 'New Academy')->set('dashboardMotto', 'Learn together')
        ->set('loginHeading', 'Hello learners')->set('loginMessage', 'Enter your account')
        ->set('copyrightCompany', 'Example Software')->set('primaryColor', $color)
        ->call('saveBranding')->assertHasNoErrors();

    $this->assertDatabaseHas('school_settings', [
        'id' => 1, 'school_name' => 'New Academy', 'dashboard_motto' => 'Learn together',
        'login_heading' => 'Hello learners', 'login_message' => 'Enter your account',
        'copyright_company' => 'Example Software', 'primary_color' => $color,
    ]);
    expect($user->fresh()->theme)->toBe('dark');
    expect((new SystemSettings)->primaryColor())->toBe($color);
})->with(['blue', 'green', 'red', 'purple', 'orange', 'teal']);

test('anonymous login renders escaped public branding and custom image', function () {
    Storage::fake('public');
    Storage::disk('public')->put('branding/login.png', 'image');
    SchoolSetting::factory()->create([
        'id' => 1, 'school_name' => '<script>School</script>',
        'login_heading' => '<img src=x onerror=alert(1)>', 'login_message' => '<b>Message</b>',
        'login_logo' => 'branding/login.png', 'contact_email' => 'private-contact@example.test', 'primary_color' => 'teal',
    ]);

    $this->get('/')->assertSee('&lt;script&gt;School&lt;/script&gt;', false)
        ->assertSeeInOrder(['<title>', 'Log in - &lt;script&gt;School&lt;/script&gt;', '</title>'], false)
        ->assertSee('&lt;img src=x onerror=alert(1)&gt;', false)
        ->assertSee('&lt;b&gt;Message&lt;/b&gt;', false)
        ->assertSee('/storage/branding/login.png', false)
        ->assertSee('<meta name="theme-color" content="#0f766e">', false)
        ->assertDontSee('<script>School</script>', false)
        ->assertDontSee('private-contact@example.test');
});

test('missing custom assets and unsafe paths use defaults', function () {
    Storage::fake('public');
    SchoolSetting::factory()->create(['id' => 1, 'dashboard_logo' => 'branding/missing.png', 'dark_logo' => 'branding/../../private.png', 'login_logo' => 'branding/missing-login.png', 'favicon' => 'branding/missing.ico']);
    $settings = app(SystemSettings::class);

    expect($settings->dashboardLogoUrl())->toEndWith('/images/branding/ample-grace-logo.png')
        ->and($settings->darkLogoUrl())->toBe($settings->dashboardLogoUrl())
        ->and($settings->loginLogoUrl())->toBe($settings->dashboardLogoUrl())
        ->and($settings->faviconUrl())->toEndWith('/favicon.ico');
});

test('dashboard and shared logo render configured identity and timezone copyright', function () {
    Storage::fake('public');
    Storage::disk('public')->put('branding/light.png', 'image');
    Storage::disk('public')->put('branding/dark.png', 'image');
    SchoolSetting::factory()->create(['id' => 1, 'school_name' => 'Sample Academy', 'dashboard_logo' => 'branding/light.png', 'dark_logo' => 'branding/dark.png', 'dashboard_motto' => '<b>Learning</b>', 'copyright_company' => '<b>Company</b>', 'timezone' => 'America/New_York']);
    $user = User::factory()->create();
    Permission::findOrCreate(Permissions::DASHBOARD_VIEW);
    $user->givePermissionTo(Permissions::DASHBOARD_VIEW);
    $this->travelTo(now()->setDate(2027, 1, 1)->setTime(0, 30)->utc());

    $this->actingAs($user)->get(route('dashboard'))
        ->assertSee('Sample Academy')->assertSee('branding/light.png', false)->assertSee('branding/dark.png', false)
        ->assertSee('&lt;b&gt;Learning&lt;/b&gt;', false)
        ->assertSee('2026 &lt;b&gt;Company&lt;/b&gt;. All rights reserved.', false)
        ->assertDontSee('<b>Company</b>', false);
});

test('independent text resets preserve other saved and unsaved settings', function (string $key, string $property, string $default) {
    SchoolSetting::factory()->create(['id' => 1, 'dashboard_motto' => 'Custom motto', 'login_heading' => 'Custom heading', 'login_message' => 'Custom message', 'copyright_company' => 'Custom company', 'primary_color' => 'green']);
    $before = SchoolSetting::query()->findOrFail(1)->getAttributes();

    Livewire::actingAs(brandingAdministrator())->test('pages::settings.system')
        ->set('schoolName', 'Unsaved school')->call('resetBrandingSetting', $key)
        ->assertSet($property, $default)->assertSet('schoolName', 'Unsaved school')->assertHasNoErrors();

    $after = SchoolSetting::query()->findOrFail(1)->getAttributes();
    expect($after[$key])->toBe($default);
    unset($before[$key], $after[$key], $before['updated_at'], $after['updated_at']);
    expect($after)->toBe($before);
})->with([
    ['dashboard_motto', 'dashboardMotto', 'Shaping Futures, Transforming Lives'],
    ['login_heading', 'loginHeading', 'Welcome Back'],
    ['login_message', 'loginMessage', 'Sign in to your school management account.'],
    ['copyright_company', 'copyrightCompany', 'eonsweb solution'],
    ['primary_color', 'primaryColor', 'blue'],
]);

test('branding input rejects invalid values while retaining edits', function (string $property, string $value) {
    Livewire::actingAs(brandingAdministrator())->test('pages::settings.system')
        ->set($property, $value)->call('saveBranding')->assertHasErrors([$property])->assertSet($property, $value);
    expect(SchoolSetting::query()->exists())->toBeFalse();
})->with([
    ['primaryColor', '#fff; color:red'], ['dashboardMotto', str_repeat('a', 256)],
    ['loginHeading', str_repeat('a', 101)], ['loginMessage', str_repeat('a', 301)],
    ['copyrightCompany', str_repeat('a', 151)], ['dashboardMotto', ''],
]);

test('view only users cannot save reset or request upload URLs', function (string $method, array $arguments) {
    Permission::findOrCreate(Permissions::SETTINGS_VIEW);
    Permission::findOrCreate(Permissions::SETTINGS_UPDATE);
    $user = User::factory()->create();
    $user->givePermissionTo(Permissions::SETTINGS_VIEW);

    Livewire::actingAs($user)->test('pages::settings.system')->call($method, ...$arguments)->assertForbidden();
    expect(SchoolSetting::query()->exists())->toBeFalse();
})->with([
    ['saveBranding', []], ['resetBrandingSetting', ['primary_color']], ['removeBrandingAsset', ['dark_logo']],
    ['_startUpload', ['darkLogoUpload', [['name' => 'logo.png', 'size' => 10, 'type' => 'image/png']], false]],
    ['_finishUpload', ['darkLogoUpload', [], false]],
]);

test('new branding images replace safely and dark logo resets independently', function () {
    Storage::fake('public');
    Storage::disk('public')->put('branding/old.png', 'old');
    SchoolSetting::factory()->create(['id' => 1, 'dashboard_logo' => 'branding/old.png']);
    $user = brandingAdministrator();

    Livewire::actingAs($user)->test('pages::settings.system')
        ->set('dashboardLogoUpload', UploadedFile::fake()->image('new.png'))
        ->set('darkLogoUpload', UploadedFile::fake()->image('dark.png'))
        ->set('loginLogoUpload', UploadedFile::fake()->image('login.png'))
        ->set('faviconUpload', UploadedFile::fake()->image('favicon.png', 32, 32))
        ->call('saveBranding')->assertHasNoErrors();

    $saved = SchoolSetting::query()->findOrFail(1);
    Storage::disk('public')->assertMissing('branding/old.png');
    foreach (SystemSettings::ASSET_KEYS as $key) {
        Storage::disk('public')->assertExists($saved->{$key});
    }
    Livewire::test('pages::settings.system')->call('removeBrandingAsset', 'dark_logo')->assertHasNoErrors();
    Storage::disk('public')->assertMissing($saved->dark_logo);
    Storage::disk('public')->assertExists($saved->dashboard_logo);
    expect(app(SystemSettings::class)->darkLogoUrl())->toBe(app(SystemSettings::class)->dashboardLogoUrl());
});

test('replacing an asset does not delete a path referenced by another branding setting', function () {
    Storage::fake('public');
    Storage::disk('public')->put('branding/shared.png', 'image');
    SchoolSetting::factory()->create(['id' => 1, 'dashboard_logo' => 'branding/shared.png', 'login_logo' => 'branding/shared.png']);

    app(SystemSettings::class)->saveBranding(['dashboard_logo' => null]);

    Storage::disk('public')->assertExists('branding/shared.png');
    $this->assertDatabaseHas('school_settings', ['id' => 1, 'dashboard_logo' => null, 'login_logo' => 'branding/shared.png']);
});

test('failed database save preserves previous assets and cleans new uploads', function () {
    Storage::fake('public');
    Storage::disk('public')->put('branding/old.png', 'old');
    SchoolSetting::factory()->create(['id' => 1, 'dashboard_logo' => 'branding/old.png']);
    SchoolSetting::saving(function (): void {
        throw new RuntimeException('Simulated database failure');
    });

    try {
        Livewire::actingAs(brandingAdministrator())->test('pages::settings.system')
            ->set('dashboardLogoUpload', UploadedFile::fake()->image('new.png'))->call('saveBranding')->assertHasErrors(['branding']);
    } finally {
        SchoolSetting::flushEventListeners();
    }

    expect(Storage::disk('public')->allFiles('branding'))->toBe(['branding/old.png']);
    $this->assertDatabaseHas('school_settings', ['id' => 1, 'dashboard_logo' => 'branding/old.png']);
});

test('invalid raster content and excessive dimensions are rejected', function (string $kind) {
    Storage::fake('public');
    $file = match ($kind) {
        'svg' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'),
        'forged' => UploadedFile::fake()->createWithContent('logo.png', '<?php echo "bad";'),
        'dimensions' => UploadedFile::fake()->image('wide.png', 2561, 1),
    };

    Livewire::actingAs(brandingAdministrator())->test('pages::settings.system')
        ->set('loginLogoUpload', $file)->call('saveBranding')->assertHasErrors(['loginLogoUpload']);
    expect(Storage::disk('public')->allFiles('branding'))->toBe([]);
})->with(['svg', 'forged', 'dimensions']);

test('defaults and seeders preserve existing school branding', function () {
    SchoolSetting::factory()->create(['id' => 1, 'school_name' => 'Existing School', 'primary_color' => 'purple', 'dashboard_motto' => 'Keep this']);

    $this->seed(SchoolSettingSeeder::class);
    $this->seed(SchoolSettingSeeder::class);

    $this->assertDatabaseHas('school_settings', ['id' => 1, 'school_name' => 'Existing School', 'primary_color' => 'purple', 'dashboard_motto' => 'Keep this']);
});

test('branding resolution loads the settings row once and shares the cache', function () {
    SchoolSetting::factory()->create(['id' => 1, 'primary_color' => 'purple']);
    $settings = app(SystemSettings::class);
    $settings->forget();
    DB::enableQueryLog();

    $settings->schoolName();
    $settings->primaryColor();
    $settings->copyright();
    $settings->dashboardLogoUrl();
    $settings->darkLogoUrl();
    (new SystemSettings)->primaryColor();

    $queries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'school_settings'));
    DB::disableQueryLog();
    expect($queries)->toHaveCount(1);
});

test('preset button and text colors meet AA contrast in light and dark mode', function (string $color) {
    $luminance = function (string $hex): float {
        $channels = array_map(function (string $channel): float {
            $value = hexdec($channel) / 255;

            return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, str_split(substr($hex, 1), 2));

        return $channels[0] * 0.2126 + $channels[1] * 0.7152 + $channels[2] * 0.0722;
    };
    $palette = SystemSettings::PALETTES[$color];

    expect(1.05 / ($luminance($palette[700]) + 0.05))->toBeGreaterThanOrEqual(4.5);
    expect(($luminance($palette[300]) + 0.05) / ($luminance('#262626') + 0.05))->toBeGreaterThanOrEqual(4.5);
})->with(['blue', 'green', 'red', 'purple', 'orange', 'teal']);

test('restoring blue updates the current page and clears only its validation error', function () {
    SchoolSetting::factory()->create(['id' => 1, 'primary_color' => 'purple']);

    Livewire::actingAs(brandingAdministrator())->test('pages::settings.system')
        ->set('schoolName', 'Unsaved school')
        ->set('primaryColor', 'invalid')
        ->call('saveBranding')->assertHasErrors(['primaryColor'])
        ->call('resetBrandingSetting', 'primary_color')
        ->assertHasNoErrors(['primaryColor'])
        ->assertSet('schoolName', 'Unsaved school')
        ->assertDispatched('school-color-updated', color: 'blue', hex: '#1d4ed8');

    expect((new SystemSettings)->primaryColor())->toBe('blue');
});

test('every image can be restored independently while keeping pending text edits', function (string $key) {
    Storage::fake('public');
    $paths = [];
    foreach (SystemSettings::ASSET_KEYS as $asset) {
        $paths[$asset] = 'branding/'.$asset.'.png';
        Storage::disk('public')->put($paths[$asset], 'image');
    }
    SchoolSetting::factory()->create(['id' => 1, ...$paths]);

    Livewire::actingAs(brandingAdministrator())->test('pages::settings.system')
        ->set('loginHeading', 'Unsaved welcome')
        ->call('removeBrandingAsset', $key)
        ->assertHasNoErrors()
        ->assertSet('loginHeading', 'Unsaved welcome');

    $this->assertDatabaseHas('school_settings', ['id' => 1, $key => null]);
    Storage::disk('public')->assertMissing($paths[$key]);
    foreach (array_diff(SystemSettings::ASSET_KEYS, [$key]) as $asset) {
        Storage::disk('public')->assertExists($paths[$asset]);
        $this->assertDatabaseHas('school_settings', ['id' => 1, $asset => $paths[$asset]]);
    }
})->with(SystemSettings::ASSET_KEYS);

test('obsolete images remain available until the surrounding transaction commits', function () {
    Storage::fake('public');
    Storage::disk('public')->put('branding/old.png', 'image');
    SchoolSetting::factory()->create(['id' => 1, 'dashboard_logo' => 'branding/old.png']);

    DB::transaction(function (): void {
        app(SystemSettings::class)->saveBranding(['dashboard_logo' => null]);
        Storage::disk('public')->assertExists('branding/old.png');
    });

    Storage::disk('public')->assertMissing('branding/old.png');
});

test('rolling back a surrounding transaction removes new images and retains the previous image', function () {
    Storage::fake('public');
    Storage::disk('public')->put('branding/old.png', 'image');
    SchoolSetting::factory()->create(['id' => 1, 'dashboard_logo' => 'branding/old.png']);

    DB::beginTransaction();
    app(SystemSettings::class)->saveBranding([], ['dashboard_logo' => UploadedFile::fake()->image('new.png')]);
    DB::rollBack();

    expect(Storage::disk('public')->allFiles('branding'))->toBe(['branding/old.png']);
    expect(app(SystemSettings::class)->dashboardLogoUrl())->toEndWith('/storage/branding/old.png');
    $this->assertDatabaseHas('school_settings', ['id' => 1, 'dashboard_logo' => 'branding/old.png']);
});

test('favicon rejects unreadable content malformed directories and oversized images', function (string $kind) {
    Storage::fake('public');
    $file = match ($kind) {
        'empty' => UploadedFile::fake()->createWithContent('favicon.ico', ''),
        'truncated' => UploadedFile::fake()->createWithContent('favicon.ico', "\x00\x00\x01\x00"),
        'directory' => UploadedFile::fake()->createWithContent('favicon.ico', pack('vvv', 0, 1, 1).str_repeat("\x00", 16)),
        'pixels' => UploadedFile::fake()->createWithContent('favicon.ico', pack('vvv', 0, 1, 1).pack('CCCCvvVV', 32, 32, 0, 0, 1, 32, 1, 22).'x'),
        'dimensions' => UploadedFile::fake()->image('favicon.png', 513, 513),
        'size' => UploadedFile::fake()->image('favicon.png', 32, 32)->size(513),
    };

    Livewire::actingAs(brandingAdministrator())->test('pages::settings.system')
        ->set('faviconUpload', $file)->call('saveBranding')->assertHasErrors(['faviconUpload']);

    expect(Storage::disk('public')->allFiles('branding'))->toBe([]);
    expect(SchoolSetting::query()->exists())->toBeFalse();
})->with(['empty', 'truncated', 'directory', 'pixels', 'dimensions', 'size']);

test('valid ICO uploads use generated paths and provide a selected image preview', function (string $format) {
    Storage::fake('public');
    $pixels = $format === 'png'
        ? UploadedFile::fake()->image('icon.png', 32, 32)->get()
        : pack('V3v2V6', 40, 32, 64, 1, 32, 0, 4096, 0, 0, 0, 0).str_repeat("\x00", 4224);
    $icon = pack('vvv', 0, 1, 1).pack('CCCCvvVV', 32, 32, 0, 0, 1, 32, strlen($pixels), 22).$pixels;

    Livewire::actingAs(brandingAdministrator())->test('pages::settings.system')
        ->set('faviconUpload', UploadedFile::fake()->createWithContent('favicon.ico', $icon))
        ->assertSee('Favicon selected image preview')
        ->call('saveBranding')->assertHasNoErrors();

    $path = SchoolSetting::query()->findOrFail(1)->favicon;
    expect($path)->toStartWith('branding/')->toEndWith('.ico')->not->toContain('favicon');
    Storage::disk('public')->assertExists($path);
})->with(['png', 'bitmap']);

test('a storage failure preserves the previous branding and reports a validation error', function () {
    $disk = Storage::fake('public');
    $disk->put('branding/old.png', 'image');
    SchoolSetting::factory()->create(['id' => 1, 'dashboard_logo' => 'branding/old.png']);
    $component = Livewire::actingAs(brandingAdministrator())->test('pages::settings.system')
        ->set('dashboardLogoUpload', UploadedFile::fake()->image('replacement.png'));
    $failedDisk = Mockery::mock($disk);
    $failedDisk->shouldReceive('put')->once()->andReturn(false);
    Storage::set('public', $failedDisk);

    $component->call('saveBranding')->assertHasErrors(['branding']);

    $this->assertDatabaseHas('school_settings', ['id' => 1, 'dashboard_logo' => 'branding/old.png']);
    expect($disk->allFiles('branding'))->toBe(['branding/old.png']);
});

test('branding theme leaves warning success and danger palette variables untouched', function () {
    SchoolSetting::factory()->create(['id' => 1, 'primary_color' => 'purple']);

    $html = $this->get('/')->getContent();
    preg_match('/<style id="school-branding-theme">(.*?)<\/style>/s', $html, $theme);

    expect($theme[1])->toContain('--color-accent:', '--color-brand-700:', ':root.dark')
        ->not->toContain('--color-amber-', '--color-yellow-', '--color-green-', '--color-red-');
});
