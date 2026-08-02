<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\ProgressReportStatus;
use App\Enums\ReportEntryMode;
use App\Models\Concerns\BelongsToTenant;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Factories\ProgressReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A project's return for one reporting window — tenant-owned.
 *
 * Guarded-by-omission, exactly as Project is: `status` is written ONLY by
 * App\Actions\Reporting\TransitionProgressReportStatus, and with it the whole
 * chain (`submitted_by_id`/`_at`, `reviewed_*`, `approved_*`, `returned_*`,
 * `submitted_late`). `due_at` is snapshotted at creation so a later calendar
 * edit cannot retroactively make a filed report late, and
 * `physical_progress_before` / `cumulative_expenditure_snapshot` are audit
 * stamps written at approval. None of them is fillable, so no
 * ->update($request->validated()) can reach them.
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property int $project_id
 * @property int $reporting_period_id
 * @property int|null $report_obligation_id
 * @property ProgressReportStatus $status
 * @property string $narrative_work_done
 * @property string|null $narrative_challenges
 * @property string|null $narrative_mitigation
 * @property string|null $narrative_next_period
 * @property string $physical_progress_claimed
 * @property string|null $physical_progress_before
 * @property string|null $progress_decrease_reason
 * @property Money $period_expenditure
 * @property Money|null $cumulative_expenditure_snapshot
 * @property ReportEntryMode $entry_mode
 * @property int|null $contractor_id
 * @property CarbonImmutable $due_at
 * @property bool $submitted_late
 * @property int $created_by_id
 * @property int|null $submitted_by_id
 * @property CarbonImmutable|null $submitted_at
 * @property int|null $reviewed_by_id
 * @property CarbonImmutable|null $reviewed_at
 * @property int|null $approved_by_id
 * @property CarbonImmutable|null $approved_at
 * @property int|null $returned_by_id
 * @property CarbonImmutable|null $returned_at
 * @property string|null $return_reason
 * @property CarbonImmutable|null $autosaved_at
 */
#[Fillable([
    'project_id', 'reporting_period_id', 'report_obligation_id',
    'narrative_work_done', 'narrative_challenges', 'narrative_mitigation',
    'narrative_next_period', 'physical_progress_claimed',
    'progress_decrease_reason', 'period_expenditure', 'entry_mode',
    'contractor_id', 'created_by_id',
])]
class ProgressReport extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ProgressReportFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (ProgressReport $report): void {
            $report->ulid ??= (string) Str::ulid();
        });
    }

    /**
     * Everything auditable. logFillable() covers the reported figures; the
     * explicit list adds back the chain columns that are deliberately not
     * fillable — "who signed this off, and when" is precisely what an auditor
     * asks about a progress report.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('progress_reports')
            ->logFillable()
            ->logOnly([
                'status', 'submitted_by_id', 'submitted_at', 'reviewed_by_id', 'reviewed_at',
                'approved_by_id', 'approved_at', 'returned_by_id', 'returned_at',
                'return_reason', 'submitted_late', 'physical_progress_before',
                'cumulative_expenditure_snapshot',
            ])
            // Money casts to a value object that JSON-encodes to {} — the raw
            // decimal string is what belongs in an audit record.
            ->useAttributeRawValues(['period_expenditure', 'cumulative_expenditure_snapshot'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected function casts(): array
    {
        return [
            'status' => ProgressReportStatus::class,
            'entry_mode' => ReportEntryMode::class,
            'physical_progress_claimed' => 'decimal:2',
            'physical_progress_before' => 'decimal:2',
            'period_expenditure' => MoneyCast::class,
            'cumulative_expenditure_snapshot' => MoneyCast::class,
            'due_at' => 'immutable_datetime',
            'submitted_late' => 'boolean',
            'submitted_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'returned_at' => 'immutable_datetime',
            'autosaved_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<ReportingPeriod, $this> */
    public function reportingPeriod(): BelongsTo
    {
        return $this->belongsTo(ReportingPeriod::class);
    }

    /** @return BelongsTo<ReportObligation, $this> */
    public function obligation(): BelongsTo
    {
        return $this->belongsTo(ReportObligation::class, 'report_obligation_id');
    }

    /** @return BelongsTo<Contractor, $this> */
    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /** @return BelongsTo<User, $this> */
    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    /** @return HasMany<ProgressReportEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(ProgressReportEvent::class);
    }

    /** Whether the author may still edit the figures, narrative and evidence. */
    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    /**
     * The one live report per (project, period). Soft-deleted drafts are
     * excluded by the SoftDeletes scope, which is why a discard genuinely
     * frees the slot.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForPeriod(Builder $query, int $projectId, int $reportingPeriodId): Builder
    {
        return $query->where('project_id', $projectId)
            ->where('reporting_period_id', $reportingPeriodId);
    }

    /**
     * Reports a user may see: consultants and field monitors see only the
     * projects they are actively assigned to.
     *
     * Delegated to Project::scopeVisibleTo — the single definition of "which
     * projects may this user see" — so a policy, a list screen and an export
     * can never disagree about a single row. Expressed as a subquery rather
     * than whereHas() because it reuses that scope verbatim; the TenantScope
     * on the inner query keeps it inside the bound MDA.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->whereIn(
            'project_id',
            Project::query()->visibleTo($user)->select('id'),
        );
    }
}
