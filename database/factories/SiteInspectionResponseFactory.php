<?php

namespace Database\Factories;

use App\Enums\ChecklistResponseType;
use App\Models\InspectionChecklistTemplateItem;
use App\Models\SiteInspection;
use App\Models\SiteInspectionResponse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Tenant-owned: this factory NEVER sets tenant_id. Run it inside a bound
 * tenant context and BelongsToTenant fills it.
 *
 * `prompt` and `response_type` are snapshots of the global template item, so
 * the fixture states both — a response that reads its prompt from the live
 * template would hide the very drift the snapshot exists to prevent.
 *
 * @extends Factory<SiteInspectionResponse>
 */
class SiteInspectionResponseFactory extends Factory
{
    protected $model = SiteInspectionResponse::class;

    public function definition(): array
    {
        return [
            'site_inspection_id' => SiteInspection::factory(),
            'inspection_checklist_template_item_id' => InspectionChecklistTemplateItem::factory(),
            'prompt' => 'Is the work on site consistent with the approved drawings and specification?',
            'response_type' => ChecklistResponseType::YesNo,
            'value_boolean' => true,
            'value_number' => null,
            'value_text' => null,
            'is_finding' => false,
            'note' => null,
        ];
    }

    public function forInspection(SiteInspection $inspection): static
    {
        return $this->state(['site_inspection_id' => $inspection->id]);
    }

    public function forItem(InspectionChecklistTemplateItem $item): static
    {
        return $this->state([
            'inspection_checklist_template_item_id' => $item->id,
            'prompt' => $item->prompt,
            'response_type' => $item->response_type,
        ]);
    }

    /** An unfavourable answer, with the explanation the domain rule demands. */
    public function finding(string $note = 'Blinding poured directly onto uncompacted fill on the eastern bay.'): static
    {
        return $this->state([
            'value_boolean' => false,
            'is_finding' => true,
            'note' => $note,
        ]);
    }

    public function rating(string $value = '4.00'): static
    {
        return $this->state([
            'response_type' => ChecklistResponseType::Rating,
            'prompt' => 'Rate the quality of workmanship observed on site.',
            'value_boolean' => null,
            'value_number' => $value,
        ]);
    }

    public function numeric(string $value = '14.00'): static
    {
        return $this->state([
            'response_type' => ChecklistResponseType::Numeric,
            'prompt' => 'How many workers were present on site at the time of the visit?',
            'value_boolean' => null,
            'value_number' => $value,
        ]);
    }

    public function text(string $value = 'Laterite surface, passable to light vehicles in dry weather only.'): static
    {
        return $this->state([
            'response_type' => ChecklistResponseType::Text,
            'prompt' => 'Describe the condition of the access road serving the site.',
            'value_boolean' => null,
            'value_text' => $value,
        ]);
    }
}
