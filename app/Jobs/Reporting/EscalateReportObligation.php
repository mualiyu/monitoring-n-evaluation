<?php

namespace App\Jobs\Reporting;

use App\Enums\Role;
use App\Jobs\Concerns\TenantAware;
use App\Models\ReportObligation;
use App\Models\User;
use App\Notifications\Reporting\ReportObligationEscalated;
use App\Tenancy\CurrentTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;

/**
 * Carries a silent obligation up the chain (progress-reporting.md §3):
 * stage 1 → the MDA admin, stage 2 and beyond → state oversight.
 *
 * The two audiences live in different permission TEAMS, and that is the whole
 * subtlety here. An MDA admin holds their role in the tenant team, which
 * SetTenantContext has bound; a state administrator holds theirs in the global
 * team, where no tenant is bound at all. Reading the second group from inside
 * the tenant context would return nobody, silently — so it is read inside
 * runWithoutTenant(), which is a permission-team switch and NOT a tenancy
 * bypass: `users` is not a tenant-scoped table.
 *
 * IDS, NOT MODELS: see NotifyReportObligationDueSoon.
 */
class EscalateReportObligation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TenantAware;

    public function __construct(
        private readonly int $obligationId,
        private readonly int $stage,
    ) {
        $this->captureTenant();
    }

    public function handle(): void
    {
        $obligation = ReportObligation::query()
            ->with(['project.tenant', 'reportingPeriod', 'tenant'])
            ->find($this->obligationId);

        if ($obligation === null) {
            return;
        }

        $recipients = $this->stage <= 1 ? $this->mdaAdmins() : $this->stateAdmins();

        if ($recipients->isEmpty()) {
            return;
        }

        $daysLate = (int) $obligation->due_at->startOfDay()->diffInDays(now()->startOfDay(), false);

        Notification::send($recipients, new ReportObligationEscalated($obligation, $this->stage, $daysLate));
    }

    /**
     * @return Collection<int, User>
     */
    private function mdaAdmins(): Collection
    {
        return User::query()
            ->role(Role::MdaAdmin->value)
            ->where('is_active', true)
            ->get();
    }

    /**
     * @return Collection<int, User>
     */
    private function stateAdmins(): Collection
    {
        return app(CurrentTenant::class)->runWithoutTenant(
            fn (): Collection => User::query()
                ->role([Role::StateAdmin->value, Role::SuperAdmin->value])
                ->where('is_active', true)
                ->get(),
        );
    }
}
