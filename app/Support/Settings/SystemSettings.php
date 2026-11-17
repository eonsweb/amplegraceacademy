<?php

namespace App\Support\Settings;

use App\Models\SchoolSetting;
use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class SystemSettings
{
    public const CACHE_KEY = 'system.settings.v2';

    /** @var array<string, string> */
    public const BRANDING_DEFAULTS = [
        'dashboard_motto' => 'Shaping Futures, Transforming Lives',
        'login_heading' => 'Welcome Back',
        'login_message' => 'Sign in to your school management account.',
        'copyright_company' => 'eonsweb solution',
        'primary_color' => 'blue',
    ];

    /** @var list<string> */
    public const ASSET_KEYS = ['dashboard_logo', 'login_logo', 'dark_logo', 'favicon'];

    /** @var array<string, array<int, string>> */
    public const PALETTES = [
        'blue' => [50 => '#eff6ff', 100 => '#dbeafe', 200 => '#bfdbfe', 300 => '#93c5fd', 400 => '#60a5fa', 500 => '#3b82f6', 600 => '#2563eb', 700 => '#1d4ed8', 800 => '#1e40af', 900 => '#1e3a8a', 950 => '#172554'],
        'green' => [50 => '#f0fdf4', 100 => '#dcfce7', 200 => '#bbf7d0', 300 => '#86efac', 400 => '#4ade80', 500 => '#22c55e', 600 => '#16a34a', 700 => '#15803d', 800 => '#166534', 900 => '#14532d', 950 => '#052e16'],
        'red' => [50 => '#fef2f2', 100 => '#fee2e2', 200 => '#fecaca', 300 => '#fca5a5', 400 => '#f87171', 500 => '#ef4444', 600 => '#dc2626', 700 => '#b91c1c', 800 => '#991b1b', 900 => '#7f1d1d', 950 => '#450a0a'],
        'purple' => [50 => '#faf5ff', 100 => '#f3e8ff', 200 => '#e9d5ff', 300 => '#d8b4fe', 400 => '#c084fc', 500 => '#a855f7', 600 => '#9333ea', 700 => '#7e22ce', 800 => '#6b21a8', 900 => '#581c87', 950 => '#3b0764'],
        'orange' => [50 => '#fff7ed', 100 => '#ffedd5', 200 => '#fed7aa', 300 => '#fdba74', 400 => '#fb923c', 500 => '#f97316', 600 => '#ea580c', 700 => '#c2410c', 800 => '#9a3412', 900 => '#7c2d12', 950 => '#431407'],
        'teal' => [50 => '#f0fdfa', 100 => '#ccfbf1', 200 => '#99f6e4', 300 => '#5eead4', 400 => '#2dd4bf', 500 => '#14b8a6', 600 => '#0d9488', 700 => '#0f766e', 800 => '#115e59', 900 => '#134e4a', 950 => '#042f2e'],
    ];

    /** @var list<string> */
    public const DATE_FORMATS = ['DD/MM/YYYY', 'MM/DD/YYYY', 'YYYY-MM-DD'];

    /** @var list<string> */
    public const TIME_FORMATS = ['12-hour', '24-hour'];

    /** @var list<int> */
    public const RECORDS_PER_PAGE_OPTIONS = [10, 25, 50, 100];

    /** @var array<string, array{name: string, symbol: string, decimals: int, position: string}> */
    public const CURRENCIES = [
        'GHS' => ['name' => 'Ghana Cedi', 'symbol' => 'GH₵', 'decimals' => 2, 'position' => 'before'],
        'USD' => ['name' => 'US Dollar', 'symbol' => '$', 'decimals' => 2, 'position' => 'before'],
        'GBP' => ['name' => 'British Pound', 'symbol' => '£', 'decimals' => 2, 'position' => 'before'],
        'EUR' => ['name' => 'Euro', 'symbol' => '€', 'decimals' => 2, 'position' => 'before'],
    ];

    /** @var list<string> */
    private const KEYS = [
        'school_name',
        'school_initials',
        'contact_email',
        'phone',
        'address',
        'dashboard_logo',
        'login_logo',
        'favicon',
        'dark_logo',
        'dashboard_motto',
        'login_heading',
        'login_message',
        'copyright_company',
        'primary_color',
        'currency_code',
        'date_format',
        'time_format',
        'timezone',
        'records_per_page',
    ];

    /** @var array<string, mixed>|null */
    private ?array $resolved = null;

    /** @return array<string, mixed> */
    public function all(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $settings = Cache::rememberForever(self::CACHE_KEY, function (): array {
            $stored = SchoolSetting::query()->find(1)?->only(self::KEYS) ?? [];

            return array_replace($this->defaults(), $stored);
        });

        return $this->resolved = $settings;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    public function update(array $values): array
    {
        if (isset($values['school_initials'])) {
            $values['school_initials'] = str($values['school_initials'])->trim()->upper()->toString();
        }

        $unexpectedKeys = array_diff(array_keys($values), self::KEYS);

        if ($unexpectedKeys !== []) {
            throw new InvalidArgumentException('Unsupported system setting: '.$unexpectedKeys[0]);
        }

        $current = $this->all();
        $changedKeys = array_values(array_filter(
            array_keys($values),
            fn (string $key): bool => ($current[$key] ?? null) !== $values[$key],
        ));

        if ($changedKeys === []) {
            return [];
        }

        DB::transaction(function () use ($values): void {
            $settings = SchoolSetting::query()->lockForUpdate()->findOrNew(1);

            if (! $settings->exists) {
                $settings->id = 1;
                $settings->school_name = (string) config('app.name', 'Ample Grace Academy');
            }

            $settings->fill($values)->save();
        });

        $this->forget();

        return $changedKeys;
    }

    public function forget(): void
    {
        $this->resolved = null;
        Cache::forget(self::CACHE_KEY);
    }

    public function schoolName(): string
    {
        return (string) $this->get('school_name', config('app.name', 'Ample Grace Academy'));
    }

    public function schoolInitials(): ?string
    {
        $initials = $this->get('school_initials');

        return is_string($initials) && $initials !== '' ? $initials : null;
    }

    public function currency(): string
    {
        $currency = (string) $this->get('currency_code', 'GHS');

        return isset(self::CURRENCIES[$currency]) ? $currency : 'GHS';
    }

    public function currencySymbol(): string
    {
        return self::CURRENCIES[$this->currency()]['symbol'];
    }

    public function timezone(): string
    {
        return (string) $this->get('timezone', 'Africa/Accra');
    }

    public function recordsPerPage(): int
    {
        $recordsPerPage = (int) $this->get('records_per_page', 25);

        return in_array($recordsPerPage, self::RECORDS_PER_PAGE_OPTIONS, true) ? $recordsPerPage : 25;
    }

    public function dashboardLogoUrl(): string
    {
        return $this->assetUrl('dashboard_logo') ?? asset('images/branding/ample-grace-logo.png');
    }

    public function loginLogoUrl(): string
    {
        return $this->assetUrl('login_logo') ?? $this->dashboardLogoUrl();
    }

    public function faviconUrl(): string
    {
        return $this->assetUrl('favicon') ?? asset('favicon.ico');
    }

    public function darkLogoUrl(): string
    {
        return $this->assetUrl('dark_logo') ?? $this->dashboardLogoUrl();
    }

    public function brandingText(string $key): string
    {
        if (! array_key_exists($key, self::BRANDING_DEFAULTS)) {
            throw new InvalidArgumentException('Unsupported branding setting.');
        }

        return (string) $this->get($key, self::BRANDING_DEFAULTS[$key]);
    }

    public function primaryColor(): string
    {
        $color = $this->brandingText('primary_color');

        return isset(self::PALETTES[$color]) ? $color : 'blue';
    }

    public function copyright(): string
    {
        return '© '.now($this->timezone())->year.' '.$this->brandingText('copyright_company').'. All rights reserved.';
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, UploadedFile|null>  $uploads
     * @return list<string>
     */
    public function saveBranding(array $values, array $uploads = []): array
    {
        $newPaths = [];

        try {
            foreach ($uploads as $key => $upload) {
                if (! in_array($key, self::ASSET_KEYS, true)) {
                    throw new InvalidArgumentException('Unsupported branding asset key.');
                }
                if ($upload === null) {
                    continue;
                }
                $path = $upload->store('branding', 'public');
                if ($path === false) {
                    throw new RuntimeException('Unable to store branding image.');
                }
                $newPaths[$key] = $path;
                if (! Storage::disk('public')->exists($path)) {
                    throw new RuntimeException('Stored branding image could not be verified.');
                }
            }

            $oldPaths = [];
            $changedKeys = DB::transaction(function () use ($values, $newPaths, &$oldPaths): array {
                $current = SchoolSetting::query()->lockForUpdate()->find(1);
                foreach (self::ASSET_KEYS as $key) {
                    if (array_key_exists($key, $values + $newPaths)) {
                        $oldPaths[] = $current?->getAttribute($key);
                    }
                }
                $this->forget();

                return $this->update(array_replace($values, $newPaths));
            });
        } catch (Throwable $exception) {
            $this->forget();
            foreach ($newPaths as $path) {
                $this->deleteUnreferencedAsset($path);
            }
            throw $exception;
        }

        $this->forget();
        DB::afterRollBack(function () use ($newPaths): void {
            $this->forget();
            foreach ($newPaths as $path) {
                $this->deleteUnreferencedAsset($path);
            }
        });
        DB::afterCommit(function () use ($oldPaths): void {
            $this->forget();
            foreach (array_unique($oldPaths) as $path) {
                $this->deleteUnreferencedAsset($path);
            }
        });

        return $changedKeys;
    }

    private function deleteUnreferencedAsset(?string $path): void
    {
        if ($path === null || ! $this->isSafeAssetPath($path)) {
            return;
        }

        try {
            $referenced = SchoolSetting::query()->where(function ($query) use ($path): void {
                foreach (self::ASSET_KEYS as $key) {
                    $query->orWhere($key, $path);
                }
            })->exists();

            if (! $referenced && ! Storage::disk('public')->delete($path)) {
                throw new RuntimeException('Unable to remove obsolete branding image.');
            }
            Cache::forget('branding.asset.'.hash('sha256', $path));
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function isSafeAssetPath(string $path): bool
    {
        return preg_match('/\Abranding\/[a-zA-Z0-9_-]+\.(?:png|jpe?g|webp|ico)\z/i', $path) === 1;
    }

    public function assetPath(string $key): ?string
    {
        if (! in_array($key, self::ASSET_KEYS, true)) {
            throw new InvalidArgumentException('Unsupported branding asset key.');
        }

        $path = $this->get($key);

        return is_string($path) && $this->isSafeAssetPath($path) ? $path : null;
    }

    public function formatMoney(int|float|string|null $amount): string
    {
        if ($amount === null) {
            return '—';
        }

        if (! is_numeric($amount)) {
            throw new InvalidArgumentException('Money amounts must be numeric or null.');
        }

        $currency = self::CURRENCIES[$this->currency()];
        $numericAmount = (float) $amount;

        return $this->formatCurrencyValue($numericAmount, $currency);
    }

    public function formatDate(DateTimeInterface|string|null $date): string
    {
        if ($date === null || $date === '') {
            return '—';
        }

        $format = match ((string) $this->get('date_format', 'DD/MM/YYYY')) {
            'MM/DD/YYYY' => 'm/d/Y',
            'YYYY-MM-DD' => 'Y-m-d',
            default => 'd/m/Y',
        };

        return $this->localizedDate($date)->format($format);
    }

    public function formatTime(DateTimeInterface|string|null $date): string
    {
        if ($date === null || $date === '') {
            return '—';
        }

        $format = $this->get('time_format', '12-hour') === '24-hour' ? 'H:i' : 'g:i A';

        return $this->localizedDate($date)->format($format);
    }

    /** @return array<string, mixed> */
    private function defaults(): array
    {
        return [
            ...self::BRANDING_DEFAULTS,
            'dark_logo' => null,
            'school_name' => (string) config('app.name', 'Ample Grace Academy'),
            'school_initials' => null,
            'contact_email' => null,
            'phone' => null,
            'address' => null,
            'dashboard_logo' => null,
            'login_logo' => null,
            'favicon' => null,
            'currency_code' => 'GHS',
            'date_format' => 'DD/MM/YYYY',
            'time_format' => '12-hour',
            'timezone' => 'Africa/Accra',
            'records_per_page' => 25,
        ];
    }

    private function assetUrl(string $key): ?string
    {
        $path = $this->assetPath($key);

        if ($path === null) {
            return null;
        }

        $exists = Cache::remember('branding.asset.'.hash('sha256', $path), 300,
            fn (): bool => Storage::disk('public')->exists($path));

        return $exists ? Storage::disk('public')->url($path) : null;
    }

    /** @param array{name: string, symbol: string, decimals: int, position: string} $currency */
    private function formatCurrencyValue(float $amount, array $currency): string
    {
        $sign = $amount < 0 ? '-' : '';
        $formatted = number_format(abs($amount), $currency['decimals'], '.', ',');

        return $currency['position'] === 'after'
            ? $sign.$formatted.$currency['symbol']
            : $sign.$currency['symbol'].$formatted;
    }

    private function localizedDate(DateTimeInterface|string $date): Carbon
    {
        return Carbon::parse($date)->setTimezone($this->timezone());
    }
}
