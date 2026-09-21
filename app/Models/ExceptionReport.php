<?php

namespace App\Models;

use App\Enums\ExceptionStatus;
use App\Enums\ExceptionTrigger;
use App\Enums\IssueSeverity;
use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\ExceptionReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * The manual's Exception Report (Table 5.2 — filed "on critical incidence /
 * high deviation"), tenant-owned.
 *
 * A NOTICE, not a piece of work: it says a project has deviated. The work it
 * provokes is an Issue, linked through `issue_id`. That split is why the
 * status chain here is three states long and why there is no owner column —
 * an exception report with an assignee would be an issue wearing a different
 * name, and the register would immediately have two places to look.
 *
 * Guarded-by-omission: `status`, `issue_id` and the whole chain
 * (`acknowledged_*`, `resolved_*`, `resolution_note`) are written ONLY by
 * App\Actions\Issues\TransitionExceptionStatus and LinkIssueToExceptionReport.
 * The measurement columns are written once, at the raise, by
 * RaiseExceptionReport — a measurement that could be edited afterwards is not
 * a measurement.
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property int $project_id
 * @property int|null $issue_id
 * @property ExceptionTrigger $trigger
 * @property IssueSeverity $severity
 * @property ExceptionStatus $status
 * @property string $narrative
 * @property string|null $measured_value
 * @property string|null $threshold_value
 * @property string|null $physical_progress
 * @property string|null $schedule_elapsed
 * @property string|null $financial_progress
 * @property CarbonImmutable $measured_at
 * @property int|null $raised_by_id
 * @property int|null $acknowledged_by_id
 * @property CarbonImmutable|null $acknowledged_at
 * @property int|null $resolved_by_id
 * @property CarbonImmutable|null $resolved_at
 * @property string|null $resolution_note
 */
#[Fillable([
    'project_id', 'trigger', 'severity', 'narrative', 'measured_value',
    'threshold_value', 'physical_progress', 'schedule_elapsed',
    'financial_progress', 'measured_at', 'raised_by_id',
])]
class ExceptionReport extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ExceptionReportFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (ExceptionReport $report): void {
            $report->ulid ??= (string) Str::ulid();
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('exception_reports')
            ->logFillable()
            ->logOnly([
                'status', 'issue_id', 'acknowledged_by_id', 'acknowledged_at',
                'resolved_by_id', 'resolved_at', 'resolution_note',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected function casts(): array
    {
        return [
            'trigger' => ExceptionTrigger::class,
            'severity' => IssueSeverity::class,
            'status' => ExceptionStatus::class,
            'measured_value' => 'decimal:2',
            'threshold_value' => 'decimal:2',
            'physical_progress' => 'decimal:2',
            'schedule_elapsed' => 'decimal:2',
            'financial_progress' => 'decimal:2',
            'measured_at' => 'immutable_datetime',
            'acknowledged_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
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

    /** The corrective work this notice provoked. */
    /** @return BelongsTo<Issue, $this> */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    /** Null when the threshold engine raised it — see the migration note. */
    /** @return BelongsTo<User, $this> */
    public function raisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by_id');
    }

    /** @return BelongsTo<User, $this> */
    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by_id');
    }

    /** @return BelongsTo<User, $this> */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_id');
    }

    /** Whether the deviation still stands. */
    public function isLive(): bool
    {
        return $this->status->isLive();
    }

    /** Raised by the threshold engine rather than by a person. */
    public function isAutomatic(): bool
    {
        return $this->raised_by_id === null;
    }

    /**
     * Reports whose condition is still live — the set the duplicate gate in
     * RaiseExceptionReport asks about, and the set every board leads with.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeLive(Builder $query): Builder
    {
        $live = [];

        foreach (ExceptionStatus::cases() as $case) {
            if ($case->isLive()) {
                $live[] = $case->value;
            }
        }

        return $query->whereIn('status', $live);
    }

    /**
     * Highest severity first, then newest — the order a board is read in,
     * defined once so the tenant list, the oversight board and the export
     * cannot disagree.
     *
     * Ordered by an explicit CASE over the enum's own ranking rather than
     * alphabetically: `critical` sorts before `high` by luck, but `low` sorts
     * before `medium` and would put the least urgent rows on top.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWorstFirst(Builder $query): Builder
    {
        $bindings = [];
        $cases = '';

        foreach (IssueSeverity::cases() as $severity) {
            $cases .= ' WHEN ? THEN ?';
            $bindings[] = $severity->value;
            // Descending weight: the highest weight must sort first.
            $bindings[] = -$severity->weight();
        }

        // Raw, with bindings, because ORDER BY cannot take a parameterised
        // expression any other way in Eloquent; every value is bound and none
        // is user input (they are enum cases).
        return $query
            ->orderByRaw('CASE severity'.$cases.' ELSE 0 END', $bindings)
            ->orderByDesc('measured_at')
            ->orderByDesc('id');
    }

    /**
     * Reports a user may see — delegated to Project::scopeVisibleTo, the
     * single definition of "which projects may this user see".
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
