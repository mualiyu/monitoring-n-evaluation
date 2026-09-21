<?php

namespace App\Actions\Evaluation;

use App\Jobs\Evaluation\NotifyRecommendationOverdue;
use App\Models\Recommendation;
use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The follow-up register's nightly conscience: a recommendation past its due
 * date and still outstanding is announced once — to the person who owns it and
 * to the MDA admin who is accountable for the register.
 *
 * This sweep is what turns the recommendations table from a list into a loop.
 * The manual's knowledge-management step (digest §7.9) asks that
 * recommendations feed decisions; nothing feeds a decision if nobody is told
 * it has been ignored for three months.
 *
 * IDEMPOTENCY IS STRUCTURAL, NOT DEFENSIVE. `overdue_flagged_at` is a
 * null-check gate advanced under the SAME row lock as the dispatch, in one
 * transaction. A double cron run, an overlapping worker or a replayed job
 * cannot double-send, because the second attempt reads a stamp that is already
 * set. There is no "have I sent this?" lookup table and no need for one.
 *
 * Closed rows are silent: outstanding() filters on the three live statuses, so
 * a recommendation someone declined with a reason does not keep nagging them.
 */
class FlagOverdueRecommendations
{
    /**
     * @return array{flagged: int}
     */
    public function __invoke(?CarbonImmutable $asOf = null): array
    {
        $asOf ??= CarbonImmutable::now();
        $current = app(CurrentTenant::class);
        $flagged = 0;

        // Per tenant via runAs(), so the existing queue payload carries
        // tenancy into every notification — the mail then renders with the
        // right MDA's branding and terminology, and the fail-closed scope has
        // a tenant to scope to.
        foreach (Tenant::query()->where('is_active', true)->cursor() as $tenant) {
            $flagged += $current->runAs($tenant, fn (): int => $this->forTenant($asOf));
        }

        return ['flagged' => $flagged];
    }

    private function forTenant(CarbonImmutable $asOf): int
    {
        $flagged = 0;

        $recommendations = Recommendation::query()
            ->overdue($asOf)
            ->whereNull('overdue_flagged_at')
            ->orderBy('due_on')
            ->cursor();

        foreach ($recommendations as $recommendation) {
            $flagged += $this->flag($recommendation);
        }

        return $flagged;
    }

    /** The "this is late" notice — exactly once per recommendation, ever. */
    private function flag(Recommendation $recommendation): int
    {
        return DB::transaction(function () use ($recommendation): int {
            $locked = Recommendation::query()->lockForUpdate()->find($recommendation->id);

            if ($locked === null || $locked->overdue_flagged_at !== null) {
                return 0;
            }

            // Re-checked under the lock: a recommendation implemented between
            // the cursor reading it and this transaction opening is no longer
            // late, and telling its addressee otherwise is how people learn to
            // ignore this channel.
            if (! $locked->isOutstanding()) {
                return 0;
            }

            // forceFill: the flag is deliberately not fillable, so this sweep
            // is its only writer.
            $locked->forceFill(['overdue_flagged_at' => now()])->save();

            NotifyRecommendationOverdue::dispatch($locked->id);

            return 1;
        });
    }
}
