<?php

namespace Database\Seeders;

use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Enums\IndicatorUnit;
use App\Enums\MeasurementFrequency;
use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use App\Enums\Role;
use App\Enums\TargetType;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\FundingSource;
use App\Models\Indicator;
use App\Models\IndicatorReading;
use App\Models\IndicatorTarget;
use App\Models\Lga;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\ProjectFundingSource;
use App\Models\ProjectLocation;
use App\Models\ProjectStatusEvent;
use App\Models\Sector;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Money;
use App\Tenancy\CurrentTenant;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Ten demo projects across the two demo MDAs — the local development state.
 *
 * Everything is created inside `CurrentTenant::runAs($tenant, ...)`, so
 * BelongsToTenant fills tenant_id on projects, locations, funding rows,
 * contracts, assignments, status events, indicators, targets and readings.
 * Nothing here writes tenant_id by hand, and nothing here queries by it: that
 * is the whole point of the trait.
 *
 * The portfolio deliberately covers the awkward cases the UI must survive: a
 * multi-site project, a co-funded one (donor + counterpart), a contract
 * variation, a project supervised by another MDA, a behind-schedule project
 * and a closed one past its post-completion review date.
 *
 * Depends on: DemoTenantSeeder (tenants + a user per role), SectorSeeder,
 * FundingSourceSeeder, LgaWardSeeder, ContractorSeeder.
 */
class DemoProjectSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        $current = app(CurrentTenant::class);
        $current->forget();

        /** @var Collection<int, Lga> $lgas */
        $lgas = Lga::query()->with('wards')->get();
        /** @var Collection<int, Contractor> $contractors */
        $contractors = Contractor::query()->eligible()->get();

        if ($lgas->isEmpty() || $contractors->isEmpty()) {
            return;
        }

        /** @var Collection<int, Tenant> $tenants */
        $tenants = Tenant::query()->get()->keyBy('slug');

        foreach ($this->portfolio() as $workspace) {
            $tenant = $tenants->get($workspace['slug']);

            if (! $tenant instanceof Tenant) {
                continue;
            }

            $sector = Sector::query()->where('code', $workspace['sector'])->firstOrFail();
            $staff = $this->staff($tenant);

            if ($staff === null) {
                continue;
            }

            $current->runAs($tenant, function () use ($workspace, $sector, $staff, $lgas, $contractors, $tenants): void {
                foreach ($workspace['projects'] as $definition) {
                    $this->createProject($definition, $sector, $staff, $lgas, $contractors, $tenants);
                }
            });
        }

        $current->forget();
    }

    /**
     * @param  array{reference: string, title: string, state: string, sites: list<string>, funding: array<string, int>, contract_type: ContractType, variation?: bool, supervised_by?: string, supervisor_name?: string, reporting_frequency?: string}  $definition
     * @param  array{admin: User, officer: User, consultant: User, monitor: User}  $staff
     * @param  Collection<int, Lga>  $lgas
     * @param  Collection<int, Contractor>  $contractors
     * @param  Collection<int, Tenant>  $tenants
     */
    private function createProject(array $definition, Sector $sector, array $staff, Collection $lgas, Collection $contractors, Collection $tenants): void
    {
        $supervisor = isset($definition['supervised_by']) ? $tenants->get($definition['supervised_by']) : null;

        $project = $this->factoryFor($definition['state'])->create([
            'reference' => $definition['reference'],
            'title' => $definition['title'],
            'sector_id' => $sector->id,
            'type' => $definition['contract_type'] === ContractType::Supply
                ? ProjectType::Programme
                : ProjectType::Capital,
            'budget_code' => str_replace('/', '-', $definition['reference']),
            // Statutory metadata only: naming a supervising MDA grants its
            // staff no access to this project (§9.1 of the design).
            'supervising_agency_id' => $supervisor instanceof Tenant ? $supervisor->id : null,
            'supervising_agency_name' => $definition['supervisor_name'] ?? null,
            'reporting_frequency' => $definition['reporting_frequency'] ?? null,
            'created_by_id' => $staff['admin']->id,
            'manager_id' => $staff['officer']->id,
        ]);

        $this->createLocations($project, $definition['sites'], $lgas);
        $this->createFunding($project, $definition['funding']);
        $this->createStatusTrail($project, $staff);
        $this->createAssignments($project, $staff);
        $this->createIndicators($project, $staff);

        if ($project->status->isAwardedOrBeyond() && $project->contract_value_total !== null) {
            $this->createContracts($project, $definition, $staff, $contractors);
        }
    }

    /**
     * The first site is the primary one — single-site projects therefore carry
     * exactly one row with is_primary = true, as RegisterProject will.
     *
     * @param  list<string>  $sites
     * @param  Collection<int, Lga>  $lgas
     */
    private function createLocations(Project $project, array $sites, Collection $lgas): void
    {
        foreach ($sites as $index => $site) {
            /** @var Lga $lga */
            $lga = $lgas[$index % $lgas->count()];
            $ward = $lga->wards->first();

            ProjectLocation::factory()->create([
                'project_id' => $project->id,
                'site_name' => $site,
                'description' => $site.' — '.$lga->name.' LGA',
                'lga_id' => $lga->id,
                'ward_id' => $ward?->id,
                'is_primary' => $index === 0,
            ]);
        }
    }

    /**
     * Funding split. Co-funded projects carry one row per source with the
     * percentage split; the amount is derived from the appropriation with
     * integer arithmetic.
     *
     * @param  array<string, int>  $split  funding source code => percentage
     */
    private function createFunding(Project $project, array $split): void
    {
        $allocation = $project->budget_allocation;
        $isFirst = true;

        foreach ($split as $code => $percentage) {
            $source = FundingSource::query()->where('code', $code)->first();

            if ($source === null) {
                continue;
            }

            ProjectFundingSource::factory()->create([
                'project_id' => $project->id,
                'funding_source_id' => $source->id,
                'amount' => $allocation === null
                    ? null
                    : Money::fromMinor(intdiv($allocation->minor() * $percentage, 100)),
                'percentage' => $percentage.'.00',
                'is_primary' => $isFirst,
            ]);

            $isFirst = false;
        }
    }

    /**
     * The lifecycle ledger the project would have accumulated to reach its
     * current status, with a real actor and timestamp on every hop.
     *
     * @param  array{admin: User, officer: User, consultant: User, monitor: User}  $staff
     */
    private function createStatusTrail(Project $project, array $staff): void
    {
        $path = $this->transitionPath($project->status);
        $occurredAt = Carbon::now()->subMonths(count($path) * 2);
        $previous = null;

        foreach ($path as $status) {
            ProjectStatusEvent::factory()->create([
                'project_id' => $project->id,
                'from_status' => $previous,
                'to_status' => $status,
                'actor_id' => $this->actorFor($status, $staff)->id,
                'reason' => $status === ProjectStatus::Suspended
                    ? 'Works halted pending resolution of a right-of-way dispute.'
                    : null,
                'occurred_at' => $occurredAt->copy(),
            ]);

            $previous = $status;
            $occurredAt = $occurredAt->addMonths(2);
        }
    }

    /**
     * @param  array{admin: User, officer: User, consultant: User, monitor: User}  $staff
     */
    private function createAssignments(Project $project, array $staff): void
    {
        $assignedAt = $project->start_date ?? Carbon::now()->subMonths(3);

        ProjectAssignment::factory()->create([
            'project_id' => $project->id,
            'user_id' => $staff['officer']->id,
            'assigned_by_id' => $staff['admin']->id,
            'assigned_at' => $assignedAt,
        ]);

        ProjectAssignment::factory()->consultant()->create([
            'project_id' => $project->id,
            'user_id' => $staff['consultant']->id,
            'assigned_by_id' => $staff['admin']->id,
            'assigned_at' => $assignedAt,
        ]);

        ProjectAssignment::factory()->fieldMonitor()->create([
            'project_id' => $project->id,
            'user_id' => $staff['monitor']->id,
            'assigned_by_id' => $staff['admin']->id,
            'assigned_at' => $assignedAt,
        ]);
    }

    /**
     * Two active indicators per project, each with a complete baseline (value
     * + date + source — the activation precondition) and period targets.
     * Projects that have started also carry a validated reading, so the demo
     * shows the submit → validate hop rather than bare definitions.
     *
     * @param  array{admin: User, officer: User, consultant: User, monitor: User}  $staff
     */
    private function createIndicators(Project $project, array $staff): void
    {
        $output = Indicator::factory()->active()->create([
            'project_id' => $project->id,
            'name' => 'Physical works delivered against scope',
            'unit' => IndicatorUnit::Number,
            'measurement_frequency' => MeasurementFrequency::Quarterly,
            'target_type' => TargetType::Continuous,
            'baseline_value' => '0.0000',
            'baseline_date' => Carbon::now()->subYear()->toDateString(),
            'baseline_source' => 'Project appraisal document',
            'responsible_collector_id' => $staff['officer']->id,
            'responsible_collector_text' => null,
            'created_by_id' => $staff['officer']->id,
        ]);

        $outcome = Indicator::factory()->active()->percentage()->pdo()->create([
            'project_id' => $project->id,
            'name' => 'Beneficiary satisfaction with the delivered works',
            'measurement_frequency' => MeasurementFrequency::Annual,
            'baseline_value' => '42.0000',
            'baseline_date' => Carbon::now()->subYear()->toDateString(),
            'baseline_source' => 'Community satisfaction survey (baseline round)',
            'responsible_collector_id' => $staff['monitor']->id,
            'responsible_collector_text' => null,
            'created_by_id' => $staff['officer']->id,
        ]);

        IndicatorTarget::factory()->ofValue('100.0000')->create(['indicator_id' => $output->id]);
        IndicatorTarget::factory()->quarter(1)->ofValue('25.0000')->create(['indicator_id' => $output->id]);
        IndicatorTarget::factory()->quarter(2)->ofValue('50.0000')->create(['indicator_id' => $output->id]);
        IndicatorTarget::factory()->ofValue('80.0000')->create(['indicator_id' => $outcome->id]);

        if ($project->status === ProjectStatus::Draft || $project->status === ProjectStatus::Awarded) {
            return;
        }

        IndicatorReading::factory()->validated($staff['officer'])->create([
            'indicator_id' => $output->id,
            'actual_value' => $project->physical_progress,
            'submitted_by_id' => $staff['monitor']->id,
        ]);
    }

    /**
     * The main award, plus a variation order where the demo calls for one. The
     * two rows always sum to the project's `contract_value_total` cache — the
     * invariant AwardContract / RecordContractVariation must hold in code.
     *
     * @param  array{reference: string, title: string, state: string, sites: list<string>, funding: array<string, int>, contract_type: ContractType, variation?: bool, supervised_by?: string, supervisor_name?: string, reporting_frequency?: string}  $definition
     * @param  array{admin: User, officer: User, consultant: User, monitor: User}  $staff
     * @param  Collection<int, Contractor>  $contractors
     */
    private function createContracts(Project $project, array $definition, array $staff, Collection $contractors): void
    {
        /** @var Money $total */
        $total = $project->contract_value_total;
        $withVariation = $definition['variation'] ?? false;
        $variationMinor = $withVariation ? intdiv($total->minor(), 10) : 0;

        /** @var Contractor $contractor */
        $contractor = $contractors[abs(crc32($definition['reference'])) % $contractors->count()];

        $contract = Contract::factory()->create([
            'project_id' => $project->id,
            'contractor_id' => $contractor->id,
            'contract_number' => $definition['reference'].'/C1',
            'type' => $definition['contract_type'],
            'status' => $this->contractStatusFor($project->status),
            'sum' => Money::fromMinor($total->minor() - $variationMinor),
            'scope_of_works' => $definition['title'].' in accordance with the approved designs and bill of quantities.',
            'award_date' => $project->start_date ?? Carbon::now()->subYear(),
            'commencement_date' => $project->start_date?->addWeeks(2),
            'expected_completion_date' => $project->expected_end_date,
            'created_by_id' => $staff['admin']->id,
        ]);

        if ($variationMinor > 0) {
            Contract::factory()->create([
                'project_id' => $project->id,
                'contractor_id' => $contractor->id,
                'contract_number' => $definition['reference'].'/VO1',
                'type' => $definition['contract_type'],
                'status' => $contract->status,
                'sum' => Money::fromMinor($variationMinor),
                'scope_of_works' => 'Additional works arising from revised ground conditions.',
                'award_date' => Carbon::now()->subMonths(2)->toDateString(),
                'commencement_date' => null,
                'expected_completion_date' => $project->expected_end_date,
                'varies_contract_id' => $contract->id,
                'variation_reason' => 'Unforeseen ground conditions on the northern section; quantities re-measured and approved.',
                'created_by_id' => $staff['admin']->id,
            ]);
        }
    }

    /**
     * Factory state per demo project. A match (not a dynamic call) so a typo
     * fails at seed time rather than producing a silently wrong portfolio.
     */
    private function factoryFor(string $state): ProjectFactory
    {
        return match ($state) {
            'draft' => Project::factory()->draft(),
            'awarded' => Project::factory()->awarded(),
            'ongoing' => Project::factory()->ongoing(),
            'behind_schedule' => Project::factory()->behindSchedule(),
            'completed' => Project::factory()->completed(),
            'certified' => Project::factory()->certified(),
            'closed' => Project::factory()->closed(),
            'suspended' => Project::factory()->suspended(),
            default => throw new InvalidArgumentException("Unknown demo project state [{$state}]."),
        };
    }

    /** @return list<ProjectStatus> */
    private function transitionPath(ProjectStatus $target): array
    {
        $execution = [
            ProjectStatus::Draft,
            ProjectStatus::Awarded,
            ProjectStatus::Mobilized,
            ProjectStatus::InProgress,
        ];

        return match ($target) {
            ProjectStatus::Draft => [ProjectStatus::Draft],
            ProjectStatus::Awarded => [ProjectStatus::Draft, ProjectStatus::Awarded],
            ProjectStatus::Mobilized => [ProjectStatus::Draft, ProjectStatus::Awarded, ProjectStatus::Mobilized],
            ProjectStatus::InProgress => $execution,
            ProjectStatus::Completed => [...$execution, ProjectStatus::Completed],
            ProjectStatus::Certified => [...$execution, ProjectStatus::Completed, ProjectStatus::Certified],
            ProjectStatus::Closed => [...$execution, ProjectStatus::Completed, ProjectStatus::Certified, ProjectStatus::Closed],
            ProjectStatus::Suspended => [...$execution, ProjectStatus::Suspended],
            ProjectStatus::Cancelled => [ProjectStatus::Draft, ProjectStatus::Cancelled],
        };
    }

    /**
     * Who signs off which hop: the MDA admin awards, certifies and closes; the
     * M&E officer runs execution.
     *
     * @param  array{admin: User, officer: User, consultant: User, monitor: User}  $staff
     */
    private function actorFor(ProjectStatus $status, array $staff): User
    {
        return match ($status) {
            ProjectStatus::Mobilized, ProjectStatus::InProgress, ProjectStatus::Completed => $staff['officer'],
            default => $staff['admin'],
        };
    }

    private function contractStatusFor(ProjectStatus $status): ContractStatus
    {
        return match ($status) {
            ProjectStatus::Awarded => ContractStatus::Awarded,
            ProjectStatus::Completed, ProjectStatus::Certified, ProjectStatus::Closed => ContractStatus::Completed,
            ProjectStatus::Cancelled => ContractStatus::Terminated,
            default => ContractStatus::Active,
        };
    }

    /**
     * The demo users DemoTenantSeeder created for this workspace.
     *
     * @return array{admin: User, officer: User, consultant: User, monitor: User}|null
     */
    private function staff(Tenant $tenant): ?array
    {
        $find = fn (Role $role): ?User => User::query()
            ->where('email', $role->value.'@'.$tenant->slug.'.mne.test')
            ->first();

        $admin = $find(Role::MdaAdmin);
        $officer = $find(Role::MeOfficer);
        $consultant = $find(Role::Consultant);
        $monitor = $find(Role::FieldMonitor);

        if ($admin === null || $officer === null || $consultant === null || $monitor === null) {
            return null;
        }

        return ['admin' => $admin, 'officer' => $officer, 'consultant' => $consultant, 'monitor' => $monitor];
    }

    /**
     * Ten projects spread across every lifecycle state the Phase 1 UI has to
     * render — including a behind-schedule one, because an M&E dashboard that
     * has never seen a late project has never been tested.
     *
     * @return list<array{slug: string, sector: string, projects: list<array{reference: string, title: string, state: string, sites: list<string>, funding: array<string, int>, contract_type: ContractType, variation?: bool, supervised_by?: string, supervisor_name?: string, reporting_frequency?: string}>}>
     */
    private function portfolio(): array
    {
        return [
            [
                'slug' => 'works',
                'sector' => 'WKS',
                'projects' => [
                    [
                        'reference' => 'WKS/2026/001',
                        'title' => 'Rehabilitation of Township Roads — Phase 2',
                        'state' => 'draft',
                        'sites' => ['Township Road Network'],
                        'funding' => ['IGR' => 100],
                        'contract_type' => ContractType::Works,
                    ],
                    [
                        'reference' => 'WKS/2026/002',
                        'title' => 'Construction of Bridge Approach Works',
                        'state' => 'awarded',
                        'sites' => ['North Approach', 'South Approach'],
                        'funding' => ['FAA' => 100],
                        'contract_type' => ContractType::Works,
                        'reporting_frequency' => 'monthly',
                    ],
                    [
                        'reference' => 'WKS/2026/003',
                        'title' => 'Storm Drainage Network Upgrade',
                        'state' => 'ongoing',
                        'sites' => ['Central Drain', 'Market Outfall'],
                        'funding' => ['WBC' => 100],
                        'contract_type' => ContractType::Works,
                        'variation' => true,          // contract variation demo
                        'reporting_frequency' => 'monthly',
                    ],
                    [
                        // Multi-site: three lots across three LGAs. Any LGA
                        // aggregate must count DISTINCT project_id or this one
                        // shows up three times in the state-wide total.
                        'reference' => 'WKS/2026/004',
                        'title' => 'Rural Roads Rehabilitation Programme',
                        'state' => 'ongoing',
                        'sites' => ['Lot 1 Access Road', 'Lot 2 Access Road', 'Lot 3 Access Road'],
                        'funding' => ['BND' => 100],
                        'contract_type' => ContractType::Works,
                        'reporting_frequency' => 'quarterly',
                    ],
                    [
                        'reference' => 'WKS/2026/005',
                        'title' => 'Ring Road Dualisation — Section B',
                        'state' => 'behind_schedule',
                        'sites' => ['Section B Corridor'],
                        'funding' => ['FAA' => 100],
                        'contract_type' => ContractType::Works,
                    ],
                    [
                        'reference' => 'WKS/2026/006',
                        'title' => 'Reconstruction of Central Motor Park',
                        'state' => 'completed',
                        'sites' => ['Central Motor Park'],
                        'funding' => ['IGR' => 100],
                        'contract_type' => ContractType::Works,
                    ],
                ],
            ],
            [
                'slug' => 'health',
                'sector' => 'HLT',
                'projects' => [
                    [
                        // Multi-site AND supervised by another MDA: Works
                        // supervises construction for Health. The field is
                        // statutory metadata — it grants Works no access.
                        'reference' => 'HLT/2026/001',
                        'title' => 'Construction of Four Primary Health Centres',
                        'state' => 'draft',
                        'sites' => ['PHC Site A', 'PHC Site B', 'PHC Site C', 'PHC Site D'],
                        'funding' => ['DNG' => 100],
                        'contract_type' => ContractType::Works,
                        'supervised_by' => 'works',
                    ],
                    [
                        // Co-funded: World Bank credit + state counterpart.
                        'reference' => 'HLT/2026/002',
                        'title' => 'Maternity Wing Expansion',
                        'state' => 'ongoing',
                        'sites' => ['General Hospital Maternity Block'],
                        'funding' => ['WBC' => 70, 'CPT' => 30],
                        'contract_type' => ContractType::Works,
                        'reporting_frequency' => 'monthly',
                    ],
                    [
                        // Was "mid_term" in the rev. 1 design; mid-term is an
                        // evaluation event at a progress threshold, not a
                        // lifecycle state. This one has run its full course
                        // and its post-completion review date has passed.
                        'reference' => 'HLT/2026/003',
                        'title' => 'Cold Chain Facility Upgrade',
                        'state' => 'closed',
                        'sites' => ['Central Cold Store', 'Zonal Cold Store'],
                        'funding' => ['DNG' => 100],
                        'contract_type' => ContractType::Works,
                        'supervisor_name' => 'Federal Project Implementation Unit',
                    ],
                    [
                        'reference' => 'HLT/2026/004',
                        'title' => 'Medical Equipment Supply Programme',
                        'state' => 'certified',
                        'sites' => ['Central Medical Store'],
                        'funding' => ['DNG' => 60, 'CPT' => 40],
                        'contract_type' => ContractType::Supply,
                    ],
                ],
            ],
        ];
    }
}
