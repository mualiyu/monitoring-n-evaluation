{{--
    <x-ui.table /> — responsive data table.

    Layout contract: a real <table> from `sm:` up; below that every row becomes a
    card (display:block) and each cell prints its own label. Cells therefore MUST
    pass :label so the mobile card is readable.

    Props
      headings  array of column labels (string) or [ 'label' => …, 'align' => 'right',
                'sortable' => true, 'sort' => 'asc|desc|null', 'click' => 'sortBy(\'x\')' ]
      caption   accessible description of the table (visually hidden)

    Slots
      $head   optional raw <tr> if you need full control (overrides :headings)
      $slot   <x-ui.table.row> elements
      $footer totals row / pagination

        <x-ui.table :headings="['Project', 'MDA', 'Contract sum', 'Status', '']" caption="Ongoing projects">
            <x-ui.table.row>
                <x-ui.table.cell label="Project">Ilorin–Jebba Road (Section II)</x-ui.table.cell>
                …
            </x-ui.table.row>
        </x-ui.table>
--}}
@props([
    'headings' => [],
    'caption' => null,
])

<div {{ $attributes->class('w-full sm:overflow-x-auto') }}>
    <table class="w-full border-collapse text-left text-sm">
        @if ($caption)
            <caption class="sr-only">{{ $caption }}</caption>
        @endif

        @if (isset($head) || filled($headings))
        <thead class="hidden sm:table-header-group">
            @isset($head)
                {{ $head }}
            @else
                <tr class="border-b border-line bg-surface-sunken">
                    @foreach ($headings as $heading)
                        @if (is_array($heading))
                            <x-ui.table.heading
                                :align="$heading['align'] ?? 'left'"
                                :sortable="$heading['sortable'] ?? false"
                                :sort="$heading['sort'] ?? null"
                                :click="$heading['click'] ?? null"
                            >{{ $heading['label'] ?? '' }}</x-ui.table.heading>
                        @else
                            <x-ui.table.heading>{{ $heading }}</x-ui.table.heading>
                        @endif
                    @endforeach
                </tr>
            @endisset
        </thead>
        @endif

        <tbody class="block sm:table-row-group">
            {{ $slot }}
        </tbody>

        @isset($footer)
            <tfoot class="block border-t border-line bg-surface-sunken sm:table-footer-group">
                {{ $footer }}
            </tfoot>
        @endisset
    </table>
</div>
