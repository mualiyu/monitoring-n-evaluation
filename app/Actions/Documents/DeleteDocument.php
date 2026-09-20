<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Remove a document from a record's vault.
 *
 * Authorization is the caller's (MediaPolicy::delete) — this Action is the
 * place the removal is RECORDED. Deleting evidence from a government record is
 * exactly the act an auditor asks about later, and the media row itself is
 * gone by the time they ask, so the log line has to carry enough to identify
 * what left: the owning record, the collection, and the file's display name.
 */
class DeleteDocument
{
    public function __invoke(Media $media, User $actor): void
    {
        $subject = $media->model;

        activity('documents')
            ->performedOn($subject)
            ->causedBy($actor)
            ->withProperties([
                'collection' => $media->collection_name,
                'name' => $media->name,
                'file_name' => $media->file_name,
                'size' => $media->size,
                'media_id' => $media->getKey(),
            ])
            ->log('document_deleted');

        try {
            $media->delete();
        } catch (\Throwable $exception) {
            // The audit line is already written; a storage failure must not
            // leave the caller thinking nothing happened.
            Log::error('Failed to delete media', ['media_id' => $media->getKey(), 'exception' => $exception]);

            throw $exception;
        }
    }
}
