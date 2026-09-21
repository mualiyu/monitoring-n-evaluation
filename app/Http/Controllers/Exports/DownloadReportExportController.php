<?php

declare(strict_types=1);

namespace App\Http\Controllers\Exports;

use App\Models\ReportExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The ONLY way a generated artifact leaves this platform — the same three
 * gates the document vault applies (DownloadDocumentController), for the same
 * reason.
 *
 * Nothing generated here sits on a public disk, so there is no URL that
 * bypasses this. The route is signed (a pasted link expires), authenticated,
 * and policy-checked: ReportExportPolicy refuses when the artifact is not
 * ready, has been pruned by retention, or was generated FOR a workspace other
 * than the one currently bound. That last check is the cross-tenant gate a
 * global register needs, and this controller is where it is spent.
 *
 * Bound by ULID, never by the auto-increment id: an integer in a URL is an
 * enumeration handle over how much data the state has been exporting.
 *
 * `Content-Disposition: attachment` + nosniff: an exported CSV must never
 * render as a page on our own origin.
 */
class DownloadReportExportController
{
    public function __invoke(Request $request, ReportExport $reportExport): StreamedResponse
    {
        abort_unless($request->user()?->can('download', $reportExport) === true, 403);

        $disk = Storage::disk((string) $reportExport->disk);
        $path = (string) $reportExport->path;

        return response()->streamDownload(
            function () use ($disk, $path): void {
                $stream = $disk->readStream($path);

                if ($stream === null) {
                    return;
                }

                while (! feof($stream)) {
                    echo fread($stream, 8192);
                    flush();
                }

                fclose($stream);
            },
            $this->downloadName($reportExport),
            [
                'Content-Type' => $reportExport->mime_type ?? 'application/octet-stream',
                'Content-Length' => (string) ($reportExport->size_bytes ?? 0),
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    /**
     * The name the browser saves as: the generated display name, stripped of
     * anything that is not a letter, a number or safe punctuation. Never the
     * stored path, and never a user's string unsanitised.
     */
    private function downloadName(ReportExport $export): string
    {
        $extension = $export->format->extension();
        $base = preg_replace('/[^\p{L}\p{N} ._-]/u', '', $export->file_name) ?: 'report';
        $base = trim(preg_replace('/\.'.preg_quote($extension, '/').'$/i', '', $base) ?: 'report');

        return ($base === '' ? 'report' : $base).'.'.$extension;
    }
}
