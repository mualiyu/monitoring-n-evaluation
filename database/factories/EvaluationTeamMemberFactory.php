<?php

namespace Database\Factories;

use App\Models\Evaluation;
use App\Models\EvaluationTeamMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Tenant-owned: never sets tenant_id. Run inside a bound tenant context.
 *
 * @extends Factory<EvaluationTeamMember>
 */
class EvaluationTeamMemberFactory extends Factory
{
    protected $model = EvaluationTeamMember::class;

    public function definition(): array
    {
        return [
            'evaluation_id' => Evaluation::factory(),
            'user_id' => User::factory(),
            'external_name' => null,
            'external_organisation' => null,
            'role' => EvaluationTeamMember::ROLE_MEMBER,
            'expertise' => 'Civil engineering',
        ];
    }

    public function forEvaluation(Evaluation $evaluation): static
    {
        return $this->state(['evaluation_id' => $evaluation->id]);
    }

    /** The evaluator who signs for the findings — and who may not approve them. */
    public function lead(?User $user = null): static
    {
        return $this->state(array_filter([
            'role' => EvaluationTeamMember::ROLE_LEAD,
            'user_id' => $user?->id,
        ], fn (mixed $value): bool => $value !== null));
    }

    public function member(?User $user = null): static
    {
        return $this->state(array_filter([
            'role' => EvaluationTeamMember::ROLE_MEMBER,
            'user_id' => $user?->id,
        ], fn (mixed $value): bool => $value !== null));
    }

    /**
     * A contracted evaluator with no account here — the common case, and the
     * reason user_id is nullable.
     */
    public function external(string $name = 'Dr. A. Balogun', string $organisation = 'Independent'): static
    {
        return $this->state([
            'user_id' => null,
            'external_name' => $name,
            'external_organisation' => $organisation,
        ]);
    }
}
