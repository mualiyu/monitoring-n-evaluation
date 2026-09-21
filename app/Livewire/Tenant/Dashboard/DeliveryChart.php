<?php

declare(strict_types=1);

namespace App\Livewire\Tenant\Dashboard;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\User;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Where this workspace's register actually sits — one bar per lifecycle state.
 *
 * A horizontal bar, not a pie: "awarded" against "in progress" against
 * "completed" are values a director compares, and comparing close angles is
 * what a pie is worst at. One series, one colour: the shape of the register is
 * the story, not which colour a status happens to wear.
 *
 * Scoped by visibleTo() like every other read, so a consultant sees the shape
 * of their own assignments.
 */
class DeliveryChart extends Component
{
    /**
     * Statuses in LIFECYCLE order, not by size: the chart is read as a pipeline
     * left to right, and sorting by count would reshuffle it every time a
     * project moved.
     *
     * @return list<array{label: string, value: int}>
     */
    #[Computed]
    public function rows(): array
    {
        /** @var User $user */
        $user = auth()->user();

        /** @var array<string, int> $counts */
        $counts = Project::query()
            ->visibleTo($user)
            ->toBase()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->all();

        $rows = [];

        foreach (ProjectStatus::cases() as $status) {
            $count = (int) ($counts[$status->value] ?? 0);

            // A status no project has ever reached is noise on the axis; a
            // status that emptied out still matters, but there is nothing to
            // distinguish the two here, so zero rows are dropped.
            if ($count > 0) {
                $rows[] = ['label' => $status->label(), 'value' => $count];
            }
        }

        return $rows;
    }

    public function placeholder(): string
    {
        return <<<'HTML'
        <div class="h-72 animate-pulse rounded-xl border border-line bg-surface-raised"></div>
        HTML;
    }

    public function render(): View
    {
        return view('livewire.tenant.dashboard.delivery-chart');
    }
}
