<?php

namespace Database\Seeders;

use App\Actions\Evaluation\ResolveReportTemplate;
use App\Enums\Role;
use App\Models\Evaluation;
use App\Models\EvaluationCriterionScore;
use App\Models\EvaluationReportSection;
use App\Models\EvaluationTeamMember;
use App\Models\Project;
use App\Models\Recommendation;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\Seeder;

/**
 * One evaluation per demo entity, at two different stages, plus the follow-up
 * register that is the module's actual point.
 *
 * The spread is deliberate. The first entity gets an APPROVED mid-term
 * evaluation with a full DAC scorecard and a written report, so the scorecard
 * and the section builder have something to show. The second gets one still
 * UNDER REVIEW with a half-written report, so the review queue is not empty
 * and the "sections still blank" state is visible.
 *
 * The recommendations matter more than the evaluations do. A demo in which
 * every recommendation is implemented would never show the register doing its
 * job, so the seed includes one implemented, one in progress, one OVERDUE and
 * one critical and still open — which is what a secretariat's follow-up board
 * actually looks like.
 *
 * Fixtures, not Actions: like DemoWorkplanSeeder this states where records
 * ARE rather than driving them there, because running the real chain would
 * fire queued notifications during `migrate:fresh --seed`.
 *
 * Depends on: DemoTenantSeeder, DemoProjectSeeder.
 */
class DemoEvaluationSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        $current = app(CurrentTenant::class);
        $current->forget();

        foreach (Tenant::query()->orderBy('id')->get()->values() as $index => $tenant) {
            $current->runAs($tenant, function () use ($index, $tenant): void {
                $staff = $this->staff($tenant);

                if ($staff === null) {
                    return;
                }

                $index === 0
                    ? $this->approvedMidTerm($staff)
                    : $this->underReviewTerminal($staff);
            });
        }

        $current->forget();
    }

    /**
     * The three people an evaluation needs: someone to commission it, someone
     * to lead it, and someone to sign it off. Null when a workspace has not
     * been staffed — a demo seeder must never be the thing that fails a seed.
     *
     * @return array{admin: User, officer: User}|null
     */
    private function staff(Tenant $tenant): ?array
    {
        $admin = $this->userWithRole($tenant, Role::MdaAdmin);
        $officer = $this->userWithRole($tenant, Role::MeOfficer);

        return $admin !== null && $officer !== null
            ? ['admin' => $admin, 'officer' => $officer]
            : null;
    }

    private function userWithRole(Tenant $tenant, Role $role): ?User
    {
        return $tenant->users()
            ->get()
            ->first(fn (User $user): bool => $user->hasRole($role->value));
    }

    /**
     * @param  array{admin: User, officer: User}  $staff
     */
    private function approvedMidTerm(array $staff): void
    {
        $project = Project::query()->orderBy('id')->first();

        if (! $project instanceof Project) {
            return;
        }

        $evaluation = Evaluation::factory()
            ->forProject($project)
            ->midTerm()
            ->by($staff['officer'])
            ->approved($staff['admin'])
            ->create([
                'title' => 'Mid-term evaluation: '.$project->title,
                'sponsor' => 'State M&E Secretariat',
            ]);

        EvaluationTeamMember::factory()->forEvaluation($evaluation)->lead($staff['officer'])->create();
        EvaluationTeamMember::factory()->forEvaluation($evaluation)->member($staff['admin'])->create();
        EvaluationTeamMember::factory()->forEvaluation($evaluation)->external()->create();

        // The DAC criteria as configured, not as hard-coded here: a state that
        // adds its own criterion gets it scored in the demo too.
        $scores = ['4.00', '3.00', '4.00', '3.00', '2.00'];

        foreach (array_values((array) config('platform.evaluation.criteria', [])) as $position => $criterion) {
            EvaluationCriterionScore::factory()
                ->forEvaluation($evaluation)
                ->criterion((string) $criterion)
                ->scored($scores[$position] ?? '3.00', $staff['officer'])
                ->create();
        }

        $this->writeReport($evaluation, blankFrom: null);

        // The follow-up register: the same evaluation, four different fates.
        Recommendation::factory()->from($evaluation)->forProject($project)
            ->addressedTo($staff['admin'])
            ->implemented($staff['admin'])
            ->create(['title' => 'Re-sequence the drainage works ahead of the wearing course']);

        Recommendation::factory()->from($evaluation)->forProject($project)
            ->addressedTo($staff['officer'])
            ->inProgress($staff['officer'])
            ->dueIn(21)
            ->create(['title' => 'Publish monthly site photographs to the transparency portal']);

        Recommendation::factory()->from($evaluation)->forProject($project)
            ->addressedTo($staff['officer'])
            ->overdue(12)
            ->create(['title' => 'Recover the four outstanding monthly returns for Q2']);

        Recommendation::factory()->from($evaluation)->forProject($project)
            ->addressedTo($staff['admin'])
            ->critical()
            ->open()
            ->dueIn(7)
            ->create(['title' => 'Re-measure the sub-base before further payment is certified']);
    }

    /**
     * @param  array{admin: User, officer: User}  $staff
     */
    private function underReviewTerminal(array $staff): void
    {
        $project = Project::query()->orderBy('id')->first();

        if (! $project instanceof Project) {
            return;
        }

        $evaluation = Evaluation::factory()
            ->forProject($project)
            ->terminal()
            ->by($staff['officer'])
            ->underReview($staff['officer'])
            ->create([
                'title' => 'Terminal evaluation: '.$project->title,
                'sponsor' => 'State M&E Secretariat',
            ]);

        EvaluationTeamMember::factory()->forEvaluation($evaluation)->lead($staff['officer'])->create();

        foreach (array_values((array) config('platform.evaluation.criteria', [])) as $criterion) {
            EvaluationCriterionScore::factory()
                ->forEvaluation($evaluation)
                ->criterion((string) $criterion)
                ->unscored()
                ->create();
        }

        // Half-written on purpose: the "sections still outstanding" state is
        // what a report under review actually looks like.
        $this->writeReport($evaluation, blankFrom: 'findings');

        Recommendation::factory()->from($evaluation)->forProject($project)
            ->addressedTo($staff['admin'])
            ->open()
            ->dueIn(45)
            ->create(['title' => 'Hand the completed facility over with a costed maintenance plan']);
    }

    /**
     * Write the configured report template, leaving everything from
     * `$blankFrom` onwards empty.
     */
    private function writeReport(Evaluation $evaluation, ?string $blankFrom): void
    {
        $blank = $blankFrom === null;

        foreach ((new ResolveReportTemplate)() as $section) {
            $key = $section['key'];

            if ($key === '') {
                continue;
            }

            if ($key === $blankFrom) {
                $blank = true;
            }

            $factory = EvaluationReportSection::factory()->forEvaluation($evaluation)->template($key);

            ($blank && $blankFrom !== null ? $factory->blank() : $factory->written())->create();
        }
    }
}
