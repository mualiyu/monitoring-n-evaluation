<?php

declare(strict_types=1);

namespace App\Livewire\Tenant\Reporting;

use App\Models\ProgressReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Dashboard widget: the last five returns filed in this workspace.
 *
 * LAZY (`<livewire:… lazy />`) because a dashboard's job is to paint fast: the
 * KPI row and the shell arrive immediately, and the two list widgets fill in
 * behind a skeleton rather than holding the whole page on their queries.
 *
 * `visibleTo()` again, so a consultant opening the dashboard sees their own
 * returns and a director sees the workspace's — one definition of visibility,
 * used by the desk, the policy and this card alike.
 */
class RecentReportsCard extends Component
{
    /** @return Collection<int, ProgressReport> */
    #[Computed]
    public function reports(): Collection
    {
        /** @var User $user */
        $user = auth()->user();

        return ProgressReport::query()
            ->visibleTo($user)
            ->with(['project:id,ulid,title,reference', 'reportingPeriod:id,label'])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get();
    }

    public function placeholder(): string
    {
        return <<<'HTML'
        <div class="rounded-xl border border-line bg-surface-raised p-4">
            <div class="h-5 w-48 animate-pulse rounded bg-neutral-soft"></div>
            <div class="mt-4 space-y-3">
                <div class="h-4 w-full animate-pulse rounded bg-neutral-soft"></div>
                <div class="h-4 w-5/6 animate-pulse rounded bg-neutral-soft"></div>
                <div class="h-4 w-4/6 animate-pulse rounded bg-neutral-soft"></div>
            </div>
        </div>
        HTML;
    }

    public function render(): View
    {
        return view('livewire.tenant.reporting.recent-reports-card');
    }
}
