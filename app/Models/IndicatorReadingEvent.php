<?php

namespace App\Models;

use App\Enums\IndicatorReadingStatus;
use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\IndicatorReadingEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * The validation ledger of an indicator reading — tenant-owned and
 * APPEND-ONLY, the twin of ProgressReportEvent and for the same reason: the
 * reading's own *_by_id / *_at columns hold the CURRENT state, which cannot
 * express a figure rejected twice, and cannot answer "how long does
 * data-quality review take here" without a history it does not have.
 *
 * Written only by App\Actions\Indicators\TransitionIndicatorReadingStatus.
 * The model refuses updates and deletes outright.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $indicator_reading_id
 * @property IndicatorReadingStatus|null $from_status
 * @property IndicatorReadingStatus $to_status
 * @property int $actor_id
 * @property string|null $reason
 * @property CarbonImmutable $occurred_at
 */
#[Fillable(['indicator_reading_id', 'from_status', 'to_status', 'actor_id', 'reason', 'occurred_at'])]
class IndicatorReadingEvent extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<IndicatorReadingEventFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('indicator_reading_events is append-only — a validation step is corrected by recording another one.');
        });

        static::deleting(function (): never {
            throw new RuntimeException('indicator_reading_events is append-only — audit records are retained, never deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'from_status' => IndicatorReadingStatus::class,
            'to_status' => IndicatorReadingStatus::class,
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<IndicatorReading, $this> */
    public function indicatorReading(): BelongsTo
    {
        return $this->belongsTo(IndicatorReading::class);
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
