<?php

namespace App\Jobs\Consolidation;

use App\Actions\Consolidation\CompileConsolidatedFigures;
use App\Models\ConsolidatedReport;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Runs the cross-MDA roll-up off the web request.
 *
 * ⚠ DELIBERATELY NOT TenantAware. Every other queued job in this platform
 * carries a workspace into the worker; this one must carry none. A
 * consolidation reads every MDA at once through
 * app/Actions/Oversight/AggregateForConsolidation, which takes an explicit
 * bypass — binding a workspace here would be meaningless at best and, at
 * worst, would leave a tenant bound while the aggregate ran.
 *
 * ShouldBeUnique on the report: an officer pressing Recompile three times
 * queues one compile, not three racing each other over the same entry rows.
 * The compile is idempotent anyway (updateOrCreate on the unique key), so the
 * uniqueness is about not wasting a worker, not about correctness — and the
 * correctness does not depend on it.
 */
class CompileConsolidation implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(
        private readonly int $consolidatedReportId,
        private readonly int $actorId,
    ) {}

    public function uniqueId(): string
    {
        return 'consolidation-compile:'.$this->consolidatedReportId;
    }

    public function handle(): void
    {
        $report = ConsolidatedReport::query()->find($this->consolidatedReportId);
        $actor = User::query()->find($this->actorId);

        if ($report === null || $actor === null) {
            return; // discarded, or the requester's account was removed
        }

        app(CompileConsolidatedFigures::class)($report, $actor);
    }
}
