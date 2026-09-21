{{--
    Published project sites, on a map.

    $pins comes from App\Actions\Portal\ListPublishedProjectPins — one entry per
    SITE of a published project, projected through PublicProjectPayload like
    every other portal read. $filters comes from ListPortalFilterOptions and
    describes exactly the set these pins can show, never the full reference
    tables.

    THE MAP IS NOT THE PAGE. Leaflet is loaded from the CDN the portal layout
    allow-lists (and nothing else on the platform does), and the identical pins
    are rendered as a plain list inside <noscript>. Plenty of the people this
    portal exists for browse with JavaScript off, on a metered connection, or on
    a device that gives up on a tile layer — a blank rectangle is a dead end,
    and a dead end on a transparency portal is a refusal.

    Filtering is client-side because the map is client-side. Without JavaScript
    the list below is unfiltered and points at /projects, which filters on the
    server.
--}}
@php
    $title = __('Project map');
    $leaflet = true;

    $sectorOptions = array_values($filters['sectors']);
    $lgaOptions = array_values($filters['lgas']);
    $statusOptions = $filters['statuses'];

    $atCap = count($pins) >= \App\Actions\Portal\ListPublishedProjectPins::MAX_PINS;

    // The no-JavaScript list reads better grouped by local government area:
    // "what is being built near me" is the question, and the LGA is how people
    // answer it out loud.
    $byLga = collect($pins)->groupBy(fn (array $pin): string => $pin['lga'] ?? __('Location not recorded'))->sortKeys();
@endphp

@extends('layouts.portal')

