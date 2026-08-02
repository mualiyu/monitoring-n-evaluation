<?php

namespace App\Actions\Reporting;

use App\Jobs\Reporting\NotifyReportObligationDueSoon;
use App\Models\ReportObligation;
use App\Models\Tenant;
use App\Support\SettingsRepository;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The reminder rung of the deadline engine (progress-reporting.md §3): tells
 * the people accountable for a project that a return is due in N days.
 *
 * IDEMPOTENCY IS STRUCTURAL, NOT DEFENSIVE. Every send is gated by the
 * monotonic `reminder_stage` counter on the obligation row, advanced inside
 * `DB::transaction(fn () => …lockForUpdate())` in the same statement batch as
 * the dispatch. A double cron run, an overlapping worker or a replayed job
 * cannot double-send, because the second attempt re-reads a stage that has
 * already advanced. There is no "have I sent this?" lookup table, and no
 * reliance on the queue being exactly-once — it is not.
 *
 * The ladder is configuration ([7, 3, 1] days by default). A stage is the
 * COUNT of rungs crossed, so an obligation that first becomes visible to the
 * sweep two days before its deadline jumps straight to the last rung and sends
 * once — rather than firing three reminders in one morning to catch up.
 */
class SendDeadlineReminders
{
    /**
     * @return int the number of reminders dispatched
     */
    public function __invoke(?CarbonImmutable $asOf = null): int
    {
        $asOf ??= CarbonImmutable::now();
        $current = app(CurrentTenant::class);
        $sent = 0;

        foreach (Tenant::query()->where('is_active', true)->cursor() as $tenant) {
            $sent += $current->runAs($tenant, fn (): int => $this->forTenant($asOf));
        }

        return $sent;
    }

    private function forTenant(CarbonImmutable $asOf): int
    {
        $ladder = $this->ladder();

        if ($ladder === []) {
            return 0;
        }

        $sent = 0;

        // Only obligations still outstanding and not yet past their deadline:
        // once a deadline passes the conversation is reporting:flag-overdue's,
        // and a "due in 1 day" notice about something already late is noise.
        $obligations = ReportObligation::query()
            ->outstanding()
            ->where('due_at', '>=', $asOf)
            ->where('due_at', '<=', $asOf->addDays($ladder[0])->endOfDay())
            ->orderBy('due_at')
            ->cursor();

        foreach ($obligations as $obligation) {
            $sent += $this->remind($obligation, $ladder, $asOf);
        }

        return $sent;
    }

    /**
     * @param  list<int>  $ladder  rungs in days-before-due, widest first
     */
    private function remind(ReportObligation $obligation, array $ladder, CarbonImmutable $asOf): int
    {
        // The model owns the definition of "days to due" — the reporting desk
        // renders its countdown from the same method, so a screen can never
        // disagree with the reminder it is about to receive.
        $stage = $this->stageFor($obligation->daysToDue($asOf), $ladder);

        if ($stage <= $obligation->reminder_stage) {
            return 0;
        }

        return DB::transaction(function () use ($obligation, $stage, $ladder): int {
            // Re-read under the lock: between the sweep's SELECT and here, a
            // concurrent worker may already have advanced the counter.
            $locked = ReportObligation::query()->lockForUpdate()->find($obligation->id);

            if ($locked === null || $stage <= $locked->reminder_stage) {
                return 0;
            }

            $locked->forceFill([
                'reminder_stage' => $stage,
                'reminder_last_sent_at' => now(),
            ])->save();

            NotifyReportObligationDueSoon::dispatch($locked->id, $ladder[$stage - 1]);

            return 1;
        });
    }

    /**
     * How many rungs of the ladder this deadline has crossed. Monotonic in
     * time, which is exactly what makes the counter a valid gate.
     *
     * @param  list<int>  $ladder
     */
    private function stageFor(int $daysToDue, array $ladder): int
    {
        $stage = 0;

        foreach ($ladder as $rung) {
            if ($daysToDue <= $rung) {
                $stage++;
            }
        }

        return $stage;
    }

    /**
     * @return list<int> rungs sorted widest-first, so stage N is always the
     *                   Nth-tightest rung
     */
    private function ladder(): array
    {
        $ladder = app(SettingsRepository::class)->ints('reporting', 'reminder_days_before', [7, 3, 1]);

        rsort($ladder);

        return array_values(array_unique($ladder));
    }
}
