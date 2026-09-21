<?php

namespace Database\Factories;

use App\Enums\ConsolidatedReportType;
use App\Models\ConsolidatedReport;
use App\Models\ConsolidatedReportSection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * GLOBAL, like its parent — no tenant_id, no bound tenant required.
 *
 * OpenConsolidation already seeds the whole skeleton for the type, so most
 * tests want ->written() against a section that already exists rather than
 * this factory. It is here for the cases that need one chapter in a particular
 * state without opening anything.
 *
 * @extends Factory<ConsolidatedReportSection>
 */
class ConsolidatedReportSectionFactory extends Factory
{
    protected $model = ConsolidatedReportSection::class;

    public function definition(): array
    {
        return [
            'consolidated_report_id' => ConsolidatedReport::factory(),
            'key' => 'executive_summary',
            'heading' => 'Executive summary',
            'body' => null,
            'position' => 0,
            'updated_by_id' => null,
        ];
    }

    public function forReport(ConsolidatedReport $report): static
    {
        return $this->state(['consolidated_report_id' => $report->id]);
    }

    /**
     * A chapter from the TYPE's skeleton, by key — so a fixture and a real
     * consolidation never disagree about a heading or its place in the
     * outline.
     */
    public function skeleton(ConsolidatedReportType $type, string $key): static
    {
        $skeleton = $type->sectionSkeleton();
        $position = array_search($key, array_keys($skeleton), true);

        return $this->state([
            'key' => $key,
            'heading' => $skeleton[$key] ?? $key,
            'position' => $position === false ? 0 : $position,
        ]);
    }

    public function written(string $body = 'Delivery across the state stood at 62% of plan, with two entities filing nothing at all.'): static
    {
        return $this->state(['body' => $body]);
    }

    public function blank(): static
    {
        return $this->state(['body' => null]);
    }

    public function writtenBy(User $user): static
    {
        return $this->written()->state(['updated_by_id' => $user->id]);
    }
}
