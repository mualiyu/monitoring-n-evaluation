<?php

declare(strict_types=1);

namespace App\Http\Controllers\Documents;

use Illuminate\Http\Request;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The ONLY way a stored file leaves this platform.
 *
 * Nothing sits on a public disk, so there is no URL that bypasses this. The
 * route is signed (a pasted link expires), authenticated, and policy-checked
 * against the record the file hangs off — three gates, because a leaked award
 * letter or a site photograph of a school is a public-records incident.
 *
 * `Content-Disposition: attachment` + a nosniff header: an uploaded document
 * must never render as a page on our own origin.
 */
class DownloadDocumentController
{
    public function __invoke(Request $request, Media $media): StreamedResponse
    {
        $this->authorizeRequest($request, $media);

        return response()->streamDownload(
            function () use ($media): void {
                $stream = $media->stream();

                while (! feof($stream)) {
                    echo fread($stream, 8192);
                    flush();
                }

                fclose($stream);
            },
            $this->downloadName($media),
            [
                'Content-Type' => $media->mime_type ?? 'application/octet-stream',
                'Content-Length' => (string) $media->size,
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    private function authorizeRequest(Request $request, Media $media): void
    {
        abort_unless($request->user()?->can('view', $media) === true, 403);
    }

    /**
     * The name the browser saves as: the display name plus the extension we
     * chose at upload. Never the raw stored filename, and never the
     * uploader's original string unsanitised.
     */
    private function downloadName(Media $media): string
    {
        $extension = pathinfo($media->file_name, PATHINFO_EXTENSION);
        $base = preg_replace('/[^\p{L}\p{N} ._-]/u', '', $media->name) ?: 'document';

        return trim($base).($extension !== '' ? '.'.$extension : '');
    }
}
