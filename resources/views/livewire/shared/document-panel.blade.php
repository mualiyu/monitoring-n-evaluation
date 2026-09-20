{{--
    Document vault panel. Reused on every record that holds files, so the
    upload rules, the evidence metadata and the signed-download behaviour are
    identical everywhere instead of re-invented per screen.
--}}
@php
    $definitions = $this->definitions();
    $documents = $this->documents();
    $heading = $heading ?? $definitions->label($collection);
@endphp

<x-ui.card :title="$heading" :subtitle="__(':count file(s)', ['count' => $documents->count()])">
    @if ($this->canUpload())
        <x-slot:actions>
            <span class="text-xs text-ink-subtle">
                {{ __('Max :size MB', ['size' => round($definitions->maxKilobytes($collection) / 1024)]) }}
            </span>
        </x-slot:actions>
    @endif

    @if ($documents->isEmpty())
        <x-ui.empty-state
            variant="empty"
            icon="document-text"
            :title="__('No files yet')"
            :description="__('Attach the documents that evidence this record. Files are stored privately and every download is logged.')"
            compact
        />
    @else
        <ul class="divide-y divide-line" role="list">
            @foreach ($documents as $document)
                <li class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-lg bg-neutral-soft text-neutral-ink">
                            <x-ui.icon :name="str_starts_with((string) $document->mime_type, 'image/') ? 'photo' : 'document-text'" class="size-5" />
                        </span>

                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-ink">{{ $document->name }}</p>
                            <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-ink-muted">
                                <span>{{ number_format($document->size / 1024, 0) }} {{ __('KB') }}</span>
                                <span aria-hidden="true">·</span>
                                <span>{{ $document->created_at?->timezone(config('platform.instance.timezone'))->format('d M Y') }}</span>
                                @if ($document->getCustomProperty('uploaded_by_name'))
                                    <span aria-hidden="true">·</span>
                                    <span>{{ $document->getCustomProperty('uploaded_by_name') }}</span>
                                @endif
                                @if ($document->getCustomProperty('latitude'))
                                    <span aria-hidden="true">·</span>
                                    <span class="inline-flex items-center gap-1 text-positive-ink">
                                        <x-ui.icon name="map-pin" class="size-3.5" />
                                        {{ __('Geotagged') }}
                                    </span>
                                @endif
                            </p>
                        </div>
                    </div>

                    <div class="flex shrink-0 items-center gap-2 sm:pl-12">
                        <x-ui.button
                            size="sm"
                            variant="secondary"
                            icon="arrow-down-tray"
                            :href="$this->downloadUrl($document)"
                        >{{ __('Download') }}</x-ui.button>

                        @can('delete', $document)
                            @if (! $readonly)
                                @if ($confirmingDeletionOf === $document->uuid)
                                    <x-ui.button
                                        size="sm"
                                        variant="destructive"
                                        wire:click="delete('{{ $document->uuid }}')"
                                        loading="delete"
                                    >{{ __('Confirm') }}</x-ui.button>
                                    <x-ui.button size="sm" variant="ghost" wire:click="cancelDelete">
                                        {{ __('Cancel') }}
                                    </x-ui.button>
                                @else
                                    <x-ui.button
                                        size="sm"
                                        variant="ghost"
                                        icon="trash"
                                        icon-only
                                        :aria-label="__('Remove :name', ['name' => $document->name])"
                                        wire:click="confirmDelete('{{ $document->uuid }}')"
                                    />
                                @endif
                            @endif
                        @endcan
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

    @if ($this->canUpload())
        <form wire:submit="save" class="mt-4 space-y-3 border-t border-line pt-4">
            <div class="grid gap-3 sm:grid-cols-2">
                <x-ui.form.group name="upload" :label="__('Add a file')" required>
                    <input
                        type="file"
                        id="upload"
                        wire:model="upload"
                        accept="{{ $definitions->acceptAttribute($collection) }}"
                        class="block w-full cursor-pointer rounded-lg border border-line bg-surface text-sm text-ink file:mr-3 file:cursor-pointer file:border-0 file:bg-neutral-soft file:px-3 file:py-2.5 file:text-sm file:font-medium file:text-neutral-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                    />
                    <x-ui.form.error name="upload" />
                </x-ui.form.group>

                <x-ui.form.group name="title" :label="__('Title')" :hint="__('Defaults to the file name')">
                    <x-ui.form.input name="title" has-hint wire:model.blur="title" maxlength="120" />
                    <x-ui.form.error name="title" />
                </x-ui.form.group>
            </div>

            <div class="flex items-center gap-3">
                <x-ui.button type="submit" size="sm" icon="arrow-up-tray" loading="save">
                    {{ __('Upload') }}
                </x-ui.button>

                <span wire:loading wire:target="upload" class="text-xs text-ink-muted">
                    {{ __('Reading file…') }}
                </span>
            </div>
        </form>
    @endif
</x-ui.card>
