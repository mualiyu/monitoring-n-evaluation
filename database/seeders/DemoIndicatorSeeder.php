<?php

namespace Database\Seeders;

use App\Enums\FrameworkLevel;
use App\Enums\IndicatorReadingStatus;
use App\Enums\IndicatorUnit;
use App\Enums\MeasurementFrequency;
use App\Enums\ReadingSourceType;
use App\Enums\Role;
use App\Enums\TargetType;
use App\Models\Indicator;
use App\Models\IndicatorDefinition;
use App\Models\IndicatorReading;
use App\Models\IndicatorTarget;
use App\Models\Project;
use App\Models\ResultFramework;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * A worked results framework per demo MDA — the local development state.
 *
 * Everything is created inside CurrentTenant::runAs($tenant, …), so
 * BelongsToTenant fills tenant_id on statements, indicators, targets and
 * readings. Nothing here writes tenant_id by hand and nothing queries by it:
 * that is the whole point of the trait.
 *
 * The demo deliberately covers the awkward cases the screens must survive:
 *   - a full impact → outcome → output tree, and a statement with no indicator;
 *   - an indicator instantiated from the library AND one defined locally;
 *   - a REDUCTION indicator (target below baseline), which is the case a naive
 *     actual/target would report as over-achieving while it gets worse;
 *   - a one-off milestone, a percentage-achievement target and a time measure;
 *   - a figure at every point of the chain, including one sitting in the
 *     Data Quality Reviewer's queue and one that was sent back with a reason —
 *     an empty validation queue has never been tested.
 *
 * Depends on: DemoTenantSeeder, DemoProjectSeeder, IndicatorDefinitionSeeder.
 */
class DemoIndicatorSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        $current = app(CurrentTenant::class);
        $current->forget();

        /** @var array<string, IndicatorDefinition> $library */
        $library = IndicatorDefinition::query()->get()->keyBy('code')->all();

        if ($library === []) {
            return;
        }

        foreach (Tenant::query()->get() as $tenant) {
            $staff = $this->staff($tenant);

            if ($staff === null) {
                continue;
            }

            $current->runAs($tenant, function () use ($staff, $library): void {
                $project = Project::query()->orderBy('id')->first();

                if (! $project instanceof Project) {
                    return;
                }

                $this->buildFramework($project, $staff, $library);
            });
        }

        $current->forget();
    }

    /**
     * @param  array{admin: User, officer: User, consultant: User, monitor: User}  $staff
     * @param  array<string, IndicatorDefinition>  $library
     */
    private function buildFramework(Project $project, array $staff, array $library): void
    {
        $impact = ResultFramework::query()->create([
            'project_id' => $project->id,
            'parent_id' => null,
            'level' => FrameworkLevel::Impact,
            'code' => '1',
            'statement' => 'Living standards in the served communities improve measurably.',
            'description' => 'The long-term change this investment exists to bring about.',
            'assumptions' => 'Security conditions permit continued access, and counterpart funding is released on schedule.',
            'sort_order' => 10,
            'created_by_id' => $staff['admin']->id,
        ]);

        $outcome = ResultFramework::query()->create([
            'project_id' => $project->id,
            'parent_id' => $impact->id,
            'level' => FrameworkLevel::Outcome,
            'code' => '1.1',
            'statement' => 'Served communities reach essential services faster and more reliably.',
            'assumptions' => 'Completed assets are maintained after handover.',
            'sort_order' => 10,
            'created_by_id' => $staff['officer']->id,
        ]);

        $delivered = ResultFramework::query()->create([
            'project_id' => $project->id,
            'parent_id' => $outcome->id,
            'level' => FrameworkLevel::Output,
            'code' => '1.1.1',
            'statement' => 'Works completed, certified and handed over to the responsible authority.',
            'assumptions' => 'Contractor capacity holds and materials remain available.',
            'sort_order' => 10,
            'created_by_id' => $staff['officer']->id,
        ]);

        // A statement with nothing measuring it yet — the state the builder's
        // "a result nobody can verify is an intention" note exists for.
        ResultFramework::query()->create([
            'project_id' => $project->id,
            'parent_id' => $outcome->id,
            'level' => FrameworkLevel::Output,
            'code' => '1.1.2',
            'statement' => 'Communities are engaged in monitoring the works.',
            'sort_order' => 20,
            'created_by_id' => $staff['officer']->id,
        ]);

        $year = Carbon::now()->startOfYear();

        // --- Output indicator, from the state library -----------------------
        $output = $this->instantiate($library['WKS-RD-01'] ?? null, $delivered, $staff, [
            'baseline_value' => '0.0000',
            'baseline_date' => $year->copy()->subYear()->toDateString(),
            'baseline_source' => 'Project appraisal document',
        ]);

        if ($output !== null) {
            $this->target($output, MeasurementFrequency::Annual, $year, $year->copy()->endOfYear(), '40.0000');
            $this->target($output, MeasurementFrequency::Quarterly, $year, $year->copy()->addMonths(3)->subDay(), '10.0000');

            // Published: on track, and quotable outside the platform.
            $this->reading($output, $year, $year->copy()->addMonths(3)->subDay(), '9.5000', $staff,
                IndicatorReadingStatus::Published);

            // Sitting in the Data Quality Reviewer's queue.
            $this->reading($output, $year->copy()->addMonths(3), $year->copy()->addMonths(6)->subDay(), '21.0000', $staff,
                IndicatorReadingStatus::Submitted);
        }

        // --- Outcome indicator: a TIME measure, lower is better -------------
        $travel = $this->instantiate($library['WKS-RD-02'] ?? null, $outcome, $staff, [
            'baseline_value' => '95.0000',
            'baseline_date' => $year->copy()->subYear()->toDateString(),
            'baseline_source' => 'Travel-time survey (baseline round)',
        ]);

        if ($travel !== null) {
            $this->target($travel, MeasurementFrequency::Biannual, $year, $year->copy()->addMonths(6)->subDay(), '60.0000');
            // 78 minutes against a 95→60 intention: just under half the
            // intended reduction delivered, which is an OFF TRACK amber-to-red
            // reading rather than the 128% a naive target/actual would print.
            $this->reading($travel, $year, $year->copy()->addMonths(6)->subDay(), '78.0000', $staff,
                IndicatorReadingStatus::Validated);
        }

        // --- Impact indicator: a REDUCTION measure --------------------------
        $mortality = $this->instantiate($library['HLT-MRT-01'] ?? null, $impact, $staff, [
            'baseline_value' => '45.0000',
            'baseline_date' => $year->copy()->subYear()->toDateString(),
            'baseline_source' => 'State demographic and health survey',
        ]);

        if ($mortality !== null) {
            $this->target($mortality, MeasurementFrequency::Annual, $year, $year->copy()->endOfYear(), '20.0000');
            $this->reading($mortality, $year, $year->copy()->endOfYear(), '31.0000', $staff,
                IndicatorReadingStatus::Validated);
        }

        // --- A locally defined indicator the library has no entry for -------
        $local = Indicator::query()->create([
            'project_id' => $project->id,
            'result_framework_id' => $delivered->id,
            'tier' => $delivered->level->defaultTier(),
            'name' => 'Community grievances resolved within the service standard',
            'definition' => 'Grievances logged against the works and closed within the published service standard.',
            'focus' => 'Community accountability',
            'unit' => IndicatorUnit::Percentage,
            'measurement_frequency' => MeasurementFrequency::Monthly,
            'target_type' => TargetType::PercentageAchievement,
            'data_source' => 'Grievance register',
            'means_of_verification' => 'Register extract with closure dates.',
            'responsible_collector_id' => $staff['monitor']->id,
            'baseline_value' => '0.0000',
            'baseline_date' => $year->copy()->subYear()->toDateString(),
            'baseline_source' => 'No register existed before the programme',
            'smart_justification' => 'Measurable from the register, time-bound to the published service standard.',
            'created_by_id' => $staff['officer']->id,
            'is_active' => true,
            'activated_at' => $year->copy()->subMonths(6),
        ]);

        $this->target($local, MeasurementFrequency::Monthly, $year, $year->copy()->addMonth()->subDay(), '90.0000');

        // Sent back with a reason: the recorder's screen has to render it.
        $rejected = IndicatorReading::query()->create([
            'indicator_id' => $local->id,
            'period_start' => $year->toDateString(),
            'period_end' => $year->copy()->addMonth()->subDay()->toDateString(),
            'actual_value' => '96.0000',
            'source_type' => ReadingSourceType::Secondary,
            'collection_method' => 'Extracted from the grievance register.',
            'recorded_by_id' => $staff['monitor']->id,
        ]);
        $rejected->forceFill([
            'submitted_by_id' => $staff['officer']->id,
            'submitted_at' => Carbon::now()->subWeeks(2),
            'rejected_by_id' => $staff['admin']->id,
            'rejected_at' => Carbon::now()->subWeek(),
            'rejection_reason' => 'The figure counts grievances closed, not grievances closed within the service standard.',
        ])->save();
    }

    /**
     * @param  array{admin: User, officer: User, consultant: User, monitor: User}  $staff
     * @param  array<string, mixed>  $baseline
     */
    private function instantiate(
        ?IndicatorDefinition $definition,
        ResultFramework $framework,
        array $staff,
        array $baseline,
    ): ?Indicator {
        if (! $definition instanceof IndicatorDefinition) {
            return null;
        }

        $tier = $definition->default_tier;

        return Indicator::query()->create([
            ...$definition->templateAttributes(),
            // The library's default tier may not fit this statement's level
            // (an output measure on an impact statement); the statement wins,
            // exactly as InstantiateIndicatorFromDefinition decides it.
            'tier' => $tier !== null && $tier->fitsLevel($framework->level)
                ? $tier
                : $framework->level->defaultTier(),
            'result_framework_id' => $framework->id,
            'project_id' => $framework->project_id,
            'responsible_collector_id' => $staff['officer']->id,
            'responsible_collector_text' => null,
            'created_by_id' => $staff['officer']->id,
            ...$baseline,
            'is_active' => true,
            'activated_at' => Carbon::now()->subMonths(6),
        ]);
    }

    private function target(
        Indicator $indicator,
        MeasurementFrequency $cadence,
        Carbon $start,
        Carbon $end,
        string $value,
    ): void {
        IndicatorTarget::query()->create([
            'indicator_id' => $indicator->id,
            'period_type' => $cadence,
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'target_value' => $value,
        ]);
    }

    /**
     * @param  array{admin: User, officer: User, consultant: User, monitor: User}  $staff
     */
    private function reading(
        Indicator $indicator,
        Carbon $start,
        Carbon $end,
        string $value,
        array $staff,
        IndicatorReadingStatus $status,
    ): void {
        $reading = IndicatorReading::query()->create([
            'indicator_id' => $indicator->id,
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'actual_value' => $value,
            'source_type' => ReadingSourceType::Primary,
            'collection_method' => 'Field verification by the assigned monitor.',
            'recorded_by_id' => $staff['monitor']->id,
        ]);

        // The chain stamps are written here rather than by driving the
        // transition Action, because the Action correctly refuses a validator
        // who is also the recorder — and a seeder has only four demo people.
        // The demo therefore states the end state directly; every guard is
        // proven in the Action's own tests.
        $stamps = ['status' => $status];

        if ($status !== IndicatorReadingStatus::Draft) {
            $stamps += [
                'submitted_by_id' => $staff['officer']->id,
                'submitted_at' => Carbon::now()->subWeeks(3),
            ];
        }

        if ($status->isValidated()) {
            $stamps += [
                'validated_by_id' => $staff['admin']->id,
                'validated_at' => Carbon::now()->subWeeks(2),
            ];
        }

        if ($status === IndicatorReadingStatus::Published) {
            $stamps += [
                'published_by_id' => $staff['admin']->id,
                'published_at' => Carbon::now()->subWeek(),
            ];
        }

        $reading->forceFill($stamps)->save();
    }

    /**
     * @return array{admin: User, officer: User, consultant: User, monitor: User}|null
     */
    private function staff(Tenant $tenant): ?array
    {
        $find = fn (Role $role): ?User => User::query()
            ->where('email', $role->value.'@'.$tenant->slug.'.'.config('platform.domain'))
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
}
