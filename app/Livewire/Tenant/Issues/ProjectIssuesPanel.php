<?php

declare(strict_types=1);

namespace App\Livewire\Tenant\Issues;

use App\Enums\IssueSeverity;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The challenges panel for a project screen:
 *
 *   <livewire:tenant.issues.project-issues-panel :project="$project" />
 *
 * A COMPONENT RATHER THAN A PARTIAL, because the project detail screen is
 * owned by another module: a partial would make the register's markup, its
 * visibility narrowing and its authority checks the project screen's problem
 * to keep correct. This way the panel answers for itself wherever it is
 * embedded, and can be dropped onto an inspection or an evaluation screen
 * later without a second implementation.
 *
 * Read-only and deliberately short. It answers "what is blocking this
 * project", not "manage the register" — the full list, the filters and every
 * mutation live on /issues, one click away.
 *
 * The project arrives as a Livewire model property, so it re-hydrates through
 * its own global scope on every update: another MDA's project cannot be
 * smuggled in by editing the payload, because the TenantScope refuses to load
 * it.
 */
class ProjectIssuesPanel extends Component
{
    public Project $project;

    /** How many open issues to show before deferring to the full register. */
    public int $limit = 5;

    public function mount(Project $project, int $limit = 5): void
    {
        // The panel is a view of the register, so it is gated on the register
        // — not on the project. Someone who may open a project but holds no
        // `issues.view` sees nothing here rather than an empty panel that
        // implies there is nothing to see.
        $this->authorize('viewAny', Issue::class);
        $this->authorize('view', $project);

        $this->project = $project;
        $this->limit = $limit;
    }

    /**
     * Worst first: the reason anyone glances at this panel is to find the
     * worst thing on it.
     *
     * @return Collection<int, Issue>
     */
    #[Computed]
    public function issues(): Collection
    {
        /** @var User $user */
        $user = auth()->user();

        // Bound parameters, not interpolation. The values are enum cases and
        // could never be injected — but "it happens to be safe today" is how a
        // raw string survives until somebody makes it take a filter value.
        $whens = str_repeat('WHEN ? THEN ? ', count(IssueSeverity::cases()));
        $bindings = [];

        foreach (IssueSeverity::cases() as $severity) {
            $bindings[] = $severity->value;
            $bindings[] = -$severity->weight();
        }

        return Issue::query()
            ->visibleTo($user)
            ->where('project_id', $this->project->id)
            ->open()
            ->with('owner:id,name')
            ->orderByRaw('CASE severity '.$whens.'ELSE 0 END', $bindings)
            ->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_date')
            ->orderByDesc('id')
            ->limit($this->limit)
            ->get();
    }

    /**
     * The total open count, which is not the same as what is shown — a panel
     * that silently truncates at five is a panel that hides the ninth problem.
     */
    #[Computed]
    public function openCount(): int
    {
        /** @var User $user */
        $user = auth()->user();

        return Issue::query()
            ->visibleTo($user)
            ->where('project_id', $this->project->id)
            ->open()
            ->count();
    }

    public function render(): View
    {
        return view('livewire.tenant.issues.project-issues-panel');
    }
}
