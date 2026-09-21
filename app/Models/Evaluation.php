<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\EvaluationStatus;
use App\Enums\EvaluationType;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocuments;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Factories\EvaluationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\MediaLibrary\HasMedia;

/**
 * A commissioned evaluation of a project or an MDA programme — tenant-owned
 * (plan §4 "Evaluation", manual digest §3–4).
 *
 * Guarded-by-omission, exactly as Project and ProgressReport are: `status` is
 * written ONLY by App\Actions\Evaluation\TransitionEvaluationStatus, and with
 * it the whole chain (`commissioned_*`, `submitted_*`, `approved_*`,
 * `published_*`, `cancellation_reason`, `status_changed_at`). None of them is
 * fillable, so no ->update($request->validated()) can reach them.
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property int|null $project_id
 * @property string $scope
 * @property string|null $subject_name
 * @property EvaluationType $type
 * @property EvaluationStatus $status
 * @property string $title
 * @property string $purpose
 * @property string|null $evaluation_questions
 * @property string|null $methodology_summary
 * @property string $sponsor
 * @property list<array{label: string, starts_on: string|null, ends_on: string|null}>|null $phases
 * @property Money|null $budget
 * @property CarbonImmutable|null $starts_on
 * @property CarbonImmutable|null $ends_on
 * @property CarbonImmutable|null $report_due_on
 * @property int $created_by_id
 * @property int|null $commissioned_by_id
 * @property CarbonImmutable|null $commissioned_at
 * @property int|null $submitted_by_id
 * @property CarbonImmutable|null $submitted_at
 * @property int|null $approved_by_id
 * @property CarbonImmutable|null $approved_at
 * @property int|null $published_by_id
 * @property CarbonImmutable|null $published_at
 * @property string|null $cancellation_reason
 * @property CarbonImmutable|null $status_changed_at
 */
#[Fillable([
    'project_id', 'scope', 'subject_name', 'type', 'title', 'purpose',
    'evaluation_questions', 'methodology_summary', 'sponsor', 'phases',
    'budget', 'starts_on', 'ends_on', 'report_due_on', 'created_by_id',
])]
class Evaluation extends Model implements HasMedia
{
    use BelongsToTenant;
    use HasDocuments;

    /** @use HasFactory<EvaluationFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    /** What an evaluation can be OF, when it is not one project. */
    public const SCOPES = ['project', 'programme', 'entity', 'sector', 'thematic'];

    /**
     * The evaluation vault: terms of reference, inception report, data
     * collection instruments, validation-workshop minutes, the signed report.
     * ONE collection, because the rules that matter — private disk, mime
     * allow-list, size ceiling, who may upload — are identical for all of
     * them and live in config/documents.php.
     *
     * @return list<string>
     */
    public function documentCollections(): array
    {
        return ['evaluation_documents'];
    }

    protected static function booted(): void
    {
        static::creating(function (Evaluation $evaluation): void {
            $evaluation->ulid ??= (string) Str::ulid();
        });
    }

