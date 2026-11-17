<?php

use App\Events\SystemSettingsChanged;
use App\Rules\BrandingFavicon;
use App\Support\Authorization\Permissions;
use App\Support\Settings\SystemSettings;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

new #[Title('System settings')] class extends Component {
    use WithFileUploads {
        _startUpload as private startBrandingUpload;
        _finishUpload as private finishBrandingUpload;
    }

    public string $dashboardMotto = '';
    public string $loginHeading = '';
    public string $loginMessage = '';
    public string $copyrightCompany = '';
    public string $primaryColor = 'blue';
    public ?TemporaryUploadedFile $darkLogoUpload = null;

    private const BRANDING_FIELDS = [
        'dashboard_motto' => 'dashboardMotto',
        'login_heading' => 'loginHeading',
        'login_message' => 'loginMessage',
        'copyright_company' => 'copyrightCompany',
        'primary_color' => 'primaryColor',
    ];

    public function _startUpload(mixed $name, mixed $fileInfo, mixed $isMultiple): void
    {
        Gate::authorize(Permissions::SETTINGS_UPDATE);
        $this->startBrandingUpload($name, $fileInfo, $isMultiple);
    }

    public function _finishUpload(mixed $name, mixed $tmpPath, mixed $isMultiple, mixed $append = true): void
    {
        Gate::authorize(Permissions::SETTINGS_UPDATE);
        $this->finishBrandingUpload($name, $tmpPath, $isMultiple, $append);
    }

    public function resetBrandingSetting(string $key, SystemSettings $settings): void
    {
        Gate::authorize(Permissions::SETTINGS_UPDATE);
        abort_unless(isset(self::BRANDING_FIELDS[$key]), 404);
        $changedKeys = $settings->update([$key => SystemSettings::BRANDING_DEFAULTS[$key]]);
        $this->{self::BRANDING_FIELDS[$key]} = SystemSettings::BRANDING_DEFAULTS[$key];
        $this->resetValidation(self::BRANDING_FIELDS[$key]);
        if ($key === 'primary_color') {
            $this->dispatch('school-color-updated', color: $settings->primaryColor(), hex: SystemSettings::PALETTES[$settings->primaryColor()][700]);
        }
        $this->dispatchSettingsChanged('settings.branding_updated', 'branding', $changedKeys);
        Flux::toast(variant: 'success', text: 'Default restored for the selected setting.');
    }

    public string $schoolName = '';
    public string $schoolInitials = '';
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
        $this->schoolInitials = str($this->schoolInitials)->trim()->upper()->toString();

        $validated = $this->validate([
            'schoolInitials' => ['required', 'string', 'max:10', 'regex:/^[A-Z0-9]+$/'],
            'contactEmail' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:500'],
        ]);

        $changedKeys = $settings->update([
            'school_initials' => str($validated['schoolInitials'])->trim()->upper()->toString(),
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
            'schoolName' => ['required', 'string', 'max:255'],
            'dashboardMotto' => ['required', 'string', 'max:255'],
            'loginHeading' => ['required', 'string', 'max:100'],
            'loginMessage' => ['required', 'string', 'max:300'],
            'copyrightCompany' => ['required', 'string', 'max:150'],
            'primaryColor' => ['required', Rule::in(array_keys(SystemSettings::PALETTES))],
            'dashboardLogoUpload' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'extensions:png,jpg,jpeg,webp', 'max:2048', 'dimensions:max_width=2048,max_height=2048'],
            'darkLogoUpload' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'extensions:png,jpg,jpeg,webp', 'max:2048', 'dimensions:max_width=2048,max_height=2048'],
            'loginLogoUpload' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'extensions:png,jpg,jpeg,webp', 'max:2048', 'dimensions:max_width=2560,max_height=2560'],
            'faviconUpload' => ['nullable', 'file', 'mimes:png,ico,webp', 'extensions:png,ico,webp', 'max:512', new BrandingFavicon],
        ]);

        $uploads = [
            'dashboard_logo' => $this->dashboardLogoUpload,
            'login_logo' => $this->loginLogoUpload,
            'dark_logo' => $this->darkLogoUpload,
            'favicon' => $this->faviconUpload,
        ];
        $values = ['school_name' => trim($this->schoolName)];
        foreach (self::BRANDING_FIELDS as $key => $property) {
            $values[$key] = trim($this->{$property});
        }

        try {
            $changedKeys = $settings->saveBranding($values, $uploads);
        } catch (Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['branding' => 'Branding could not be saved. Please check storage availability and try again.']);
        }

        $this->reset('dashboardLogoUpload', 'loginLogoUpload', 'darkLogoUpload', 'faviconUpload');
        $this->dispatchSettingsChanged('settings.branding_updated', 'branding', $changedKeys);
        Flux::toast(variant: 'success', text: 'Branding updated.');
        $this->redirect(route('settings.system').'#branding', navigate: true);
    }

    public function removeBrandingAsset(string $key, SystemSettings $settings): void
    {
        Gate::authorize(Permissions::SETTINGS_UPDATE);
        abort_unless(in_array($key, SystemSettings::ASSET_KEYS, true), 404);
        try {
            $changedKeys = $settings->saveBranding([$key => null]);
        } catch (Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['branding' => 'The image could not be reset. Please try again.']);
        }
        $property = ['dashboard_logo' => 'dashboardLogoUpload', 'login_logo' => 'loginLogoUpload', 'dark_logo' => 'darkLogoUpload', 'favicon' => 'faviconUpload'][$key];
        $this->reset($property);
        $this->resetValidation($property);
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
        foreach (self::BRANDING_FIELDS as $key => $property) {
            $this->{$property} = $settings->brandingText($key);
        }
        $this->primaryColor = $settings->primaryColor();
        $this->schoolName = $settings->schoolName();
        $this->schoolInitials = (string) $settings->schoolInitials();
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

        <div x-data="{ section: window.location.hash === '#branding' ? 'branding' : 'general' }" class="grid gap-5">
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-4" role="tablist" aria-label="System setting sections">
                @foreach (['general' => 'General', 'branding' => 'School Branding', 'localization' => 'Localization', 'usability' => 'Usability'] as $key => $label)
                    <button wire:key="settings-tab-{{ $key }}" type="button" role="tab" x-on:click="section = '{{ $key }}'" x-bind:aria-selected="section === '{{ $key }}'" x-bind:class="section === '{{ $key }}' ? 'bg-brand-700 text-white' : 'bg-zinc-100 text-zinc-700 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-200 dark:hover:bg-zinc-700'" class="rounded-lg px-3 py-2 text-sm font-medium transition-colors">{{ $label }}</button>
                @endforeach
            </div>

            <form x-show="section === 'general'" wire:submit="save" class="grid gap-5">
                <flux:input wire:model="schoolInitials" label="School initials" description="Letters and numbers only. Used as the admission-number prefix." maxlength="10" required :disabled="! auth()->user()->can(Permissions::SETTINGS_UPDATE)" />
                <flux:input wire:model="contactEmail" type="email" label="Contact email" maxlength="255" :disabled="! auth()->user()->can(Permissions::SETTINGS_UPDATE)" />
                <flux:input wire:model="phone" type="tel" label="Phone" maxlength="30" :disabled="! auth()->user()->can(Permissions::SETTINGS_UPDATE)" />
                <flux:textarea wire:model="address" label="Address" rows="4" maxlength="500" :disabled="! auth()->user()->can(Permissions::SETTINGS_UPDATE)" />
                @can(Permissions::SETTINGS_UPDATE)
                    <div class="flex justify-end"><flux:button type="submit" variant="primary">Save general settings</flux:button></div>
                @endcan
            </form>

            <form x-cloak x-show="section === 'branding'" wire:submit="saveBranding" class="grid gap-6">
                <flux:heading size="lg">School Branding</flux:heading>
                <flux:text>Changes below are previews until you save. Restore buttons immediately reset only the selected setting.</flux:text>
                <flux:error name="branding" />
                <fieldset class="grid gap-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                    <legend class="px-1 font-semibold">School Identity</legend>
                    <flux:input wire:model="schoolName" label="School name" maxlength="255" required :disabled="! auth()->user()->can(Permissions::SETTINGS_UPDATE)" />
                    @foreach (['dashboard_motto' => ['dashboardMotto', 'Dashboard footer / school motto', 255], 'login_heading' => ['loginHeading', 'Login welcome heading', 100], 'login_message' => ['loginMessage', 'Login supporting message', 300]] as $key => [$property, $label, $limit])
                        <div wire:key="branding-text-{{ $key }}" class="grid gap-2">
                            <flux:input wire:model.live.debounce.300ms="{{ $property }}" :label="$label" :maxlength="$limit" required :disabled="! auth()->user()->can(Permissions::SETTINGS_UPDATE)" />
                            @can(Permissions::SETTINGS_UPDATE)
                                <div><flux:button size="sm" wire:click="resetBrandingSetting('{{ $key }}')" wire:confirm="Restore this setting? Its saved value and unsaved edits will be discarded.">Restore default</flux:button></div>
                            @endcan
                        </div>
                    @endforeach
                </fieldset>
                <flux:heading>Branding Images</flux:heading>
                @foreach ([
                    ['property' => 'dashboardLogoUpload', 'key' => 'dashboard_logo', 'label' => 'Primary sidebar school logo', 'url' => $systemSettings->dashboardLogoUrl(), 'hint' => 'PNG, JPG, or WebP up to 2 MB and 2048 × 2048 pixels.'],
                    ['property' => 'loginLogoUpload', 'key' => 'login_logo', 'label' => 'Login page image', 'url' => $systemSettings->loginLogoUrl(), 'hint' => 'PNG, JPG, or WebP up to 2 MB and 2560 × 2560 pixels. Falls back to the primary logo.'],
                    ['property' => 'darkLogoUpload', 'key' => 'dark_logo', 'label' => 'Optional dark mode school logo', 'url' => $systemSettings->darkLogoUrl(), 'hint' => 'PNG, JPG, or WebP up to 2 MB and 2048 × 2048 pixels. Falls back to the primary logo.'],
                    ['property' => 'faviconUpload', 'key' => 'favicon', 'label' => 'Favicon', 'url' => $systemSettings->faviconUrl(), 'hint' => 'PNG, ICO, or WebP up to 512 KB.'],
                ] as $asset)
                    <fieldset wire:key="branding-{{ $asset['key'] }}" x-data="{ selectedImage: null, destroy() { if (this.selectedImage) URL.revokeObjectURL(this.selectedImage) } }" class="grid gap-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                        <legend class="px-1 text-sm font-semibold text-zinc-900 dark:text-white">{{ $asset['label'] }}</legend>
                        <div class="flex items-center gap-4">
                            <img src="{{ $asset['url'] }}" alt="{{ $asset['label'] }} current image" class="size-16 rounded-lg border border-zinc-200 bg-white object-contain p-1 dark:border-zinc-700 dark:bg-zinc-800">
                            @if ($this->{$asset['property']})
                                <img x-show="selectedImage" x-bind:src="selectedImage" alt="{{ $asset['label'] }} selected image preview" class="size-16 rounded-lg border border-zinc-200 bg-white object-contain p-1 dark:border-zinc-700 dark:bg-zinc-800">
                            @endif
                            <div class="min-w-0 flex-1">
                                <input aria-label="{{ $asset['label'] }}" type="file" wire:model="{{ $asset['property'] }}" x-on:change="if (selectedImage) URL.revokeObjectURL(selectedImage); selectedImage = $event.target.files[0] ? URL.createObjectURL($event.target.files[0]) : null" accept="{{ $asset['key'] === 'favicon' ? '.png,.ico,.webp' : '.png,.jpg,.jpeg,.webp' }}" class="block w-full text-sm text-zinc-600 file:mr-3 file:rounded-md file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:font-medium file:text-brand-800 hover:file:bg-brand-100 dark:text-zinc-300 dark:file:bg-brand-950 dark:file:text-brand-200" @disabled(! auth()->user()->can(Permissions::SETTINGS_UPDATE))>
                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $asset['hint'] }}</p>
                            </div>
                        </div>
                        <flux:error :name="$asset['property']" />
                        @if ($systemSettings->assetPath($asset['key']) || $this->{$asset['property']})
                            @can(Permissions::SETTINGS_UPDATE)
                                <div><flux:button type="button" size="sm" variant="danger" wire:click="removeBrandingAsset('{{ $asset['key'] }}')" wire:confirm="Restore this image? The custom image and any pending replacement will be discarded.">Restore default / remove custom image</flux:button></div>
                            @endcan
                        @endif
                    </fieldset>
                @endforeach
                @can(Permissions::SETTINGS_UPDATE)
                    <fieldset class="grid gap-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                        <legend class="px-1 font-semibold">Appearance</legend>
                        <fieldset class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                            <legend class="mb-2 text-sm font-medium">Primary school color</legend>
                            @foreach (SystemSettings::PALETTES as $color => $palette)
                                <label wire:key="color-{{ $color }}" class="flex cursor-pointer items-center gap-2 rounded-lg border border-zinc-300 p-3 has-checked:ring-2 has-checked:ring-accent has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-accent dark:border-zinc-600">
                                    <input type="radio" wire:model.live="primaryColor" value="{{ $color }}" name="primaryColor" class="accent-accent">
                                    <span aria-hidden="true" class="size-5 rounded-full" style="background-color: {{ $palette[700] }}"></span>
                                    <span class="text-sm">{{ ucfirst($color) }}</span>
                                </label>
                            @endforeach
                        </fieldset>
                        <flux:error name="primaryColor" />
                        <flux:text>Presets use accessible button and text shades in both modes. Your personal light/dark preference stays unchanged.</flux:text>
                        <flux:link :href="route('appearance.edit')" wire:navigate>Personal light/dark appearance</flux:link>
                        <div><flux:button size="sm" wire:click="resetBrandingSetting('primary_color')" wire:confirm="Restore default blue and discard this color selection?">Restore default blue</flux:button></div>
                        <div class="branding-preview grid gap-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700" data-branding-color="{{ array_key_exists($primaryColor, SystemSettings::PALETTES) ? $primaryColor : 'blue' }}">
                            <p class="text-sm font-medium">Unsaved color preview: {{ ucfirst($primaryColor) }}</p>
                            <div><flux:button variant="primary" type="button">Sample primary action</flux:button></div>
                            <div class="rounded-lg border-l-4 border-accent bg-brand-50 px-3 py-2 text-brand-800 dark:bg-brand-950 dark:text-brand-200">Selected navigation item</div>
                        </div>
                    </fieldset>
                    <fieldset class="grid gap-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                        <legend class="px-1 font-semibold">Footer and Attribution</legend>
                        <flux:input wire:model.live.debounce.300ms="copyrightCompany" label="Copyright company name" maxlength="150" required />
                        <div><flux:button size="sm" wire:click="resetBrandingSetting('copyright_company')" wire:confirm="Restore the copyright company name and discard its edits?">Restore default company</flux:button></div>
                        <div class="grid gap-2 break-words rounded-lg bg-zinc-50 p-4 text-center text-sm dark:bg-zinc-800">
                            <p class="font-medium">Unsaved footer preview</p>
                            <p>{{ $dashboardMotto }}</p>
                            <p>&copy; {{ now($systemSettings->timezone())->year }} {{ $copyrightCompany }}. All rights reserved.</p>
                        </div>
                    </fieldset>
                    <p wire:loading wire:target="dashboardLogoUpload,loginLogoUpload,darkLogoUpload,faviconUpload" role="status">Uploading preview…</p>
                    <div class="flex justify-end"><flux:button type="submit" variant="primary" wire:loading.attr="disabled">Save Changes</flux:button></div>
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
