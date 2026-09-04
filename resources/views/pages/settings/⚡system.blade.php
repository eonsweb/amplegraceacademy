<?php

use App\Events\SystemSettingsChanged;
use App\Support\Authorization\Permissions;
use App\Support\Settings\SystemSettings;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

new #[Title('System settings')] class extends Component {
    use WithFileUploads;

    public string $schoolName = '';
    public string $contactEmail = '';
    public string $phone = '';
    public string $address = '';
    public ?TemporaryUploadedFile $dashboardLogoUpload = null;
    public ?TemporaryUploadedFile $loginLogoUpload = null;
    public ?TemporaryUploadedFile $faviconUpload = null;
    public string $currencyCode = 'GHS';
    public string $dateFormat = 'DD/MM/YYYY';
    public string $timeFormat = '12-hour';
    public string $timezone = 'Africa/Accra';
    public int $recordsPerPage = 25;

    /** @var array<string, array{name: string, symbol: string, decimals: int, position: string}> */
    public array $currencies = SystemSettings::CURRENCIES;

    /** @var list<string> */
    public array $timezones = [];

    public function mount(SystemSettings $settings): void
    {
        abort_unless(Gate::any([Permissions::SETTINGS_VIEW, Permissions::SETTINGS_UPDATE]), 403);

        $this->fillFromSettings($settings);
        $this->timezones = timezone_identifiers_list();
    }

    public function save(SystemSettings $settings): void
    {
        Gate::authorize(Permissions::SETTINGS_UPDATE);

        $validated = $this->validate([
            'schoolName' => ['required', 'string', 'max:255'],
            'contactEmail' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:500'],
        ]);

        $changedKeys = $settings->update([
            'school_name' => trim($validated['schoolName']),
            'contact_email' => filled($validated['contactEmail']) ? trim($validated['contactEmail']) : null,
            'phone' => filled($validated['phone']) ? trim($validated['phone']) : null,
            'address' => filled($validated['address']) ? trim($validated['address']) : null,
        ]);

        $this->dispatchSettingsChanged('settings.general_updated', 'general', $changedKeys);
        Flux::toast(variant: 'success', text: 'General settings updated.');
    }

    public function saveBranding(SystemSettings $settings): void
    {
        Gate::authorize(Permissions::SETTINGS_UPDATE);

        $this->validate([
            'dashboardLogoUpload' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'extensions:png,jpg,jpeg,webp', 'max:2048'],
            'loginLogoUpload' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'extensions:png,jpg,jpeg,webp', 'max:2048'],
            'faviconUpload' => ['nullable', 'file', 'mimes:png,ico,webp', 'extensions:png,ico,webp', 'max:512'],
        ]);

        $uploads = [
            'dashboard_logo' => $this->dashboardLogoUpload,
            'login_logo' => $this->loginLogoUpload,
            'favicon' => $this->faviconUpload,
        ];
        $oldPaths = [];
        $newPaths = [];

        foreach ($uploads as $key => $upload) {
            if ($upload === null) {
                continue;
            }

            $oldPaths[$key] = $settings->assetPath($key);
            $newPaths[$key] = $upload->store(path: 'branding', options: 'public');
        }

        if ($newPaths === []) {
            $this->addError('dashboardLogoUpload', 'Choose at least one branding image to upload.');

            return;
        }

        try {
            $changedKeys = $settings->update($newPaths);
        } catch (Throwable $exception) {
            Storage::disk('public')->delete(array_values($newPaths));

            throw $exception;
        }

        foreach ($changedKeys as $key) {
            $this->deleteCustomAsset($oldPaths[$key] ?? null);
        }

        $this->reset('dashboardLogoUpload', 'loginLogoUpload', 'faviconUpload');
        $this->dispatchSettingsChanged('settings.branding_updated', 'branding', $changedKeys);
        Flux::toast(variant: 'success', text: 'Branding updated.');
    }

    public function removeBrandingAsset(string $key, SystemSettings $settings): void
    {
        Gate::authorize(Permissions::SETTINGS_UPDATE);
        abort_unless(in_array($key, ['dashboard_logo', 'login_logo', 'favicon'], true), 404);

        $oldPath = $settings->assetPath($key);
        $changedKeys = $settings->update([$key => null]);

        $this->deleteCustomAsset($oldPath);
        $this->dispatchSettingsChanged('settings.branding_updated', 'branding', $changedKeys);
        Flux::toast(variant: 'success', text: 'Custom asset removed. The fallback is active.');
    }

    public function saveLocalization(SystemSettings $settings): void
    {
        Gate::authorize(Permissions::SETTINGS_UPDATE);

        $validated = $this->validate([
            'currencyCode' => ['required', Rule::in(array_keys(SystemSettings::CURRENCIES))],
            'dateFormat' => ['required', Rule::in(SystemSettings::DATE_FORMATS)],
            'timeFormat' => ['required', Rule::in(SystemSettings::TIME_FORMATS)],
            'timezone' => ['required', Rule::in(timezone_identifiers_list())],
        ]);

        $changedKeys = $settings->update([
            'currency_code' => $validated['currencyCode'],
            'date_format' => $validated['dateFormat'],
            'time_format' => $validated['timeFormat'],
            'timezone' => $validated['timezone'],
        ]);

        $this->dispatchSettingsChanged('settings.localization_updated', 'localization', $changedKeys);
        Flux::toast(variant: 'success', text: 'Localization settings updated.');
    }

    public function saveUsability(SystemSettings $settings): void
    {
        Gate::authorize(Permissions::SETTINGS_UPDATE);

        $validated = $this->validate([
            'recordsPerPage' => ['required', 'integer', Rule::in(SystemSettings::RECORDS_PER_PAGE_OPTIONS)],
        ]);

        $changedKeys = $settings->update([
            'records_per_page' => $validated['recordsPerPage'],
        ]);

        $this->dispatchSettingsChanged('settings.usability_updated', 'usability', $changedKeys);
        Flux::toast(variant: 'success', text: 'Usability settings updated.');
    }

    private function fillFromSettings(SystemSettings $settings): void
    {
        $this->schoolName = $settings->schoolName();
        $this->contactEmail = (string) $settings->get('contact_email', '');
        $this->phone = (string) $settings->get('phone', '');
        $this->address = (string) $settings->get('address', '');
        $this->currencyCode = $settings->currency();
        $this->dateFormat = (string) $settings->get('date_format', 'DD/MM/YYYY');
        $this->timeFormat = (string) $settings->get('time_format', '12-hour');
        $this->timezone = $settings->timezone();
        $this->recordsPerPage = $settings->recordsPerPage();
    }

    /** @param list<string> $changedKeys */
    private function dispatchSettingsChanged(string $action, string $group, array $changedKeys): void
    {
        if ($changedKeys !== []) {
            SystemSettingsChanged::dispatch($action, (int) auth()->id(), $group, $changedKeys);
        }
    }

    private function deleteCustomAsset(?string $path): void
    {
        if ($path !== null && str_starts_with($path, 'branding/')) {
            Storage::disk('public')->delete($path);
        }
    }
};
?>

