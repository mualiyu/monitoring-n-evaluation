{{--
    The publication preview: App\Support\Publishing\PublicProjectPayload, field
    by field, in the order the class declares it.

    Driven by PublicProjectPayload::FIELDS rather than a hand-written list, so a
    field added to the whitelist shows up here the moment it is added. The
    alternative — a list maintained by hand — is how a preview quietly stops
    matching what publishing actually exposes.

    $payload: array<string, mixed> straight from the component.
--}}
@php
    use App\Support\Publishing\PublicProjectPayload;

    $labels = [
        'ulid' => __('Public reference'),
        'reference' => __('Project reference'),
        'title' => __('Title'),
        'description' => __('Description'),
        'goal' => __('Goal'),
        'objectives' => __('Objectives'),
        'entity' => __('Delivering entity'),
        'supervising_agency' => __('Supervising agency'),
        'sector' => __('Sector'),
        'type' => __('Type'),
        'status' => __('Status (machine value)'),
        'status_label' => __('Status'),
        'physical_progress' => __('Physical progress (%)'),
        'contract_value' => __('Contract value (exact)'),
        'contract_value_formatted' => __('Contract value'),
        'expenditure' => __('Paid to date (exact)'),
        'expenditure_formatted' => __('Paid to date'),
        'financial_progress' => __('Financial progress (%)'),
        'contractor' => __('Contractor'),
        'start_date' => __('Start date'),
        'expected_end_date' => __('Expected end date'),
        'revised_end_date' => __('Revised end date'),
        'actual_end_date' => __('Actual end date'),
        'locations' => __('Sites'),
        'primary_location' => __('Primary site'),
        'photos' => __('Photographs'),
        'published_at' => __('Published on'),
    ];
@endphp

<div class="space-y-4">
    <x-ui.alert variant="info" :title="__('This is the whole of it')">
        {{ __('Budget appropriation lines, internal lifecycle dates and the names of the officers involved are not on this list and are never published.') }}
    </x-ui.alert>

    <dl class="divide-y divide-line">
        @foreach (PublicProjectPayload::FIELDS as $field)
            @php $value = $payload[$field] ?? null; @endphp

            <div class="grid gap-1 py-2 sm:grid-cols-3 sm:gap-4">
                <dt class="text-xs font-medium tracking-wide text-ink-muted uppercase">
                    {{ $labels[$field] ?? \Illuminate\Support\Str::headline($field) }}
                </dt>

                <dd class="text-sm break-words text-ink sm:col-span-2">
                    @if ($field === 'locations')
                        @if (blank($value))
                            <span class="text-ink-subtle">{{ __('None') }}</span>
                        @else
                            <ul class="space-y-1">
                                @foreach ($value as $location)
                                    <li>
                                        {{ collect([$location['site_name'], $location['lga'], $location['ward']])->filter()->implode(' · ') }}
                                        @if ($location['latitude'] !== null && $location['longitude'] !== null)
                                            <span class="font-mono text-xs text-ink-muted">({{ $location['latitude'] }}, {{ $location['longitude'] }})</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    @elseif ($field === 'primary_location')
                        @if (blank($value))
                            <span class="text-ink-subtle">{{ __('None') }}</span>
                        @else
                            {{ collect([$value['site_name'], $value['lga'], $value['ward']])->filter()->implode(' · ') }}
                        @endif
                    @elseif ($field === 'photos')
                        @if (blank($value))
                            <span class="text-ink-subtle">{{ __('None') }}</span>
                        @else
                            <ul class="space-y-1">
                                @foreach ($value as $photo)
                                    <li>{{ $photo['caption'] !== '' ? $photo['caption'] : __('Untitled photograph') }}</li>
                                @endforeach
                            </ul>
                        @endif
                    @elseif ($value === null || $value === '')
                        <span class="text-ink-subtle">{{ __('Not recorded') }}</span>
                    @else
                        <span class="whitespace-pre-line">{{ $value }}</span>
                    @endif
                </dd>
            </div>
        @endforeach
    </dl>
</div>
