<?php

namespace App\Support\Settings;

use App\Models\SchoolSetting;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

final class SystemSettings
{
    public const CACHE_KEY = 'system.settings';

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
            $settings = SchoolSetting::query()->findOrNew(1);

            if (! $settings->exists) {
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

    public function assetPath(string $key): ?string
    {
        if (! in_array($key, ['dashboard_logo', 'login_logo', 'favicon'], true)) {
            throw new InvalidArgumentException('Unsupported branding asset key.');
        }

        $path = $this->get($key);

        return is_string($path) && str_starts_with($path, 'branding/') ? $path : null;
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

        return $path === null ? null : Storage::disk('public')->url($path);
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
