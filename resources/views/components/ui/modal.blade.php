{{--
    <x-ui.modal /> — accessible dialog in plain Alpine (no plugins beyond core).

    Handles: focus trap (Tab / Shift+Tab cycle inside the panel), focus restore to
    the trigger on close, Esc, overlay click, body scroll lock, aria-modal wiring.

    Opening it, three ways:
      1. Livewire property:   <x-ui.modal wire="confirmingDeletion">
      2. Browser event:       <x-ui.modal name="export"> + $dispatch('open-modal', 'export')
      3. Static/preview:      <x-ui.modal :show="true">

    Props
      name, wire, show, title, description, maxWidth (sm|md|lg|xl|2xl), closeable

    Slots
      $slot     body
      $footer   action row (right-aligned on desktop, stacked on mobile)
--}}
@props([
    'name' => null,
    'wire' => null,
    'show' => false,
    'title' => null,
    'description' => null,
    'maxWidth' => 'md',
    'closeable' => true,
])

@php
    $modalId = 'modal-'.Str::slug($name ?? $title ?? Str::random(6));

    $widths = [
        'sm' => 'sm:max-w-sm',
        'md' => 'sm:max-w-lg',
        'lg' => 'sm:max-w-2xl',
        'xl' => 'sm:max-w-4xl',
        '2xl' => 'sm:max-w-6xl',
    ];

    $openState = $wire
        ? '$wire.entangle('.json_encode($wire).')'
        : ($show ? 'true' : 'false');

    $nameJson = json_encode($name);
    $closeableJson = json_encode((bool) $closeable);

    // Not user input: a hand-written Alpine expression assembled from component
    // props (all values json_encode()d). Rendered raw because Blade would
    // entity-encode the JS operators.
    $alpineData = <<<JS
    {
        open: {$openState},
        name: {$nameJson},
        closeable: {$closeableJson},
        focusables() {
            const selector = "a[href], area[href], button, input, select, textarea, summary, [tabindex]";
            return Array.from(this.\$refs.panel.querySelectorAll(selector))
                .filter((el) => !el.disabled && el.tabIndex >= 0 && el.offsetParent !== null);
        },
        focusFirst() {
            const items = this.focusables();
            (items[0] ?? this.\$refs.panel).focus();
        },
        trapTab(event) {
            const items = this.focusables();
            if (items.length === 0) return;
            const first = items[0];
            const last = items[items.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        },
        close() {
            if (!this.closeable) return;
            this.open = false;
        },
    }
    JS;
@endphp

<div
    x-data='{!! $alpineData !!}'
    x-on:open-modal.window="if ($event.detail === name || $event.detail?.name === name) open = true"
    x-on:close-modal.window="if (! $event.detail || $event.detail === name || $event.detail?.name === name) open = false"
    x-on:keydown.escape.window="if (open) close()"
    {{-- The trigger is stashed on the DOM node, not in x-data: reading reactive
         state inside x-effect while writing to it would re-trigger the effect. --}}
    x-effect="
        if (open) {
            $el._restoreFocusTo = document.activeElement;
            document.body.style.overflow = 'hidden';
            $nextTick(() => focusFirst());
        } else {
            document.body.style.overflow = '';
            $el._restoreFocusTo?.focus?.();
            $el._restoreFocusTo = null;
        }
    "
    x-cloak
    {{ $attributes }}
>
    <div
        x-show="open"
        class="fixed inset-0 z-50 flex items-end justify-center overflow-y-auto p-0 sm:items-center sm:p-6"
        role="dialog"
        aria-modal="true"
        @if ($title) aria-labelledby="{{ $modalId }}-title" @endif
        @if ($description) aria-describedby="{{ $modalId }}-description" @endif
    >
        {{-- Overlay --}}
        <div
            x-show="open"
            x-transition.opacity.duration.150ms
            x-on:click="close()"
            class="fixed inset-0 bg-surface-inverse/60"
            aria-hidden="true"
        ></div>

        {{-- Panel --}}
        <div
            x-ref="panel"
            x-show="open"
            x-on:keydown.tab="trapTab($event)"
            x-transition:enter="transition duration-150 ease-out"
            x-transition:enter-start="translate-y-4 opacity-0 sm:translate-y-0 sm:scale-95"
            x-transition:enter-end="translate-y-0 opacity-100 sm:scale-100"
            x-transition:leave="transition duration-100 ease-in"
            x-transition:leave-start="translate-y-0 opacity-100 sm:scale-100"
            x-transition:leave-end="translate-y-4 opacity-0 sm:translate-y-0 sm:scale-95"
            tabindex="-1"
            class="relative z-10 flex max-h-[90dvh] w-full flex-col rounded-t-2xl border border-line bg-surface-raised shadow-e3 sm:rounded-2xl {{ $widths[$maxWidth] ?? $widths['md'] }}"
        >
            @if ($title || $closeable)
                <header class="flex items-start gap-4 border-b border-line px-4 py-4 sm:px-6">
                    <div class="min-w-0 flex-1">
                        @if ($title)
                            <h2 id="{{ $modalId }}-title" class="text-base font-semibold text-ink">{{ $title }}</h2>
                        @endif
                        @if ($description)
                            <p id="{{ $modalId }}-description" class="mt-1 text-sm text-ink-muted">{{ $description }}</p>
                        @endif
                    </div>

                    @if ($closeable)
                        <button
                            type="button"
                            x-on:click="close()"
                            class="-m-1 rounded-lg p-1 text-ink-muted transition-colors hover:bg-neutral-soft hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                        >
                            <x-ui.icon name="x-mark" class="size-5" />
                            <span class="sr-only">{{ __('Close') }}</span>
                        </button>
                    @endif
                </header>
            @endif

            <div class="flex-1 overflow-y-auto px-4 py-4 text-sm text-ink sm:px-6">
                {{ $slot }}
            </div>

            @isset($footer)
                <footer class="flex flex-col-reverse gap-2 border-t border-line px-4 py-4 sm:flex-row sm:justify-end sm:px-6">
                    {{ $footer }}
                </footer>
            @endisset
        </div>
    </div>
</div>
