<?php

namespace App\Jobs\Consolidation;

use App\Jobs\Concerns\TenantAware;
use App\Models\ReportExport;
use App\Models\User;
use App\Support\Exporting\ReportExporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Builds a large export off the web request (plan §6 — "queue heavy exports").
 *
 * IDS, NOT MODELS, on purpose: SerializesModels restores relations in
 * __unserialize — i.e. BEFORE the job middleware binds tenancy — so a
 * serialized tenant-owned model would be re-queried with no tenant bound and
 * the fail-closed scope would throw before handle() ever ran.
 *
 * TenantAware even though every dataset the builder offers is cross-MDA: an
 * export generated FROM a workspace surface (which other modules will register
 * here) must run inside that workspace, and a trait applied only once the
 * first such caller appears is a trait that gets forgotten.
 *
 * ShouldBeUnique on the register row: a double-click, a retried dispatch and
 * an overlapping worker must not write the same file twice — the second
 * attempt would race the first over the same path on the disk.
 */
class GenerateReportExport implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TenantAware;

    /**
     * One attempt at generation, then the failure is recorded. A report that
     * failed to build will fail the same way three times, and three identical
     * rows in failed_jobs teach nobody anything.
     */
    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        private readonly int $reportExportId,
        private readonly int $actorId,
    ) {
        $this->captureTenant();
    }

    public function uniqueId(): string
    {
        return 'report-export:'.$this->reportExportId;
    }

    public function handle(): void
    {
        $export = ReportExport::query()->find($this->reportExportId);
        $actor = User::query()->find($this->actorId);

        // Deleted between dispatch and execution, or the requester's account
        // was removed: there is nobody to authorize the read and nowhere to
        // put the result.
        if ($export === null || $actor === null) {
            return;
        }

        if (! $export->isPending()) {
            return; // already generated — a replayed job is a no-op
        }

        app(ReportExporter::class)->generate($export, $actor);
    }

    /**
     * The exporter already records the failure on the register row; this
     * catches the cases it cannot reach — a timeout, a killed worker — so the
     * screen never shows an artifact that is pending for ever.
     */
    public function failed(?Throwable $exception): void
    {
        $export = ReportExport::query()->find($this->reportExportId);

        if ($export === null || ! $export->isPending()) {
            return;
        }

        $export->forceFill([
            'status' => ReportExport::STATUS_FAILED,
            'error_message' => $exception?->getMessage() ?? 'The export worker stopped before the file was written.',
            'completed_at' => now(),
        ])->save();
    }
}
