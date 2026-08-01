<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\ProjectStatusEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * The typed transition ledger — tenant-owned and APPEND-ONLY. Activitylog
 * records *that* something changed, generically; this table is what makes
 * "average days from award to mobilization per MDA" one indexed query.
 *
 * Written only by App\Actions\Projects\TransitionProjectStatus. The model
 * refuses updates and deletes outright: an audit trail a feature can edit is
 * not an audit trail.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $project_id
 * @property ProjectStatus|null $from_status
 * @property ProjectStatus $to_status
 * @property int $actor_id
 * @property string|null $reason
 * @property CarbonImmutable $occurred_at
 */
#[Fillable(['project_id', 'from_status', 'to_status', 'actor_id', 'reason', 'occurred_at'])]
class ProjectStatusEvent extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ProjectStatusEventFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('project_status_events is append-only — a transition is corrected by recording another one.');
        });

        static::deleting(function (): never {
            throw new RuntimeException('project_status_events is append-only — audit records are retained, never deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'from_status' => ProjectStatus::class,
            'to_status' => ProjectStatus::class,
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** Creation events have no origin status. */
    public function isCreation(): bool
    {
        return $this->from_status === null;
    }
}
