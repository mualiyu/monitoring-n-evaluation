<?php

namespace Database\Seeders;

use App\Enums\ChecklistResponseType;
use App\Enums\InspectionType;
use App\Models\InspectionChecklistTemplate;
use App\Models\InspectionChecklistTemplateItem;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\Seeder;

/**
 * The state's inspection instruments — GLOBAL reference data, so this ships in
 * production alongside the sectors and the reporting calendar, not with the
 * demo fixtures.
 *
 * Two instruments, which is the honest minimum: a general site-monitoring
 * sweep usable on any project, and a final-inspection instrument, because the
 * questions that gate a completion certificate are not the questions you ask
 * a site mid-build (digest §8, step 5).
 *
 * IDEMPOTENT: templates are matched by name and their items rewritten in
 * place, so re-running converges rather than accumulating. A state that has
 * edited an item keeps the edit unless the prompt itself changed — the seeder
 * owns the SET of questions, not their wording forever.
 *
 * Runs with no tenant bound: this table has no tenant_id, and binding one
 * would be a lie about what the data is.
 */
class InspectionChecklistSeeder extends Seeder
{
    public function run(): void
    {
        app(CurrentTenant::class)->runWithoutTenant(function (): void {
            foreach ($this->instruments() as $definition) {
                $this->sync($definition);
            }
        });
    }

    /**
     * @param  array{name: string, description: string, type: InspectionType|null, items: list<array<string, mixed>>}  $definition
     */
    private function sync(array $definition): void
    {
        $template = InspectionChecklistTemplate::query()->firstOrNew(['name' => $definition['name']]);

        $template->fill([
            'description' => $definition['description'],
            'inspection_type' => $definition['type'],
            'sector_id' => null,
            'is_active' => true,
        ])->save();

        foreach ($definition['items'] as $position => $item) {
            $existing = InspectionChecklistTemplateItem::query()
                ->where('inspection_checklist_template_id', $template->id)
                ->where('prompt', $item['prompt'])
                ->first();

            ($existing ?? new InspectionChecklistTemplateItem)
                ->fill([
                    ...$item,
                    'inspection_checklist_template_id' => $template->id,
                    'position' => $position,
                ])
                ->save();
        }
    }

    /**
     * @return list<array{name: string, description: string, type: InspectionType|null, items: list<array<string, mixed>>}>
     */
    private function instruments(): array
    {
        return [
            [
                'name' => 'General site monitoring checklist',
                'description' => 'The state-wide instrument for routine monitoring visits, usable on any project where no '
                    .'sector-specific checklist applies. Every MDA answers the same questions, which is what makes the '
                    .'answers comparable across the state.',
                'type' => null,
                'items' => [
                    [
                        'prompt' => 'Is the work on site consistent with the approved drawings and specification?',
                        'guidance' => 'Compare what is built against the approved drawing. Note any deviation, however small.',
                        'response_type' => ChecklistResponseType::YesNo,
                        'finding_on_no' => true,
                        'is_required' => true,
                    ],
                    [
                        'prompt' => 'Is the contractor’s signboard displayed, showing the project title, contract sum and duration?',
                        'guidance' => 'Public disclosure at the site is a standing requirement, not a courtesy.',
                        'response_type' => ChecklistResponseType::YesNo,
                        'finding_on_no' => true,
                        'is_required' => true,
                    ],
                    [
                        'prompt' => 'Rate the quality of workmanship observed on site.',
                        'guidance' => '1 = unacceptable, 5 = fully to specification. Two or below must be written up.',
                        'response_type' => ChecklistResponseType::Rating,
                        'rating_scale' => 5,
                        'finding_on_no' => true,
                        'finding_threshold' => '2.00',
                        'is_required' => true,
                    ],
                    [
                        'prompt' => 'Are materials on site stored and protected in line with specification?',
                        'guidance' => 'Cement under cover, reinforcement off the ground, aggregates uncontaminated.',
                        'response_type' => ChecklistResponseType::YesNo,
                        'finding_on_no' => true,
                        'is_required' => true,
                    ],
                    [
                        'prompt' => 'Are site safety measures in place (signage, barricades, protective equipment)?',
                        'guidance' => null,
                        'response_type' => ChecklistResponseType::YesNo,
                        'finding_on_no' => true,
                        'is_required' => true,
                    ],
                    [
                        'prompt' => 'How many workers were present on site at the time of the visit?',
                        'guidance' => 'A descriptive count. Persistently low numbers against the programme are a schedule risk.',
                        'response_type' => ChecklistResponseType::Numeric,
                        'unit' => 'workers',
                        'finding_on_no' => false,
                        'is_required' => true,
                    ],
                    [
                        'prompt' => 'Describe the condition of the access road serving the site.',
                        'guidance' => null,
                        'response_type' => ChecklistResponseType::Text,
                        'finding_on_no' => false,
                        'is_required' => false,
                    ],
                    [
                        'prompt' => 'Were any community concerns raised during the visit?',
                        'guidance' => 'The manual asks for participatory monitoring; what the community says on the day belongs on the record.',
                        'response_type' => ChecklistResponseType::Text,
                        'finding_on_no' => false,
                        'is_required' => false,
                    ],
                ],
            ],
            [
                'name' => 'Final inspection checklist',
                'description' => 'The instrument for the final inspection that precedes a completion certificate. These are '
                    .'the questions that gate certification, and they are deliberately not the questions asked mid-build.',
                'type' => InspectionType::Final,
                'items' => [
                    [
                        'prompt' => 'Are all contracted works complete, including the punch list from the last visit?',
                        'guidance' => null,
                        'response_type' => ChecklistResponseType::YesNo,
                        'finding_on_no' => true,
                        'is_required' => true,
                    ],
                    [
                        'prompt' => 'Have all required tests and certifications been produced by the contractor?',
                        'guidance' => 'Concrete cube results, electrical certification, water quality — whatever the specification demands.',
                        'response_type' => ChecklistResponseType::YesNo,
                        'finding_on_no' => true,
                        'is_required' => true,
                    ],
                    [
                        'prompt' => 'Has the site been cleared of plant, spoil and temporary works?',
                        'guidance' => null,
                        'response_type' => ChecklistResponseType::YesNo,
                        'finding_on_no' => true,
                        'is_required' => true,
                    ],
                    [
                        'prompt' => 'Rate the overall finish of the completed works.',
                        'guidance' => '1 = unacceptable, 5 = fully to specification. Two or below must be written up.',
                        'response_type' => ChecklistResponseType::Rating,
                        'rating_scale' => 5,
                        'finding_on_no' => true,
                        'finding_threshold' => '2.00',
                        'is_required' => true,
                    ],
                    [
                        'prompt' => 'Have as-built drawings and operating manuals been handed over?',
                        'guidance' => null,
                        'response_type' => ChecklistResponseType::YesNo,
                        'finding_on_no' => true,
                        'is_required' => true,
                    ],
                    [
                        'prompt' => 'List any outstanding defects to be made good before certification.',
                        'guidance' => 'Write "none" if there are none — a blank reads as "not checked".',
                        'response_type' => ChecklistResponseType::Text,
                        'finding_on_no' => false,
                        'is_required' => true,
                    ],
                ],
            ],
        ];
    }
}
