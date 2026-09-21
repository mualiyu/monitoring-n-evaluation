<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Actions\Portal\FindPublishedProject;
use App\Models\Project;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The one way an image reaches the public portal.
 *
 * Evidence lives on a private disk and normally leaves the platform only
 * through a signed, authenticated, policy-checked download route. The portal
 * has no user to authenticate, so the authorization here is the PUBLISHING
 * DECISION itself, re-checked on every request:
 *
 *  1. the project must resolve as PUBLISHED (FindPublishedProject applies the
 *     same predicate the browser and the map do) — unpublish a project and
 *     every photo URL that was ever shared stops working in the same instant;
 *  2. the media must belong to THAT project and sit in the `project_photos`
 *     collection. A uuid from `project_documents` — an award letter, a BOQ, a
 *     contractor's correspondence — is a 404 here, not a download;
 *  3. only the `thumb` CONVERSION is served, never the original. The original
 *     of a site photograph carries EXIF, and EXIF carries the GPS fix and
 *     often the device of the field monitor who took it. Medialibrary
 *     re-encodes conversions, so what the public gets is pixels and nothing
 *     else.
 *
 * Bound by uuid, never the auto-increment media id: an integer here would be
 * an enumeration handle over every MDA's evidence store.
 */
class PublishedPhotoController
{
    public function __invoke(string $ulid, string $uuid): StreamedResponse
    {
        $project = (new FindPublishedProject)->model($ulid);

        abort_unless($project instanceof Project, 404);

        /** @var Media|null $media */
        $media = $project->getMedia('project_photos')
            ->first(fn (Media $candidate): bool => $candidate->uuid === $uuid);

        abort_unless($media instanceof Media, 404);
        abort_unless($media->hasGeneratedConversion('thumb'), 404);

        $disk = Storage::disk($media->conversions_disk ?? $media->disk);
        $path = $media->getPathRelativeToRoot('thumb');

        abort_unless($disk->exists($path), 404);

        return $disk->response($path, null, [
            // A conversion is always a re-encoded raster; nosniff stops a
            // crafted upload from being interpreted as anything else.
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
            // Published photographs change only when a project is republished,
            // and this route is hit once per image per visitor.
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
