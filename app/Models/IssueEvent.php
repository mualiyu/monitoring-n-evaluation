<?php

namespace App\Models;

use App\Enums\IssueStatus;
use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\IssueEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * The lifecycle ledger of an issue — tenant-owned and APPEND-ONLY, the twin of
 * ProgressReportEvent and for the same reason: the issue's own *_by_id/*_at
 * columns hold the current state, which cannot express an issue resolved,
 * reopened and resolved again, nor answer "how long does this MDA take to
 * clear an access dispute" without a history it does not have.
 *
 * Written only by App\Actions\Issues\TransitionIssueStatus. The model refuses
 * updates and deletes outright.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $issue_id
 * @property IssueStatus|null $from_status
 * @property IssueStatus $to_status
 * @property int|null $actor_id
 * @property string|null $reason
 * @property CarbonImmutable $occurred_at
 */
#[Fillable(['issue_id', 'from_status', 'to_status', 'actor_id', 'reason', 'occurred_at'])]
class IssueEvent extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<IssueEventFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('issue_events is append-only — a step is corrected by recording another one.');
        });

        static::deleting(function (): never {
            throw new RuntimeException('issue_events is append-only — audit records are retained, never deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'from_status' => IssueStatus::class,
            'to_status' => IssueStatus::class,
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Issue, $this> */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** The raise itself has no origin status. */
    public function isCreation(): bool
    {
        return $this->from_status === null;
    }

    /**
     * An escalation raised by the threshold engine, which has no human actor.
     * The timeline renders these as "the system", rather than attributing a
     * machine judgement to a person who was not consulted.
     */
    public function isSystemAction(): bool
    {
        return $this->actor_id === null;
    }
}
