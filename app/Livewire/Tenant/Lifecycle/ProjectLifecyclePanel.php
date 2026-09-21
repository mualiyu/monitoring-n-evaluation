<?php

declare(strict_types=1);

namespace App\Livewire\Tenant\Lifecycle;

use App\Models\Certificate;
use App\Models\CommencementNotice;
use App\Models\Contract;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The lifecycle strip for a project: has the contractor been told to start,
 * and has the finished work been certified.
 *
 * EMBEDDABLE, NOT ROUTED. The project detail screen is owned elsewhere, so
 * this module exposes a component instead of editing that file:
 *
 *     <livewire:tenant.lifecycle.project-lifecycle-panel :project="$project" />
 *
 * Read only by design — both acts have their own screen, where the
 * preconditions and the paperwork are visible. A panel that could certify
 * would put the most consequential signature in the platform on a summary
 * card.
 */
class ProjectLifecyclePanel extends Component
{
    public Project $project;

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);

        $this->project = $project;
    }

    /**
     * Awards, served notices and overdue ones, in one pass over a list that is
     * one or two rows for almost every project.
     *
     * @return array{awards: int, served: int, overdue: int, next_due: ?CarbonImmutable}
     */
    #[Computed]
    public function commencement(): array
    {
        /** @var Collection<int, Contract> $contracts */
        $contracts = Contract::query()
            ->where('project_id', $this->project->id)
            ->whereNull('varies_contract_id')
            ->get(['id', 'award_date']);

        /** @var Collection<int, CommencementNotice> $notices */
        $notices = CommencementNotice::query()
            ->where('project_id', $this->project->id)
            ->get(['id', 'contract_id', 'status', 'due_at'])
            ->keyBy('contract_id');

        $served = 0;
        $overdue = 0;
        $nextDue = null;

        foreach ($contracts as $contract) {
            $notice = $notices->get($contract->id);

            if ($notice?->status->isServed()) {
                $served++;

                continue;
            }

            if ($notice !== null && $notice->isOverdue()) {
                $overdue++;
            }

            $due = $notice?->due_at;

            if ($due !== null && ($nextDue === null || $due->isBefore($nextDue))) {
                $nextDue = $due;
            }
        }

        return [
            'awards' => $contracts->count(),
            'served' => $served,
            'overdue' => $overdue,
            'next_due' => $nextDue,
        ];
    }

    /**
     * Certificates in force on this project, newest first.
     *
     * @return Collection<int, Certificate>
     */
    #[Computed]
    public function certificates(): Collection
    {
        return Certificate::query()
            ->active()
            ->where('project_id', $this->project->id)
            ->orderByDesc('issued_at')
            ->get(['id', 'ulid', 'project_id', 'type', 'reference', 'issued_at', 'defects_liability_ends_on', 'revoked_at']);
    }

    public function render(): View
    {
        return view('livewire.tenant.lifecycle.project-lifecycle-panel');
    }
}
