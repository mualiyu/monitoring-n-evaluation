<?php

namespace Database\Factories;

use App\Actions\Evaluation\ResolveReportTemplate;
use App\Models\Evaluation;
use App\Models\EvaluationReportSection;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Tenant-owned: never sets tenant_id. Run inside a bound tenant context.
 *
 * Note that CommissionEvaluation already seeds the whole eleven-section
 * skeleton, so most tests want ->sections() off a committed evaluation rather
 * than this factory. It exists for the cases that need one section in a
 * particular state without commissioning anything.
 *
 * @extends Factory<EvaluationReportSection>
 */
class EvaluationReportSectionFactory extends Factory
{
    protected $model = EvaluationReportSection::class;

    public function definition(): array
    {
        return [
            'evaluation_id' => Evaluation::factory(),
            'key' => 'findings',
            'heading' => 'Findings',
            'ordinal' => 8,
            'body' => null,
            'is_required' => true,
            'updated_by_id' => null,
            'drafted_at' => null,
        ];
    }

    public function forEvaluation(Evaluation $evaluation): static
    {
        return $this->state(['evaluation_id' => $evaluation->id]);
    }

    /**
     * A section from the configured template, by key — so a fixture and the
     * real report builder never disagree about a heading.
     */
    public function template(string $key): static
    {
        $ordinal = 1;

        foreach ((new ResolveReportTemplate)() as $section) {
            if ($section['key'] === $key) {
                return $this->state([
                    'key' => $section['key'],
                    'heading' => $section['heading'],
                    'ordinal' => $ordinal,
                    'is_required' => $section['required'],
                ]);
            }

            $ordinal++;
        }

        return $this->state(['key' => $key, 'heading' => $key]);
    }

    public function written(string $body = 'Physical delivery stands at 62% against a planned 70%.'): static
    {
        return $this->state([
            'body' => $body,
            'drafted_at' => CarbonImmutable::now(),
        ]);
    }

    public function blank(): static
    {
        return $this->state(['body' => null, 'drafted_at' => null]);
    }

    public function optional(): static
    {
        return $this->state(['is_required' => false]);
    }
}
