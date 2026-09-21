<?php

namespace App\Models;

use App\Enums\InspectionStatus;
use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\SiteInspectionEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * The lifecycle ledger of a site inspection — tenant-owned and APPEND-ONLY,
 * the exact twin of ProgressReportEvent and for the same reason: the
 * inspection's own *_by_id/*_at columns hold the current state, which cannot
 * express a visit rescheduled twice, nor answer "how long does this MDA take
 * between a site visit and its report" without a history it does not have.
 *
 * Written only by App\Actions\Inspections\TransitionInspectionStatus. The
 * model refuses updates and deletes outright.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $site_inspection_id
 * @property InspectionStatus|null $from_status
 * @property InspectionStatus $to_status
 * @property int $actor_id
 * @property string|null $reason
 * @property CarbonImmutable $occurred_at
 */
#[Fillable(['site_inspection_id', 'from_status', 'to_status', 'actor_id', 'reason', 'occurred_at'])]
class SiteInspectionEvent extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<SiteInspectionEventFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('site_inspection_events is append-only — a lifecycle step is corrected by recording another one.');
        });

        static::deleting(function (): never {
            throw new RuntimeException('site_inspection_events is append-only — audit records are retained, never deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'from_status' => InspectionStatus::class,
            'to_status' => InspectionStatus::class,
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<SiteInspection, $this> */
    public function inspection(): BelongsTo
    {
        return $this->belongsTo(SiteInspection::class, 'site_inspection_id');
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
