<?php

namespace App\Actions\Issues;

use App\Enums\IssueSeverity;
use App\Enums\IssueStatus;
use App\Models\Issue;
use App\Models\Tenant;
use App\Support\SettingsRepository;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The escalation rung of the challenges register: an obstruction nobody has
 * cleared within `platform.exceptions.issue_escalation_days[severity]` becomes
 * a director's problem, without anyone having to run a report.
 *
 * The ladder is keyed on SEVERITY, not on a single number, because that is the
 * only version that survives contact with a real register: three days for a
 * critical issue and sixty for a low one means the critical ones surface while
 * they still matter and the low ones do not drown them.
 *
 * ONE GATE, structural and monotonic: `issues.escalated_at`, advanced under a
 * row lock in the same transaction as the transition. A director who has been
 * told once is not told again nightly — which is the difference between an
 * escalation and a nuisance.
 *
 * The clock runs from `created_at`: "how long has this been known", not "how
 * long since somebody last touched it". An issue that is acknowledged, given a
 * corrective action and then forgotten is exactly the case the ladder exists
 * for, and a last-touched clock would reset on every one of those touches.
 */
class EscalateStaleIssues
{
    public function __invoke(?CarbonImmutable $asOf = null): int
    {
        $asOf ??= CarbonImmutable::now();
        $current = app(CurrentTenant::class);
        $escalated = 0;

        foreach (Tenant::query()->where('is_active', true)->cursor() as $tenant) {
            $escalated += $current->runAs($tenant, fn (): int => $this->forTenant($asOf));
        }

        return $escalated;
    }

    private function forTenant(CarbonImmutable $asOf): int
    {
        $ladder = $this->ladder();
        $transition = app(TransitionIssueStatus::class);
        $escalated = 0;

        $issues = Issue::query()
            ->escalatable()
            ->orderBy('created_at')
            ->orderBy('id')
            ->cursor();

        foreach ($issues as $issue) {
            $allowance = $ladder[$issue->severity->value] ?? null;

            if ($allowance === null) {
                continue;
            }

            $daysOpen = (int) CarbonImmutable::instance($issue->created_at)
                ->startOfDay()
                ->diffInDays($asOf->startOfDay(), false);

            if ($daysOpen < $allowance) {
                continue;
            }

            $escalated += $this->escalate($issue, $transition, $daysOpen, $allowance);
        }

        return $escalated;
    }

    /**
     * The gate and the write, under one lock. Re-reading inside the
     * transaction is not belt-and-braces: the sweep's `cursor()` streams rows
     * that were read before this transaction opened, so by the time a given
     * issue is reached an officer may already have resolved it — or a second,
     * overlapping sweep may already have escalated it.
     */
    private function escalate(Issue $issue, TransitionIssueStatus $transition, int $daysOpen, int $allowance): int
    {
        return DB::transaction(function () use ($issue, $transition, $daysOpen, $allowance): int {
            $locked = Issue::query()->lockForUpdate()->find($issue->getKey());

            if ($locked === null
                || $locked->escalated_at !== null
                || ! $locked->status->isEscalatable()) {
                return 0;
            }

            $transition(
                $locked,
                IssueStatus::Escalated,
                // No actor: nobody escalated this, the absence of action did.
                null,
                __(
                    'Escalated automatically: open for :days day(s) against a :severity allowance of :allowance day(s).',
                    [
                        'days' => $daysOpen,
                        'severity' => $locked->severity->value,
                        'allowance' => $allowance,
                    ],
                ),
            );

            return 1;
        });
    }

    /**
     * severity => days, from the settings chain (tenant override → instance
     * setting → config default).
     *
     * SettingsRepository has no map helper — the reporting ladders are flat
     * lists — so the shape is validated here: an override that is not a
     * severity-keyed map of whole numbers falls back to the config default
     * rather than silencing the ladder. A malformed setting that quietly
     * stopped every escalation would be invisible until the first audit.
     *
     * @return array<string, int>
     */
    private function ladder(): array
    {
        /** @var array<string, int> $default */
        $default = config('platform.exceptions.issue_escalation_days', []);

        $configured = app(SettingsRepository::class)->get('exceptions', 'issue_escalation_days', $default);

        if (! is_array($configured)) {
            return $this->normalise($default);
        }

        $ladder = [];

        foreach (IssueSeverity::cases() as $severity) {
            $days = $configured[$severity->value] ?? null;

            if (! is_numeric($days)) {
                return $this->normalise($default);
            }

            $ladder[$severity->value] = (int) $days;
        }

        return $ladder;
    }

    /**
     * @param  array<array-key, mixed>  $default
     * @return array<string, int>
     */
    private function normalise(array $default): array
    {
        $ladder = [];

        foreach (IssueSeverity::cases() as $severity) {
            $days = $default[$severity->value] ?? null;

            if (is_numeric($days)) {
                $ladder[$severity->value] = (int) $days;
            }
        }

        return $ladder;
    }
}
