{{--
    <x-ui.brand /> — white-label lockup. THE seam where tenant branding enters the UI.

    Nothing here hard-codes a state, ministry or product name: the logo and label come
    from the resolved tenant (view-shared `$tenant`), falling back to instance config
    (config/platform.php) and finally to a neutral platform label. Renders gracefully
    when $tenant is null (oversight surface, public portal, tests).

    Props: tenant, href, subtitle, compact
--}}
@props([
    'tenant' => null,
    'href' => null,
    'subtitle' => null,
    'compact' => false,
])

@php
    $name = data_get($tenant, 'branding.display_name')
        ?? data_get($tenant, 'name')
        ?? config('platform.instance.name')
        ?? __('M&E Platform');

    $logo = data_get($tenant, 'branding.logo_url') ?? config('platform.instance.logo_url');
    $tag = $href ? 'a' : 'div';
@endphp

<{{ $tag }}
    @if ($href) href="{{ $href }}" @endif
    {{ $attributes->class('flex min-w-0 items-center gap-3 rounded-lg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus') }}
>
    @if ($logo)
        <img
            src="{{ $logo }}"
            alt=""
            class="size-9 shrink-0 rounded-lg object-contain"
            width="36"
            height="36"
        />
    @else
        <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-brand text-on-brand" aria-hidden="true">
            <x-ui.icon name="shield-check" class="size-5" />
        </span>
    @endif

    @unless ($compact)
        <span class="min-w-0">
            <span class="block truncate text-sm font-semibold text-ink">{{ $name }}</span>
            @if ($subtitle)
                <span class="block truncate text-xs text-ink-muted">{{ $subtitle }}</span>
            @endif
        </span>
    @endunless
</{{ $tag }}>
