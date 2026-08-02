<?php

namespace Database\Factories;

use App\Enums\ProgressReportStatus;
use App\Models\ProgressReport;
use App\Models\ProgressReportEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Chain-ledger rows are tenant-owned: this factory NEVER sets tenant_id. Run
 * it inside a bound tenant context and BelongsToTenant fills it.
 *
 * @extends Factory<ProgressReportEvent>
 */
class ProgressReportEventFactory extends Factory
{
    protected $model = ProgressReportEvent::class;

    public function definition(): array
    {
        return [
            'progress_report_id' => ProgressReport::factory(),
            'from_status' => ProgressReportStatus::Draft,
            'to_status' => ProgressReportStatus::Submitted,
            'actor_id' => User::factory(),
            'reason' => null,
            'occurred_at' => CarbonImmutable::now(),
        ];
    }

    public function forReport(ProgressReport $report): static
    {
        return $this->state(['progress_report_id' => $report->id]);
    }

    public function step(?ProgressReportStatus $from, ProgressReportStatus $to, ?string $reason = null): static
    {
        return $this->state([
            'from_status' => $from,
            'to_status' => $to,
            'reason' => $reason,
        ]);
    }
}
