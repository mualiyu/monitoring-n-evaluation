<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use App\Enums\Role;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocuments;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LogicException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\MediaLibrary\HasMedia;

/**
 * A public project or programme executed under one MDA — tenant-owned.
 *
 * `published_at` / `published_by_id` are deliberately NOT fillable: publishing
 * is an explicit act by PublishProject (Phase 3 portal gate), never something
 * a form payload can flip. `status` is written ONLY by
 * App\Actions\Projects\TransitionProjectStatus;
 * `contract_value_total` ONLY by AwardContract / RecordContractVariation
 * inside the contract transaction (through RecalculateContractValueTotal);
 * `physical_progress` and `expenditure_to_date` ONLY by RecordProjectProgress.
 * `financial_progress` is derived and writable by no one.
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property string $reference
 * @property string $title
 * @property string|null $description
 * @property string|null $goal
 * @property string|null $objectives
 * @property int $sector_id
 * @property ProjectType $type
 * @property ProjectStatus $status
 * @property int|null $supervising_agency_id
 * @property string|null $supervising_agency_name
 * @property Money|null $budget_allocation
 * @property string|null $budget_code
 * @property Money|null $contract_value_total
 * @property Money $expenditure_to_date
 * @property string $physical_progress
 * @property float|null $financial_progress
 * @property CarbonImmutable|null $start_date
 * @property CarbonImmutable|null $expected_end_date
 * @property CarbonImmutable|null $revised_end_date
 * @property CarbonImmutable|null $actual_end_date
 * @property CarbonImmutable|null $post_completion_review_due_at
 * @property CarbonImmutable|null $mid_term_flagged_at
 * @property CarbonImmutable|null $published_at
 * @property int|null $published_by_id
 * @property string|null $reporting_frequency
 * @property int $created_by_id
 * @property int|null $manager_id
 * @property CarbonImmutable|null $status_changed_at
 */
// Guarded-by-omission (migration review §2): status, contract_value_total,
// expenditure_to_date, physical_progress, mid_term_flagged_at,
// status_changed_at and post_completion_review_due_at are NOT fillable —
// their chokepoint Actions assign them explicitly (forceFill/property write),
// so no ->update($request->validated()) can ever bypass the §2 guards.
#[Fillable([
    'reference', 'title', 'description', 'goal', 'objectives', 'sector_id',
    'type', 'supervising_agency_id', 'supervising_agency_name',
    'budget_allocation', 'budget_code', 'start_date',
    'expected_end_date', 'revised_end_date', 'actual_end_date',
    'reporting_frequency', 'created_by_id', 'manager_id',
])]
class Project extends Model implements HasMedia
{
    use BelongsToTenant;
    use HasDocuments;

    /** @use HasFactory<ProjectFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    /**
     * The project vault: approvals, designs, correspondence, and site photography.
     *
     * @return list<string>
     */
    public function documentCollections(): array
    {
        return ['project_documents', 'project_photos'];
    }

    protected static function booted(): void
    {
        static::creating(function (Project $project): void {
            $project->ulid ??= (string) Str::ulid();
        });
    }

    /**
     * Everything auditable (rules/architecture.md). `logFillable()` covers the
     * scope fields; the explicit list adds back the seven chokepoint columns
     * that are deliberately NOT fillable — status, the money roll-ups and the
     * lifecycle stamps are exactly the values an auditor asks "who changed
     * this, and from what" about.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('projects')
            ->logFillable()
            ->logOnly([
                'status', 'contract_value_total', 'expenditure_to_date',
                'physical_progress', 'mid_term_flagged_at', 'status_changed_at',
                'post_completion_review_due_at', 'published_at', 'published_by_id',
            ])
            // Money casts to a value object that JSON-encodes to {} — the raw
            // decimal string is what belongs in an audit record.
            ->useAttributeRawValues(['budget_allocation', 'contract_value_total', 'expenditure_to_date'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected function casts(): array
    {
        return [
            'type' => ProjectType::class,
            'status' => ProjectStatus::class,
            'budget_allocation' => MoneyCast::class,
            'contract_value_total' => MoneyCast::class,
            'expenditure_to_date' => MoneyCast::class,
            'physical_progress' => 'decimal:2',
            'start_date' => 'immutable_date',
            'expected_end_date' => 'immutable_date',
            'revised_end_date' => 'immutable_date',
            'actual_end_date' => 'immutable_date',
            'post_completion_review_due_at' => 'immutable_date',
            'mid_term_flagged_at' => 'immutable_datetime',
            'status_changed_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /**
     * Expenditure as a percentage of the contract value — DERIVED, never
     * stored. Null when there is no contract value yet (nothing to be a
     * percentage of), so callers must handle "not applicable" rather than read
     * a false 0%.
     *
     * Writable by no one: assigning to it throws rather than silently
     * shadowing the derivation with a stale figure.
     *
     * @return Attribute<float|null, mixed>
     */
    protected function financialProgress(): Attribute
    {
        return Attribute::make(
            get: function (): ?float {
                $total = $this->contract_value_total;

                return $total === null ? null : $this->expenditure_to_date->percentageOf($total);
            },
            set: fn (): never => throw new LogicException(
                'financial_progress is derived — write expenditure_to_date / contract_value_total through their Actions instead.'
            ),
        );
    }

