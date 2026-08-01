{{--
    Design-system preview. Renders every <x-ui.*> primitive in every variant and
    state, using representative M&E content (no lorem ipsum, no real client names —
    the platform is white-label, so nothing here identifies a state or ministry).

    No route is registered for this view yet; wire it behind an internal/dev route.
--}}
@php
    $title = __('Design system');

    $projects = [
        [
            'ref' => 'PRJ-2401',
            'name' => 'Township Road Rehabilitation (Section II)',
            'mda' => 'Ministry of Works & Infrastructure',
            'contractor' => 'Harmony Civil Works Ltd',
            'sum' => '₦1,842,500,000',
            'physical' => 72,
            'financial' => 65,
            'status' => 'on_track',
            'due' => '30 Sep 2026',
        ],
        [
            'ref' => 'PRJ-2417',
            'name' => 'Model Primary Healthcare Centre, Ward 7',
            'mda' => 'Ministry of Health',
            'contractor' => 'Bridgeline Consortium',
            'sum' => '₦486,300,000',
            'physical' => 41,
            'financial' => 58,
            'status' => 'behind',
            'due' => '14 Jun 2026',
        ],
        [
            'ref' => 'PRJ-2388',
            'name' => 'Rural Water Supply Scheme — Cluster B',
            'mda' => 'Rural Water Supply & Sanitation Agency',
            'contractor' => 'Nova Hydrotech Nigeria Ltd',
            'sum' => '₦912,000,000',
            'physical' => 100,
            'financial' => 96,
            'status' => 'completed',
            'due' => '02 Mar 2026',
        ],
        [
            'ref' => 'PRJ-2432',
            'name' => 'Solar Street Lighting — Trunk A Corridor',
            'mda' => 'Ministry of Energy & Power',
            'contractor' => 'Rayfield Energy Systems',
            'sum' => '₦327,750,000',
            'physical' => 18,
            'financial' => 35,
            'status' => 'overdue',
            'due' => '31 Jan 2026',
        ],
        [
            'ref' => 'PRJ-2440',
            'name' => 'Renovation of 24 Junior Secondary Schools',
            'mda' => 'Universal Basic Education Board',
            'contractor' => 'Almond Facilities Ltd',
            'sum' => '₦1,150,000,000',
            'physical' => 100,
            'financial' => 100,
            'status' => 'certified',
            'due' => '18 Dec 2025',
        ],
    ];

    $brandScale = [
        'bg-brand-50' => '--brand-50',
        'bg-brand-100' => '--brand-100',
        'bg-brand-200' => '--brand-200',
        'bg-brand-300' => '--brand-300',
        'bg-brand-400' => '--brand-400',
        'bg-brand-500' => '--brand-500',
        'bg-brand-600' => '--brand-600',
        'bg-brand-700' => '--brand-700',
        'bg-brand-800' => '--brand-800',
        'bg-brand-900' => '--brand-900',
        'bg-brand-950' => '--brand-950',
    ];

    $semanticTokens = [
        ['class' => 'bg-surface', 'token' => '--surface', 'use' => 'Page background'],
        ['class' => 'bg-surface-raised', 'token' => '--surface-raised', 'use' => 'Cards, sidebar, modals'],
        ['class' => 'bg-surface-sunken', 'token' => '--surface-sunken', 'use' => 'Table headers, wells'],
        ['class' => 'bg-ink', 'token' => '--ink', 'use' => 'Primary text'],
        ['class' => 'bg-ink-muted', 'token' => '--ink-muted', 'use' => 'Secondary text (AA on surface)'],
        ['class' => 'bg-line', 'token' => '--line', 'use' => 'Borders, dividers'],
        ['class' => 'bg-positive', 'token' => '--positive', 'use' => 'On track, approved'],
        ['class' => 'bg-warning', 'token' => '--warning', 'use' => 'Behind schedule, attention'],
        ['class' => 'bg-critical', 'token' => '--critical', 'use' => 'Overdue, rejected, destructive'],
        ['class' => 'bg-info', 'token' => '--info', 'use' => 'Submitted, informational'],
    ];

    $statuses = ['draft', 'submitted', 'under_review', 'approved', 'rejected', 'overdue', 'on_track', 'behind', 'completed', 'certified', 'on_hold', 'cancelled'];
@endphp

@extends('layouts.tenant')

