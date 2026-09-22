{{--
    The GIS dashboard body, shared by the oversight and workspace screens.
    filter bar → summary tiles → map + legend → LGA breakdown → site list.

    Variables
      $map            ProjectMapBuilder output (pins, capped, totals, by_category, by_lga)
      $sites          LengthAwarePaginator over the same pins (the accessible list)
      $categories     list<App\Enums\ProjectMapCategory>
      $filters        ['status' => …, 'sector' => …, 'lga' => …, 'overdue' => bool, 'mda' => …?]
      $hasFilters     bool
      $statusOptions  value => label
      $sectors, $lgas Collections of reference rows
      $tenants        Collection|null — oversight only; adds the entity filter + column
      $projectUrl     URL template with __ULID__ in place of the project key
      $listUrl        Closure(array $query): string — the project list this surface drills into
      $lgaUrl         Closure(int $lgaId): string — this map, focused on one LGA

    THE MAP IS NOT THE PAGE. Everything a marker says is also in the LGA table
    and the site list, which is what a screen reader reads and what renders
    when the tile server or JavaScript does not.
--}}
@php
    $totals = $map['totals'];
    $pins = $map['pins'];
    $showEntity = $tenants !== null;
    $focusedLga = $filters['lga'] !== '' ? (int) $filters['lga'] : null;

    // One highlight: the focused LGA if there is one, otherwise the area with
    // the most overdue projects — the bar a director should look at first.
    $attention = $focusedLga ?? collect($map['by_lga'])
        ->filter(fn (array $row): bool => $row['overdue'] > 0 && $row['lga_id'] !== null)
        ->sortByDesc('overdue')
        ->first()['lga_id'] ?? null;

    $chartRows = collect($map['by_lga'])
        ->filter(fn (array $row): bool => $row['lga_id'] !== null)
        ->map(fn (array $row): array => [
            'label' => $row['name'],
            'value' => $row['projects'],
            'href' => $lgaUrl($row['lga_id']),
            'highlight' => $row['lga_id'] === $attention,
        ])
        ->values()
        ->all();

    $mapConfig = [
        'projectUrl' => $projectUrl,
        'tiles' => [
            'url' => config('platform.gis.tile_url'),
            'attribution' => config('platform.gis.tile_attribution'),
            'maxZoom' => config('platform.gis.max_zoom'),
        ],
        'labels' => [
            'open' => __('Open project'),
            'progress' => __(':percent% physical progress'),
            'value' => __('Contract value: :value'),
        ],
    ];

    $filterTargets = 'tenantId,status,sector,lga,overdue,clearFilters,focusLga';
@endphp

