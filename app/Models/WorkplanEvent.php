<?php

namespace App\Models;

use App\Enums\WorkplanStatus;
use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\WorkplanEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * The approval-chain ledger of a work plan — tenant-owned and APPEND-ONLY,
 * the same pattern as ProgressReportEvent and ProjectStatusEvent.
 *
 * Written only by App\Actions\Workplans\TransitionWorkplanStatus. The model
 * refuses updates and deletes outright: a chain step is corrected by
 * recording another one, never by editing the record of the first.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $workplan_id
 * @property WorkplanStatus|null $from_status
 * @property WorkplanStatus $to_status
 * @property int $actor_id
 * @property string|null $reason
 * @property CarbonImmutable $occurred_at
 */
#[Fillable(['workplan_id', 'from_status', 'to_status', 'actor_id', 'reason', 'occurred_at'])]
class WorkplanEvent extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<WorkplanEventFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('workplan_events is append-only — a chain step is corrected by recording another one.');
        });

        static::deleting(function (): never {
            throw new RuntimeException('workplan_events is append-only — audit records are retained, never deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'from_status' => WorkplanStatus::class,
            'to_status' => WorkplanStatus::class,
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Workplan, $this> */
    public function workplan(): BelongsTo
    {
        return $this->belongsTo(Workplan::class);
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
