<?php

declare(strict_types=1);

namespace App\Livewire\Shared;

use App\Actions\Documents\AttachDocument;
use App\Actions\Documents\DeleteDocument;
use App\Enums\Surface;
use App\Models\User;
use App\Support\DocumentCollections;
use App\Tenancy\CurrentSurface;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The document vault panel — one component for every collection on every
 * record: project documents, contract instruments, report evidence,
 * inspection photographs.
 *
 * Reused rather than re-implemented per screen on purpose. The rules about
 * what may be uploaded live in config/documents.php and are enforced in
 * AttachDocument; a second implementation is how one screen ends up accepting
 * what another refuses.
 *
 *   <livewire:shared.document-panel :model="$project" collection="project_documents" />
 *
 * The owning record arrives as a Livewire model property, so it re-hydrates
 * through its own global scope on every update: another MDA's record cannot
 * be smuggled in by editing the payload — the TenantScope refuses to load it.
 */
class DocumentPanel extends Component
{
    use WithFileUploads;

    public Model&HasMedia $model;

    public string $collection;

    public bool $readonly = false;

    /** Heading shown above the panel; null uses the collection's label. */
    public ?string $heading = null;

    public ?TemporaryUploadedFile $upload = null;

    public string $title = '';

    public ?string $confirmingDeletionOf = null;

    public function mount(
        Model&HasMedia $model,
        string $collection,
        bool $readonly = false,
        ?string $heading = null,
    ): void {
        abort_unless(app(DocumentCollections::class)->exists($collection), 500);

        $this->model = $model;
        $this->collection = $collection;
        $this->readonly = $readonly;
        $this->heading = $heading;

        $this->authorize('view', $model);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    #[Computed]
    public function definitions(): DocumentCollections
    {
        return app(DocumentCollections::class);
    }

    #[Computed]
    public function canUpload(): bool
    {
        return ! $this->readonly && $this->definitions()->canUpload($this->user(), $this->collection);
    }

    /** @return Collection<int, Media> */
    #[Computed]
    public function documents(): Collection
    {
        /** @var Collection<int, Media> $media */
        $media = $this->model->getMedia($this->collection);

        return $media;
    }

    /**
     * A signed, short-lived link on the surface the viewer is actually on.
     * Both surfaces register the same route name under their own prefix, and
     * generating the wrong one would sign a URL for a host the viewer cannot
     * reach.
     */
    public function downloadUrl(Media $media): string
    {
        $surface = app(CurrentSurface::class)->get();
        $oversight = $surface === Surface::Oversight;
        $prefix = $oversight ? Surface::Oversight->value : Surface::Tenant->value;

        // The tenant route carries a {tenant} DOMAIN parameter. ResolveTenant
        // fills it via URL::defaults() on a real request, but a Livewire
        // component test renders without ever crossing HTTP — and the missing
        // default is then a UrlGenerationException, not a wrong link. Passing
        // the bound tenant explicitly makes the panel render the same way in
        // both worlds.
        $parameters = ['media' => $media->uuid];

        if (! $oversight) {
            $tenant = app(CurrentTenant::class)->get();

            if ($tenant !== null) {
                $parameters['tenant'] = $tenant->slug;
            }
        }

        return url()->temporarySignedRoute(
            $prefix.'.documents.download',
            now()->addMinutes((int) config('documents.signed_url_minutes', 15)),
            $parameters,
        );
    }

    public function save(AttachDocument $attach): void
    {
        // Re-authorized on the update request, not just on mount: route
        // middleware does not gate a Livewire component update by itself.
        $this->authorize('view', $this->model);

        abort_unless($this->canUpload(), 403);

        $this->validate([
            'upload' => ['required', ...$this->definitions()->validationRules($this->collection)],
            'title' => ['nullable', 'string', 'max:120'],
        ], attributes: ['upload' => __('file')]);

        $upload = $this->upload;

        if (! $upload instanceof UploadedFile) {
            return;
        }

        try {
            $attach($this->model, $this->collection, $upload, $this->user(), $this->title ?: null);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages([
                'upload' => $exception->validator->errors()->all(),
            ]);
        }

        $this->reset(['upload', 'title']);
        unset($this->documents);

        $this->dispatch('document-attached', collection: $this->collection);
    }

    public function confirmDelete(string $uuid): void
    {
        $this->confirmingDeletionOf = $uuid;
    }

    public function cancelDelete(): void
    {
        $this->confirmingDeletionOf = null;
    }

    public function delete(string $uuid, DeleteDocument $deleteDocument): void
    {
        // Resolved from THIS record's own media, never from Media::find() —
        // a uuid from another record (or another MDA) simply is not here.
        $media = $this->documents()->firstWhere('uuid', $uuid);

        abort_unless($media instanceof Media, 404);

        $this->authorize('delete', $media);

        $deleteDocument($media, $this->user());

        $this->confirmingDeletionOf = null;
        unset($this->documents);

        $this->dispatch('document-deleted', collection: $this->collection);
    }

    public function render(): View
    {
        return view('livewire.shared.document-panel');
    }
}
