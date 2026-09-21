{{--
    <x-indicators.statement /> — one result statement in the logframe builder,
    with the indicators that measure it.

    Module-local on purpose: it is not a design-system primitive, it is this
    screen's row, and the three tiers render it identically so an impact
    statement and an output statement never drift apart visually.

    Props
      statement        App\Models\ResultFramework (with `indicators` loaded)
      canManage        may add/edit/remove statements
      canAddIndicator  may attach an indicator
      indicatorUrl     closure(Indicator): string
      addChildLabel    label for the "add the level beneath" button, or null
--}}
@props([
    'statement',
    'canManage' => false,
    'canAddIndicator' => false,
    'indicatorUrl' => null,
    'addChildLabel' => null,
])

<div {{ $attributes->class('rounded-lg border border-line bg-surface p-3 sm:p-4') }}>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                {{-- Level is icon + text: a board pack prints in greyscale. --}}
                <x-ui.badge
                    status="level"
                    size="sm"
                    :icon="$statement->level->icon()"
                    :label="$statement->level->label()"
                />

                @if ($statement->code)
                    <span class="font-mono text-xs text-ink-muted">{{ $statement->code }}</span>
                @endif
            </div>

            <p class="mt-1.5 text-sm font-semibold text-ink">{{ $statement->statement }}</p>

            @if ($statement->description)
                <p class="mt-1 text-sm text-ink-muted">{{ $statement->description }}</p>
            @endif

            @if ($statement->assumptions)
                <p class="mt-1 flex items-start gap-1.5 text-xs text-ink-muted">
                    <x-ui.icon name="information-circle" class="mt-0.5 size-3.5 shrink-0" />
                    <span>{{ __('Assumes: :assumptions', ['assumptions' => $statement->assumptions]) }}</span>
                </p>
            @endif
        </div>

        <div class="flex flex-wrap items-center gap-2 sm:justify-end">
            @if ($canAddIndicator)
                <x-ui.button
                    variant="secondary"
                    size="sm"
                    icon="chart-bar"
                    wire:click="startIndicator('{{ $statement->ulid }}')"
                    loading="startIndicator('{{ $statement->ulid }}')"
                >{{ __('Add indicator') }}</x-ui.button>
            @endif

            @if ($canManage && $addChildLabel)
                <x-ui.button
                    variant="secondary"
                    size="sm"
                    icon="plus"
                    wire:click="startStatement('{{ $statement->ulid }}')"
                    loading="startStatement('{{ $statement->ulid }}')"
                >{{ $addChildLabel }}</x-ui.button>
            @endif

            @if ($canManage)
                <x-ui.button
                    variant="ghost"
                    size="sm"
                    icon="pencil-square"
                    wire:click="editStatement('{{ $statement->ulid }}')"
                    loading="editStatement('{{ $statement->ulid }}')"
                >{{ __('Edit') }}</x-ui.button>

                <x-ui.button
                    variant="ghost"
                    size="sm"
                    icon="trash"
                    wire:click="deleteStatement('{{ $statement->ulid }}')"
                    loading="deleteStatement('{{ $statement->ulid }}')"
                    wire:confirm="{{ __('Remove this result statement? Only a leaf with no indicators and nothing beneath it can be removed.') }}"
                >{{ __('Remove') }}</x-ui.button>
            @endif
        </div>
    </div>

    @if ($statement->indicators->isNotEmpty())
        <ul class="mt-3 space-y-2">
            @foreach ($statement->indicators as $indicator)
                @php $achievement = $indicator->achievement(); @endphp

                <li
                    wire:key="fw-indicator-{{ $indicator->ulid }}"
                    class="flex flex-col gap-2 rounded-md bg-surface-sunken px-3 py-2 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div class="min-w-0">
                        <a
                            href="{{ $indicatorUrl ? $indicatorUrl($indicator) : '#' }}"
                            class="rounded text-sm font-medium text-ink hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                        >{{ $indicator->name }}</a>

                        <span class="mt-0.5 block text-xs text-ink-muted">
                            {{ $indicator->unit->label() }} · {{ $indicator->measurement_frequency->label() }}
                            @if ($indicator->libraryDefinition)
                                · <span class="font-mono">{{ $indicator->libraryDefinition->code }}</span>
                            @else
                                · {{ __('locally defined') }}
                            @endif
                        </span>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        @unless ($indicator->is_active)
                            <x-ui.badge status="draft" size="sm" :label="__('Awaiting baseline')" />
                        @endunless

                        <x-ui.badge
                            :status="$achievement->badgeStatus()"
                            size="sm"
                            :icon="$achievement->icon()"
                            :label="$achievement->label().' · '.$achievement->percentLabel()"
                        />
                    </div>
                </li>
            @endforeach
        </ul>
    @elseif ($canAddIndicator)
        <p class="mt-3 text-xs text-ink-muted">
            {{ __('Nothing measures this statement yet — a result nobody can verify is an intention.') }}
        </p>
    @endif
</div>
