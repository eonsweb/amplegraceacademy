@props(['context' => 'dashboard'])

@inject('systemSettings', 'App\Support\Settings\SystemSettings')

<img
    src="{{ $context === 'login' ? $systemSettings->loginLogoUrl() : $systemSettings->dashboardLogoUrl() }}"
    alt=""
    aria-hidden="true"
    width="140"
    height="150"
    {{ $attributes->class('object-contain') }}
>
