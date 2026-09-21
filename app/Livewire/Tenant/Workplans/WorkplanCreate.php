<?php

declare(strict_types=1);

namespace App\Livewire\Tenant\Workplans;

use App\Actions\Workplans\CreateWorkplan;
use App\Models\User;
use App\Models\Workplan;
use App\Rules\IsWorkspaceMember;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Opens a year's Annual Work Plan & Budget. Deliberately a SMALL form: the
 * plan's substance is its activities, and those are added on the builder
 * screen where the budget totals are visible while you type.
 *
 * The period is derived from the year and the basis the officer picks, and
 * shown back to them before they commit — a financial year that silently runs
 * January-to-December is the kind of error nobody notices until the annual
 * report will not reconcile. It stays editable, because some states run a
 * transition year that is neither.
 */
#[Layout('layouts::tenant')]
class WorkplanCreate extends Component
{
    public string $title = '';

    public string $year = '';

    public string $yearBasis = 'calendar';

    public string $periodStart = '';

    public string $periodEnd = '';

    public string $ownerId = '';

    public string $narrative = '';

    public function mount(): void
    {
        $this->authorize('create', Workplan::class);

        $next = CarbonImmutable::now()->year;

        $this->year = (string) $next;
        $this->title = __('Annual Work Plan & Budget :year', ['year' => $next]);
        $this->ownerId = (string) auth()->id();

        $this->applyDerivedPeriod();
    }

    public function updatedYear(): void
    {
        $this->applyDerivedPeriod();
    }

    public function updatedYearBasis(): void
    {
        $this->applyDerivedPeriod();
    }

    /**
     * The period the chosen year implies. A financial year is assumed to open
     * on 1 April (the commonest Nigerian state practice) — the officer may
     * override both dates, which is why they are real inputs and not a label.
     */
    private function applyDerivedPeriod(): void
    {
        if (! ctype_digit($this->year)) {
            return;
        }

        $year = (int) $this->year;

        if ($year < 2000 || $year > 2100) {
            return;
        }

        $this->periodStart = ($this->yearBasis === 'financial'
            ? CarbonImmutable::create($year, 4, 1)
            : CarbonImmutable::create($year, 1, 1))->toDateString();

        $this->periodEnd = ($this->yearBasis === 'financial'
            ? CarbonImmutable::create($year + 1, 3, 31)
            : CarbonImmutable::create($year, 12, 31))->toDateString();
    }

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'yearBasis' => ['required', Rule::in(Workplan::YEAR_BASES)],
            'periodStart' => ['required', 'date'],
            'periodEnd' => ['required', 'date', 'after:periodStart'],
            // The owner must be a member of THIS workspace. exists() alone
            // would accept any user id on the platform and hand an MDA's plan
            // to a stranger.
            'ownerId' => ['required', 'integer', new IsWorkspaceMember],
            'narrative' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /** @return array<string, string> */
    protected function validationAttributes(): array
    {
        return [
            'yearBasis' => __('year basis'),
            'periodStart' => __('period start'),
            'periodEnd' => __('period end'),
            'ownerId' => __('owner'),
        ];
    }

    public function save(CreateWorkplan $create): mixed
    {
        // Authorizing again: route middleware does not protect a direct POST
        // to the Livewire update endpoint.
        $this->authorize('create', Workplan::class);

        $data = $this->validate();

        /** @var User $actor */
        $actor = auth()->user();

        try {
            $workplan = $create($actor, [
                'title' => $data['title'],
                'year' => (int) $data['year'],
                'year_basis' => $data['yearBasis'],
                'period_start' => $data['periodStart'],
                'period_end' => $data['periodEnd'],
                'owner_id' => (int) $data['ownerId'],
                'narrative' => $data['narrative'] === '' ? null : $data['narrative'],
            ]);
        } catch (DomainException $e) {
            // A domain rule refused (a plan already exists for this year).
            // Surfaced on the field it is about, not as a 500.
            $this->addError('year', $e->getMessage());

            return null;
        }

        session()->flash('status', __('Work plan opened. Add its activities below.'));

        return $this->redirectRoute('tenant.workplans.show', $workplan, navigate: true);
    }

    /**
     * Workspace members, for the owner picker. An id-keyed map, so the select
     * submits the id rather than the name.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function memberOptions(): array
    {
        return app(CurrentTenant::class)->getOrFail()
            ->users()
            ->where('users.is_active', true)
            ->orderBy('users.name')
            ->pluck('users.name', 'users.id')
            ->all();
    }

    /** @return array<string, string> */
    #[Computed]
    public function basisOptions(): array
    {
        return [
            'calendar' => __('Calendar year (January – December)'),
            'financial' => __('Financial year (April – March)'),
        ];
    }

    public function render(): View
    {
        return view('livewire.tenant.workplans.workplan-create');
    }
}
