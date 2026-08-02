<?php

declare(strict_types=1);

namespace App\Livewire\Tenant\Reporting;

use App\Models\ReportObligation;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Dashboard widget: what this workspace owes next, with the countdown.
 *
 * Deliberately includes deadlines that have ALREADY passed, sorted first. A
 * "next 30 days" widget that quietly drops the return you failed to file last
 * month is the one that lets an MDA reach the compliance board without
 * warning — the overdue rows are exactly the ones that need to be on a
 * dashboard.
 *
 * LAZY, and `visibleTo()`-scoped like every other reporting read.
 */
class UpcomingDeadlinesCard extends Component
{
    /** How far ahead the widget looks. Past-due rows are always included. */
    public const HORIZON_DAYS = 30;

    /** @return Collection<int, ReportObligation> */
    #[Computed]
    public function obligations(): Collection
    {
        /** @var User $user */
        $user = auth()->user();

        return ReportObligation::query()
            ->visibleTo($user)
            ->outstanding()
            ->where('due_at', '<=', now()->addDays(self::HORIZON_DAYS)->endOfDay())
            ->with(['project:id,ulid,title,reference', 'reportingPeriod:id,label'])
            ->orderBy('due_at')
            ->orderBy('id')
            ->limit(5)
            ->get();
    }

    public function placeholder(): string
    {
        return <<<'HTML'
        <div class="rounded-xl border border-line bg-surface-raised p-4">
            <div class="h-5 w-40 animate-pulse rounded bg-neutral-soft"></div>
            <div class="mt-4 space-y-3">
                <div class="h-4 w-full animate-pulse rounded bg-neutral-soft"></div>
                <div class="h-4 w-5/6 animate-pulse rounded bg-neutral-soft"></div>
                <div class="h-4 w-3/6 animate-pulse rounded bg-neutral-soft"></div>
            </div>
        </div>
        HTML;
    }

    public function render(): View
    {
        return view('livewire.tenant.reporting.upcoming-deadlines-card');
    }
}