<div>
    {{-- Filter bar --}}
    <x-ui.card class="mb-4" flush>
        <div class="p-4">
            <div @class(['grid gap-3 sm:grid-cols-2', 'lg:grid-cols-4' => $showEntity, 'lg:grid-cols-3' => ! $showEntity])>
                @if ($showEntity)
                    <x-ui.form.group name="tenantId" :label="__('Entity')">
                        <x-ui.form.select
                            name="tenantId"
                            :placeholder="__('All entities')"
                            :options="$tenants->pluck('name', 'id')->all()"
                            wire:model.live="tenantId"
                        />
                    </x-ui.form.group>
                @endif

                <x-ui.form.group name="status" :label="__('Status')">
                    <x-ui.form.select
                        name="status"
                        :placeholder="__('Any status')"
                        :options="$statusOptions"
                        wire:model.live="status"
                    />
                </x-ui.form.group>

                <x-ui.form.group name="sector" :label="__('Sector')">
                    <x-ui.form.select
                        name="sector"
                        :placeholder="__('All sectors')"
                        :options="$sectors->pluck('name', 'id')->all()"
                        wire:model.live="sector"
                    />
                </x-ui.form.group>

                <x-ui.form.group name="lga" :label="__('Local government area')">
                    <x-ui.form.select
                        name="lga"
                        :placeholder="__('All areas')"
                        :options="$lgas->pluck('name', 'id')->all()"
                        wire:model.live="lga"
                    />
                </x-ui.form.group>
            </div>

            <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-3">
                <x-ui.form.checkbox
                    name="overdue"
                    :label="__('Past delivery date only')"
                    wire:model.live="overdue"
                />

                @if ($hasFilters)
                    <div class="sm:ml-auto">
                        <x-ui.button variant="ghost" size="sm" icon="x-mark" wire:click="clearFilters">
                            {{ __('Clear filters') }}
                        </x-ui.button>
                    </div>
                @endif
            </div>
        </div>
    </x-ui.card>

    {{-- Summary tiles: every one drills into the list that explains it, with
         the map's filters AND its geotagged condition carried over (the list's
         `geotagged` filter honours the LGA the same way the map does), so the
         list reproduces the tile's number rather than a bigger one. --}}
    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat
            :label="__('Projects on the map')"
            :value="number_format($totals['projects'])"
            icon="map-pin"
            :hint="trans_choice(':count site|:count sites', $totals['sites'], ['count' => number_format($totals['sites'])])"
            :href="$listUrl(['geotagged' => 'yes'])"
        />
        <x-ui.stat
            :label="__('Contract value mapped')"
            :value="$totals['contract_value']->format()"
            icon="banknotes"
            :hint="__('awarded contracts on these projects')"
            :href="$listUrl(['geotagged' => 'yes'])"
        />
        <x-ui.stat
            :label="__('Overdue projects')"
            :value="number_format($totals['overdue'])"
            icon="exclamation-triangle"
            :hint="__('past the revised or planned delivery date')"
            :href="$listUrl(['overdue' => 1, 'geotagged' => 'yes'])"
        />
        <x-ui.stat
            :label="__('Not geotagged')"
            :value="number_format($totals['unmapped'])"
            icon="exclamation-circle"
            :hint="__('matching projects with no GPS fix on any site')"
            :href="$listUrl(['geotagged' => 'no'])"
        />
    </div>

    @if ($map['capped'])
        <x-ui.alert variant="info" class="mb-4" :title="__('Showing the first :count sites', ['count' => number_format(\App\Support\Gis\ProjectMapBuilder::MAX_PINS)])">
            {{ __('The map draws at most :count markers so it stays usable on a field phone. Overdue sites are always drawn first; use the filters to narrow the rest. The figures above count every matching project.', ['count' => number_format(\App\Support\Gis\ProjectMapBuilder::MAX_PINS)]) }}
        </x-ui.alert>
    @endif

    {{-- Map + legend --}}
    <x-ui.card class="mb-4" flush>
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-4 py-3">
            <div>
                <h2 class="text-base font-semibold text-ink">{{ __('Project sites') }}</h2>
                {{-- Markers are SITES; the legend counts PROJECTS. Said out loud,
                     or a five-site overdue road reads "Overdue 1" beside five
                     red markers. --}}
                <p class="text-xs text-ink-muted">{{ __('One marker per site · legend counts projects') }}</p>
            </div>

            {{-- The legend doubles as the delivery breakdown. Icon + text + count:
                 the marker colour is never the only carrier of meaning. --}}
            <ul class="flex flex-wrap gap-x-4 gap-y-2" aria-label="{{ __('Map legend') }}">
                @foreach ($categories as $category)
                    <li class="inline-flex items-center gap-1.5 text-sm text-ink-muted">
                        <span class="map-pin map-pin--sm map-pin--{{ $category->tone() }}" aria-hidden="true">
                            <x-ui.icon :name="$category->icon()" class="size-3" />
                        </span>
                        <span>{{ $category->label() }}</span>
                        <span class="font-semibold tabular-nums text-ink">{{ number_format($map['by_category'][$category->value] ?? 0) }}</span>
                    </li>
                @endforeach
            </ul>
        </div>

        {{-- Every marker is a tab stop; up to a thousand of them is no way
             to reach the tables below by keyboard. --}}
        <a href="#gis-site-list" class="sr-only rounded-md bg-surface-raised px-3 py-2 text-sm font-medium text-ink ring-2 ring-focus focus:not-sr-only focus:absolute focus:z-[700] focus:m-3">
            {{ __('Skip the map to the site list') }}
        </a>

        <div class="relative">
            <div
                wire:ignore
                x-data="projectMap(@js($mapConfig))"
                x-on:project-map-updated.window="draw($event.detail.pins)"
            >
                {{-- Pins as data: type="application/json" never executes, and @json
                     escapes < and & so nothing in a title can close the element. --}}
                <script type="application/json" x-ref="pins">@json($pins)</script>

                {{-- Marker glyphs, server-rendered from our own icon set and cloned
                     by the map script. Never built from project data. --}}
                @foreach ($categories as $category)
                    <template x-ref="icon-{{ $category->value }}">
                        <span class="map-pin map-pin--{{ $category->tone() }}"><x-ui.icon :name="$category->icon()" class="size-3.5" /></span>
                    </template>
                @endforeach

                <div
                    x-ref="canvas"
                    class="h-80 w-full rounded-b-xl bg-neutral-soft sm:h-[34rem]"
                    role="region"
                    aria-label="{{ __('Map of project sites') }}"
                ></div>
            </div>

            @if (count($pins) === 0)
                <div class="absolute inset-0 z-[500] flex items-center justify-center rounded-b-xl bg-surface-raised/95 p-4">
                    <x-ui.empty-state
                        :variant="$hasFilters ? 'filtered' : 'empty'"
                        icon="map-pin"
                        :title="$hasFilters ? __('No sites match these filters') : __('No project sites have coordinates yet')"
                        :description="$hasFilters
                            ? __('Try another status, sector or area, or clear the filters.')
                            : __('A project appears here once one of its sites has a recorded GPS position. Add coordinates on the project’s Sites tab.')"
                    >
                        @if ($hasFilters)
                            <x-slot:actions>
                                <x-ui.button variant="secondary" icon="x-mark" wire:click="clearFilters">{{ __('Clear filters') }}</x-ui.button>
                            </x-slot:actions>
                        @endif
                    </x-ui.empty-state>
                </div>
            @endif

            <div
                wire:loading.delay.flex
                wire:target="{{ $filterTargets }}"
                class="absolute inset-x-0 top-3 z-[600] hidden justify-center"
            >
                <span class="inline-flex items-center gap-2 rounded-full bg-surface-raised px-3 py-1.5 text-sm font-medium text-ink shadow ring-1 ring-line" role="status">
                    <x-ui.icon name="arrow-path" class="size-4 animate-spin" />
                    {{ __('Updating map…') }}
                </span>
            </div>
        </div>

        <p class="sr-only">{{ __('The same sites are listed in the tables below.') }}</p>
    </x-ui.card>

    {{-- LGA breakdown --}}
    <div class="mb-4 grid gap-4 xl:grid-cols-5">
        <x-ui.card class="xl:col-span-3" flush :title="__('By local government area')" :subtitle="__('Distinct projects per area — a multi-site project counts once in each area it touches')">
            <div wire:loading.delay.long.flex wire:target="{{ $filterTargets }}" class="hidden p-4">
                <x-ui.skeleton variant="table" :rows="5" />
            </div>

            <div wire:loading.delay.long.remove wire:target="{{ $filterTargets }}">
                @if (empty($map['by_lga']))
                    <x-ui.empty-state
                        compact
                        icon="map-pin"
                        :title="__('No areas to show')"
                        :description="__('Areas appear once mapped sites match the filters.')"
                    />
                @else
                    <x-ui.table
                        :caption="__('Mapped projects by local government area')"
                        class="p-4 sm:p-0"
                        :headings="[
                            __('Area'),
                            ['label' => __('Projects'), 'align' => 'right'],
                            ['label' => __('Sites'), 'align' => 'right'],
                            ['label' => __('Overdue'), 'align' => 'right'],
                            __('Average progress'),
                            '',
                        ]"
                    >
                        @foreach ($map['by_lga'] as $row)
                            <x-ui.table.row wire:key="gis-lga-{{ $row['lga_id'] ?? 'none' }}">
                                <x-ui.table.cell :label="__('Area')" primary>{{ $row['name'] }}</x-ui.table.cell>
                                <x-ui.table.cell :label="__('Projects')" numeric>{{ number_format($row['projects']) }}</x-ui.table.cell>
                                <x-ui.table.cell :label="__('Sites')" numeric>{{ number_format($row['sites']) }}</x-ui.table.cell>
                                <x-ui.table.cell :label="__('Overdue')" numeric>
                                    @if ($row['overdue'] > 0)
                                        <x-ui.badge status="overdue" size="sm" :label="number_format($row['overdue'])" />
                                    @else
                                        <span class="text-ink-muted">0</span>
                                    @endif
                                </x-ui.table.cell>
                                <x-ui.table.cell :label="__('Average progress')">
                                    <x-ui.progress :value="$row['average_progress']" size="sm" class="sm:w-28"
                                        :label="__('Average physical progress in :area', ['area' => $row['name']])" />
                                </x-ui.table.cell>
                                <x-ui.table.cell align="right">
                                    @if ($row['lga_id'] !== null && $row['lga_id'] === $focusedLga)
                                        <x-ui.button variant="ghost" size="sm" icon="x-mark" wire:click="focusLga(null)">
                                            {{ __('All areas') }}
                                        </x-ui.button>
                                    @elseif ($row['lga_id'] !== null)
                                        <x-ui.button variant="ghost" size="sm" icon="map-pin" wire:click="focusLga({{ $row['lga_id'] }})">
                                            {{ __('Show on map') }}
                                        </x-ui.button>
                                    @endif
                                </x-ui.table.cell>
                            </x-ui.table.row>
                        @endforeach
                    </x-ui.table>
                @endif
            </div>
        </x-ui.card>

        <x-ui.card class="xl:col-span-2">
            <x-ui.chart
                type="bar"
                :title="__('Mapped projects by area')"
                :description="$focusedLga !== null
                    ? __('Highlighted: the area in focus.')
                    : ($attention !== null ? __('Highlighted: the area with the most overdue projects.') : null)"
                :rows="$chartRows"
                :empty="__('No mapped projects match these filters.')"
                :height="max(200, count($chartRows) * 36)"
            />
        </x-ui.card>
    </div>

    {{-- The same sites as a list --}}
    <x-ui.card id="gis-site-list" tabindex="-1" flush :title="__('Sites on this map')" :subtitle="trans_choice(':count site|:count sites', $sites->total(), ['count' => number_format($sites->total())])">
        <div wire:loading.delay.long.flex wire:target="{{ $filterTargets }},gotoPage,previousPage,nextPage" class="hidden p-4">
            <x-ui.skeleton variant="table" :rows="6" />
        </div>

        <div wire:loading.delay.long.remove wire:target="{{ $filterTargets }},gotoPage,previousPage,nextPage">
            @if ($sites->isEmpty())
                <x-ui.empty-state
                    compact
                    :variant="$hasFilters ? 'filtered' : 'empty'"
                    icon="map-pin"
                    :title="$hasFilters ? __('No sites match these filters') : __('No mapped sites yet')"
                    :description="__('Sites are listed here once they have recorded coordinates.')"
                />
            @else
                <x-ui.table
                    :caption="__('Mapped project sites')"
                    class="p-4 sm:p-0"
                    :headings="array_values(array_filter([
                        __('Project'),
                        $showEntity ? __('Entity') : null,
                        __('Site'),
                        __('Status'),
                        __('Progress'),
                        ['label' => __('Coordinates'), 'align' => 'right'],
                    ]))"
                >
                    @foreach ($sites as $site)
                        <x-ui.table.row wire:key="gis-site-{{ $site['ulid'] }}-{{ $loop->index }}">
                            <x-ui.table.cell :label="__('Project')" primary>
                                <a href="{{ str_replace('__ULID__', $site['ulid'], $projectUrl) }}" class="rounded hover:underline">{{ $site['title'] }}</a>
                                <span class="mt-0.5 block font-mono text-xs font-normal text-ink-muted">{{ $site['reference'] }}</span>
                            </x-ui.table.cell>

                            @if ($showEntity)
                                <x-ui.table.cell :label="__('Entity')">
                                    <span class="text-ink-muted">{{ $site['entity'] }}</span>
                                </x-ui.table.cell>
                            @endif

                            <x-ui.table.cell :label="__('Site')">
                                {{ $site['site'] ?? __('Unnamed site') }}
                                <span class="block text-xs text-ink-muted">{{ $site['lga'] ?? __('LGA not recorded') }}</span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Status')">
                                <div class="flex flex-wrap gap-1">
                                    <x-ui.badge :status="$site['status']" :label="$site['status_label']" size="sm" />
                                    @if ($site['overdue'])
                                        <x-ui.badge status="overdue" size="sm" />
                                    @endif
                                </div>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Progress')">
                                <x-ui.progress :value="$site['progress']" size="sm" class="sm:w-28"
                                    :label="__('Physical progress for :project', ['project' => $site['title']])" />
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Coordinates')" numeric>
                                <span class="font-mono text-xs">{{ number_format($site['lat'], 5) }}, {{ number_format($site['lng'], 5) }}</span>
                            </x-ui.table.cell>
                        </x-ui.table.row>
                    @endforeach
                </x-ui.table>

                <x-ui.pagination :paginator="$sites" :label="__('Site list pages')" />
            @endif
        </div>
    </x-ui.card>
</div>
