<?php

declare(strict_types=1);

namespace App\Livewire\Oversight\Dashboard;

use App\Actions\Oversight\BuildPortfolioSummary;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * How the state portfolio is distributed across the entities delivering it.
 *
 * Horizontal bars because MDA names are long, sorted by size because "who is
 * carrying the most" is the question — this is a ranking, not a pipeline, so
 * ordering by value is the meaning rather than a distortion of it.
 *
 * The cross-MDA read goes through the Oversight Action, which re-checks
 * `oversight.portfolio.view` in the GLOBAL permission team before bypassing
 * tenancy. A role without that authority gets an empty chart, not an
 * exception — this is the landing page, and the screens behind it refuse.
 */
class PortfolioChart extends Component
{
    /** How many entities are plotted before the tail is folded into "Other". */
    private const MAX_BARS = 8;

    /**
     * @return list<array{label: string, value: int}>
     */
    #[Computed]
    public function rows(): array
    {
        /** @var User $user */
        $user = auth()->user();

        try {
            $summary = (new BuildPortfolioSummary)($user);
        } catch (AuthorizationException) {
            return [];
        }

        $entities = collect($summary['tenants'])
            ->filter(fn (array $row): bool => (int) ($row['project_count'] ?? 0) > 0)
            ->sortByDesc('project_count')
            ->values();

        $rows = $entities
            ->take(self::MAX_BARS)
            ->map(fn (array $row): array => [
                'label' => (string) ($row['name'] ?? __('Unknown entity')),
                'value' => (int) $row['project_count'],
            ])
            ->all();

        // Past eight bars the axis labels collide and the chart stops being
        // readable, so the tail folds into one honest row rather than being
        // dropped — the total must still add up.
        $tail = $entities->skip(self::MAX_BARS);

        if ($tail->isNotEmpty()) {
            $rows[] = [
                'label' => __(':count other entities', ['count' => $tail->count()]),
                'value' => (int) $tail->sum('project_count'),
            ];
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
        return view('livewire.oversight.dashboard.portfolio-chart');
    }
}
