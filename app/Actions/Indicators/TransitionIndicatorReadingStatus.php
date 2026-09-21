<?php

namespace App\Actions\Indicators;

use App\Enums\IndicatorReadingStatus;
use App\Exceptions\Indicators\IndicatorRuleViolation;
use App\Exceptions\Indicators\InvalidReadingTransition;
use App\Models\IndicatorReading;
use App\Models\IndicatorReadingEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Support\SettingsRepository;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * THE single writer of IndicatorReading::$status and of every chain stamp on
 * it. Nothing else in the codebase assigns those columns — which is why none
 * of them is fillable, why this class is greppable as the one chokepoint, and
 * why the separation guard below cannot be routed around by a form payload or
 * by a second entry path.
 *
 * Order is deliberate and fails closed at the cheapest question first:
 *   1. the chain table (an impossible move is impossible for everyone),
 *   2. authorization, per TARGET status — what a move costs is a property of
 *      where it lands,
 *   3. domain preconditions (a rejection carries a reason),
 *   4. the separation guard — the reviewer is never the person who recorded
 *      or filed the figure,
 *   5. the write + the typed ledger row, in one transaction, under a lock.
 *
 * CROSS-SURFACE BY CONSTRUCTION. Validation is an OVERSIGHT act on a
 * TENANT-OWNED record: the Data Quality Reviewer works a queue that spans
 * MDAs, with no tenant bound. Authorization therefore runs in the caller's
 * context (where a global permission is what answers for an oversight role),
 * and the WRITE runs inside CurrentTenant::runAs() on the reading's own
 * workspace — so tenant_id is filled by the trait, the ledger row lands in the
 * right MDA, and no tenancy bypass is needed anywhere outside the oversight
 * Action that does the cross-MDA reading.
 */
class TransitionIndicatorReadingStatus
{
    public function __invoke(
        IndicatorReading $reading,
        IndicatorReadingStatus $to,
        User $actor,
        ?string $reason = null,
    ): IndicatorReading {
        $from = $reading->status;

        if (! $from->canTransitionTo($to)) {
            throw InvalidReadingTransition::between($from, $to);
        }

        Gate::forUser($actor)->authorize($this->abilityFor($to), $reading);

        $reason = $reason === null ? null : trim($reason);

        $this->assertPreconditions($to, $reason);
        $this->assertSeparation($reading, $to, $actor);

        // The workspace that owns the figure is the authority on where the
        // write belongs — not whatever context the reviewer happened to open
        // the queue in. `tenant` is the tenants table, which carries no tenant
        // scope of its own, so this resolves from an unbound context too.
        /** @var Tenant $tenant a tenant-owned row always has one — the FK is non-nullable. */
        $tenant = $reading->loadMissing('tenant')->tenant;

        app(CurrentTenant::class)->runAs($tenant, function () use ($reading, $from, $to, $actor, $reason): void {
            DB::transaction(function () use ($reading, $from, $to, $actor, $reason): void {
                // Re-read under a lock, then re-ask the chain table: between
                // the guard above and this line another reviewer may have
                // cleared the same figure. Re-querying through the model
                // (never ->fresh(), which drops the TenantScope) keeps the
                // read inside this MDA.
                $locked = IndicatorReading::query()->lockForUpdate()->whereKey($reading->getKey())->firstOrFail();

                if ($locked->status !== $from) {
                    throw InvalidReadingTransition::between($locked->status, $to);
                }

                // forceFill: these columns are deliberately not fillable, so
                // the assignment is explicit and the chokepoint stays
                // greppable.
                $reading->forceFill([
                    ...$this->chainStamps($to, $actor, $reason),
                    'status' => $to,
                ])->save();

                $event = new IndicatorReadingEvent([
                    'indicator_reading_id' => $reading->id,
                    'from_status' => $from,
                    'to_status' => $to,
                    'actor_id' => $actor->id,
                    'reason' => $reason,
                    'occurred_at' => now(),
                ]);
                // Explicit property write (tenant_id is not fillable): the
                // reading itself is always the authority on which MDA this
                // belongs to.
                $event->tenant_id = $reading->tenant_id;
                $event->save();
            });
        });

        return $reading;
    }

    /**
     * Records the creation of a reading in the ledger, so the timeline starts
     * where the figure did rather than at its first move. Called by
     * RecordIndicatorReading inside its own transaction; it writes no status.
     */
    public function recordCreation(IndicatorReading $reading, User $actor): IndicatorReadingEvent
    {
        $event = new IndicatorReadingEvent([
            'indicator_reading_id' => $reading->id,
            'from_status' => null,
            'to_status' => $reading->status,
            'actor_id' => $actor->id,
            'reason' => null,
            'occurred_at' => now(),
        ]);

        $event->tenant_id = $reading->tenant_id;
        $event->save();

        return $event;
    }