@section('content')
    <x-ui.page-header
        :title="__('Design system')"
        :description="__('Every primitive in resources/views/components/ui, in every variant and state. Compose screens from these — do not fork them. Colours come from semantic tokens only, so re-skinning is a CSS-variable change.')"
        :breadcrumbs="[
            ['label' => __('Workspace'), 'href' => '#'],
            ['label' => __('Design system')],
        ]"
    >
        <x-slot:actions>
            <x-ui.button variant="secondary" size="sm" icon="arrow-down-tray">{{ __('Export tokens') }}</x-ui.button>
            <x-ui.button size="sm" icon="plus">{{ __('New component') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="space-y-12 pb-16" x-data>

        {{-- ------------------------------------------------------------------ --}}
        <x-styleguide.section
            id="tokens"
            :title="__('1. Design tokens')"
            :description="__('The placeholder palette is a dignified deep green. Swapping the custom-property values in resources/css/app.css re-skins every screen, chart and status colour — nothing below hard-codes a hex.')"
            usage="resources/css/app.css → :root / .dark"
        >
            <x-ui.card :title="__('Brand scale')" :subtitle="__('Interactive brand = brand-600 in light, brand-500 in dark, so button labels always pass AA.')">
                <div class="grid grid-cols-3 gap-3 sm:grid-cols-6 lg:grid-cols-11">
                    @foreach ($brandScale as $class => $token)
                        <div class="space-y-1">
                            <div class="h-14 rounded-lg border border-line {{ $class }}"></div>
                            <p class="font-mono text-[11px] text-ink-muted">{{ $token }}</p>
                        </div>
                    @endforeach
                </div>
            </x-ui.card>

            <x-ui.card :title="__('Semantic tokens')" :subtitle="__('Screens reference these, never the raw scale.')">
                <dl class="grid gap-3 sm:grid-cols-2">
                    @foreach ($semanticTokens as $token)
                        <div class="flex items-center gap-3 rounded-lg border border-line p-3">
                            <span class="size-10 shrink-0 rounded-md border border-line {{ $token['class'] }}"></span>
                            <div class="min-w-0">
                                <dt class="font-mono text-xs text-ink">{{ $token['token'] }}</dt>
                                <dd class="text-sm text-ink-muted">{{ $token['use'] }}</dd>
                            </div>
                        </div>
                    @endforeach
                </dl>
            </x-ui.card>
        </x-styleguide.section>

        {{-- ------------------------------------------------------------------ --}}
        <x-styleguide.section
            id="buttons"
            :title="__('2. Buttons')"
            :description="__('Four variants, two sizes. md is 44px tall — the thumb target for a monitor filing a report on a phone. Loading state is driven by wire:loading, so it disables itself while the request is in flight.')"
            usage="x-ui.button · variant: primary/secondary/ghost/destructive · size: sm/md"
        >
            <x-ui.card>
                <div class="space-y-6">
                    @foreach (['primary' => __('Primary — one per screen'), 'secondary' => __('Secondary'), 'ghost' => __('Ghost — toolbars, table actions'), 'destructive' => __('Destructive — irreversible only')] as $variant => $caption)
                        <div class="space-y-2">
                            <p class="text-xs font-semibold tracking-wide text-ink-muted uppercase">{{ $caption }}</p>
                            <div class="flex flex-wrap items-center gap-3">
                                <x-ui.button :variant="$variant">{{ __('Submit report') }}</x-ui.button>
                                <x-ui.button :variant="$variant" size="sm">{{ __('Small') }}</x-ui.button>
                                <x-ui.button :variant="$variant" icon="clipboard-check">{{ __('With icon') }}</x-ui.button>
                                <x-ui.button :variant="$variant" trailing-icon="chevron-right">{{ __('Trailing') }}</x-ui.button>
                                <x-ui.button :variant="$variant" icon="ellipsis-vertical" icon-only>{{ __('More actions') }}</x-ui.button>
                                <x-ui.button :variant="$variant" disabled>{{ __('Disabled') }}</x-ui.button>
                                <x-ui.button :variant="$variant" loading="save">{{ __('Saving state') }}</x-ui.button>
                            </div>
                        </div>
                    @endforeach

                    <div class="space-y-2">
                        <p class="text-xs font-semibold tracking-wide text-ink-muted uppercase">{{ __('As a link') }}</p>
                        <x-ui.button href="#buttons" variant="secondary" icon="arrow-down-tray">{{ __('Download inspection form (PDF)') }}</x-ui.button>
                    </div>
                </div>
            </x-ui.card>
        </x-styleguide.section>

        {{-- ------------------------------------------------------------------ --}}
        <x-styleguide.section
            id="badges"
            :title="__('3. Status badges')"
            :description="__('Status is always icon + text. Print a board pack in greyscale, or hand the phone to someone with deuteranopia — the meaning survives.')"
            usage="x-ui.badge · status + optional label override"
        >
            <x-ui.card>
                <div class="flex flex-wrap gap-2">
                    @foreach ($statuses as $status)
                        <x-ui.badge :status="$status" />
                    @endforeach
                </div>

                <div class="mt-5 space-y-2">
                    <p class="text-xs font-semibold tracking-wide text-ink-muted uppercase">{{ __('Small size + custom label (terminology is configurable per instance)') }}</p>
                    <div class="flex flex-wrap gap-2">
                        <x-ui.badge status="under_review" size="sm" :label="__('With M&E officer')" />
                        <x-ui.badge status="approved" size="sm" :label="__('Certified by QS')" />
                        <x-ui.badge status="unknown_future_state" size="sm" />
                    </div>
                </div>
            </x-ui.card>
        </x-styleguide.section>

        {{-- ------------------------------------------------------------------ --}}
        <x-styleguide.section
            id="stats"
            :title="__('4. Stat tiles')"
            :description="__('Every metric links to the list that explains it. Direction is an arrow icon plus a screen-reader word, and “up” is not automatically good — overdue reports rising is a critical delta.')"
            usage="x-ui.stat · label, value, delta, trend, intent, href"
        >
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <x-ui.stat
                    :label="__('Active projects')"
                    value="148"
                    icon="folder"
                    delta="+12"
                    trend="up"
                    intent="positive"
                    :hint="__('vs last quarter')"
                    href="#stats"
                />
                <x-ui.stat
                    :label="__('Contract value under monitoring')"
                    value="₦42.8bn"
                    icon="banknotes"
                    delta="+4.1%"
                    trend="up"
                    intent="neutral"
                    :hint="__('of ₦51.2bn appropriated')"
                    href="#stats"
                />
                <x-ui.stat
                    :label="__('Reports overdue')"
                    value="9"
                    icon="exclamation-triangle"
                    delta="+3"
                    trend="up"
                    intent="critical"
                    :hint="__('across 4 entities')"
                    href="#stats"
                />
                <x-ui.stat :label="__('Average physical progress')" value="63%" icon="chart-bar" delta="0.0%" trend="flat" :hint="__('no movement this month')" />
            </div>
        </x-styleguide.section>

        {{-- ------------------------------------------------------------------ --}}
        <x-styleguide.section
            id="pattern"
            :title="__('5. Data-heavy screen pattern')"
            :description="__('Filter bar → stat summary → table → pagination. Resize below 640px: each row becomes a card and every cell prints its own label. Search debounces at 300ms; filters persist in the query string.')"
            usage="x-ui.card + x-ui.table + x-ui.table.row + x-ui.table.cell (label per cell)"
        >
            <x-ui.card flush>
                {{-- Filter bar --}}
                <div class="border-b border-line p-4">
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <x-ui.form.group name="project_search" :label="__('Search')" class="lg:col-span-2">
                            <x-ui.form.input
                                name="project_search"
                                type="search"
                                icon="magnifying-glass"
                                :placeholder="__('Project name, reference or contractor…')"
                                wire:model.live.debounce.300ms="search"
                            />
                        </x-ui.form.group>

                        <x-ui.form.group name="filter_mda" :label="__('Entity')">
                            <x-ui.form.select
                                name="filter_mda"
                                :placeholder="__('All entities')"
                                :options="[
                                    'works' => __('Ministry of Works & Infrastructure'),
                                    'health' => __('Ministry of Health'),
                                    'education' => __('Universal Basic Education Board'),
                                    'water' => __('Rural Water Supply & Sanitation Agency'),
                                ]"
                                wire:model.live="filters.mda"
                            />
                        </x-ui.form.group>

                        <x-ui.form.group name="filter_status" :label="__('Status')">
                            <x-ui.form.select
                                name="filter_status"
                                :placeholder="__('Any status')"
                                :options="[
                                    'on_track' => __('On track'),
                                    'behind' => __('Behind schedule'),
                                    'overdue' => __('Overdue'),
                                    'completed' => __('Completed'),
                                ]"
                                wire:model.live="filters.status"
                            />
                        </x-ui.form.group>
                    </div>

                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <x-ui.button variant="ghost" size="sm" icon="x-mark">{{ __('Clear filters') }}</x-ui.button>
                        <span class="flex-1"></span>
                        <x-ui.button variant="secondary" size="sm" icon="arrow-down-tray" loading="exportExcel">{{ __('Excel') }}</x-ui.button>
                        <x-ui.button variant="secondary" size="sm" icon="document-text" loading="exportPdf">{{ __('PDF') }}</x-ui.button>
                    </div>
                </div>

                {{-- Summary row --}}
                <div class="grid gap-3 border-b border-line bg-surface-sunken p-4 sm:grid-cols-3">
                    <x-ui.stat :label="__('Matching projects')" value="148" icon="folder" />
                    <x-ui.stat :label="__('Combined contract sum')" value="₦42.8bn" icon="banknotes" />
                    <x-ui.stat :label="__('Behind or overdue')" value="27" icon="exclamation-triangle" delta="18%" trend="up" intent="critical" :hint="__('of portfolio')" />
                </div>

                {{-- Table --}}
                <x-ui.table
                    :caption="__('Projects under monitoring, with physical progress and current status')"
                    class="p-4 sm:p-0"
                    :headings="[
                        ['label' => __('Project'), 'sortable' => true, 'sort' => 'asc', 'click' => 'sortBy(\'name\')'],
                        __('Entity'),
                        ['label' => __('Contract sum'), 'align' => 'right', 'sortable' => true, 'click' => 'sortBy(\'contract_sum\')'],
                        ['label' => __('Physical'), 'align' => 'right'],
                        __('Status'),
                        ['label' => __('Next report due'), 'align' => 'right'],
                        '',
                    ]"
                >
                    @foreach ($projects as $project)
                        <x-ui.table.row wire:key="project-{{ $project['ref'] }}">
                            <x-ui.table.cell :label="__('Project')" primary>
                                <a href="#pattern" class="rounded hover:underline">{{ $project['name'] }}</a>
                                <span class="block font-mono text-xs font-normal text-ink-muted">{{ $project['ref'] }} · {{ $project['contractor'] }}</span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Entity')">
                                <span class="text-ink-muted">{{ $project['mda'] }}</span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Contract sum')" numeric>{{ $project['sum'] }}</x-ui.table.cell>

                            <x-ui.table.cell :label="__('Physical progress')" numeric>
                                <span class="inline-flex items-center gap-2">
                                    <span class="h-1.5 w-16 overflow-hidden rounded-full bg-neutral-soft" aria-hidden="true">
                                        <span class="block h-full rounded-full bg-brand" style="width: {{ $project['physical'] }}%"></span>
                                    </span>
                                    {{ $project['physical'] }}%
                                </span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Status')">
                                <x-ui.badge :status="$project['status']" />
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Next report due')" align="right">
                                <span class="text-ink-muted">{{ $project['due'] }}</span>
                            </x-ui.table.cell>

                            <x-ui.table.cell align="right">
                                <x-ui.dropdown align="right" :label="__('Project actions')">
                                    <x-slot:trigger>
                                        <x-ui.button variant="ghost" size="sm" icon="ellipsis-vertical" icon-only>{{ __('Actions for :project', ['project' => $project['name']]) }}</x-ui.button>
                                    </x-slot:trigger>
                                    <x-ui.dropdown.item icon="eye" href="#pattern">{{ __('View project') }}</x-ui.dropdown.item>
                                    <x-ui.dropdown.item icon="document-text" href="#pattern">{{ __('Progress reports') }}</x-ui.dropdown.item>
                                    <x-ui.dropdown.item icon="map-pin" href="#pattern">{{ __('Schedule inspection') }}</x-ui.dropdown.item>
                                    <x-ui.dropdown.item icon="trash" destructive>{{ __('Archive project') }}</x-ui.dropdown.item>
                                </x-ui.dropdown>
                            </x-ui.table.cell>
                        </x-ui.table.row>
                    @endforeach
                </x-ui.table>

                <x-slot:footer>
                    <div class="flex flex-col items-center justify-between gap-3 sm:flex-row">
                        <p class="text-sm text-ink-muted">
                            {{ __('Showing :from–:to of :total projects', ['from' => 1, 'to' => 25, 'total' => 148]) }}
                        </p>
                        <div class="flex items-center gap-2">
                            <x-ui.button variant="secondary" size="sm" icon="chevron-left" disabled>{{ __('Previous') }}</x-ui.button>
                            <x-ui.button variant="secondary" size="sm" trailing-icon="chevron-right">{{ __('Next') }}</x-ui.button>
                        </div>
                    </div>
                </x-slot:footer>
            </x-ui.card>
        </x-styleguide.section>

        {{-- ------------------------------------------------------------------ --}}
        <x-styleguide.section
            id="empty-states"
            :title="__('6. Empty, filtered and error states')"
            :description="__('No blank white screens. Each state names what happened and offers the next action.')"
            usage="x-ui.empty-state · variant: empty / filtered / error"
        >
            <div class="grid gap-4 lg:grid-cols-3">
                <x-ui.card flush>
                    <x-ui.empty-state
                        :title="__('No projects yet')"
                        :description="__('Register the first capital project for this entity to start collecting progress reports and inspections.')"
                    >
                        <x-slot:actions>
                            <x-ui.button icon="plus">{{ __('Register project') }}</x-ui.button>
                            <x-ui.button variant="secondary" icon="arrow-up-tray">{{ __('Import from budget') }}</x-ui.button>
                        </x-slot:actions>
                    </x-ui.empty-state>
                </x-ui.card>

                <x-ui.card flush>
                    <x-ui.empty-state
                        variant="filtered"
                        :description="__('No projects match “borehole” with status Overdue in this quarter.')"
                    >
                        <x-slot:actions>
                            <x-ui.button variant="secondary" icon="x-mark">{{ __('Clear filters') }}</x-ui.button>
                        </x-slot:actions>
                    </x-ui.empty-state>
                </x-ui.card>

                <x-ui.card flush>
                    <x-ui.empty-state
                        variant="error"
                        :title="__('Could not load progress data')"
                        :description="__('The request timed out. Your filters are saved — try again, or continue offline and sync later.')"
                    >
                        <x-slot:actions>
                            <x-ui.button variant="secondary" icon="arrow-path" wire:click="$refresh">{{ __('Retry') }}</x-ui.button>
                        </x-slot:actions>
                    </x-ui.empty-state>
                </x-ui.card>
            </div>
        </x-styleguide.section>

        {{-- ------------------------------------------------------------------ --}}
        <x-styleguide.section
            id="skeletons"
            :title="__('7. Loading skeletons')"
            :description="__('Pair with wire:loading (and lazy components) so a 3G connection sees structure, not a white flash. The shimmer stops under prefers-reduced-motion.')"
            usage="x-ui.skeleton · variant: text / table / card / stat / chart"
        >
            <div class="grid gap-4 lg:grid-cols-2">
                <x-ui.card :title="__('Table rows')"><x-ui.skeleton variant="table" :rows="4" /></x-ui.card>
                <x-ui.card :title="__('Chart widget (lazy)')"><x-ui.skeleton variant="chart" height="h-40" /></x-ui.card>
                <x-ui.card :title="__('Stat tiles')">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <x-ui.skeleton variant="stat" />
                        <x-ui.skeleton variant="stat" />
                    </div>
                </x-ui.card>
                <x-ui.card :title="__('Text block')"><x-ui.skeleton :lines="4" /></x-ui.card>
            </div>
        </x-styleguide.section>

        {{-- ------------------------------------------------------------------ --}}
        <x-styleguide.section
            id="alerts"
            :title="__('7b. Alerts & banners')"
            :description="__('Flash messages, validation summaries and system notices. Severity sets both the icon and the ARIA role — critical and warning announce assertively, the rest politely.')"
            usage="x-ui.alert · variant: info / positive / warning / critical / neutral"
        >
            <div class="space-y-3">
                <x-ui.alert variant="positive" :title="__('Report approved')">
                    {{ __('Q1 2026 progress for Township Road Rehabilitation (Section II) was approved and is queued for publication.') }}
                </x-ui.alert>

                <x-ui.alert variant="warning" :title="__('Reporting deadline in 3 days')">
                    {{ __('4 consultants have not submitted Q1 returns. Reminders were sent on 12 March.') }}
                </x-ui.alert>

                <x-ui.alert variant="critical" :title="__('There are 2 problems with your submission')">
                    <ul class="mt-1 space-y-1">
                        <li><a href="#alerts" class="font-medium underline underline-offset-2 hover:no-underline">{{ __('Physical progress cannot exceed 100%.') }}</a></li>
                        <li><a href="#alerts" class="font-medium underline underline-offset-2 hover:no-underline">{{ __('Attach at least one geotagged photograph.') }}</a></li>
                    </ul>
                </x-ui.alert>

                <x-ui.alert variant="info" dismissible>
                    {{ __('Draft autosave is on. This report saves every 30 seconds while you type.') }}
                </x-ui.alert>

                <x-ui.alert variant="neutral" icon="shield-check">
                    {{ __('This workspace is in read-only mode while the quarter is being certified.') }}
                </x-ui.alert>
            </div>

            <x-ui.card :title="__('Checkbox')" :subtitle="__('Native control, brand accent — reliable on low-end Android WebViews')">
                <div class="space-y-3">
                    <x-ui.form.checkbox
                        name="sg_remember"
                        :label="__('Keep me signed in')"
                        :description="__('Do not use on a shared or public device.')"
                    />
                    <x-ui.form.checkbox
                        name="sg_publish"
                        :label="__('Publish to the public portal on approval')"
                        :checked="true"
                    />
                    <x-ui.form.checkbox name="sg_locked" :label="__('Certified (locked by oversight)')" :checked="true" disabled />
                </div>
            </x-ui.card>
        </x-styleguide.section>

        {{-- ------------------------------------------------------------------ --}}
        <x-styleguide.section
            id="forms"
            :title="__('8. Forms')"
            :description="__('Label on every input, hint above the field, error inline with an icon and role=alert. The group and the control derive the same id, so aria-describedby is wired without passing ids around.')"
            usage="x-ui.form.group wraps x-ui.form.input / select / textarea (pass has-hint when the group has a hint)"
        >
            <div class="grid gap-4 lg:grid-cols-2">
                <x-ui.card :title="__('Quarterly progress report')" :subtitle="__('Q1 2026 · Township Road Rehabilitation (Section II)')">
                    <div class="space-y-4">
                        <x-ui.form.group name="report_title" :label="__('Report title')" required>
                            <x-ui.form.input name="report_title" value="Q1 2026 progress — Section II" wire:model.blur="form.title" />
                        </x-ui.form.group>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-ui.form.group name="physical_progress" :label="__('Physical progress')" :hint="__('Verified on site, not the contractor’s claim.')" required>
                                <x-ui.form.input name="physical_progress" type="number" suffix="%" value="72" has-hint wire:model.blur="form.physical" />
                            </x-ui.form.group>

                            <x-ui.form.group name="amount_paid" :label="__('Amount paid to date')" required>
                                <x-ui.form.input name="amount_paid" type="text" prefix="₦" value="1,197,625,000" wire:model.blur="form.paid" />
                            </x-ui.form.group>
                        </div>

                        <x-ui.form.group name="reporting_period" :label="__('Reporting period')" required>
                            <x-ui.form.select
                                name="reporting_period"
                                selected="q1-2026"
                                :options="[
                                    'q1-2026' => __('Q1 2026 (Jan–Mar)'),
                                    'q2-2026' => __('Q2 2026 (Apr–Jun)'),
                                    'q3-2026' => __('Q3 2026 (Jul–Sep)'),
                                ]"
                                wire:model="form.period"
                            />
                        </x-ui.form.group>

                        <x-ui.form.group name="challenges" :label="__('Challenges encountered')" :hint="__('Seen by the M&E officer and, once published, by the public.')" optional>
                            <x-ui.form.textarea
                                name="challenges"
                                rows="4"
                                maxlength="500"
                                has-hint
                                :placeholder="__('e.g. Rainfall suspended earthworks for 11 days in February…')"
                                wire:model.blur="form.challenges"
                            >Rainfall suspended earthworks for 11 days in February. Asphalt plant relocated to reduce haulage distance.</x-ui.form.textarea>
                        </x-ui.form.group>

                        <div class="flex flex-wrap gap-2">
                            <x-ui.button loading="submit">{{ __('Submit for review') }}</x-ui.button>
                            <x-ui.button variant="secondary" loading="saveDraft">{{ __('Save draft') }}</x-ui.button>
                        </div>
                    </div>
                </x-ui.card>

                <x-ui.card :title="__('States')" :subtitle="__('Error, disabled, read-only and search variants')">
                    <div class="space-y-4">
                        <x-ui.form.group
                            name="contract_sum"
                            :label="__('Contract sum')"
                            :hint="__('Excluding VAT and contingencies.')"
                            required
                            :error="__('The contract sum must not exceed the appropriated budget line (₦1,900,000,000).')"
                        >
                            <x-ui.form.input
                                name="contract_sum"
                                prefix="₦"
                                value="2,400,000,000"
                                has-hint
                                aria-invalid="true"
                                described-by="contract_sum-error"
                            />
                        </x-ui.form.group>

                        <x-ui.form.group name="project_ref" :label="__('Project reference')" :hint="__('Generated by the platform.')">
                            <x-ui.form.input name="project_ref" value="PRJ-2401" has-hint readonly />
                        </x-ui.form.group>

                        <x-ui.form.group name="locked_mda" :label="__('Implementing entity')">
                            <x-ui.form.select name="locked_mda" :options="['works' => __('Ministry of Works & Infrastructure')]" selected="works" disabled />
                        </x-ui.form.group>

                        <x-ui.form.group name="inspection_search" :label="__('Find an inspection')">
                            <x-ui.form.input name="inspection_search" type="search" icon="magnifying-glass" :placeholder="__('Inspector, ward or date…')" />
                        </x-ui.form.group>

                        <x-ui.form.group name="visit_date" :label="__('Date of site visit')" required>
                            <x-ui.form.input name="visit_date" type="date" value="2026-03-18" />
                        </x-ui.form.group>
                    </div>
                </x-ui.card>
            </div>
        </x-styleguide.section>

        {{-- ------------------------------------------------------------------ --}}
        <x-styleguide.section
            id="steps"
            :title="__('9. Wizard steps')"
            :description="__('Long M&E forms are multi-step with autosaved drafts. Mobile collapses to “Step 3 of 5” plus a progress bar; completed steps carry a check icon.')"
            usage="x-ui.steps · steps array + current (1-based)"
        >
            <x-ui.card>
                <x-ui.steps
                    :current="3"
                    :label="__('Project registration progress')"
                    :steps="[
                        ['label' => __('Identity'), 'description' => __('Name, reference, sector')],
                        ['label' => __('Scope & budget'), 'description' => __('Contract sum, funding')],
                        ['label' => __('Location'), 'description' => __('LGA, ward, coordinates')],
                        ['label' => __('Indicators'), 'description' => __('Outputs & outcomes')],
                        ['label' => __('Review'), 'description' => __('Submit for approval')],
                    ]"
                />

                <div class="mt-6 flex flex-wrap items-center gap-2 border-t border-line pt-4">
                    <x-ui.button variant="secondary" icon="chevron-left">{{ __('Back') }}</x-ui.button>
                    <x-ui.button trailing-icon="chevron-right">{{ __('Continue') }}</x-ui.button>
                    <span class="flex-1"></span>
                    <span class="inline-flex items-center gap-1.5 text-sm text-ink-muted">
                        <x-ui.icon name="check-circle" class="size-4 text-positive" />
                        {{ __('Draft saved 2 minutes ago') }}
                    </span>
                </div>
            </x-ui.card>
        </x-styleguide.section>

        {{-- ------------------------------------------------------------------ --}}
        <x-styleguide.section
            id="overlays"
            :title="__('10. Modals & menus')"
            :description="__('Plain-Alpine focus trap: Tab cycles inside the panel, Esc and the overlay close it, and focus returns to the trigger. Menus are arrow-key navigable.')"
            usage="$dispatch('open-modal', 'confirm-rejection')"
        >
            <x-ui.card>
                <div class="flex flex-wrap gap-3">
                    <x-ui.button variant="secondary" icon="eye" x-on:click="$dispatch('open-modal', 'inspection-detail')">
                        {{ __('Open detail modal') }}
                    </x-ui.button>

                    <x-ui.button variant="destructive" icon="x-circle" x-on:click="$dispatch('open-modal', 'confirm-rejection')">
                        {{ __('Open destructive confirm') }}
                    </x-ui.button>

                    <x-ui.dropdown align="left" :label="__('Bulk actions')">
                        <x-slot:trigger>
                            <x-ui.button variant="secondary" trailing-icon="chevron-down">{{ __('Bulk actions') }}</x-ui.button>
                        </x-slot:trigger>
                        <x-ui.dropdown.item icon="check-circle">{{ __('Approve selected reports') }}</x-ui.dropdown.item>
                        <x-ui.dropdown.item icon="arrow-down-tray">{{ __('Export selection to Excel') }}</x-ui.dropdown.item>
                        <x-ui.dropdown.item icon="globe">{{ __('Publish to public portal') }}</x-ui.dropdown.item>
                        <x-ui.dropdown.item icon="x-circle" destructive>{{ __('Reject selected reports') }}</x-ui.dropdown.item>
                    </x-ui.dropdown>
                </div>
            </x-ui.card>

            <x-ui.modal
                name="inspection-detail"
                :title="__('Site inspection — 18 March 2026')"
                :description="__('Township Road Rehabilitation (Section II) · Ward 4')"
                max-width="lg"
            >
                <div class="space-y-4">
                    <dl class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <dt class="text-xs tracking-wide text-ink-muted uppercase">{{ __('Inspector') }}</dt>
                            <dd class="text-sm text-ink">Fatima A. Bello — {{ __('M&E Officer') }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs tracking-wide text-ink-muted uppercase">{{ __('Verified progress') }}</dt>
                            <dd class="text-sm text-ink">72% ({{ __('claimed 78%') }})</dd>
                        </div>
                        <div>
                            <dt class="text-xs tracking-wide text-ink-muted uppercase">{{ __('Coordinates') }}</dt>
                            <dd class="font-mono text-sm text-ink">9.0765, 7.3986</dd>
                        </div>
                        <div>
                            <dt class="text-xs tracking-wide text-ink-muted uppercase">{{ __('Status') }}</dt>
                            <dd><x-ui.badge status="under_review" /></dd>
                        </div>
                    </dl>

                    <x-ui.empty-state
                        variant="empty"
                        compact
                        icon="photo"
                        :title="__('No geotagged photos attached')"
                        :description="__('Evidence photos keep their GPS and EXIF data when uploaded from the field.')"
                    >
                        <x-slot:actions>
                            <x-ui.button size="sm" variant="secondary" icon="arrow-up-tray">{{ __('Upload evidence') }}</x-ui.button>
                        </x-slot:actions>
                    </x-ui.empty-state>
                </div>

                <x-slot:footer>
                    <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'inspection-detail')">{{ __('Close') }}</x-ui.button>
                    <x-ui.button icon="check-circle">{{ __('Approve inspection') }}</x-ui.button>
                </x-slot:footer>
            </x-ui.modal>

            <x-ui.modal
                name="confirm-rejection"
                :title="__('Reject this progress report?')"
                :description="__('The consultant will be notified and the report returns to draft. This action is recorded in the audit log.')"
                max-width="md"
            >
                <x-ui.form.group name="rejection_reason" :label="__('Reason for rejection')" :hint="__('Shared with the consultant.')" required>
                    <x-ui.form.textarea name="rejection_reason" rows="3" maxlength="300" has-hint :placeholder="__('e.g. Physical progress not supported by the attached evidence…')" />
                </x-ui.form.group>

                <x-slot:footer>
                    <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'confirm-rejection')">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button variant="destructive" icon="x-circle" loading="reject">{{ __('Reject report') }}</x-ui.button>
                </x-slot:footer>
            </x-ui.modal>
        </x-styleguide.section>

        {{-- ------------------------------------------------------------------ --}}
        <x-styleguide.section
            id="shells"
            :title="__('11. App shells')"
            :description="__('Three shells, one navigation grammar. Sidebar for the two app surfaces (off-canvas drawer under lg), top-nav for the public portal.')"
            usage="#[Layout('layouts::tenant')] or @@extends('layouts.tenant')"
        >
            <div class="grid gap-4 lg:grid-cols-3">
                <x-ui.card :title="__('layouts.tenant')" :subtitle="__('MDA workspace — you are looking at it')">
                    <ul class="space-y-2 text-sm text-ink-muted">
                        <li class="flex gap-2"><x-ui.icon name="check" class="mt-0.5 size-4 text-positive" />{{ __('Brand lockup from the resolved tenant, degrades to the instance name') }}</li>
                        <li class="flex gap-2"><x-ui.icon name="check" class="mt-0.5 size-4 text-positive" />{{ __('Collapsible nav groups, off-canvas drawer under lg') }}</li>
                        <li class="flex gap-2"><x-ui.icon name="check" class="mt-0.5 size-4 text-positive" />{{ __('Skip link, dark-mode toggle, account menu') }}</li>
                    </ul>
                </x-ui.card>

                <x-ui.card :title="__('layouts.oversight')" :subtitle="__('State-level surface')">
                    <ul class="space-y-2 text-sm text-ink-muted">
                        <li class="flex gap-2"><x-ui.icon name="check" class="mt-0.5 size-4 text-positive" />{{ __('Denser rhythm: 56px topbar, 16rem sidebar, compact nav') }}</li>
                        <li class="flex gap-2"><x-ui.icon name="check" class="mt-0.5 size-4 text-positive" />{{ __('Tinted surface derived from the brand tokens') }}</li>
                        <li class="flex gap-2"><x-ui.icon name="check" class="mt-0.5 size-4 text-positive" />{{ __('Labelled “State-level oversight” chip — never colour alone') }}</li>
                    </ul>
                </x-ui.card>

                <x-ui.card :title="__('layouts.portal')" :subtitle="__('Public transparency portal')">
                    <ul class="space-y-2 text-sm text-ink-muted">
                        <li class="flex gap-2"><x-ui.icon name="check" class="mt-0.5 size-4 text-positive" />{{ __('Top navigation, full-width content, no app chrome') }}</li>
                        <li class="flex gap-2"><x-ui.icon name="check" class="mt-0.5 size-4 text-positive" />{{ __('Footer branding slots via $footerColumns and @@section') }}</li>
                        <li class="flex gap-2"><x-ui.icon name="check" class="mt-0.5 size-4 text-positive" />{{ __('“Read-only public data” marker in the footer') }}</li>
                    </ul>
                </x-ui.card>
            </div>

            <x-ui.card :title="__('Shell chrome primitives')">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="space-y-2 rounded-lg border border-line p-3">
                        <p class="font-mono text-xs text-ink-muted">&lt;x-ui.brand&gt;</p>
                        <x-ui.brand :subtitle="__('MDA workspace')" />
                    </div>
                    <div class="space-y-2 rounded-lg border border-line p-3">
                        <p class="font-mono text-xs text-ink-muted">&lt;x-ui.nav-item&gt;</p>
                        <x-ui.nav-item icon="folder" href="#shells" active>{{ __('Projects') }}</x-ui.nav-item>
                        <x-ui.nav-item icon="document-text" href="#shells" badge="6">{{ __('Progress reports') }}</x-ui.nav-item>
                        <x-ui.nav-item icon="chart-bar" href="#shells" disabled>{{ __('Indicators (no access)') }}</x-ui.nav-item>
                    </div>
                    <div class="space-y-2 rounded-lg border border-line p-3">
                        <p class="font-mono text-xs text-ink-muted">&lt;x-ui.user-menu&gt;</p>
                        <x-ui.user-menu :role="__('M&E Officer')" />
                    </div>
                    <div class="space-y-2 rounded-lg border border-line p-3">
                        <p class="font-mono text-xs text-ink-muted">&lt;x-ui.theme-toggle&gt;</p>
                        <x-ui.theme-toggle />
                    </div>
                </div>
            </x-ui.card>
        </x-styleguide.section>
    </div>
@endsection
