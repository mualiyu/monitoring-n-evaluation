<?php

namespace App\Actions\Issues;

use App\Enums\ExceptionStatus;
use App\Exceptions\Issues\InvalidExceptionTransition;
use App\Exceptions\Issues\IssueRuleViolation;
use App\Models\ExceptionReport;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * THE single writer of ExceptionReport::$status and of its chain
 * (`acknowledged_*`, `resolved_*`, `resolution_note`). Same shape and same
 * order as TransitionIssueStatus — chain table, authorization, preconditions,
 * then the write under a row lock — because a second writer with a slightly
 * different order is exactly how the two registers would drift apart.
 *
 * Resolving an exception report means "the deviation no longer stands", not
 * "somebody is working on it". The working-on-it record is an Issue, which is
 * why the note is mandatory: a report closed with no account of why the
 * deviation went away is indistinguishable from one closed to clear a board.
 */
class TransitionExceptionStatus
{
    public function __invoke(
        ExceptionReport $report,
        ExceptionStatus $to,
        User $actor,
        ?string $reason = null,
    ): ExceptionReport {
        $from = $report->status;

        if (! $from->canTransitionTo($to)) {
            throw InvalidExceptionTransition::between($from, $to);
        }

        Gate::forUser($actor)->authorize($this->abilityFor($to), $report);

        $reason = $reason === null ? null : trim($reason);

        if ($to === ExceptionStatus::Resolved && ($reason === null || $reason === '')) {
            throw IssueRuleViolation::exceptionResolutionRequired();
        }

        DB::transaction(function () use ($report, $from, $to, $actor, $reason): void {
            $locked = ExceptionReport::query()->lockForUpdate()->find($report->getKey());

            if ($locked === null || $locked->status !== $from) {
                throw InvalidExceptionTransition::between($locked?->status ?? $from, $to);
            }

            $changes = [
                ...$this->chainStamps($to, $actor, $reason),
                'status' => $to,
            ];

            $locked->forceFill($changes)->save();

            // The caller's instance follows the row, so a screen re-rendering
            // straight after the decision shows the new state.
            $report->forceFill($changes)->syncOriginal();
        });

        return $report;
    }

    /**
     * Acknowledging is saying "seen, and it is real" — the same authority that
     * may resolve it, because an exception report nobody may act on is a
     * notice with no addressee. Both sit on `exceptions.resolve`, which the
     * matrix gives to MDA staff and not to the field roles who may raise one.
     */
    private function abilityFor(ExceptionStatus $to): string
    {
        return match ($to) {
            ExceptionStatus::Acknowledged => 'acknowledge',
            ExceptionStatus::Resolved => 'resolve',
            ExceptionStatus::Open => 'view',
        };
    }

    /** @return array<string, mixed> */
    private function chainStamps(ExceptionStatus $to, User $actor, ?string $reason): array
    {
        $now = now();

        return match ($to) {
            ExceptionStatus::Acknowledged => [
                'acknowledged_by_id' => $actor->id,
                'acknowledged_at' => $now,
            ],
            ExceptionStatus::Resolved => [
                'resolved_by_id' => $actor->id,
                'resolved_at' => $now,
                'resolution_note' => $reason,
            ],
            ExceptionStatus::Open => [],
        };
    }
}
