<?php

namespace App\Models;

use App\Enums\ConsolidationStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ConsolidationEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only ledger row for a consolidation's chain — GLOBAL, like its
 * parent, and written ONLY by
 * App\Actions\Consolidation\TransitionConsolidationStatus inside the same
 * transaction as the status write.
 *
 * No soft deletes and no update path anywhere in the codebase: "who compiled
 * these figures, who sent them up, who signed them" is the first question an
 * auditor asks about a state report (rules/security.md — the audit trail is
 * append-only).
 *
 * @property int $id
 * @property int $consolidated_report_id
 * @property ConsolidationStatus|null $from_status
 * @property ConsolidationStatus $to_status
 * @property int $actor_id
 * @property string|null $reason
 * @property CarbonImmutable $occurred_at
 */
#[Fillable(['consolidated_report_id', 'from_status', 'to_status', 'actor_id', 'reason', 'occurred_at'])]
class ConsolidationEvent extends Model
{
    /** @use HasFactory<ConsolidationEventFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'from_status' => ConsolidationStatus::class,
            'to_status' => ConsolidationStatus::class,
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<ConsolidatedReport, $this> */
    public function consolidatedReport(): BelongsTo
    {
        return $this->belongsTo(ConsolidatedReport::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
