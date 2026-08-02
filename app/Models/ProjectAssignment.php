<?php

namespace App\Models;

use App\Enums\ProjectRole;
use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\ProjectAssignmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Who is accountable for a project, in what capacity — tenant-owned. This is
 * the row Consultant/FieldMonitor visibility hangs off (Project::visibleTo).
 *
 * Unassignment sets `unassigned_at` and keeps the row; re-assignment clears it
 * again, so the (project, user, role) unique key stays meaningful and the
 * history is never rewritten.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $project_id
 * @property int $user_id
 * @property ProjectRole $role
 * @property int $assigned_by_id
 * @property CarbonImmutable $assigned_at
 * @property CarbonImmutable|null $unassigned_at
 */
#[Fillable(['project_id', 'user_id', 'role', 'assigned_by_id', 'assigned_at', 'unassigned_at'])]
class ProjectAssignment extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ProjectAssignmentFactory> */
    use HasFactory, LogsActivity;

    /**
     * Everything auditable (rules/architecture.md). "Who was accountable for
     * this site in March, and who took them off" is the question an audit
     * actually asks — `assigned_by_id` answers the first half and the log's
     * causer answers the second (UnassignProjectMember writes it explicitly,
     * because a queue worker or a console caller has no authenticated user).
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('project_assignments')
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected function casts(): array
    {
        return [
            'role' => ProjectRole::class,
            'assigned_at' => 'immutable_datetime',
            'unassigned_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_id');
    }

    public function isActive(): bool
    {
        return $this->unassigned_at === null;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('unassigned_at');
    }
}
