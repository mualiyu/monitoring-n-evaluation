<?php

namespace App\Models;

use App\Enums\ReportObligationStatus;
use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\ReportObligationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * "This project owes a return for this window" — tenant-owned, and the row the
 * compliance league table counts (progress-reporting.md §1.2).
 *
 * Guarded-by-omission: only `reporting_period_id`, `project_id` and `due_at`
 * are fillable. `status`, the fulfilment stamps and every reminder /
 * escalation counter are written explicitly by their chokepoint Actions
 * (SubmitProgressReport, WaiveReportObligation, the deadline-engine sweeps),
 * because those counters ARE the idempotency mechanism — a payload that could
 * reset `reminder_stage` could make the engine send twice.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $reporting_period_id
 * @property int|null $project_id
 * @property CarbonImmutable $due_at
 * @property ReportObligationStatus $status
 * @property int|null $progress_report_id
 * @property CarbonImmutable|null $fulfilled_at
 * @property bool $submitted_late
 * @property int $reminder_stage
 * @property CarbonImmutable|null $reminder_last_sent_at
 * @property CarbonImmutable|null $overdue_notified_at
 * @property int $escalation_stage
 * @property CarbonImmutable|null $escalated_at
 * @property int|null $waived_by_id
 * @property CarbonImmutable|null $waived_at
 * @property string|null $waiver_reason
 */
#[Fillable(['reporting_period_id', 'project_id', 'due_at'])]
class ReportObligation extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ReportObligationFactory> */
    use HasFactory, LogsActivity;

    /**
     * Everything auditable (rules/architecture.md). The interesting audit
     * question here is "who waived this MDA's obligation, and why" — hence the
     * waiver columns are logged explicitly alongside the fillable set.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('report_obligations')
            ->logFillable()
            ->logOnly(['status', 'fulfilled_at', 'submitted_late', 'waived_by_id', 'waived_at', 'waiver_reason'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected function casts(): array
    {
        return [
            'status' => ReportObligationStatus::class,
            'due_at' => 'immutable_datetime',
            'fulfilled_at' => 'immutable_datetime',
            'submitted_late' => 'boolean',
            'reminder_stage' => 'integer',
            'reminder_last_sent_at' => 'immutable_datetime',
            'overdue_notified_at' => 'immutable_datetime',
            'escalation_stage' => 'integer',
            'escalated_at' => 'immutable_datetime',
            'waived_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<ReportingPeriod, $this> */
    public function reportingPeriod(): BelongsTo
    {
        return $this->belongsTo(ReportingPeriod::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<ProgressReport, $this> */
    public function progressReport(): BelongsTo
    {
        return $this->belongsTo(ProgressReport::class);
    }

    /** @return BelongsTo<User, $this> */
    public function waivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waived_by_id');
    }

    public function isOutstanding(): bool
    {
        return $this->status->isOutstanding();
    }

    public function isOverdue(?CarbonImmutable $asOf = null): bool
    {
        return $this->isOutstanding() && $this->due_at->isBefore($asOf ?? CarbonImmutable::now());
    }

    /**
     * Whole calendar days until the deadline — negative once it has passed.
     *
     * ONE definition, shared by the reminder ladder and by the countdown on
     * the reporting desk, because a screen that says "due in 3 days" while the
     * engine is sending the 2-day reminder destroys trust in both. Days, not
     * hours: "due in 3 days" has to mean the same thing to a deadline at 23:59
     * as to one at 09:00, or the ladder fires a day early for half the
     * calendar.
     */
    public function daysToDue(?CarbonImmutable $asOf = null): int
    {
        $asOf ??= CarbonImmutable::now();

        return (int) $asOf->startOfDay()->diffInDays($this->due_at->startOfDay(), false);
    }

    /**
     * Rows the deadline engine still chases.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->where('status', ReportObligationStatus::Pending);
    }

    /**
     * Obligations a user may see: consultants and field monitors see only the
     * projects they are actively assigned to.
     *
     * Delegated to Project::scopeVisibleTo — the single definition of "which
     * projects may this user see" — so the reporting inbox, the project
     * register and every policy answer the question the same way. MDA-level
     * obligations (no project) are workspace-wide by nature and stay visible
     * to anyone who may read reports at all.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $scoped) => $scoped
            ->whereNull('project_id')
            ->orWhereIn('project_id', Project::query()->visibleTo($user)->select('id')));
    }
}
