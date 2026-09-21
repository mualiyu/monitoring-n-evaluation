<?php

namespace Database\Factories;

use App\Enums\InspectionType;
use App\Models\InspectionChecklistTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Checklist templates are GLOBAL reference data: this factory never sets a
 * tenant_id, because the table has no such column. A state's instrument is
 * state-wide, so that the cross-MDA question the checklist exists for stays
 * answerable.
 *
 * @extends Factory<InspectionChecklistTemplate>
 */
class InspectionChecklistTemplateFactory extends Factory
{
    protected $model = InspectionChecklistTemplate::class;

    public function definition(): array
    {
        return [
            'name' => 'General site monitoring checklist',
            'description' => 'The state-wide instrument used on routine monitoring visits where no sector-specific checklist applies.',
            'inspection_type' => null,
            'sector_id' => null,
            'is_active' => true,
        ];
    }

    public function forType(InspectionType $type): static
    {
        return $this->state(['inspection_type' => $type]);
    }

    public function forSector(int $sectorId): static
    {
        return $this->state(['sector_id' => $sectorId]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    /**
     * A template with a usable spread of item shapes — a yes/no that fails on
     * "no", a rating with a threshold, a descriptive number and a written
     * answer. Enough for a test to exercise every branch of the judging rule.
     */
    public function withItems(int $count = 4): static
    {
        return $this->afterCreating(function (InspectionChecklistTemplate $template) use ($count): void {
            $shapes = [
                fn (int $position) => InspectionChecklistTemplateItemFactory::new()->yesNo()->state(['position' => $position]),
                fn (int $position) => InspectionChecklistTemplateItemFactory::new()->rating()->state(['position' => $position]),
                fn (int $position) => InspectionChecklistTemplateItemFactory::new()->numeric()->state(['position' => $position]),
                fn (int $position) => InspectionChecklistTemplateItemFactory::new()->text()->state(['position' => $position]),
            ];

            for ($index = 0; $index < $count; $index++) {
                $shapes[$index % count($shapes)]($index)
                    ->for($template, 'template')
                    ->create();
            }
        });
    }
}
