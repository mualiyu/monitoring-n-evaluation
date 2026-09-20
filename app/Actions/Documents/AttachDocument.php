<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Models\User;
use App\Support\DocumentCollections;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The only way a file enters this platform.
 *
 * Three things happen here that a component must never be trusted to repeat:
 *  1. the mime type and size are re-validated SERVER-side against
 *     config/documents.php — the browser's `accept` attribute is a hint;
 *  2. the name on disk is generated, never the uploader's (rules/security.md);
 *     the original name survives as the display name only;
 *  3. EXIF capture metadata (GPS, taken-at) is lifted into custom properties
 *     so a site photograph can prove where and when it was taken, which is the
 *     whole evidentiary point of photo evidence in a monitoring record.
 *
 * @throws ValidationException when the file fails the collection's rules
 */
class AttachDocument
{
    public function __construct(private DocumentCollections $collections) {}

    public function __invoke(
        Model&HasMedia $model,
        string $collection,
        UploadedFile $file,
        User $actor,
        ?string $title = null,
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

        Validator::make(
            ['file' => $file],
            ['file' => $this->collections->validationRules($collection)],
        )->validate();

        $extension = $this->safeExtension($file);

        return $model->addMedia($file->getRealPath())
            // Generated name on disk: a user-controlled filename is a path
            // traversal and a content-sniffing problem wearing a label.
            ->usingFileName(Str::ulid()->toBase32().'.'.$extension)
            ->usingName($title !== null && $title !== '' ? $title : $this->displayName($file))
            ->withCustomProperties([
                'uploaded_by_id' => $actor->id,
                'uploaded_by_name' => $actor->name,
                'original_file_name' => $file->getClientOriginalName(),
                ...$this->captureMetadata($file),
            ])
            ->toMediaCollection($collection, config('documents.disk'));
    }

    /**
     * The extension we are willing to write, derived from the SNIFFED mime
     * type rather than the submitted filename.
     */
    private function safeExtension(UploadedFile $file): string
    {
        $guessed = $file->guessExtension();

        if (is_string($guessed) && preg_match('/^[a-z0-9]{1,8}$/', $guessed) === 1) {
            return $guessed;
        }

        return 'bin';
    }

    private function displayName(UploadedFile $file): string
    {
        $name = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);

        return Str::limit(trim($name) !== '' ? $name : __('Document'), 120, '');
    }

    /**
     * GPS + capture time from EXIF, when the file carries them.
     *
     * Decimal degrees, because that is what Leaflet and every mapping API
     * want; the rational-fraction form EXIF stores is unusable downstream.
     *
     * @return array<string, mixed>
     */
    private function captureMetadata(UploadedFile $file): array
    {
        if (! function_exists('exif_read_data') || ! str_starts_with((string) $file->getMimeType(), 'image/')) {
            return [];
        }

        // Suppressed deliberately: a phone photo with a truncated or absent
        // EXIF block is normal, and it must not fail the upload.
        $exif = @exif_read_data($file->getRealPath());

        if (! is_array($exif)) {
            return [];
        }

        $metadata = [];

        $latitude = $this->coordinate($exif, 'GPSLatitude', 'GPSLatitudeRef', ['S']);
        $longitude = $this->coordinate($exif, 'GPSLongitude', 'GPSLongitudeRef', ['W']);

        if ($latitude !== null && $longitude !== null) {
            $metadata['latitude'] = $latitude;
            $metadata['longitude'] = $longitude;
        }

        if (isset($exif['DateTimeOriginal']) && is_string($exif['DateTimeOriginal'])) {
            $metadata['captured_at'] = $exif['DateTimeOriginal'];
        }

        return $metadata;
    }

    /**
     * @param  array<string, mixed>  $exif
     * @param  list<string>  $negativeRefs
     */
    private function coordinate(array $exif, string $key, string $refKey, array $negativeRefs): ?float
    {
        $parts = $exif[$key] ?? null;

        if (! is_array($parts) || count($parts) < 3) {
            return null;
        }

        $degrees = $this->fraction($parts[0]) + $this->fraction($parts[1]) / 60 + $this->fraction($parts[2]) / 3600;

        $ref = $exif[$refKey] ?? null;

        if (is_string($ref) && in_array(strtoupper($ref), $negativeRefs, true)) {
            $degrees *= -1;
        }

        return round($degrees, 7);
    }

    private function fraction(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        if (is_string($value) && str_contains($value, '/')) {
            [$numerator, $denominator] = array_pad(explode('/', $value, 2), 2, '1');

            return (float) $denominator === 0.0 ? 0.0 : (float) $numerator / (float) $denominator;
        }

        return 0.0;
    }
}
