<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\ActivityScheduleGranularity;
use App\Enums\ActivityStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\WorkplanActivityFactory;
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
 * One line of an Annual Work Plan & Budget — tenant-owned.
 *
 * Guarded-by-omission: `status`, `progress_percent`, `actual_start`,
 * `actual_end`, `expenditure_to_date`, `progress_recorded_at` and
 * `overdue_notified_at` are NOT fillable. Progress is written only by
 * App\Actions\Workplans\RecordActivityProgress and the overdue stamp only by
 * FlagOverdueActivities, so a definition edit can never quietly move a
 * reported figure and vice versa.
 *
 * THE MANUAL'S RULE lives on `indicator_id`: "every activity carries an output
 * indicator". The column is nullable so a half-drafted plan is saveable, and
 * lacksOutputIndicator() is what every screen asks in order to show the
 * warning. See the migration for why this is not a NOT NULL constraint.
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property int $workplan_id
 * @property int|null $project_id
 * @property int|null $indicator_id
 * @property int $position
 * @property string $title
 * @property string|null $description
 * @property int|null $owner_id
 * @property string|null $responsible_unit
 * @property ActivityScheduleGranularity $schedule_granularity
 * @property CarbonImmutable $planned_start
 * @property CarbonImmutable $planned_end
 * @property CarbonImmutable|null $actual_start
 * @property CarbonImmutable|null $actual_end
 * @property string|null $budget_line
 * @property Money $budget_amount
 * @property Money $expenditure_to_date
 * @property int $weight
 * @property int $progress_percent
 * @property ActivityStatus $status
 * @property int|null $depends_on_id
 * @property CarbonImmutable|null $progress_recorded_at
 * @property CarbonImmutable|null $overdue_notified_at
 * @property int $created_by_id
 * @property-read Workplan $workplan
 */
#[Fillable([
    'workplan_id', 'project_id', 'indicator_id', 'position', 'title',
    'description', 'owner_id', 'responsible_unit', 'schedule_granularity',
    'planned_start', 'planned_end', 'budget_line', 'budget_amount', 'weight',
    'depends_on_id', 'created_by_id',
])]
class WorkplanActivity extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<WorkplanActivityFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    /**
     * The fields the approval freeze protects: what the work is, who does it,
     * when it is scheduled and what it costs — the commitments an approving
     * officer signed for. Everything else on the row is reported reality
     * (progress, actuals, expenditure) and keeps moving after approval.
     *
     * @var list<string>
     */
    public const DEFINITION_FIELDS = [
        'project_id', 'indicator_id', 'title', 'description', 'owner_id',
        'responsible_unit', 'schedule_granularity', 'planned_start',
        'planned_end', 'budget_line', 'budget_amount', 'weight', 'depends_on_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (WorkplanActivity $activity): void {
            $activity->ulid ??= (string) Str::ulid();
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('workplan_activities')
            ->logFillable()
            ->logOnly([
                'status', 'progress_percent', 'actual_start', 'actual_end',
                'expenditure_to_date', 'progress_recorded_at',
            ])
            // Money casts to a value object that JSON-encodes to {} — the raw
            // decimal string is what belongs in an audit record.
            ->useAttributeRawValues(['budget_amount', 'expenditure_to_date'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected function casts(): array
    {
        return [
            'status' => ActivityStatus::class,
            'schedule_granularity' => ActivityScheduleGranularity::class,
            'planned_start' => 'immutable_date',
            'planned_end' => 'immutable_date',
            'actual_start' => 'immutable_date',
            'actual_end' => 'immutable_date',
            'budget_amount' => MoneyCast::class,
            'expenditure_to_date' => MoneyCast::class,
            'position' => 'integer',
            'weight' => 'integer',
            'progress_percent' => 'integer',
            'progress_recorded_at' => 'immutable_datetime',
            'overdue_notified_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<Workplan, $this> */
    public function workplan(): BelongsTo
    {
        return $this->belongsTo(Workplan::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The output indicator this activity delivers (ondo-manual-digest §2:
     * "Output indicators — one per annual-work-plan activity"). Read-only
     * here; the Indicator model belongs to the results-framework module.
     *
     * @return BelongsTo<Indicator, $this>
     */
    public function indicator(): BelongsTo
    {
        return $this->belongsTo(Indicator::class);
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /** The predecessor whose completion this activity waits on (the Gantt arrow). */
    /** @return BelongsTo<WorkplanActivity, $this> */
    public function dependsOn(): BelongsTo
    {
        return $this->belongsTo(WorkplanActivity::class, 'depends_on_id');
    }

    /** @return HasMany<WorkplanActivity, $this> */
    public function dependents(): HasMany
    {
        return $this->hasMany(WorkplanActivity::class, 'depends_on_id');
    }

    /**
     * The manual's rule, broken. Every screen that lists an activity asks
     * this and shows a warning when it is true — the point of a countable
     * breach is that somebody can be asked to fix it.
     */
    public function lacksOutputIndicator(): bool
    {
        return $this->indicator_id === null;
    }

    /** Planned duration in days, inclusive of both endpoints. */
    public function plannedDays(): int
    {
        return (int) $this->planned_start->startOfDay()->diffInDays($this->planned_end->startOfDay()) + 1;
    }

    /**
     * Past its planned end without being finished. The same rule the overdue
     * sweep applies, expressed once — ActivityStatus::derive() is where the
     * lateness question is answered for the stored status.
     */
    public function isOverdue(?CarbonInterface $asOf = null): bool
    {
        return $this->status->canFallOverdue()
            && $this->planned_end->endOfDay()->isBefore($asOf ?? now());
    }

    /**
     * Activities past their planned end that nobody has finished — the
     * sweep's candidate set, and the list screen's "slipping" filter.
     *
     * CarbonInterface, not Illuminate\Support\Carbon: the platform's date
     * casts are `immutable_date`, so every date this module holds is a
     * CarbonImmutable — which is NOT a subclass of the mutable Carbon and
     * would TypeError here. FlagOverdueActivities passes exactly that.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOverdue(Builder $query, ?CarbonInterface $asOf = null): Builder
    {
        return $query
            ->whereNotIn('status', [ActivityStatus::Completed->value, ActivityStatus::Cancelled->value])
            ->whereDate('planned_end', '<', ($asOf ?? now())->toDateString());
    }
}
