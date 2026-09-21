<?php

namespace App\Models;

use App\Enums\Role;
use App\Enums\WorkplanStatus;
use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\WorkplanFactory;
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
 * An MDA's Annual Work Plan & Budget — tenant-owned.
 *
 * Guarded-by-omission, exactly as Project and ProgressReport are: `status` is
 * written ONLY by App\Actions\Workplans\TransitionWorkplanStatus, and with it
 * the whole chain (`submitted_*`, `approved_*`, `rejected_*`, `activated_at`,
 * `closed_*`, `status_changed_at`). None of them is fillable, so no
 * ->update($request->validated()) can reach them.
 *
 * NO STORED TOTALS. Budget, expenditure and progress are sums over the
 * activities and are derived in exactly one place —
 * App\Support\WorkplanProgress — which every screen, export and notification
 * calls. A figure stored here would be a second answer to a question that
 * already has one.
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property string $title
 * @property int $year
 * @property string $year_basis
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property int $owner_id
 * @property WorkplanStatus $status
 * @property string|null $narrative
 * @property int $created_by_id
 * @property int|null $submitted_by_id
 * @property CarbonImmutable|null $submitted_at
 * @property int|null $approved_by_id
 * @property CarbonImmutable|null $approved_at
 * @property int|null $rejected_by_id
 * @property CarbonImmutable|null $rejected_at
 * @property string|null $rejection_reason
 * @property CarbonImmutable|null $activated_at
 * @property int|null $closed_by_id
 * @property CarbonImmutable|null $closed_at
 * @property CarbonImmutable|null $status_changed_at
 */
#[Fillable([
    'title', 'year', 'year_basis', 'period_start', 'period_end',
    'owner_id', 'narrative', 'created_by_id',
])]
class Workplan extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<WorkplanFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    /** How the `year` column is to be read. */
    public const YEAR_BASES = ['calendar', 'financial'];

    protected static function booted(): void
    {
        static::creating(function (Workplan $workplan): void {
            $workplan->ulid ??= (string) Str::ulid();
        });
    }

    /**
     * Everything auditable. logFillable() covers the plan's scope; the
     * explicit list adds back the chain columns that are deliberately not
     * fillable — "who approved this year's programme, and when" is the first
     * question an auditor asks of a work plan.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('workplans')
            ->logFillable()
            ->logOnly([
                'status', 'submitted_by_id', 'submitted_at', 'approved_by_id',
                'approved_at', 'rejected_by_id', 'rejected_at', 'rejection_reason',
                'activated_at', 'closed_by_id', 'closed_at', 'status_changed_at',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected function casts(): array
    {
        return [
            'status' => WorkplanStatus::class,
            'year' => 'integer',
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'submitted_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
            'activated_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'status_changed_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return HasMany<WorkplanActivity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(WorkplanActivity::class)->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<WorkplanEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(WorkplanEvent::class);
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
    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by_id');
    }

    /** @return BelongsTo<User, $this> */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_id');
    }

    /**
     * Whether ACTIVITY DEFINITIONS are frozen (from `approved` onward) — the
     * same shape as Project::isFrozen(). It never blocks recording progress,
     * expenditure or actual dates: that is what an approved plan is for.
     */
    public function isFrozen(): bool
    {
        return ! $this->status->allowsDefinitionEdits();
    }

    /** The label a user reads: "2026" or "2026/2027" for a financial year. */
    public function yearLabel(): string
    {
        return $this->year_basis === 'financial'
            ? $this->year.'/'.($this->year + 1)
            : (string) $this->year;
    }

    /** Whether the plan's period has begun — the gate on activation. */
    public function hasStarted(?CarbonInterface $asOf = null): bool
    {
        return ! $this->period_start->startOfDay()->isAfter($asOf ?? now());
    }

    /**
     * Plans that COMMIT the MDA: signed off and either running or about to.
     * The overdue sweep, the oversight board's "live" filter and the tenant
     * dashboard all mean this same set — a draft plan's dates are a proposal,
     * and a closed plan's deadlines are history.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            WorkplanStatus::Approved->value,
            WorkplanStatus::Active->value,
        ]);
    }

    /**
     * Plans a user may see. Field roles (Consultant / FieldMonitor) hold
     * `workplans.view` but have no business browsing the MDA's whole
     * programme, so they see only the plans they own a line in. The
     * TenantScope has already confined the query to the bound MDA.
     *
     * The single definition, shared by WorkplanPolicy::view and every list
     * screen, so a policy and a list can never disagree about a single row.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $planLevelOnly = ($user->hasRole(Role::Consultant->value)
                || $user->hasRole(Role::FieldMonitor->value))
            && ! $user->hasRole(Role::MdaAdmin->value)
            && ! $user->hasRole(Role::MeOfficer->value);

        return $query->when($planLevelOnly, fn (Builder $q) => $q
            ->where(fn (Builder $mine) => $mine
                ->where('owner_id', $user->id)
                ->orWhereHas('activities', fn (Builder $a) => $a->where('owner_id', $user->id))));
    }

    /**
     * Policy-side twin of scopeVisibleTo(): answered by the same query the
     * list screens run. The TenantScope also makes a foreign-tenant plan
     * invisible here.
     */
    public function isVisibleTo(User $user): bool
    {
        return static::query()->visibleTo($user)->whereKey($this->getKey())->exists();
    }
}