    /** @return BelongsTo<Sector, $this> */
    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }

    /**
     * The funding split (donor + counterpart is the normal case, not the
     * exception). READ-SIDE ONLY: writes go through ProjectFundingSource
     * models, because attach() inserts a pivot row directly and would bypass
     * the tenant_id auto-fill that BelongsToTenant performs on create.
     *
     * @return BelongsToMany<FundingSource, $this>
     */
    public function fundingSources(): BelongsToMany
    {
        return $this->belongsToMany(FundingSource::class, 'project_funding_sources')
            ->withPivot(['id', 'amount', 'percentage', 'is_primary'])
            ->withTimestamps();
    }

    /** @return HasMany<ProjectFundingSource, $this> */
    public function fundingAllocations(): HasMany
    {
        return $this->hasMany(ProjectFundingSource::class);
    }

    /**
     * The MDA supervising execution, when it is another tenant on this
     * platform (`supervising_agency_name` covers supervisors that are not).
     *
     * @return BelongsTo<Tenant, $this>
     */
    public function supervisingAgency(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'supervising_agency_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /** @return BelongsTo<User, $this> */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /** @return BelongsTo<User, $this> */
    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_id');
    }

    /** @return HasMany<ProjectLocation, $this> */
    public function locations(): HasMany
    {
        return $this->hasMany(ProjectLocation::class);
    }

    /**
     * The headline site. Single-site projects have exactly one location and it
     * is the primary one; the invariant is enforced in
     * SetPrimaryProjectLocation, not by a unique index (§1.5).
     *
     * @return HasOne<ProjectLocation, $this>
     */
    public function primaryLocation(): HasOne
    {
        return $this->hasOne(ProjectLocation::class)->where('is_primary', true);
    }

    /** @return HasMany<Contract, $this> */
    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    /** @return HasMany<ProjectAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(ProjectAssignment::class);
    }

    /** @return HasMany<ProjectStatusEvent, $this> */
    public function statusEvents(): HasMany
    {
        return $this->hasMany(ProjectStatusEvent::class);
    }

    /** @return HasMany<Indicator, $this> */
    public function indicators(): HasMany
    {
        return $this->hasMany(Indicator::class);
    }

    /**
     * The single definition of "which projects may this user see", shared by
     * ProjectPolicy::view and every list screen so a policy and a query can
     * never disagree.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        // Users holding ONLY project-level roles (Consultant / FieldMonitor)
        // in the current team see just their active assignments; everyone
        // else sees the tenant portfolio (TenantScope already confines it).
        $projectLevelOnly = ($user->hasRole(Role::Consultant->value)
                || $user->hasRole(Role::FieldMonitor->value))
            && ! $user->hasRole(Role::MdaAdmin->value)
            && ! $user->hasRole(Role::MeOfficer->value);

        return $query->when($projectLevelOnly, fn (Builder $q) => $q
            ->whereHas('assignments', fn (Builder $a) => $a
                ->where('user_id', $user->id)
                ->whereNull('unassigned_at')));
    }

    /**
     * Policy-side twin of scopeVisibleTo(): "may this user see THIS project",
     * answered by the same query the list screens run, so a policy and a list
     * can never disagree about a single row (design §3). The TenantScope on
     * the query also makes a foreign-tenant project invisible here.
     */
    public function isVisibleTo(User $user): bool
    {
        return static::query()->visibleTo($user)->whereKey($this->getKey())->exists();
    }

    /**
     * The fields the certification freeze protects (§2.3): scope, money and
     * dates — the figures a certificate attests to. Everything else on the
     * record (manager, reporting frequency) stays editable, and attaching
     * monitoring artifacts is never blocked at all.
     *
     * @var list<string>
     */
    public const FROZEN_FIELDS = [
        'reference', 'title', 'description', 'goal', 'objectives', 'sector_id',
        'type', 'supervising_agency_id', 'supervising_agency_name',
        'budget_allocation', 'budget_code', 'start_date', 'expected_end_date',
        'revised_end_date', 'actual_end_date',
    ];

    /**
     * Whether scope and financial FIELDS are frozen (from `certified` onward),
     * so certified figures cannot be quietly rewritten.
     *
     * This never blocks attaching artifacts: post-completion inspections,
     * documents, indicator readings and issues are all still creatable against
     * certified and closed projects — that is exactly what 6–12 month
     * post-completion monitoring requires. No child-record Action may consult
     * this method for permission to exist.
     */
    public function isFrozen(): bool
    {
        return $this->status->isFrozen();
    }

    /** Whether the mid-term evaluation trigger has already been raised. */
    public function isMidTermFlagged(): bool
    {
        return $this->mid_term_flagged_at !== null;
    }

    /** Past its (revised, else expected) end date without being finished. */
    public function isOverdue(?Carbon $asOf = null): bool
    {
        $deadline = $this->revised_end_date ?? $this->expected_end_date;

        if ($deadline === null || $this->actual_end_date !== null) {
            return false;
        }

        return $deadline->isBefore($asOf ?? now())
            && ! in_array($this->status, [
                ProjectStatus::Completed,
                ProjectStatus::Certified,
                ProjectStatus::Closed,
                ProjectStatus::Cancelled,
            ], true);
    }
}
