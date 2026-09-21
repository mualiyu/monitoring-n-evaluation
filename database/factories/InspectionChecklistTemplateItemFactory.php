<?php

namespace Database\Factories;

use App\Enums\ChecklistResponseType;
use App\Models\InspectionChecklistTemplate;
use App\Models\InspectionChecklistTemplateItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Global reference data, like its parent — no tenant_id anywhere.
 *
 * @extends Factory<InspectionChecklistTemplateItem>
 */
class InspectionChecklistTemplateItemFactory extends Factory
{
    protected $model = InspectionChecklistTemplateItem::class;

    public function definition(): array
    {
        return [
            'inspection_checklist_template_id' => InspectionChecklistTemplate::factory(),
            'prompt' => 'Is the work on site consistent with the approved drawings and specification?',
            'guidance' => 'Compare what is built against the approved drawing. Note any deviation, however small.',
            'response_type' => ChecklistResponseType::YesNo,
            'finding_on_no' => true,
            'finding_threshold' => null,
            'rating_scale' => null,
            'unit' => null,
            'is_required' => true,
            'position' => 0,
        ];
    }

    public function yesNo(): static
    {
        return $this->state([
            'prompt' => 'Is the work on site consistent with the approved drawings and specification?',
            'response_type' => ChecklistResponseType::YesNo,
            'finding_on_no' => true,
            'finding_threshold' => null,
        ]);
    }

    /** A 1–5 rating where 2 or below is a finding. */
    public function rating(int $scale = 5, string $threshold = '2.00'): static
    {
        return $this->state([
            'prompt' => 'Rate the quality of workmanship observed on site.',
            'guidance' => '1 = unacceptable, 5 = fully to specification.',
            'response_type' => ChecklistResponseType::Rating,
            'rating_scale' => $scale,
            'finding_on_no' => true,
            'finding_threshold' => $threshold,
        ]);
    }

    /** A descriptive number that is never a failure on its own. */
    public function numeric(): static
    {
        return $this->state([
            'prompt' => 'How many workers were present on site at the time of the visit?',
            'guidance' => null,
            'response_type' => ChecklistResponseType::Numeric,
            'unit' => 'workers',
            'finding_on_no' => false,
            'finding_threshold' => null,
        ]);
    }

    public function text(): static
    {
        return $this->state([
            'prompt' => 'Describe the condition of the access road serving the site.',
            'guidance' => null,
            'response_type' => ChecklistResponseType::Text,
            'finding_on_no' => false,
            'finding_threshold' => null,
            'is_required' => false,
        ]);
    }

    public function optional(): static
    {
        return $this->state(['is_required' => false]);
    }
}
