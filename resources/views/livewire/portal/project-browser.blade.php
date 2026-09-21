{{--
    Published-projects browser (App\Livewire\Portal\ProjectBrowser).

    $this->projects is a paginator of ARRAYS from PublicProjectPayload — there is
    no Project model in this component and therefore no attribute for this view
    to reach for. That is the control, not a formatting choice.

    PROGRESSIVE ENHANCEMENT, and it is load-bearing here. The filter bar is a
    real <form method="GET"> whose field names are exactly the component's #[Url]
    aliases (q, sector, lga, status), so:
      - JavaScript off → the form submits, the query string arrives, the
        component reads it from the URL on the next page load;
      - JavaScript on  → wire:model.live filters in place over the same URL.
    Pagination is rendered as plain links (:wire="false") for the same reason. A
    public portal in a state where data is expensive cannot require a runtime to
    answer "what is being built in my area".
--}}
<div>
    <x-ui.page-header
        :title="__('Published projects')"
        :description="__('Every project the state has opened to the public, newest publication first. Figures are as the monitoring team verified them.')"
        :breadcrumbs="[
            ['label' => __('Home'), 'href' => route('portal.home')],
            ['label' => __('Published projects')],
        ]"
    >
        <x-slot:actions>
            <x-ui.button :href="route('portal.map')" variant="secondary" icon="map-pin">
                {{ __('See them on a map') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Filter bar --}}
    <x-ui.card class="mb-4" flush>
        <form method="GET" action="{{ route('portal.projects.index') }}" class="p-4">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <x-ui.form.group name="q" :label="__('Search')">
                    <x-ui.form.input
                        name="q"
                        type="search"
                        icon="magnifying-glass"
                        :placeholder="__('Project title or reference…')"
                        :value="$search"
                        wire:model.live.debounce.300ms="search"
                    />
                </x-ui.form.group>

                <x-ui.form.group name="sector" :label="__('Sector')">
                    <x-ui.form.select
                        name="sector"
                        :placeholder="__('All sectors')"
                        :options="$this->filterOptions['sectors']"
                        :selected="$sector"
                        wire:model.live="sector"
                    />
                </x-ui.form.group>

                <x-ui.form.group name="lga" :label="__('Local government area')">
                    <x-ui.form.select
                        name="lga"
                        :placeholder="__('All areas')"
                        :options="$this->filterOptions['lgas']"
                        :selected="$lga"
                        wire:model.live="lga"
                    />
                </x-ui.form.group>

                <x-ui.form.group name="status" :label="__('Status')">
                    <x-ui.form.select
                        name="status"
                        :placeholder="__('Any status')"
                        :options="$this->filterOptions['statuses']"
                        :selected="$status"
                        wire:model.live="status"
                    />
                </x-ui.form.group>
            </div>

            <div class="mt-3 flex flex-wrap items-center justify-end gap-2">
                @if ($this->hasFilters())
                    {{-- An ordinary link, so clearing works without JavaScript too. --}}
                    <x-ui.button variant="ghost" size="sm" icon="x-mark" :href="route('portal.projects.index')">
                        {{ __('Clear filters') }}
                    </x-ui.button>
                @endif

                {{-- Works with JavaScript off; harmlessly redundant with it on. --}}
                <x-ui.button type="submit" size="sm" variant="secondary" icon="funnel">
                    {{ __('Apply filters') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.card>

    {{-- Summary row --}}
    <div class="mb-4 grid gap-3 sm:grid-cols-3">
        <x-ui.stat
            :label="__('Projects found')"
            :value="number_format($this->projects->total())"
            icon="folder"
            :hint="$this->hasFilters() ? __('matching your filters') : __('published state-wide')"
        />
        <x-ui.stat
            :label="__('Sectors published')"
            :value="number_format(count($this->filterOptions['sectors']))"
            icon="squares"
        />
        <x-ui.stat
            :label="__('Areas covered')"
            :value="number_format(count($this->filterOptions['lgas']))"
            icon="map-pin"
            :href="route('portal.map')"
        />
    </div>

    <div wire:loading.delay.long.flex class="hidden">
        <div class="grid w-full gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <x-ui.skeleton variant="card" />
            <x-ui.skeleton variant="card" />
            <x-ui.skeleton variant="card" />
        </div>
    </div>

    <div wire:loading.delay.long.remove wire:target="search,sector,lga,status">
        @if ($this->projects->isEmpty())
            <x-ui.card flush>
                @if ($this->hasFilters())
                    <x-ui.empty-state
                        variant="filtered"
                        :title="__('No published project matches those filters')"
                        :description="__('Try a wider search, or clear the filters to see everything the state has published.')"
                    >
                        <x-slot:actions>
                            <x-ui.button variant="secondary" icon="x-mark" :href="route('portal.projects.index')">
                                {{ __('Clear filters') }}
                            </x-ui.button>
                        </x-slot:actions>
                    </x-ui.empty-state>
                @else
                    <x-ui.empty-state
                        icon="inbox"
                        :title="__('Nothing has been published yet')"
                        :description="__('Projects appear here as soon as an entity publishes them. Nothing on this portal is a plan or a projection.')"
                    >
                        <x-slot:actions>
                            <x-ui.button :href="route('portal.feedback.create')" variant="secondary" icon="chat-bubble">
                                {{ __('Tell the monitoring team what you see') }}
                            </x-ui.button>
                        </x-slot:actions>
                    </x-ui.empty-state>
                @endif
            </x-ui.card>
        @else
            <ul class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($this->projects as $project)
                    <li wire:key="portal-project-{{ $project['ulid'] }}">
                        @include('portal.partials.project-card', ['project' => $project])
                    </li>
                @endforeach
            </ul>

            <div class="mt-6">
                {{-- Plain links, not wire:click: the list has to paginate with
                     JavaScript switched off, and withQueryString() carries the
                     filters across either way. --}}
                <x-ui.pagination :paginator="$this->projects" :wire="false" :label="__('Published project pages')" />
            </div>
        @endif
    </div>
</div>
