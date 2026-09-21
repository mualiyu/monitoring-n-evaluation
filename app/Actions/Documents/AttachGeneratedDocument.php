<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Models\User;
use App\Support\DocumentCollections;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The vault entrance for a document this platform GENERATED — a rendered
 * commencement notice, a completion certificate.
 *
 * AttachDocument is the entrance for a file a human chose, and it takes an
 * UploadedFile because everything it must distrust (the uploader's filename,
 * the declared mime, the EXIF block) lives on that object. A PDF dompdf just
 * produced has no uploader and no temp file, and inventing an UploadedFile to
 * satisfy the signature would mean writing the bytes to disk twice and
 * pretending a machine artifact arrived over HTTP.
 *
 * It is a SIBLING, not a bypass: the three vault rules hold identically —
 *  1. the collection's own role list still decides who may put a file in it
 *     (config/documents.php, not the screen doing the generating);
 *  2. the mime and size ceilings are still enforced against that config;
 *  3. the name on disk is still generated, the private `documents` disk is
 *     still the only destination, and the write still leaves an audit line.
 *
 * @throws ValidationException when the generated file fails the collection's rules
 */
class AttachGeneratedDocument
{
    public function __construct(private DocumentCollections $collections) {}

    /**
     * @param  string  $contents  the rendered bytes (not a path)
     * @param  string  $title  the display name, e.g. "Commencement notice — WKS/2026/001"
     */
    public function __invoke(
        Model&HasMedia $model,
        string $collection,
        string $contents,
        string $title,
        User $actor,
        string $extension = 'pdf',
        string $mimeType = 'application/pdf',
    ): Media {
        if (! $this->collections->exists($collection)) {
            throw new InvalidArgumentException("Unknown document collection [{$collection}].");
        }

        if (! $this->collections->canUpload($actor, $collection)) {
            throw ValidationException::withMessages([
                'file' => __('You may not add files to :collection.', [
                    'collection' => $this->collections->label($collection),
                ]),
            ]);
        }

        // The same allow-list AttachDocument validates an upload against. A
        // generated artifact is trusted in origin, never in type: a module
        // that started writing HTML into a PDF-only collection should fail
        // here rather than on someone's screen a year later.
        if (! in_array($mimeType, $this->collections->mimeTypes($collection), true)) {
            throw ValidationException::withMessages([
                'file' => __(':collection does not accept :type documents.', [
                    'collection' => $this->collections->label($collection),
                    'type' => $mimeType,
                ]),
            ]);
        }

        $bytes = strlen($contents);

        if ($bytes === 0) {
            throw ValidationException::withMessages([
                'file' => __('The generated document was empty and has not been filed.'),
            ]);
        }

        if ($bytes > $this->collections->maxKilobytes($collection) * 1024) {
            throw ValidationException::withMessages([
                'file' => __('The generated document is larger than :collection accepts.', [
                    'collection' => $this->collections->label($collection),
                ]),
            ]);
        }

        $media = $model->addMediaFromString($contents)
            // Generated name on disk, exactly as for an upload — nothing
            // user-controlled ever reaches the filesystem.
            ->usingFileName(Str::ulid()->toBase32().'.'.$this->safeExtension($extension))
            ->usingName(Str::limit($title, 120, ''))
            ->withCustomProperties([
                'generated' => true,
                'generated_by_id' => $actor->id,
                'generated_by_name' => $actor->name,
                'generated_at' => now()->toIso8601String(),
            ])
            ->toMediaCollection($collection, config('documents.disk'));

        // The audit line: a document that entered the vault without a human
        // choosing it still has to answer "who caused this, and when".
        activity('documents')
            ->performedOn($model)
            ->causedBy($actor)
            ->withProperties([
                'collection' => $collection,
                'name' => $media->name,
                'file_name' => $media->file_name,
                'size' => $media->size,
                'media_id' => $media->getKey(),
            ])
            ->log('document_generated');

        return $media;
    }

    /** Never trust a caller's extension string on a path. */
    private function safeExtension(string $extension): string
    {
        $extension = Str::lower(trim($extension, '. '));

        return preg_match('/^[a-z0-9]{1,8}$/', $extension) === 1 ? $extension : 'bin';
    }
}
