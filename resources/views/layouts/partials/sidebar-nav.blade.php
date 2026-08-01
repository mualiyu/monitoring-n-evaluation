{{--
    Sidebar navigation body, shared by the tenant and oversight shells.

    Expects $navigation:
    [
        ['label' => 'Monitoring', 'collapsed' => false, 'items' => [
            ['label' => 'Progress reports', 'icon' => 'document-text', 'href' => '#', 'active' => true, 'badge' => 4],
        ]],
    ]

    Groups are collapsible (disclosure button + aria-expanded/aria-controls) so a long
    MDA workspace menu stays usable on a small screen.
--}}
@php
    $navigation = $navigation ?? [];
    $density = $density ?? 'comfortable';
    $gap = $density === 'compact' ? 'space-y-3' : 'space-y-5';
    $itemGap = $density === 'compact' ? 'space-y-0.5' : 'space-y-1';
@endphp

<nav aria-label="{{ $navLabel ?? __('Main navigation') }}" class="flex-1 overflow-y-auto px-3 py-4 {{ $gap }}">
    @foreach ($navigation as $index => $group)
        @php $groupId = 'nav-group-'.$index; @endphp

        @if (filled($group['label'] ?? null))
            <div x-data="{ open: {{ ($group['collapsed'] ?? false) ? 'false' : 'true' }} }">
                <button
                    type="button"
                    x-on:click="open = ! open"
                    :aria-expanded="open ? 'true' : 'false'"
                    aria-controls="{{ $groupId }}"
                    class="flex w-full items-center justify-between gap-2 rounded-lg px-3 py-1.5 text-xs font-semibold tracking-wide text-ink-subtle uppercase transition-colors hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                >
                    <span class="truncate">{{ $group['label'] }}</span>
                    <x-ui.icon name="chevron-down" class="size-3.5 transition-transform" x-bind:class="open ? '' : '-rotate-90'" />
                </button>

                <ul id="{{ $groupId }}" x-show="open" x-transition.opacity.duration.150ms class="mt-1 {{ $itemGap }}">
                    @foreach ($group['items'] ?? [] as $item)
                        <li>
                            <x-ui.nav-item
                                :href="$item['href'] ?? '#'"
                                :icon="$item['icon'] ?? null"
                                :active="$item['active'] ?? false"
                                :badge="$item['badge'] ?? null"
                                :disabled="$item['disabled'] ?? false"
                            >{{ $item['label'] ?? '' }}</x-ui.nav-item>
                        </li>
                    @endforeach
                </ul>
            </div>
        @else
            <ul class="{{ $itemGap }}">
                @foreach ($group['items'] ?? [] as $item)
                    <li>
                        <x-ui.nav-item
                            :href="$item['href'] ?? '#'"
                            :icon="$item['icon'] ?? null"
                            :active="$item['active'] ?? false"
                            :badge="$item['badge'] ?? null"
                            :disabled="$item['disabled'] ?? false"
                        >{{ $item['label'] ?? '' }}</x-ui.nav-item>
                    </li>
                @endforeach
            </ul>
        @endif
    @endforeach
</nav>
