<?php

namespace App\Actions\Evaluation;

use App\Exceptions\Evaluation\EvaluationRuleViolation;
use App\Models\Evaluation;
use App\Models\EvaluationCriterionScore;
use App\Models\User;
use App\Support\SettingsRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Scores one criterion of an evaluation, with the reasoning that makes the
 * number defensible.
 *
 * ONE CRITERION PER CALL, because that is how a scorecard is actually filled:
 * an evaluator settles relevance on Tuesday and sustainability a fortnight
 * later, and a whole-scorecard save would make the second overwrite the first
 * with whatever the form last held.
 *
 * The criterion must be one the instance recognises
 * (`evaluation.criteria`, tenant override → instance setting → config
 * default). Refusing an unknown key is not pedantry: the overall score is the
 * weighted mean of these rows, so a stray criterion silently changes every
 * evaluation's headline figure.
 */
class RecordCriterionScore
{
    /**
     * @param  string|null  $score  decimal string, or null to clear the score
     */
    public function __invoke(
        Evaluation $evaluation,
        User $actor,
        string $criterion,
        ?string $score,
        string $justification,
        ?string $evidenceReference = null,
        ?string $weight = null,
    ): EvaluationCriterionScore {
        Gate::forUser($actor)->authorize('update', $evaluation);

        if (! $evaluation->isEditable()) {
            throw EvaluationRuleViolation::notEditable($evaluation->status);
        }

        $settings = app(SettingsRepository::class);

        $this->assertKnownCriterion($settings, $criterion);

        $scoreMax = $settings->int('evaluation', 'score_max', 5);
        $justification = trim($justification);

        if ($score !== null) {
            $this->assertInRange($score, $scoreMax);

            // A number with no reasoning is an opinion. The justification is
            // required only WITH a score: clearing a score back to "not yet
            // answered" is a legitimate correction.
            if ($justification === '') {
                throw EvaluationRuleViolation::justificationRequired();
            }
        }

        return DB::transaction(function () use (
            $evaluation, $actor, $criterion, $score, $justification, $evidenceReference, $weight
        ): EvaluationCriterionScore {
            // Upsert under a lock rather than by a DB unique: soft deletes
            // make unique(evaluation_id, criterion) either block re-adding a
            // removed criterion or — with deleted_at in the key — enforce
            // nothing. Two evaluators saving the same criterion at once
            // serialise here instead of creating two rows that would both
            // count towards the weighted mean.
            $row = EvaluationCriterionScore::query()
                ->where('evaluation_id', $evaluation->id)
                ->where('criterion', $criterion)
                ->lockForUpdate()
                ->first();

            $attributes = [
                'score' => $score,
                'justification' => $justification === '' ? null : $justification,
                'evidence_reference' => $evidenceReference === null || trim($evidenceReference) === ''
                    ? null
                    : trim($evidenceReference),
                'scored_by_id' => $actor->id,
            ];

            if ($weight !== null && is_numeric($weight) && (float) $weight > 0) {
                $attributes['weight'] = $weight;
            }

            if ($row === null) {
                $row = EvaluationCriterionScore::create([
                    ...$attributes,
                    'evaluation_id' => $evaluation->id,
                    'criterion' => $criterion,
                ]);
            } else {
                $row->fill($attributes)->save();
            }

            // Not fillable: when the judgement was made is an audit fact, and
            // clearing a score clears it rather than leaving a timestamp that
            // claims an answer exists.
            $row->forceFill(['scored_at' => $score === null ? null : now()])->save();

            return $row;
        });
    }

    private function assertKnownCriterion(SettingsRepository $settings, string $criterion): void
    {
        $criteria = $settings->strings(
            'evaluation',
            'criteria',
            ['relevance', 'efficiency', 'effectiveness', 'impact', 'sustainability'],
        );

        if (! in_array($criterion, $criteria, true)) {
            throw EvaluationRuleViolation::unknownCriterion($criterion);
        }
    }

    /**
     * Range is checked on DIGITS, never through a float: the score column is
     * decimal(5,2) and the comparison happens in integer hundredths, the same
     * way the reporting module compares physical progress.
     */
    private function assertInRange(string $score, int $scoreMax): void
    {
        if (! is_numeric($score)) {
            throw EvaluationRuleViolation::scoreOutOfRange($score, $scoreMax);
        }

        $hundredths = (int) round(((float) $score) * 100);

        if ($hundredths < 0 || $hundredths > $scoreMax * 100) {
            throw EvaluationRuleViolation::scoreOutOfRange($score, $scoreMax);
        }
    }
}
