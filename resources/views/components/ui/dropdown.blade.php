{{--
    <x-ui.dropdown /> — keyboard-navigable menu in plain Alpine.

    Arrow keys move between items, Home/End jump, Esc closes and returns focus to the
    trigger, click-outside closes. Items are <x-ui.dropdown.item> (role="menuitem").

        <x-ui.dropdown align="right">
            <x-slot:trigger>
                <x-ui.button variant="ghost" size="sm" icon="ellipsis-vertical" icon-only>Row actions</x-ui.button>
            </x-slot:trigger>
            <x-ui.dropdown.item icon="eye" href="#">View project</x-ui.dropdown.item>
        </x-ui.dropdown>

    Props: align (left|right), width, label (accessible name for the menu)
--}}
@props([
    'align' => 'right',
    'width' => 'w-56',
    'label' => null,
])

<div
    x-data="{
        open: false,
        items() {
            return Array.from($refs.menu.querySelectorAll('[role=menuitem]')).filter((el) => el.offsetParent !== null)
        },
        focusAt(index) {
            const items = this.items()
            if (items.length === 0) return
            const target = (index + items.length) % items.length
            items[target].focus()
        },
        move(step) {
            const items = this.items()
            const current = items.indexOf(document.activeElement)
            this.focusAt(current < 0 ? 0 : current + step)
        },
        toggle() {
            this.open = ! this.open
            if (this.open) $nextTick(() => this.focusAt(0))
        },
        close(refocus = true) {
            if (! this.open) return
            this.open = false
            if (refocus) $refs.trigger.querySelector('button, a')?.focus()
        },
    }"
    x-on:keydown.escape.stop="close()"
    {{-- ARIA lives on the caller's real <button>, not on this wrapper, so screen
         readers announce "menu, collapsed/expanded" on the control itself. --}}
    x-effect="
        const control = $refs.trigger?.querySelector('button, a');
        if (control) {
            control.setAttribute('aria-haspopup', 'true');
            control.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
    "
    {{ $attributes->class('relative') }}
>
    <div
        x-ref="trigger"
        x-on:click="toggle()"
        x-on:keydown.arrow-down.prevent="open = true; $nextTick(() => focusAt(0))"
        class="contents"
    >
        {{ $trigger }}
    </div>

    <div
        x-ref="menu"
        x-show="open"
        x-cloak
        x-on:click.outside="close(false)"
        x-on:click="close(false)"
        x-on:keydown.arrow-down.prevent="move(1)"
        x-on:keydown.arrow-up.prevent="move(-1)"
        x-on:keydown.home.prevent="focusAt(0)"
        x-on:keydown.end.prevent="focusAt(-1)"
        x-transition:enter="transition duration-100 ease-out"
        x-transition:enter-start="scale-95 opacity-0"
        x-transition:enter-end="scale-100 opacity-100"
        x-transition:leave="transition duration-75 ease-in"
        x-transition:leave-start="scale-100 opacity-100"
        x-transition:leave-end="scale-95 opacity-0"
        role="menu"
        aria-orientation="vertical"
        @if ($label) aria-label="{{ $label }}" @endif
        class="absolute z-40 mt-2 {{ $width }} origin-top overflow-hidden rounded-xl border border-line bg-surface-raised p-1 shadow-e2 {{ $align === 'left' ? 'left-0' : 'right-0' }}"
    >
        {{ $slot }}
    </div>
</div>
