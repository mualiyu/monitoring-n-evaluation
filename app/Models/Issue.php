<?php

namespace App\Models;

use App\Enums\IssueCategory;
use App\Enums\IssueSeverity;
use App\Enums\IssueStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocuments;
use Carbon\CarbonImmutable;
use Database\Factories\IssueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\MediaLibrary\HasMedia;

/**
 * One obstruction standing between a project and its delivery — tenant-owned.
 *
 * Guarded-by-omission, exactly as Project and ProgressReport are: `status` is
 * written ONLY by App\Actions\Issues\TransitionIssueStatus, and with it the
 * whole chain (`acknowledged_*`, `resolved_*`, `closed_*`, `resolution_note`,
 * `status_changed_at`). `escalated_at` is written ONLY by the threshold
 * engine, as its once-ever gate. None of them is fillable, so no
 * ->update($request->validated()) can reach them.
 *
 * `corrective_action` and `due_date` ARE fillable: deciding what will be done
 * about an obstruction and by when is an ordinary edit an M&E officer makes
 * repeatedly, guarded by IssuePolicy::update, not a lifecycle event.
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property int $project_id
 * @property string|null $source_type
 * @property int|null $source_id
 * @property string $title
 * @property string $description
 * @property IssueCategory $category
 * @property IssueSeverity $severity
 * @property IssueStatus $status
 * @property int|null $owner_id
 * @property string|null $corrective_action
 * @property CarbonImmutable|null $due_date
 * @property string|null $resolution_note
 * @property int $raised_by_id
 * @property int|null $acknowledged_by_id
 * @property CarbonImmutable|null $acknowledged_at
 * @property int|null $resolved_by_id
 * @property CarbonImmutable|null $resolved_at
 * @property int|null $closed_by_id
 * @property CarbonImmutable|null $closed_at
 * @property CarbonImmutable|null $escalated_at
 * @property CarbonImmutable|null $status_changed_at
 */
#[Fillable([
    'project_id', 'title', 'description', 'category', 'severity',
    'owner_id', 'corrective_action', 'due_date', 'raised_by_id',
])]
class Issue extends Model implements HasMedia
{
    use BelongsToTenant;
    use HasDocuments;

    /** @use HasFactory<IssueFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    /**
     * Evidence that the obstruction is real: a photograph of the flooded
     * access road, the letter withholding an approval, the stop-work notice.
     * One collection — the rules that matter (private disk, mime allow-list,
     * size ceiling) live in config/documents.php, not here.
     *
     * @return list<string>
     */
    public function documentCollections(): array
    {
        return ['issue_evidence'];
    }

    protected static function booted(): void
    {
        static::creating(function (Issue $issue): void {
            $issue->ulid ??= (string) Str::ulid();
        });
    }

    /**
     * Everything auditable. logFillable() covers what was reported and what
     * was decided about it; the explicit list adds back the chain columns that
     * are deliberately not fillable — "who accepted this, who cleared it, and
     * when" is exactly what an auditor asks of a corrective-action register.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('issues')
            ->logFillable()
            ->logOnly([
                'status', 'resolution_note', 'acknowledged_by_id', 'acknowledged_at',
                'resolved_by_id', 'resolved_at', 'closed_by_id', 'closed_at',
                'escalated_at', 'status_changed_at',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected function casts(): array
    {
        return [
            'category' => IssueCategory::class,
            'severity' => IssueSeverity::class,
            'status' => IssueStatus::class,
            'due_date' => 'immutable_date',
            'acknowledged_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'escalated_at' => 'immutable_datetime',
            'status_changed_at' => 'immutable_datetime',
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

    /**
     * The record that surfaced this obstruction — a progress report, a site
     * inspection, an evaluation. Optional: the commonest raise of all is
     * somebody simply knowing, and requiring a parent record would push the
     * issue off the register entirely.
     *
     * @return MorphTo<Model, $this>
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<User, $this> */
    public function raisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by_id');
    }

    /** @return BelongsTo<User, $this> */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_id');
    }

    /** @return BelongsTo<User, $this> */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_id');
    }

    /** @return HasMany<IssueEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(IssueEvent::class);
    }

    /** Exception reports that point at this issue as the work they provoked. */
    /** @return HasMany<ExceptionReport, $this> */
    public function exceptionReports(): HasMany
    {
        return $this->hasMany(ExceptionReport::class);
    }

    /** Still the register's problem — what the "open issues" stat counts. */
    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    /**
     * Past its corrective-action deadline and still open. An issue with no due
     * date is never overdue — a deadline nobody set is not a deadline missed,
     * and flagging one would teach officers to ignore the flag.
     */
    public function isOverdue(?Carbon $asOf = null): bool
    {
        if ($this->due_date === null || ! $this->isOpen()) {
            return false;
        }

        return $this->due_date->isBefore(($asOf ?? now())->startOfDay());
    }

    /**
     * Open issues, for the boards and the escalation sweep. Expressed against
     * the enum rather than a literal list, so adding a live state cannot leave
     * one screen counting it and another not.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        $open = [];

        foreach (IssueStatus::cases() as $case) {
            if ($case->isOpen()) {
                $open[] = $case->value;
            }
        }

        return $query->whereIn('status', $open);
    }

    /**
     * Issues the escalation ladder may still act on: open, acknowledged or in
     * progress, and not yet escalated. Already-escalated rows are excluded
     * structurally here as well as by the `escalated_at` gate — a director who
     * has been told once does not need telling nightly.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeEscalatable(Builder $query): Builder
    {
        $states = [];

        foreach (IssueStatus::cases() as $case) {
            if ($case->isEscalatable()) {
                $states[] = $case->value;
            }
        }

        return $query->whereIn('status', $states)->whereNull('escalated_at');
    }

    /**
     * Issues a user may see: consultants and field monitors see only the
     * projects they are actively assigned to.
     *
     * Delegated to Project::scopeVisibleTo — the single definition of "which
     * projects may this user see" — so a policy, a list screen and an export
     * can never disagree about a single row. A subquery rather than whereHas()
     * because it reuses that scope verbatim; the TenantScope on the inner
     * query keeps it inside the bound MDA.
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