    /**
     * Who signed for this step and when — the CURRENT chain state that lists
     * and the detail screen read. The history lives in
     * indicator_reading_events.
     *
     * A rejection clears the stamps of the hop it undoes: a figure sent back
     * to draft has not been validated, and leaving `validated_at` behind on a
     * row that is once again a draft is how a screen ends up claiming a
     * rejected number cleared review. The submission stamps survive, exactly
     * as a returned progress report keeps its.
     *
     * @return array<string, mixed>
     */
    private function chainStamps(IndicatorReadingStatus $to, User $actor, ?string $reason): array
    {
        $now = now();

        return match ($to) {
            IndicatorReadingStatus::Submitted => [
                'submitted_by_id' => $actor->id,
                'submitted_at' => $now,
                // A resubmission is a fresh answer to the rejection, so the
                // rejection stamps go: the reason has served its purpose and
                // is preserved in the ledger.
                'rejected_by_id' => null,
                'rejected_at' => null,
                'rejection_reason' => null,
            ],
            IndicatorReadingStatus::Validated => [
                'validated_by_id' => $actor->id,
                'validated_at' => $now,
            ],
            IndicatorReadingStatus::Published => [
                'published_by_id' => $actor->id,
                'published_at' => $now,
            ],
            // A rejection clears the VALIDATION it undoes and keeps the
            // submission stamps: who filed the figure last is still true, and
            // it is what the separation guard weighs when the corrected figure
            // comes back round.
            IndicatorReadingStatus::Draft => [
                'rejected_by_id' => $actor->id,
                'rejected_at' => $now,
                'rejection_reason' => $reason,
                'validated_by_id' => null,
                'validated_at' => null,
            ],
        };
    }

    /**
     * Authorization is per TARGET status, because what a move costs is a
     * property of where it lands: filing is the collector's act, validating is
     * the data-quality reviewer's, publishing is what makes a figure quotable
     * outside the platform.
     *
     * A rejection lands on `draft` and asks for `validate` — sending a figure
     * back is the reviewer's call whether it arrives from `submitted` or from
     * `validated`. Reading it as the author's own ability would let a
     * consultant un-validate the number that had just gone against them.
     */
    private function abilityFor(IndicatorReadingStatus $to): string
    {
        return match ($to) {
            IndicatorReadingStatus::Submitted => 'submit',
            IndicatorReadingStatus::Validated => 'validate',
            IndicatorReadingStatus::Published => 'publish',
            IndicatorReadingStatus::Draft => 'validate',
        };
    }

    private function assertPreconditions(IndicatorReadingStatus $to, ?string $reason): void
    {
        if ($to === IndicatorReadingStatus::Draft && ($reason === null || $reason === '')) {
            throw IndicatorRuleViolation::rejectionRequiresReason();
        }
    }

    /**
     * The guard the Data Quality Reviewer role exists for (plan §2, manual
     * digest §5): recording a figure is delivery work, validating one is
     * assurance, and the two never sit with the same person.
     *
     * Permissions alone do not achieve this. `indicators.readings.record` and
     * `.validate` are both seeded to SuperAdmin, and a StateAdmin holds
     * `.validate` while being perfectly able to enter a figure on an MDA's
     * behalf — so without this check the one separation the module exists to
     * enforce would hold for everyone except the people with the most
     * authority, which is precisely backwards.
     *
     * BOTH identities are weighed. An M&E officer can file a figure a field
     * monitor measured; a reviewer who happens to be that monitor would clear
     * their own measurement while passing a submitter-only check.
     */
    private function assertSeparation(IndicatorReading $reading, IndicatorReadingStatus $to, User $actor): void
    {
        if ($to === IndicatorReadingStatus::Validated
            && in_array($actor->id, $reading->originators(), true)) {
            throw IndicatorRuleViolation::validatorIsOriginator();
        }

        if ($to === IndicatorReadingStatus::Published
            && $reading->validated_by_id === $actor->id
            // Off by default: most states run a single secretariat desk where
            // the same officer validates and publishes. A state that wants the
            // second pair of eyes turns it on from a settings screen, without
            // a release.
            && app(SettingsRepository::class)->bool('indicators', 'require_separate_publisher', false)) {
            throw IndicatorRuleViolation::publisherIsValidator();
        }
    }
}
