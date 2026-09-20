<?php

namespace App\Models\Concerns;

use App\Support\DocumentCollections;
use Illuminate\Database\Eloquent\Model;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The document vault, attached to a domain model.
 *
 * Medialibrary plumbing plus the two platform rules it must never lose:
 *  - everything lands on the PRIVATE `documents` disk, so there is no URL
 *    that serves a government record without a permission check; and
 *  - each collection's mime/size/role rules come from config/documents.php,
 *    not from the screen that happens to be uploading.
 *
 * A model using this trait declares `documentCollections()`; the collections
 * are registered from config so a state can retune sizes without a release.
 *
 * @mixin Model
 * @mixin InteractsWithMedia
 */
trait HasDocuments
{
    use InteractsWithMedia;

    /**
     * The collection keys (from config/documents.php) this model accepts.
     *
     * @return list<string>
     */
    abstract public function documentCollections(): array;

    public function registerMediaCollections(): void
    {
        $definitions = app(DocumentCollections::class);

        foreach ($this->documentCollections() as $collection) {
            $registration = $this->addMediaCollection($collection)
                ->useDisk(config('documents.disk'))
                ->acceptsMimeTypes($definitions->mimeTypes($collection));

            if ($definitions->isSingle($collection)) {
                // A completion certificate or a commencement notice is ONE
                // artifact; a second upload replaces it rather than leaving
                // two documents both claiming to be the notice.
                $registration->singleFile();
            }
        }
    }

    /**
     * Thumbnails for the list and gallery views. Conversions land on the same
     * private disk — a derived image of a site photo is still evidence.
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->fit(Fit::Crop, 320, 240)
            ->nonQueued();
    }
}
