<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

@inject('systemSettings', 'App\Support\Settings\SystemSettings')

<title>
    {{ filled($title ?? null) ? $title.' - '.$systemSettings->schoolName() : $systemSettings->schoolName() }}
</title>

<link rel="icon" href="{{ $systemSettings->faviconUrl() }}" sizes="any">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

@php($savedAppearance = auth()->user()?->theme)
<script>
    (() => {
        const appearance = @js($savedAppearance);

        if (appearance) {
            window.localStorage.setItem('flux.appearance', appearance);
        }

        const preference = appearance || window.localStorage.getItem('flux.appearance') || 'system';
        const dark = preference === 'dark' || (preference === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);

        document.documentElement.classList.toggle('dark', dark);
    })();
</script>

@fluxAppearance

@fonts

@vite(['resources/css/app.css', 'resources/js/app.js'])
