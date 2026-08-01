{{--
    Shared <head> for all three shells.

    Available variables (all optional): $title, $tenant.
    The theme script runs before paint so dark mode never flashes; it reads the same
    localStorage key <x-ui.theme-toggle> writes.
--}}
@php
    $instanceName = data_get($tenant, 'branding.display_name')
        ?? data_get($tenant, 'name')
        ?? config('platform.instance.name')
        ?? config('app.name');

    $documentTitle = collect([$title ?? null, $instanceName])->filter()->implode(' · ');

    // Per-tenant re-skin: tenants->branding['tokens'] is a map of design-token
    // overrides, e.g. ['--brand-600' => 'oklch(0.49 0.1 158)']. Both the property
    // name and the value are allow-listed here so a compromised branding row can
    // never inject markup or a CSS expression into the document.
    $brandingTokens = collect(data_get($tenant, 'branding.tokens', []))
        ->filter(fn ($value, $token) => is_string($token)
            && is_string($value)
            && preg_match('/^--[a-z0-9-]+$/', $token) === 1
            && preg_match('/^[a-zA-Z0-9\s(),.%\/#-]+$/', $value) === 1);
@endphp

<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="color-scheme" content="light dark">

<title>{{ $documentTitle }}</title>

{{-- Set the theme class before first paint: no flash of the wrong palette. --}}
<script>
    (function () {
        try {
            var stored = localStorage.getItem('ui.theme');
            var dark = stored ? stored === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches;
            document.documentElement.classList.toggle('dark', dark);
        } catch (e) {}
    })();
</script>

@fonts

@vite(['resources/css/app.css', 'resources/js/app.js'])

@livewireStyles

{{-- Per-tenant token overrides: the only thing needed to re-brand a deployment. --}}
@if ($brandingTokens->isNotEmpty())
    <style>
        :root {
            @foreach ($brandingTokens as $token => $value)
                {{ $token }}: {{ $value }};
            @endforeach
        }
    </style>
@endif