@section('content')
    <x-ui.page-header
        :title="__('Project map')"
        :description="__('Every published project site the state has recorded coordinates for. Select a marker to see what is being built there and open the full record.')"
        :breadcrumbs="[
            ['label' => __('Home'), 'href' => route('portal.home')],
            ['label' => __('Project map')],
        ]"
    >
        <x-slot:actions>
            <x-ui.button :href="route('portal.projects.index')" variant="secondary" icon="squares">
                {{ __('Browse as a list') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if (blank($pins))
        <x-ui.card flush>
            <x-ui.empty-state
                icon="map-pin"
                :title="__('No project sites have been published yet')"
                :description="__('A project appears on this map once it has been published and has at least one site with recorded coordinates.')"
            >
                <x-slot:actions>
                    <x-ui.button :href="route('portal.projects.index')" variant="secondary" icon="folder">
                        {{ __('See published projects') }}
                    </x-ui.button>
                </x-slot:actions>
            </x-ui.empty-state>
        </x-ui.card>
    @else
        @if ($atCap)
            <x-ui.alert variant="info" class="mb-4" :title="__('Showing the most recently published sites')">
                {{ __('This map draws at most :count markers so it stays usable on a slow connection. Use the filters, or the project list, to narrow it down.', ['count' => \App\Actions\Portal\ListPublishedProjectPins::MAX_PINS]) }}
            </x-ui.alert>
        @endif

        {{-- The pins, as data. type="application/json" is never executed, and
             @json escapes < and & so nothing here can close the element. --}}
        <script type="application/json" id="portal-map-pins">@json($pins)</script>

        <x-ui.card class="mb-4" flush>
            {{-- Client-side, and honest about it: hidden entirely without
                 JavaScript, because a filter bar that does nothing is worse
                 than no filter bar. --}}
            <div class="hidden p-4" data-map-filters>
                <div class="grid gap-3 sm:grid-cols-3">
                    <x-ui.form.group name="map-sector" :label="__('Sector')">
                        <x-ui.form.select
                            id="map-sector"
                            :placeholder="__('All sectors')"
                            :options="$sectorOptions"
                            data-map-filter="sector"
                        />
                    </x-ui.form.group>

                    <x-ui.form.group name="map-lga" :label="__('Local government area')">
                        <x-ui.form.select
                            id="map-lga"
                            :placeholder="__('All areas')"
                            :options="$lgaOptions"
                            data-map-filter="lga"
                        />
                    </x-ui.form.group>

                    <x-ui.form.group name="map-status" :label="__('Status')">
                        <x-ui.form.select
                            id="map-status"
                            :placeholder="__('Any status')"
                            :options="$statusOptions"
                            data-map-filter="status"
                        />
                    </x-ui.form.group>
                </div>

                <p class="mt-3 text-sm text-ink-muted" data-map-count role="status" aria-live="polite"></p>
            </div>
        </x-ui.card>

        <x-ui.card flush>
            <div
                id="portal-map"
                class="h-96 w-full rounded-xl sm:h-[32rem]"
                role="region"
                aria-label="{{ __('Map of published project sites') }}"
            ></div>

            {{-- A map is not readable by a screen reader however it is marked
                 up, so the accessible route to the same records is named here
                 rather than left implicit. --}}
            <p class="sr-only">
                <a href="{{ route('portal.projects.index') }}">{{ __('Browse the same published projects as a list') }}</a>
            </p>
        </x-ui.card>

        {{-- The same pins, without a map. Not a fallback message: the data. --}}
        <noscript>
            <x-ui.alert variant="neutral" class="mt-4" :title="__('The map needs JavaScript')">
                {{ __('Every published site is listed below instead, grouped by local government area. The project list can be filtered without JavaScript.') }}
            </x-ui.alert>

            <div class="mt-4 space-y-6">
                @foreach ($byLga as $lga => $group)
                    <section aria-labelledby="lga-{{ $loop->index }}">
                        <h2 id="lga-{{ $loop->index }}" class="text-base font-semibold text-ink">{{ $lga }}</h2>

                        <ul class="mt-2 divide-y divide-line rounded-xl border border-line bg-surface-raised">
                            @foreach ($group as $pin)
                                <li class="px-4 py-3">
                                    <a
                                        href="{{ route('portal.projects.show', ['ulid' => $pin['ulid']]) }}"
                                        class="rounded text-sm font-medium text-ink underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                                    >{{ $pin['title'] }}</a>

                                    <p class="mt-0.5 text-xs text-ink-muted">
                                        {{ collect([$pin['site'], $pin['entity'], $pin['sector']])->filter()->implode(' · ') }}
                                    </p>

                                    <p class="mt-1 flex flex-wrap items-center gap-2">
                                        <x-ui.badge :status="$pin['status']" :label="$pin['status_label']" size="sm" />
                                        <span class="text-xs text-ink-muted">
                                            {{ __(':percent% complete', ['percent' => rtrim(rtrim(number_format((float) $pin['progress'], 1, '.', ''), '0'), '.')]) }}
                                        </span>
                                    </p>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endforeach
            </div>
        </noscript>

        <script>
            document.addEventListener('DOMContentLoaded', function () {
                var node = document.getElementById('portal-map-pins');
                var container = document.getElementById('portal-map');

                if (! node || ! container || typeof L === 'undefined') {
                    return;
                }

                var pins = JSON.parse(node.textContent);
                var filters = document.querySelector('[data-map-filters]');
                var counter = document.querySelector('[data-map-count]');

                if (filters) {
                    filters.classList.remove('hidden');
                }

                var map = L.map(container, { scrollWheelZoom: false });

                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 18,
                    attribution: '&copy; OpenStreetMap contributors',
                }).addTo(map);

                var layer = L.layerGroup().addTo(map);

                // Popups are built from DOM nodes with textContent, never from an
                // HTML string: project titles are staff-entered, and "staff-entered"
                // is not "trusted" on a page served to the public.
                function popup(pin) {
                    var wrap = document.createElement('div');

                    var title = document.createElement('strong');
                    title.textContent = pin.title;
                    wrap.appendChild(title);

                    [[pin.site, pin.lga].filter(Boolean).join(', '), pin.entity, pin.status_label].forEach(function (line) {
                        if (! line) {
                            return;
                        }
                        var p = document.createElement('div');
                        p.textContent = line;
                        wrap.appendChild(p);
                    });

                    var link = document.createElement('a');
                    link.href = @json(route('portal.projects.show', ['ulid' => '__ULID__'])).replace('__ULID__', encodeURIComponent(pin.ulid));
                    link.textContent = @json(__('See the detail'));
                    wrap.appendChild(document.createElement('br'));
                    wrap.appendChild(link);

                    return wrap;
                }

                function selected(name) {
                    var field = document.querySelector('[data-map-filter="' + name + '"]');

                    return field ? field.value : '';
                }

                function draw() {
                    var sector = selected('sector');
                    var lga = selected('lga');
                    var status = selected('status');

                    layer.clearLayers();

                    var points = [];

                    pins.forEach(function (pin) {
                        if (sector && pin.sector !== sector) { return; }
                        if (lga && pin.lga !== lga) { return; }
                        if (status && pin.status !== status) { return; }

                        var marker = L.marker([pin.lat, pin.lng], { title: pin.title });
                        marker.bindPopup(popup(pin));
                        marker.addTo(layer);
                        points.push([pin.lat, pin.lng]);
                    });

                    if (counter) {
                        counter.textContent = points.length === 1
                            ? @json(__('1 site shown'))
                            : @json(__(':count sites shown')).replace(':count', points.length);
                    }

                    if (points.length > 0) {
                        map.fitBounds(points, { padding: [32, 32], maxZoom: 13 });
                    }
                }

                document.querySelectorAll('[data-map-filter]').forEach(function (field) {
                    field.addEventListener('change', draw);
                });

                // First pin, then fitBounds in draw(). No hard-coded centre:
                // this platform ships to more than one state.
                map.setView([pins[0].lat, pins[0].lng], 10);
                draw();
            });
        </script>
    @endif
@endsection
