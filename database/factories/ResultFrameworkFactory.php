<?php

namespace Database\Factories;

use App\Enums\FrameworkLevel;
use App\Models\Project;
use App\Models\ResultFramework;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Tenant-owned — never sets tenant_id (see ProjectFactory).
 *
 * The default is a ROOT IMPACT statement, because that is the only level that
 * stands on its own: an outcome with nothing above it is a result nobody asked
 * for, and CreateResultFramework refuses it.
 *
 * @extends Factory<ResultFramework>
 */
class ResultFrameworkFactory extends Factory
{
    protected $model = ResultFramework::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'parent_id' => null,
            'level' => FrameworkLevel::Impact,
            'code' => '1',
            'statement' => 'Living standards in the served communities improve measurably.',
            'description' => null,
            'assumptions' => 'Security conditions permit continued access to the sites.',
            'sort_order' => 10,
            'created_by_id' => User::factory(),
        ];
    }

    /** An outcome hanging off an impact statement. */
    public function outcomeOf(ResultFramework $impact): static
    {
        return $this->state([
            'level' => FrameworkLevel::Outcome,
            'parent_id' => $impact->id,
            'project_id' => $impact->project_id,
            'code' => $impact->code.'.1',
            'statement' => 'Travel time between the served communities and the nearest referral facility is reduced.',
        ]);
    }

    /** An output hanging off an outcome statement. */
    public function outputOf(ResultFramework $outcome): static
    {
        return $this->state([
            'level' => FrameworkLevel::Output,
            'parent_id' => $outcome->id,
            'project_id' => $outcome->project_id,
            'code' => $outcome->code.'.1',
            'statement' => 'Road sections rehabilitated and handed over to the maintenance authority.',
        ]);
    }

    public function forProject(Project $project): static
    {
        return $this->state(['project_id' => $project->id]);
    }

    /**
     * The MDA PROGRAMME framework: results that belong to no single project.
     * The manual's results chain exists above the level of any one contract.
     */
    public function programmeLevel(): static
    {
        return $this->state(['project_id' => null]);
    }

    public function createdBy(User $user): static
    {
        return $this->state(['created_by_id' => $user->id]);
    }
}
