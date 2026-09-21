<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\RecommendationPriority;
use App\Enums\RecommendationStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\RecommendationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * The follow-up register — tenant-owned (manual digest §7.9, plan §4).
 *
 * A recommendation raised by an evaluation, a progress report or an inspection,
 * addressed to someone, costed, timetabled, and tracked to implementation.
 * This register is the thing that makes evaluations matter: an unimplemented
 * recommendation has to be findable, overdue-flaggable and reportable, or the
 * evaluation that produced it was an expensive document.
 *
 * Guarded-by-omission: `status` and its stamps (`accepted_*`, `implemented_*`,
 * `closure_reason`, `superseded_by_id`, `status_changed_at`) are written ONLY
 * by App\Actions\Evaluation\TransitionRecommendationStatus, and
 * `overdue_flagged_at` only by FlagOverdueRecommendations. None is fillable.
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property string $source_type
 * @property int $source_id
 * @property int|null $project_id
 * @property string $title
 * @property string $body
 * @property int|null $addressee_id
 * @property string|null $addressee_body
 * @property RecommendationPriority $priority
 * @property RecommendationStatus $status
 * @property Money|null $estimated_cost
 * @property string|null $timeline
 * @property CarbonImmutable|null $due_on
 * @property string|null $implementation_evidence
 * @property string|null $follow_up_notes
 * @property CarbonImmutable|null $overdue_flagged_at
 * @property int $raised_by_id
 * @property CarbonImmutable|null $raised_at
 * @property int|null $accepted_by_id
 * @property CarbonImmutable|null $accepted_at
 * @property int|null $implemented_by_id
 * @property CarbonImmutable|null $implemented_at
 * @property string|null $closure_reason
 * @property int|null $superseded_by_id
 * @property CarbonImmutable|null $status_changed_at
 */
#[Fillable([
    'source_type', 'source_id', 'project_id', 'title', 'body', 'addressee_id',
    'addressee_body', 'priority', 'estimated_cost', 'timeline', 'due_on',
    'implementation_evidence', 'follow_up_notes', 'raised_by_id',
])]
class Recommendation extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<RecommendationFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (Recommendation $recommendation): void {
            $recommendation->ulid ??= (string) Str::ulid();
        });
    }

    /**
     * Everything auditable. The explicit list adds back the follow-up columns
     * that are deliberately not fillable — "who accepted this, who says it was
     * implemented, and on what evidence" is the whole audit question here.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('recommendations')
            ->logFillable()
            ->logOnly([
                'status', 'accepted_by_id', 'accepted_at', 'implemented_by_id',
                'implemented_at', 'closure_reason', 'superseded_by_id',
                'status_changed_at', 'overdue_flagged_at',
            ])
            // Money casts to a value object that JSON-encodes to {} — the raw
            // decimal string is what belongs in an audit record.
            ->useAttributeRawValues(['estimated_cost'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected function casts(): array
    {
        return [
            'priority' => RecommendationPriority::class,
            'status' => RecommendationStatus::class,
            'estimated_cost' => MoneyCast::class,
            'due_on' => 'immutable_date',
            'overdue_flagged_at' => 'immutable_datetime',
            'raised_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'implemented_at' => 'immutable_datetime',
            'status_changed_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /* ------------------------------------------------------------------ */
    /* Relations */
    /* ------------------------------------------------------------------ */

    /**
     * What raised this: an Evaluation, a ProgressReport, a SiteInspection.
     * Laravel's default morph map (the FQCN) — there is no morph-map config
     * for this module to register an alias in.
     *
     * @return MorphTo<Model, $this>
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function addressee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'addressee_id');
    }

    /** @return BelongsTo<User, $this> */
    public function raisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by_id');
    }

    /** @return BelongsTo<User, $this> */
    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by_id');
    }

    /** @return BelongsTo<User, $this> */
    public function implementedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'implemented_by_id');
    }

    /** @return BelongsTo<Recommendation, $this> */
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(Recommendation::class, 'superseded_by_id');
    }

    /* ------------------------------------------------------------------ */
    /* Derived state */
    /* ------------------------------------------------------------------ */

    public function isOutstanding(): bool
    {
        return $this->status->isOutstanding();
    }

    /**
     * Past its due date and still owed. DERIVED, never a stored flag: a
     * boolean column would be wrong every night between a deadline passing
     * and the sweep running, and a stale status on a deadline is a compliance
     * defect. `overdue_flagged_at` records only that the sweep has ALREADY
     * announced it, which is a different fact.
     */
    public function isOverdue(?CarbonInterface $asOf = null): bool
    {
        if ($this->due_on === null || ! $this->isOutstanding()) {
            return false;
        }

        return $this->due_on->isBefore(($asOf ?? now())->startOfDay());
    }

    /** Whole days until the deadline; negative when it has passed. */
    public function daysToDue(?CarbonInterface $asOf = null): ?int
    {
        if ($this->due_on === null) {
            return null;
        }

        return (int) $this->due_on->startOfDay()->diffInDays(($asOf ?? now())->startOfDay(), false) * -1;
    }

    /** Who owes this, in one line: the named user, else the body addressed. */
    public function addresseeLabel(): string
    {
        if ($this->addressee_id !== null && $this->relationLoaded('addressee') && $this->addressee !== null) {
            return $this->addressee->name;
        }

        return $this->addressee_body ?? __('Unassigned');
    }

    /** Human label for the record that raised this — "Evaluation", "Progress report". */
    public function sourceLabel(): string
    {
        return Str::headline(class_basename($this->source_type));
    }

    /* ------------------------------------------------------------------ */
    /* Scopes */
    /* ------------------------------------------------------------------ */

    /**
     * Still owed — the definition the register, the stat row and the nightly
     * sweep all share, expressed once so a screen and a job cannot disagree.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereIn('status', array_values(array_filter(
            array_map(
                fn (RecommendationStatus $status): ?string => $status->isOutstanding() ? $status->value : null,
                RecommendationStatus::cases(),
            ),
        )));
    }

    /**
     * Outstanding AND past due — the SQL twin of isOverdue().
     *
     * `$asOf` is typed on CarbonInterface, not on Illuminate\Support\Carbon:
     * CarbonImmutable does not extend that class, and the nightly sweep
     * (FlagOverdueRecommendations) works in immutables because every date on
     * this model is an `immutable_date`. Narrowing it to the mutable class
     * made the sweep a TypeError on every run.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOverdue(Builder $query, ?CarbonInterface $asOf = null): Builder
    {
        return $query->outstanding()
            ->whereNotNull('due_on')
            ->whereDate('due_on', '<', ($asOf ?? now())->toDateString());
    }

    /**
     * Recommendations a user may see. Narrowed on the PROJECT the
     * recommendation bites on, through Project::scopeVisibleTo — the single
     * definition of project visibility. A recommendation with no project
     * (a programme-level one) is visible to anyone the permission admitted.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereNull('project_id')
            ->orWhereIn('project_id', Project::query()->visibleTo($user)->select('id')));
    }

    /**
     * Policy-side twin of scopeVisibleTo(), answered by the same query the
     * register runs.
     */
    public function isVisibleTo(User $user): bool
    {
        return static::query()->visibleTo($user)->whereKey($this->getKey())->exists();
    }
}
