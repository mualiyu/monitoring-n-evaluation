<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\CommencementNoticeStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocuments;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Factories\CommencementNoticeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\MediaLibrary\HasMedia;

/**
 * The notice to commence served on a contractor after award — tenant-owned,
 * one per contract (digest §8, step 1).
 *
 * Guarded-by-omission, exactly as Project and ProgressReport are: `status` and
 * the whole service chain (`issued_by_id`/`issued_at`/`issued_late`,
 * `acknowledged_*`, `overdue_notified_at`) are written ONLY by the Actions in
 * App\Actions\Lifecycle, so no ->update($request->validated()) can serve a
 * notice or mark one acknowledged.
 *
 * The scope/money/date columns ARE fillable, because they are a SNAPSHOT taken
 * at creation rather than live contract data: what a contractor was told on
 * the day is not rewritten by a later variation order.
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property int $project_id
 * @property int $contract_id
 * @property int $contractor_id
 * @property string|null $reference
 * @property CommencementNoticeStatus $status
 * @property string|null $scope_of_works
 * @property Money $contract_sum
 * @property int|null $duration_days
 * @property CarbonImmutable|null $commencement_date
 * @property CarbonImmutable|null $expected_completion_date
 * @property string|null $supervising_agency_name
 * @property string|null $instructions
 * @property CarbonImmutable $due_at
 * @property bool $issued_late
 * @property CarbonImmutable|null $overdue_notified_at
 * @property int|null $issued_by_id
 * @property CarbonImmutable|null $issued_at
 * @property int|null $acknowledged_by_id
 * @property CarbonImmutable|null $acknowledged_by_contractor_at
 * @property string|null $acknowledgement_note
 * @property int|null $created_by_id
 */
#[Fillable([
    'project_id', 'contract_id', 'contractor_id', 'reference', 'scope_of_works',
    'contract_sum', 'duration_days', 'commencement_date',
    'expected_completion_date', 'supervising_agency_name', 'instructions',
    'due_at', 'created_by_id',
])]
class CommencementNotice extends Model implements HasMedia
{
    use BelongsToTenant;
    use HasDocuments;

    /** @use HasFactory<CommencementNoticeFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    /**
     * The served notice itself, as a single-file collection: a second upload
     * replaces the first, because there is exactly one document that IS the
     * notice (config/documents.php).
     *
     * @return list<string>
     */
    public function documentCollections(): array
    {
        return ['commencement_notice'];
    }

    protected static function booted(): void
    {
        static::creating(function (CommencementNotice $notice): void {
            $notice->ulid ??= (string) Str::ulid();
        });
    }

    /**
     * Everything auditable. logFillable() covers the snapshot; the explicit
     * list adds back the chain columns that are deliberately not fillable —
     * "when was this served, by whom, and did the contractor confirm" is the
     * whole evidentiary content of a commencement notice.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('commencement_notices')
            ->logFillable()
            ->logOnly([
                'status', 'issued_by_id', 'issued_at', 'issued_late',
                'acknowledged_by_id', 'acknowledged_by_contractor_at',
                'acknowledgement_note', 'overdue_notified_at',
            ])
            // Money casts to a value object that JSON-encodes to {} — the raw
            // decimal string is what belongs in an audit record.
            ->useAttributeRawValues(['contract_sum'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected function casts(): array
    {
        return [
            'status' => CommencementNoticeStatus::class,
            'contract_sum' => MoneyCast::class,
            'duration_days' => 'integer',
            'commencement_date' => 'immutable_date',
            'expected_completion_date' => 'immutable_date',
            'due_at' => 'immutable_date',
            'issued_late' => 'boolean',
            'issued_at' => 'immutable_datetime',
            'acknowledged_by_contractor_at' => 'immutable_datetime',
            'overdue_notified_at' => 'immutable_datetime',
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

    /** @return BelongsTo<Contract, $this> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /** @return BelongsTo<Contractor, $this> */
    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    /** @return BelongsTo<User, $this> */
    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_id');
    }

    /** @return BelongsTo<User, $this> */
    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * The attribute snapshot a notice is built from — used by the issuing
     * Action and by the overdue sweep, which materialises a `pending` notice
     * for a contract that never got one. ONE definition, because a notice the
     * sweep created and a notice an officer created must describe the same
     * contract in the same words.
     *
     * `supervising_agency_name` prefers the project's named supervisor and
     * falls back to the workspace itself — both are DATA. Nothing here may
     * name a state, a ministry or a product (rules/architecture.md).
     *
     * @return array<string, mixed>
     */
    public static function snapshotOf(Contract $contract, Project $project, int $noticeDays): array
    {
        return [
            'project_id' => $project->id,
            'contract_id' => $contract->id,
            'contractor_id' => $contract->contractor_id,
            'scope_of_works' => $contract->scope_of_works,
            // The award sum as served. Money is a value object; the cast
            // writes the decimal string.
            'contract_sum' => $contract->sum,
            'duration_days' => $contract->duration_days,
            'commencement_date' => $contract->commencement_date,
            'expected_completion_date' => $contract->expected_completion_date,
            'supervising_agency_name' => $project->supervising_agency_name
                ?? $project->tenant?->name,
            'due_at' => $contract->award_date->addDays($noticeDays),
        ];
    }

    /** Whether the statutory window has passed with the notice still unserved. */
    public function isOverdue(?Carbon $asOf = null): bool
    {
        if ($this->status->isServed()) {
            return false;
        }

        return $this->due_at->endOfDay()->isBefore($asOf ?? now());
    }

    /**
     * Whole days past the deadline, negative while there is still time. Days
     * rather than hours because the statutory window is expressed in days and
     * an officer reads "4 days late", never "91 hours".
     */
    public function daysLate(?Carbon $asOf = null): int
    {
        return (int) $this->due_at->startOfDay()->diffInDays(
            ($asOf ?? now())->startOfDay(),
            false,
        );
    }

    /**
     * Notices a user may see: consultants and field monitors see only the
     * projects they are actively assigned to. Delegated to
     * Project::scopeVisibleTo — the single definition of project visibility —
     * so a policy, a list screen and an export can never disagree.
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
