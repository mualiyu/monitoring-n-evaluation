<?php

namespace Database\Factories;

use App\Enums\CertificateType;
use App\Models\Certificate;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Certificates are tenant-owned: this factory NEVER sets tenant_id. Run it
 * inside a bound tenant context and BelongsToTenant fills it.
 *
 * `reference`, `issued_at` and the revocation columns are deliberately not
 * fillable — the Actions in App\Actions\Lifecycle are their only writers in
 * application code. Factories run unguarded, which is the point: a fixture may
 * state where a record IS, while only an Action may put it there.
 *
 * `site_inspection_id` defaults to null and carries no foreign key: the
 * inspections module ships independently, so a fixture that invented an id
 * would be asserting a row that may not exist.
 *
 * @extends Factory<Certificate>
 */
class CertificateFactory extends Factory
{
    protected $model = Certificate::class;

    public function definition(): array
    {
        $type = CertificateType::PracticalCompletion;
        $issuedAt = CarbonImmutable::now()->subDays(fake()->numberBetween(1, 120));

        return [
            'project_id' => Project::factory()->completed(),
            'type' => $type,
            // Shaped like the minted reference (<SLUG>/PC/<year>/<0000>) but
            // uniquified: the real allocator runs inside the Action, and a
            // fixture must not collide with the tenant+reference unique index.
            'reference' => 'FIX/'.$type->referenceSegment().'/'.$issuedAt->year.'/'.fake()->unique()->numerify('####'),
            'site_inspection_id' => null,
            'narrative' => 'Works inspected and accepted. Minor snags listed in the inspection report have been made good.',
            'defects_liability_ends_on' => $issuedAt->addMonths(12)->toDateString(),
            'issued_by_id' => User::factory(),
            'issued_at' => $issuedAt,
            'revoked_by_id' => null,
            'revoked_at' => null,
            'revocation_reason' => null,
            'created_by_id' => User::factory(),
        ];
    }

    public function practicalCompletion(): static
    {
        return $this->ofType(CertificateType::PracticalCompletion);
    }

    /** Final completion closes the defects window, so it carries no end date. */
    public function finalCompletion(): static
    {
        return $this->ofType(CertificateType::FinalCompletion)
            ->state(['defects_liability_ends_on' => null]);
    }

    public function ofType(CertificateType $type): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => $type,
            'reference' => 'FIX/'.$type->referenceSegment().'/'
                .CarbonImmutable::now()->year.'/'.fake()->unique()->numerify('####'),
        ]);
    }

    /** Withdrawn, on the record — never deleted. */
    public function revoked(string $reason = 'Issued against the wrong contract lot; corrected certificate to follow.'): static
    {
        return $this->state([
            'revoked_by_id' => User::factory(),
            'revoked_at' => CarbonImmutable::now()->subDay(),
            'revocation_reason' => $reason,
        ]);
    }

    /** Still inside the window in which the contractor can be recalled. */
    public function underDefectsLiability(): static
    {
        return $this->state([
            'defects_liability_ends_on' => CarbonImmutable::now()->addMonths(6)->toDateString(),
        ]);
    }

    /** The defects window has run out. */
    public function defectsLiabilityExpired(): static
    {
        return $this->state([
            'defects_liability_ends_on' => CarbonImmutable::now()->subMonth()->toDateString(),
        ]);
    }

    public function forProject(Project $project): static
    {
        return $this->state(['project_id' => $project->id]);
    }

    public function issuedBy(User $user): static
    {
        return $this->state(['issued_by_id' => $user->id, 'created_by_id' => $user->id]);
    }

    public function issuedOn(CarbonImmutable $moment): static
    {
        return $this->state(['issued_at' => $moment]);
    }
}