    /**
     * Everything auditable. logFillable() covers the commission's terms; the
     * explicit list adds back the chain columns that are deliberately not
     * fillable — "who approved these findings, and when" is the first thing
     * an auditor asks of an evaluation.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('evaluations')
            ->logFillable()
            ->logOnly([
                'status', 'commissioned_by_id', 'commissioned_at',
                'submitted_by_id', 'submitted_at', 'approved_by_id', 'approved_at',
                'published_by_id', 'published_at', 'cancellation_reason',
                'status_changed_at',
            ])
            // Money casts to a value object that JSON-encodes to {} — the raw
            // decimal string is what belongs in an audit record.
            ->useAttributeRawValues(['budget'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected function casts(): array
    {
        return [
            'type' => EvaluationType::class,
            'status' => EvaluationStatus::class,
            'phases' => 'array',
            'budget' => MoneyCast::class,
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'report_due_on' => 'immutable_date',
            'commissioned_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
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

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /** @return BelongsTo<User, $this> */
    public function commissionedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'commissioned_by_id');
    }

    /** @return BelongsTo<User, $this> */
    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    /** @return BelongsTo<User, $this> */
    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_id');
    }

    /** @return HasMany<EvaluationTeamMember, $this> */
    public function teamMembers(): HasMany
    {
        return $this->hasMany(EvaluationTeamMember::class);
    }

    /**
     * The evaluator who signs for the findings. Exactly one exists; the
     * invariant is enforced in AssignEvaluationTeam, not by a unique index
     * (the migration says why).
     *
     * @return HasOne<EvaluationTeamMember, $this>
     */
    public function lead(): HasOne
    {
        return $this->hasOne(EvaluationTeamMember::class)
            ->where('role', EvaluationTeamMember::ROLE_LEAD);
    }

    /** @return HasMany<EvaluationReportSection, $this> */
    public function sections(): HasMany
    {
        return $this->hasMany(EvaluationReportSection::class)->orderBy('ordinal');
    }

    /** @return HasMany<EvaluationCriterionScore, $this> */
    public function criterionScores(): HasMany
    {
        return $this->hasMany(EvaluationCriterionScore::class);
    }

    /** @return HasMany<EvaluationEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(EvaluationEvent::class);
    }

    /**
     * The follow-up register entries this evaluation raised — the whole point
     * of the module (manual digest §7.9).
     *
     * @return MorphMany<Recommendation, $this>
     */
    public function recommendations(): MorphMany
    {
        return $this->morphMany(Recommendation::class, 'source');
    }

    /* ------------------------------------------------------------------ */
    /* Derived state */
    /* ------------------------------------------------------------------ */

    /** Whether the team may still write sections, scores and the roster. */
    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    /**
     * What is being evaluated, in one line. A project's title when there is
     * one, the typed subject otherwise — never a hard-coded entity name.
     */
    public function subjectLabel(): string
    {
        if ($this->project_id !== null && $this->relationLoaded('project') && $this->project !== null) {
            return $this->project->title;
        }

        return $this->subject_name ?? __('Entity-wide');
    }

    /**
     * The weighted mean of the criterion scores, on the instance's scale —
     * DERIVED, never stored. Null when nothing has been scored yet, so a
     * caller must handle "not scored" rather than read a false 0.
     *
     * Weighted, because a state that counts sustainability double should not
     * have to re-derive the arithmetic; unscored rows are skipped rather than
     * counted as zero, because "not answered" and "answered badly" are
     * different findings.
     *
     * Reads the loaded relation — callers eager-load it, and
     * preventLazyLoading is on outside production, so a missed eager load is
     * a loud failure rather than an N+1.
     */
    public function overallScore(): ?float
    {
        $weighted = 0.0;
        $weights = 0.0;

        foreach ($this->criterionScores as $score) {
            if ($score->score === null) {
                continue;
            }

            $weight = (float) $score->weight;
            $weighted += ((float) $score->score) * $weight;
            $weights += $weight;
        }

        return $weights > 0.0 ? round($weighted / $weights, 2) : null;
    }

    /** The overall score as a percentage of the configured maximum. */
    public function overallScorePercent(int $scoreMax): ?float
    {
        $score = $this->overallScore();

        return $score === null || $scoreMax <= 0 ? null : round($score * 100 / $scoreMax, 1);
    }

    /**
     * Sections that still have to be written before the report can go up for
     * review. The completeness rule lives here so the screen's warning and
     * the chokepoint's refusal can never disagree.
     *
     * @return list<string>
     */
    public function missingRequiredSections(): array
    {
        $missing = [];

        foreach ($this->sections as $section) {
            if ($section->is_required && trim((string) $section->body) === '') {
                $missing[] = $section->heading;
            }
        }

        return $missing;
    }

    /**
     * Criteria still awaiting a score or a justification. A score with no
     * reasoning is an opinion; the review gate refuses both.
     *
     * @return list<string>
     */
    public function unscoredCriteria(): array
    {
        $unscored = [];

        foreach ($this->criterionScores as $score) {
            if ($score->score === null || trim((string) $score->justification) === '') {
                $unscored[] = $score->criterion;
            }
        }

        return $unscored;
    }

    /** Whether this user leads the evaluation — the separation-of-duties key. */
    public function isLedBy(User $user): bool
    {
        return $this->teamMembers()
            ->where('role', EvaluationTeamMember::ROLE_LEAD)
            ->where('user_id', $user->id)
            ->exists();
    }

    /** Past its report deadline without an approved report. */
    public function isReportOverdue(?Carbon $asOf = null): bool
    {
        if ($this->report_due_on === null || $this->status->isSettled() || $this->status->isTerminal()) {
            return false;
        }

        return $this->report_due_on->isBefore($asOf ?? now());
    }

    /* ------------------------------------------------------------------ */
    /* Scopes */
    /* ------------------------------------------------------------------ */

    /**
     * Evaluations a user may see. Project-level roles (Consultant,
     * FieldMonitor) hold no `evaluations.view` permission at all in the seeded
     * matrix, so this narrows on the PROJECT rather than on the role: an
     * evaluation of a project the user cannot see is not listed, and a
     * programme evaluation (no project) is visible to anyone the permission
     * already admitted.
     *
     * Delegated to Project::scopeVisibleTo — the single definition of project
     * visibility — so a policy, a list screen and an export can never disagree
     * about a single row.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereNull('project_id')
            ->orWhereIn('project_id', Project::query()->visibleTo($user)->select('id')));
    }

    /**
     * Policy-side twin of scopeVisibleTo(): "may this user see THIS
     * evaluation", answered by the same query the list screens run.
     */
    public function isVisibleTo(User $user): bool
    {
        return static::query()->visibleTo($user)->whereKey($this->getKey())->exists();
    }
}