<section class="w-full">
    @include('partials.settings-heading')
    @inject('systemSettings', 'App\Support\Settings\SystemSettings')

    <flux:heading level="2" class="sr-only">{{ __('System settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('System settings')" :subheading="__('Manage school-wide branding, localization, and usability')">
        @cannot(Permissions::SETTINGS_UPDATE)
            <flux:callout class="mb-5" variant="warning" icon="eye" heading="Read-only access" text="You can review these settings, but you do not have permission to change them." />
        @endcannot

        <div x-data="{ section: 'general' }" class="grid gap-5">
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-4" role="tablist" aria-label="System setting sections">
                @foreach (['general' => 'General', 'branding' => 'Branding', 'localization' => 'Localization', 'usability' => 'Usability'] as $key => $label)
                    <button type="button" role="tab" x-on:click="section = '{{ $key }}'" x-bind:aria-selected="section === '{{ $key }}'" x-bind:class="section === '{{ $key }}' ? 'bg-brand-700 text-white' : 'bg-zinc-100 text-zinc-700 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-200 dark:hover:bg-zinc-700'" class="rounded-lg px-3 py-2 text-sm font-medium transition-colors">{{ $label }}</button>
                @endforeach
            </div>

            <form x-show="section === 'general'" wire:submit="save" class="grid gap-5">
                <flux:input wire:model="schoolName" label="School name" maxlength="255" required :disabled="! auth()->user()->can(Permissions::SETTINGS_UPDATE)" />
                <flux:input wire:model="contactEmail" type="email" label="Contact email" maxlength="255" :disabled="! auth()->user()->can(Permissions::SETTINGS_UPDATE)" />
                <flux:input wire:model="phone" type="tel" label="Phone" maxlength="30" :disabled="! auth()->user()->can(Permissions::SETTINGS_UPDATE)" />
                <flux:textarea wire:model="address" label="Address" rows="4" maxlength="500" :disabled="! auth()->user()->can(Permissions::SETTINGS_UPDATE)" />
                @can(Permissions::SETTINGS_UPDATE)
                    <div class="flex justify-end"><flux:button type="submit" variant="primary">Save general settings</flux:button></div>
                @endcan
            </form>

            <form x-cloak x-show="section === 'branding'" wire:submit="saveBranding" class="grid gap-6">
                @foreach ([
                    ['property' => 'dashboardLogoUpload', 'key' => 'dashboard_logo', 'label' => 'Dashboard logo', 'url' => $systemSettings->dashboardLogoUrl(), 'hint' => 'PNG, JPG, or WebP up to 2 MB.'],
                    ['property' => 'loginLogoUpload', 'key' => 'login_logo', 'label' => 'Login logo', 'url' => $systemSettings->loginLogoUrl(), 'hint' => 'PNG, JPG, or WebP up to 2 MB. Falls back to the dashboard logo.'],
                    ['property' => 'faviconUpload', 'key' => 'favicon', 'label' => 'Favicon', 'url' => $systemSettings->faviconUrl(), 'hint' => 'PNG, ICO, or WebP up to 512 KB.'],
                ] as $asset)
                    <fieldset wire:key="branding-{{ $asset['key'] }}" class="grid gap-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                        <legend class="px-1 text-sm font-semibold text-zinc-900 dark:text-white">{{ $asset['label'] }}</legend>
                        <div class="flex items-center gap-4">
                            <img src="{{ $this->{$asset['property']} && in_array(strtolower($this->{$asset['property']}->getClientOriginalExtension()), ['png', 'jpg', 'jpeg', 'webp'], true) ? $this->{$asset['property']}->temporaryUrl() : $asset['url'] }}" alt="{{ $asset['label'] }} preview" class="size-16 rounded-lg border border-zinc-200 bg-white object-contain p-1 dark:border-zinc-700 dark:bg-zinc-800">
                            <div class="min-w-0 flex-1">
                                <input type="file" wire:model="{{ $asset['property'] }}" accept="{{ $asset['key'] === 'favicon' ? '.png,.ico,.webp' : '.png,.jpg,.jpeg,.webp' }}" class="block w-full text-sm text-zinc-600 file:mr-3 file:rounded-md file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:font-medium file:text-brand-800 hover:file:bg-brand-100 dark:text-zinc-300 dark:file:bg-brand-950 dark:file:text-brand-200" @disabled(! auth()->user()->can(Permissions::SETTINGS_UPDATE))>
                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $asset['hint'] }}</p>
                            </div>
                        </div>
                        <flux:error :name="$asset['property']" />
                        @if ($systemSettings->assetPath($asset['key']))
                            @can(Permissions::SETTINGS_UPDATE)
                                <div><flux:button type="button" size="sm" variant="danger" wire:click="removeBrandingAsset('{{ $asset['key'] }}')">Remove custom asset</flux:button></div>
                            @endcan
                        @endif
                    </fieldset>
                @endforeach
                @can(Permissions::SETTINGS_UPDATE)
                    <div class="flex justify-end"><flux:button type="submit" variant="primary">Upload selected assets</flux:button></div>
                @endcan
            </form>

            <form x-cloak x-show="section === 'localization'" wire:submit="saveLocalization" class="grid gap-5">
                <flux:select wire:model="currencyCode" label="Currency" :disabled="! auth()->user()->can(Permissions::SETTINGS_UPDATE)">
                    @foreach ($currencies as $code => $currency)
                        <flux:select.option wire:key="currency-{{ $code }}" :value="$code">{{ $currency['name'] }} ({{ $code }} — {{ $currency['symbol'] }})</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="dateFormat" label="Date format" :disabled="! auth()->user()->can(Permissions::SETTINGS_UPDATE)">
                    @foreach (SystemSettings::DATE_FORMATS as $format)
                        <flux:select.option wire:key="date-format-{{ $format }}" :value="$format">{{ $format }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="timeFormat" label="Time format" :disabled="! auth()->user()->can(Permissions::SETTINGS_UPDATE)">
                    <flux:select.option value="12-hour">12-hour (2:30 PM)</flux:select.option>
                    <flux:select.option value="24-hour">24-hour (14:30)</flux:select.option>
                </flux:select>
                <flux:select wire:model="timezone" label="Timezone" searchable :disabled="! auth()->user()->can(Permissions::SETTINGS_UPDATE)">
                    @foreach ($timezones as $timezoneOption)
                        <flux:select.option wire:key="timezone-{{ $timezoneOption }}" :value="$timezoneOption">{{ str_replace('_', ' ', $timezoneOption) }}</flux:select.option>
                    @endforeach
                </flux:select>
                @can(Permissions::SETTINGS_UPDATE)
                    <div class="flex justify-end"><flux:button type="submit" variant="primary">Save localization</flux:button></div>
                @endcan
            </form>

            <form x-cloak x-show="section === 'usability'" wire:submit="saveUsability" class="grid gap-5">
                <flux:select wire:model="recordsPerPage" label="Records per page" :disabled="! auth()->user()->can(Permissions::SETTINGS_UPDATE)">
                    @foreach (SystemSettings::RECORDS_PER_PAGE_OPTIONS as $option)
                        <flux:select.option wire:key="records-per-page-{{ $option }}" :value="$option">{{ $option }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:text>Used as the default page size for administrative lists.</flux:text>
                @can(Permissions::SETTINGS_UPDATE)
                    <div class="flex justify-end"><flux:button type="submit" variant="primary">Save usability</flux:button></div>
                @endcan
            </form>
        </div>
    </x-pages::settings.layout>
</section>
