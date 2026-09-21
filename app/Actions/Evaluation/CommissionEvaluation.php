<?php

namespace App\Actions\Evaluation;

use App\Enums\EvaluationStatus;
use App\Exceptions\Evaluation\EvaluationRuleViolation;
use App\Models\Evaluation;
use App\Models\EvaluationCriterionScore;
use App\Models\EvaluationEvent;
use App\Models\EvaluationReportSection;
use App\Models\User;
use App\Support\SettingsRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Commissions an evaluation in the CURRENT workspace — the ToR made into a
 * record (manual digest §3, plan §4).
 *
 * `tenant_id` is never passed: BelongsToTenant fills it from the bound tenant,
 * and passing a foreign one throws. Status is never passed either — an
 * evaluation is born `planned` and only TransitionEvaluationStatus moves it,
 * so the ledger starts with a creation row and stays complete from day one.
 *
 * THE SCAFFOLD IS BUILT AT COMMISSIONING, not lazily when someone first opens
 * the report builder. Eleven empty section rows and one row per configured
 * criterion are created here, so that:
 *   - the scorecard shows what still has to be ANSWERED rather than an empty
 *     panel that looks like a feature nobody has used,
 *   - the review gate has something concrete to refuse on (§ the chokepoint's
 *     completeness guards), and
 *   - an evaluation commissioned under today's template keeps that template
 *     even after a state changes it, because the rows were already written.
 */
class CommissionEvaluation
{
    public function __construct(
        private readonly ResolveReportTemplate $template,
        private readonly AssignEvaluationTeam $assignTeam,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  validated commission fields
     * @param  list<array<string, mixed>>  $team  optional roster — see AssignEvaluationTeam
     */
    public function __invoke(User $actor, array $attributes, array $team = []): Evaluation
    {
        Gate::forUser($actor)->authorize('create', Evaluation::class);

        $this->assertSubject($attributes);
        $this->assertDates($attributes);

        $evaluation = DB::transaction(function () use ($actor, $attributes): Evaluation {
            $evaluation = Evaluation::create([
                ...$attributes,
                'created_by_id' => $actor->id,
            ]);

            // Not fillable: commissioning is an act with an actor, exactly as
            // publishing is, and a form payload must not be able to claim it.
            $evaluation->forceFill([
                'status' => EvaluationStatus::Planned,
                'commissioned_by_id' => $actor->id,
                'commissioned_at' => now(),
                'status_changed_at' => now(),
            ])->save();

            $this->seedSections($evaluation);
            $this->seedCriteria($evaluation);

            EvaluationEvent::create([
                'evaluation_id' => $evaluation->id,
                'from_status' => null, // commissioning has no origin
                'to_status' => EvaluationStatus::Planned,
                'actor_id' => $actor->id,
                'occurred_at' => now(),
            ]);

            return $evaluation;
        });

        if ($team !== []) {
            // Outside the transaction: assigning the team queues the
            // "you are on this evaluation" notification, and a notification
            // dispatched inside a transaction that then rolls back has already
            // been sent.
            ($this->assignTeam)($evaluation, $actor, $team);
        }

        return $evaluation;
    }

    /**
     * An evaluation evaluates SOMETHING. A project row when there is one; a
     * named programme otherwise. Refusing both is not pedantry — a commission
     * with no subject cannot be reported on, and the report template's title
     * page has nothing to print.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function assertSubject(array $attributes): void
    {
        $scope = $attributes['scope'] ?? 'project';

        if (! is_string($scope) || ! in_array($scope, Evaluation::SCOPES, true)) {
            throw EvaluationRuleViolation::unknownScope(is_string($scope) ? $scope : gettype($scope));
        }

        $hasProject = ($attributes['project_id'] ?? null) !== null;
        $hasSubject = trim((string) ($attributes['subject_name'] ?? '')) !== '';

        if (! $hasProject && ! $hasSubject) {
            throw EvaluationRuleViolation::subjectRequired();
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assertDates(array $attributes): void
    {
        $starts = $attributes['starts_on'] ?? null;
        $ends = $attributes['ends_on'] ?? null;

        if ($starts === null || $ends === null) {
            return;
        }

        if (strtotime((string) $ends) < strtotime((string) $starts)) {
            throw EvaluationRuleViolation::datesOutOfOrder();
        }
    }

    /** The eleven-section skeleton, from the configurable template. */
    private function seedSections(Evaluation $evaluation): void
    {
        $ordinal = 1;

        foreach (($this->template)() as $section) {
            EvaluationReportSection::create([
                'evaluation_id' => $evaluation->id,
                'key' => $section['key'],
                'heading' => $section['heading'],
                'ordinal' => $ordinal++,
                'body' => null,
                'is_required' => $section['required'],
            ]);
        }
    }

    /**
     * One row per configured criterion, unscored. Read through
     * SettingsRepository (tenant override → instance setting → config
     * default), never as a literal: a state that adds its own criterion to the
     * OECD-DAC five must not need a release, and hard-coding the five here
     * would undo the whole reason the scores are rows.
     */
    private function seedCriteria(Evaluation $evaluation): void
    {
        $criteria = app(SettingsRepository::class)->strings(
            'evaluation',
            'criteria',
            ['relevance', 'efficiency', 'effectiveness', 'impact', 'sustainability'],
        );

        foreach ($criteria as $criterion) {
            EvaluationCriterionScore::create([
                'evaluation_id' => $evaluation->id,
                'criterion' => $criterion,
                'score' => null,     // not yet scored — emphatically not zero
                'weight' => '1.00',  // parity, the DAC convention
            ]);
        }
    }
}
