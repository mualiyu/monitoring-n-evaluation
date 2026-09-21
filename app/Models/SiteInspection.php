<?php

namespace App\Models;

use App\Enums\InspectionOutcome;
use App\Enums\InspectionStatus;
use App\Enums\InspectionType;
use App\Enums\Role;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocuments;
use Carbon\CarbonImmutable;
use Database\Factories\SiteInspectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\MediaLibrary\HasMedia;

/**
 * One site visit — tenant-owned. The Nasarawa BPP monitoring step and the
 * manual's Field Trip Report in one record (digest §3, §8).
 *
 * GUARDED BY OMISSION, exactly as Project and ProgressReport are. `status` is
 * written ONLY by App\Actions\Inspections\TransitionInspectionStatus, and with
 * it the entire chain (`started_at`, `submitted_*`, `reviewed_*`,
 * `cancelled_*`, `report_late`). The GPS fix and its geofence judgement are
 * written only by RecordInspectionPosition inside SaveInspectionFieldNotes;
 * `report_due_at` is snapshotted at the visit so a later policy change cannot
 * retroactively make a filed report late. None of them is fillable, so no
 * ->update($validated) can reach them.
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property int $project_id
 * @property int|null $project_location_id
 * @property int|null $inspection_checklist_template_id
 * @property InspectionType $type
 * @property InspectionStatus $status
 * @property CarbonImmutable $scheduled_date
 * @property CarbonImmutable|null $conducted_at
 * @property int $lead_inspector_id
 * @property string|null $team
 * @property string|null $latitude
 * @property string|null $longitude
 * @property int|null $gps_accuracy_metres
 * @property CarbonImmutable|null $gps_captured_at
 * @property int|null $geofence_distance_metres
 * @property bool $geofence_breached
 * @property string|null $physical_progress_observed
 * @property InspectionOutcome|null $outcome
 * @property list<string>|null $risk_flags
 * @property string|null $objectives
 * @property string|null $people_met
 * @property string|null $methods
 * @property string|null $findings
 * @property string|null $comparison_with_previous
 * @property string|null $conclusions
 * @property string|null $recommendations
 * @property int|null $scheduled_by_id
 * @property CarbonImmutable|null $started_at
 * @property int|null $submitted_by_id
 * @property CarbonImmutable|null $submitted_at
 * @property int|null $reviewed_by_id
 * @property CarbonImmutable|null $reviewed_at
 * @property string|null $review_notes
 * @property int|null $cancelled_by_id
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $cancellation_reason
 * @property CarbonImmutable|null $report_due_at
 * @property CarbonImmutable|null $report_overdue_notified_at
 * @property bool $report_late
 * @property CarbonImmutable|null $autosaved_at
 * @property string $generated_by
 * @property string|null $schedule_key
 */
#[Fillable([
    'project_id', 'project_location_id', 'inspection_checklist_template_id',
    'type', 'scheduled_date', 'lead_inspector_id', 'team',
    'physical_progress_observed', 'outcome', 'risk_flags',
    'objectives', 'people_met', 'methods', 'findings',
    'comparison_with_previous', 'conclusions', 'recommendations',
    'scheduled_by_id',
])]
class SiteInspection extends Model implements HasMedia
{
    use BelongsToTenant;
    use HasDocuments;

    /** @use HasFactory<SiteInspectionFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    /**
     * The evidence vault. Photographs carry EXIF GPS and capture time, lifted
     * into custom properties by AttachDocument — which is why the conduct
     * screen never re-extracts them and the detail screen can show where a
     * photograph was actually taken, independently of the fix the inspector's
     * browser reported.
     *
     * @return list<string>
     */
    public function documentCollections(): array
    {
        return ['inspection_photos', 'inspection_documents'];
    }

    protected static function booted(): void
    {
        static::creating(function (SiteInspection $inspection): void {
            $inspection->ulid ??= (string) Str::ulid();
        });
    }

