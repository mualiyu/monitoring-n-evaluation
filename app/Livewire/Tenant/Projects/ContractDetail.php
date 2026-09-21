<?php

declare(strict_types=1);

namespace App\Livewire\Tenant\Projects;

use App\Models\Contract;
use App\Models\Project;
use App\Support\Money;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * One contract (design §5, `/projects/{project}/contracts/{contract}`): the
 * terms as awarded, the amendment register that has grown on top of them, and
 * the instrument's own paper trail.
 *
 * There is no edit form here, and that is the design rather than an omission.
 * `sum`, `award_date`, `contractor_id` and `scope_of_works` are immutable on
 * the model itself (Contract::IMMUTABLE_TERMS) — a correction is a new row in
 * the register, not a rewrite — so the only mutation this screen offers is
 * "record a variation", which is a different screen and a different Action.
 *
 * A variation has no separate URL in the navigation, but its ULID resolves
 * here too: someone will paste one. It renders as itself, headed by a link to
 * the award it amends, rather than 404ing on a record that plainly exists.
 *
 * Livewire resolves a #[Computed] method as a property, with caching; these
 * annotations are what let static analysis see that. They mirror the methods
 * below — keep them in step.
 *
 * @property-read Contract $record
 * @property-read Contract $head
 * @property-read Collection<int, Contract> $variations
 * @property-read Money $revisedValue
 * @property-read bool $canRecordVariation
 * @property-read string $projectsUrl
 * @property-read string $projectUrl
 * @property-read string $contractsTabUrl
 * @property-read string $headUrl
 * @property-read string $recordVariationUrl
 */
#[Layout('layouts::tenant')]
class ContractDetail extends Component
{
    public Project $project;

    public Contract $contract;

    /**
     * Both parameters are route-model bound on `ulid`, and the route scopes
     * `{contract}` through `$project->contracts()`. The tenancy check happens
     * before either: the TenantScope is applied at the binder, so another
     * MDA's ULID is a 404 and never reaches this method.
     */
    public function mount(Project $project, Contract $contract): void
    {
        $this->authorize('view', $project);
        $this->authorize('view', $contract);

        // Belt and braces against the route's scoping: Livewire resolves
        // bindings a second way (Drawer\ImplicitRouteBinding), and a contract
        // rendered under the wrong project's header would misattribute public
        // money even within one workspace.
        abort_unless($contract->project_id === $project->id, 404);

        $this->project = $project;
        $this->contract = $contract;
    }

    /* ------------------------------------------------------------------ */
    /* Reads — every one eager-loaded */
    /* ------------------------------------------------------------------ */

    /** The contract actually being viewed (award or variation). */
    #[Computed]
    public function record(): Contract
    {
        // Re-queried through the model rather than with fresh(): fresh() is
        // newQueryWithoutScopes(), i.e. an unscoped cross-tenant read. Under
        // the scope, firstOrFail() fails closed.
        return Contract::query()
            ->with(['contractor:id,name,rc_number,is_blacklisted', 'createdBy:id,name'])
            ->whereKey($this->contract->getKey())
            ->firstOrFail();
    }

    /**
     * The award at the head of this chain — the record itself, unless we are
     * looking at one of its variations. Variations attach to the award and
     * never to each other, so the chain is one level deep by construction.
     */
    #[Computed]
    public function head(): Contract
    {
        $record = $this->record;

        if (! $record->isVariation()) {
            return $record;
        }

        return Contract::query()
            ->with(['contractor:id,name,rc_number,is_blacklisted', 'createdBy:id,name', 'variations.createdBy:id,name'])
            ->whereKey($record->varies_contract_id)
            ->firstOrFail();
    }

    /**
     * The amendment register for this award, oldest first — the order the
     * instruments were issued in is the order they have to be read in.
     *
     * @return Collection<int, Contract>
     */
    #[Computed]
    public function variations(): Collection
    {
        return $this->head->variations()
            ->with('createdBy:id,name')
            ->orderBy('award_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * Award sum plus every variation raised against it — what a commissioner
     * means by "what is this contract worth now". The original stays readable
     * beside it; that is the whole point of the register.
     */
    #[Computed]
    public function revisedValue(): Money
    {
        return $this->variations->reduce(
            fn (Money $carry, Contract $variation): Money => $carry->plus($variation->sum),
            $this->head->sum,
        );
    }

    /** Whether this actor may add to the register (the Action re-checks). */
    #[Computed]
    public function canRecordVariation(): bool
    {
        return auth()->user()?->can('update', $this->head) === true;
    }

    /* ------------------------------------------------------------------ */
    /* Links */
    /* ------------------------------------------------------------------ */

    #[Computed]
    public function projectsUrl(): string
    {
        return $this->tenantRoute('tenant.projects.index');
    }

    #[Computed]
    public function projectUrl(): string
    {
        return $this->tenantRoute('tenant.projects.show', ['project' => $this->project]);
    }

    /** The contracts tab of the project, where this record is listed. */
    #[Computed]
    public function contractsTabUrl(): string
    {
        return $this->tenantRoute('tenant.projects.show', ['project' => $this->project, 'tab' => 'contracts']);
    }

    /** The award this record varies, when it is a variation. */
    #[Computed]
    public function headUrl(): string
    {
        return $this->contractUrl($this->head);
    }

    /** This screen, for any contract row on this project. */
    public function contractUrl(Contract $contract): string
    {
        return $this->tenantRoute('tenant.projects.contracts.show', [
            'project' => $this->project,
            'contract' => $contract,
        ]);
    }

    /** The variation form, with this award preselected. */
    #[Computed]
    public function recordVariationUrl(): string
    {
        return $this->tenantRoute('tenant.projects.contracts.create', [
            'project' => $this->project,
            'mode' => ContractCreate::MODE_VARIATION,
            'varies' => $this->head->ulid,
        ]);
    }

    /**
     * A named-route URL on THIS workspace's subdomain.
     *
     * route() is the rule — it fails loudly on a missing route or the wrong
     * binding key, where a hand-built string 404s silently in front of a user.
     * The tenant routes carry a `{tenant}` domain parameter that ResolveTenant
     * fills through URL::defaults on a real request and that nothing fills
     * inside Livewire::test(), which never crosses HTTP; passing the bound
     * tenant explicitly makes both paths generate the same URL.
     *
     * @param  array<string, mixed>  $parameters
     */
    private function tenantRoute(string $name, array $parameters = []): string
    {
        return route($name, [
            'tenant' => app(CurrentTenant::class)->getOrFail()->slug,
            ...$parameters,
        ]);
    }

    public function render(): View
    {
        return view('livewire.tenant.projects.contract-detail');
    }
}
