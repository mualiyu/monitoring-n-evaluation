<?php

namespace Database\Factories;

use App\Models\Evaluation;
use App\Models\EvaluationCriterionScore;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Tenant-owned: never sets tenant_id. Run inside a bound tenant context.
 *
 * `score` is nullable and the default is UNSCORED, deliberately: that is the
 * state a freshly commissioned evaluation's scorecard is in, and a factory
 * whose default was "already answered" would let a completeness test pass
 * without ever exercising the gate it is meant to prove.
 *
 * @extends Factory<EvaluationCriterionScore>
 */
class EvaluationCriterionScoreFactory extends Factory
{
    protected $model = EvaluationCriterionScore::class;

    public function definition(): array
    {
        return [
            'evaluation_id' => Evaluation::factory(),
            'criterion' => 'relevance',
            'score' => null,
            'weight' => '1.00',
            'justification' => null,
            'evidence_reference' => null,
            'scored_by_id' => null,
            'scored_at' => null,
        ];
    }

    public function forEvaluation(Evaluation $evaluation): static
    {
        return $this->state(['evaluation_id' => $evaluation->id]);
    }

    public function criterion(string $criterion): static
    {
        return $this->state(['criterion' => $criterion]);
    }

    public function scored(string $score = '4.00', ?User $by = null): static
    {
        return $this->state([
            'score' => $score,
            'justification' => 'Beneficiary interviews and the 2026 needs assessment both place this '
                .'intervention among the three highest-priority works in the LGA.',
            'evidence_reference' => 'Findings §4.2',
            'scored_by_id' => $by?->id ?? User::factory(),
            'scored_at' => CarbonImmutable::now(),
        ]);
    }

    public function unscored(): static
    {
        return $this->state([
            'score' => null,
            'justification' => null,
            'scored_at' => null,
        ]);
    }

    public function weighted(string $weight): static
    {
        return $this->state(['weight' => $weight]);
    }
}