    /**
     * Everything auditable. logFillable() covers what the inspector typed;
     * the explicit list adds back the chain and the evidence stamps that are
     * deliberately not fillable — "who filed this, when, and where were they
     * standing" is exactly what an auditor asks of a site visit.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('inspections')
            ->logFillable()
            ->logOnly([
                'status', 'conducted_at', 'started_at', 'submitted_by_id', 'submitted_at',
                'reviewed_by_id', 'reviewed_at', 'review_notes', 'cancelled_by_id',
                'cancelled_at', 'cancellation_reason', 'latitude', 'longitude',
                'gps_captured_at', 'geofence_distance_metres', 'geofence_breached',
                'report_due_at', 'report_late',
            ])
            // Coordinates and the observed percentage are decimal casts; the
            // raw string is what belongs in an audit record.
            ->useAttributeRawValues(['latitude', 'longitude', 'physical_progress_observed'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected function casts(): array
    {
        return [
            'type' => InspectionType::class,
            'status' => InspectionStatus::class,
            'outcome' => InspectionOutcome::class,
            'scheduled_date' => 'immutable_date',
            'conducted_at' => 'immutable_datetime',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'gps_captured_at' => 'immutable_datetime',
            'geofence_breached' => 'boolean',
            'physical_progress_observed' => 'decimal:2',
            'risk_flags' => 'array',
            'started_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'report_due_at' => 'immutable_datetime',
            'report_overdue_notified_at' => 'immutable_datetime',
            'report_late' => 'boolean',
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

    /** @return BelongsTo<ProjectLocation, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(ProjectLocation::class, 'project_location_id');
    }

    /** @return BelongsTo<InspectionChecklistTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(InspectionChecklistTemplate::class, 'inspection_checklist_template_id');
    }

    /** @return BelongsTo<User, $this> */
    public function leadInspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lead_inspector_id');
    }

    /** @return BelongsTo<User, $this> */
    public function scheduledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scheduled_by_id');
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
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_id');
    }

    /** @return HasMany<SiteInspectionResponse, $this> */
    public function responses(): HasMany
    {
        return $this->hasMany(SiteInspectionResponse::class);
    }

    /** @return HasMany<SiteInspectionEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(SiteInspectionEvent::class);
    }

    /** Whether the inspector may still edit findings, checklist and evidence. */
    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    /**
     * The visit immediately before this one on the same project — the manual's
     * section 5, "comparison with earlier visits". Ordered by when the
     * inspector was actually on site, falling back to the diary date for a
     * visit that has not happened yet.
     */
    public function previousOnProject(): ?self
    {
        return static::query()
            ->where('project_id', $this->project_id)
            ->whereKeyNot($this->getKey())
            ->whereIn('status', [InspectionStatus::Submitted, InspectionStatus::Reviewed])
            ->where('scheduled_date', '<=', $this->scheduled_date)
            ->orderByDesc('conducted_at')
            ->orderByDesc('scheduled_date')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Whether the Field Trip Report is past its deadline. Derived, never
     * stored as a status: an overdue report is an outstanding one that has
     * passed a date, and a column would need a cron to stay true.
     */
    public function isReportOverdue(?Carbon $asOf = null): bool
    {
        if ($this->report_due_at === null || $this->status->isFiled()) {
            return false;
        }

        return $this->report_due_at->isBefore($asOf ?? Carbon::now());
    }

    /** Whether the visit is in the diary and its date has passed. */
    public function isVisitOverdue(?Carbon $asOf = null): bool
    {
        return $this->status === InspectionStatus::Scheduled
            && $this->scheduled_date->isBefore(($asOf ?? Carbon::now())->startOfDay());
    }

    /** Whether the platform proposed this visit rather than an officer. */
    public function isProposed(): bool
    {
        return $this->generated_by === 'system';
    }

    /**
     * Open inspections — still expected to happen.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [InspectionStatus::Scheduled, InspectionStatus::InProgress]);
    }

    /**
     * Inspections a user may see. Delegated to Project::scopeVisibleTo — the
     * single definition of "which projects may this user see" — so a policy, a
     * list screen and an export can never disagree about a single row.
     *
     * A FieldMonitor is additionally shown any visit they personally lead,
     * which is how an inspector sent to verify a project they are not on the
     * assignment list for still finds their own diary. The union is expressed
     * as an OR on the same query rather than as a second list.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $projectLevelOnly = ($user->hasRole(Role::Consultant->value) || $user->hasRole(Role::FieldMonitor->value))
            && ! $user->hasRole(Role::MdaAdmin->value)
            && ! $user->hasRole(Role::MeOfficer->value);

        return $query->when($projectLevelOnly, fn (Builder $q) => $q
            ->where(fn (Builder $scoped) => $scoped
                ->whereIn('project_id', Project::query()->visibleTo($user)->select('id'))
                ->orWhere('lead_inspector_id', $user->id)));
    }
}
