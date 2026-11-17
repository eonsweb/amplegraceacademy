@props(['context' => 'dashboard'])

@inject('systemSettings', 'App\Support\Settings\SystemSettings')

<img
    src="{{ $context === 'login' ? $systemSettings->loginLogoUrl() : $systemSettings->dashboardLogoUrl() }}"
    x-data
    @if ($context !== 'login' && $systemSettings->darkLogoUrl() !== $systemSettings->dashboardLogoUrl())
        data-light-logo="{{ $systemSettings->dashboardLogoUrl() }}"
        data-dark-logo="{{ $systemSettings->darkLogoUrl() }}"
        x-bind:src="$flux.dark ? $el.dataset.darkLogo : $el.dataset.lightLogo"
    @endif
    onerror="this.onerror=null;this.src='{{ asset('images/branding/ample-grace-logo.png') }}'"
    alt=""
    aria-hidden="true"
    width="140"
    height="150"
    {{ $attributes->class('object-contain') }}
>
