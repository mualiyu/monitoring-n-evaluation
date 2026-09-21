{{--
    Instance settings (App\Livewire\Oversight\Settings\InstanceSettings).
    Every field is generated from the declarative settings map, so this screen
    and the workspace override screen can never disagree about a rule.
--}}
<div>
    <x-ui.page-header
        :title="__('Instance settings')"
        :description="__('The state\'s own policy floor: what this deployment calls things, how long entities have to report, and where the thresholds sit. Entities may run tighter rules than these — never looser ones they set themselves.')"
    />

    @if (session('status'))
        <x-ui.alert variant="positive" class="mb-5" dismissible>{{ session('status') }}</x-ui.alert>
    @endif

    @if ($failure)
        <x-ui.alert variant="critical" class="mb-5" :title="__('That setting could not be saved')">{{ $failure }}</x-ui.alert>
    @endif

    <div class="grid gap-4 lg:grid-cols-4">
        {{-- Group navigation. A list on mobile, a rail on desktop. --}}
        <nav class="lg:col-span-1" aria-label="{{ __('Settings groups') }}">
            <x-ui.card flush>
                <ul class="divide-y divide-line">
                    @foreach ($this->groups as $candidate)
                        <li wire:key="group-{{ $candidate }}">
                            <button
                                type="button"
                                wire:click="selectGroup('{{ $candidate }}')"
                                @if ($candidate === $group) aria-current="page" @endif
                                class="flex w-full items-center justify-between gap-2 px-4 py-3 text-left text-sm transition-colors focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-focus {{ $candidate === $group ? 'bg-brand-soft font-semibold text-brand-ink' : 'text-ink-muted hover:bg-neutral-soft hover:text-ink' }}"
                            >
                                <span>{{ $this->groupLabel($candidate) }}</span>
                                @if ($candidate === $group)
                                    <x-ui.icon name="chevron-right" class="size-4" />
                                @endif
                            </button>
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>
        </nav>

        <div class="lg:col-span-3">
            <form wire:submit="saveGroup('{{ $group }}')">
                <x-ui.card
                    :title="$this->groupLabel($group)"
                    :subtitle="$this->groupDescription($group)"
                >
                    <div class="grid gap-5 sm:grid-cols-2">
                        @foreach ($this->definitions as $definition)
                            <div
                                wire:key="setting-{{ $definition->id() }}"
                                class="{{ in_array($definition->type, ['text', 'ints', 'strings'], true) ? 'sm:col-span-2' : '' }}"
                            >
                                @include('livewire.shared.partials.setting-field', [
                                    'definition' => $definition,
                                    'model' => 'values.'.$definition->group.'.'.$definition->key,
                                    'hint' => $definition->hint,
                                    'disabled' => false,
                                ])

                                @unless ($definition->tenantOverridable)
                                    <p class="mt-1 inline-flex items-center gap-1 text-xs text-ink-subtle">
                                        <x-ui.icon name="shield-check" class="size-3.5" />
                                        {{ __('State-wide — entities cannot override this.') }}
                                    </p>
                                @endunless
                            </div>
                        @endforeach
                    </div>

                    <x-slot:footer>
                        <div class="flex justify-end">
                            <x-ui.button type="submit" icon="check" loading="saveGroup">
                                {{ __('Save :group', ['group' => $this->groupLabel($group)]) }}
                            </x-ui.button>
                        </div>
                    </x-slot:footer>
                </x-ui.card>
            </form>
        </div>
    </div>
</div>
