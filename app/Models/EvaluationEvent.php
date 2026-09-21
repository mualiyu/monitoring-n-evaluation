<?php

namespace App\Models;

use App\Enums\EvaluationStatus;
use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\EvaluationEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * The lifecycle ledger of an evaluation — tenant-owned and APPEND-ONLY, the
 * twin of ProjectStatusEvent and ProgressReportEvent for the same reason: the
 * evaluation's own *_by_id/*_at columns hold the CURRENT state, which cannot
 * express a draft sent back twice, and cannot answer "how long did this sit
 * with the approver" without a history it does not have.
 *
 * Written only by App\Actions\Evaluation\TransitionEvaluationStatus. The model
 * refuses updates and deletes outright.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $evaluation_id
 * @property EvaluationStatus|null $from_status
 * @property EvaluationStatus $to_status
 * @property int $actor_id
 * @property string|null $reason
 * @property CarbonImmutable $occurred_at
 */
#[Fillable(['evaluation_id', 'from_status', 'to_status', 'actor_id', 'reason', 'occurred_at'])]
class EvaluationEvent extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<EvaluationEventFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('evaluation_events is append-only — a lifecycle step is corrected by recording another one.');
        });

        static::deleting(function (): never {
            throw new RuntimeException('evaluation_events is append-only — audit records are retained, never deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'from_status' => EvaluationStatus::class,
            'to_status' => EvaluationStatus::class,
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Evaluation, $this> */
    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(Evaluation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** Commissioning events have no origin status. */
    public function isCommissioning(): bool
    {
        return $this->from_status === null;
    }
}
