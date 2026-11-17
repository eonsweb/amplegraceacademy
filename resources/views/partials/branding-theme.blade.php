@inject('systemSettings', 'App\Support\Settings\SystemSettings')
<meta name="theme-color" content="{{ \App\Support\Settings\SystemSettings::PALETTES[$systemSettings->primaryColor()][700] }}">
<style id="school-branding-theme">
    @foreach (\App\Support\Settings\SystemSettings::PALETTES as $name => $palette)
        :root[data-branding-color="{{ $name }}"],
        .branding-preview[data-branding-color="{{ $name }}"] {
            @foreach ($palette as $shade => $hex)
                --color-brand-{{ $shade }}: {{ $hex }};
            @endforeach
            --color-accent: {{ $palette[700] }};
            --color-accent-content: {{ $palette[700] }};
            --color-accent-foreground: #ffffff;
        }
        :root.dark[data-branding-color="{{ $name }}"],
        .dark .branding-preview[data-branding-color="{{ $name }}"] {
            --color-accent-content: {{ $palette[300] }};
        }
    @endforeach
</style>
<script>
    document.documentElement.dataset.brandingColor = @js($systemSettings->primaryColor());
    if (! window.schoolColorListener) {
        window.schoolColorListener = (event) => {
            document.documentElement.dataset.brandingColor = event.detail.color;
            document.querySelector('meta[name="theme-color"]').content = event.detail.hex;
        };
        window.addEventListener('school-color-updated', window.schoolColorListener);
    }
</script>
