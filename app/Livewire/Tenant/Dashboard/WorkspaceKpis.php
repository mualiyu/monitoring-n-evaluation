<?php

declare(strict_types=1);

namespace App\Livewire\Tenant\Dashboard;

use App\Actions\Analytics\BuildWorkspaceSummary;
use App\Models\User;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The MDA dashboard's KPI row.
 *
 * It replaces four hard-coded literals (24, ₦8.6bn, 6, 1) that shipped
 * labelled "sample figure" — a number on a government dashboard is either
 * true or it is a liability, and these are now read from this workspace.
 *
 * LAZY: the shell and the page header paint immediately and the tiles fill in
 * behind a skeleton, so a slow aggregate never holds the whole dashboard.
 * Scoped by `visibleTo()` inside the Action, so a consultant sees their own
 * assignments and a director the workspace.
 */
class WorkspaceKpis extends Component
{
    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function summary(): array
    {
        /** @var User $user */
        $user = auth()->user();

        return (new BuildWorkspaceSummary)($user);
    }

    public function placeholder(): string
    {
        return <<<'HTML'
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="h-28 animate-pulse rounded-xl border border-line bg-surface-raised"></div>
            <div class="h-28 animate-pulse rounded-xl border border-line bg-surface-raised"></div>
            <div class="h-28 animate-pulse rounded-xl border border-line bg-surface-raised"></div>
            <div class="h-28 animate-pulse rounded-xl border border-line bg-surface-raised"></div>
        </div>
        HTML;
    }

    public function render(): View
    {
        return view('livewire.tenant.dashboard.workspace-kpis');
    }
}
