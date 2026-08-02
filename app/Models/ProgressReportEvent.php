<?php

namespace App\Models;

use App\Enums\ProgressReportStatus;
use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\ProgressReportEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * The approval-chain ledger of a progress report — tenant-owned and
 * APPEND-ONLY (progress-reporting.md §1.4). The same pattern as
 * ProjectStatusEvent, for the same reason: the report's own *_by_id/*_at
 * columns hold the current state, which cannot express a report returned
 * twice, or answer "how long does this MDA take between submission and
 * approval" without a self-join against a history it does not have.
 *
 * Written only by App\Actions\Reporting\TransitionProgressReportStatus. The
 * model refuses updates and deletes outright.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $progress_report_id
 * @property ProgressReportStatus|null $from_status
 * @property ProgressReportStatus $to_status
 * @property int $actor_id
 * @property string|null $reason
 * @property CarbonImmutable $occurred_at
 */
#[Fillable(['progress_report_id', 'from_status', 'to_status', 'actor_id', 'reason', 'occurred_at'])]
class ProgressReportEvent extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ProgressReportEventFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('progress_report_events is append-only — a chain step is corrected by recording another one.');
        });

        static::deleting(function (): never {
            throw new RuntimeException('progress_report_events is append-only — audit records are retained, never deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'from_status' => ProgressReportStatus::class,
            'to_status' => ProgressReportStatus::class,
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<ProgressReport, $this> */
    public function progressReport(): BelongsTo
    {
        return $this->belongsTo(ProgressReport::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** Creation events have no origin status. */
    public function isCreation(): bool
    {
        return $this->from_status === null;
    }
}
