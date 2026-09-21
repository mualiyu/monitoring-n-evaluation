<?php

namespace App\Actions\Inspections;

use App\Enums\ChecklistResponseType;
use App\Exceptions\Inspections\InspectionRuleViolation;
use App\Models\InspectionChecklistTemplateItem;
use App\Models\SiteInspection;
use App\Models\SiteInspectionResponse;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Records the inspector's answers to the checklist — the other half of the
 * conduct form's autosave, and the reason the instrument is worth having.
 *
 * ONE ANSWER PER ITEM, held under a row lock rather than by a DB unique. Soft
 * deletes make `unique(inspection_id, item_id)` either block a legitimate
 * re-answer or, with `deleted_at` in the key, enforce nothing — the same trap
 * documented on `progress_reports`. A field monitor on a flaky connection
 * re-sends the same answer constantly; every one of those must update the
 * existing row, not add a second.
 *
 * The prompt, the response type and the finding judgement are SNAPSHOTTED onto
 * each response. The template is global and curated by the state: it will be
 * reworded and its thresholds retuned, and neither may rewrite what a report
 * from two years ago says was asked or was wrong.
 */
class RecordChecklistResponses
{
    /**
     * @param  array<int, array{value: bool|float|string|null, note?: string|null}>  $answers
     *                                                                                         keyed by checklist item id
     * @return int the number of items recorded
     */
    public function __invoke(SiteInspection $inspection, User $actor, array $answers): int
    {
        Gate::forUser($actor)->authorize('conduct', $inspection);

        if (! $inspection->isEditable()) {
            throw InspectionRuleViolation::notUnderWay($inspection->status);
        }

        if ($answers === []) {
            return 0;
        }

        $items = $this->itemsOnInstrument($inspection, array_keys($answers));

        return DB::transaction(function () use ($inspection, $items, $answers): int {
            $recorded = 0;

            foreach ($items as $item) {
                $answer = $answers[$item->id];
                $value = $answer['value'] ?? null;
                $note = isset($answer['note']) && trim((string) $answer['note']) !== ''
                    ? trim((string) $answer['note'])
                    : null;

                $isFinding = $item->judge($this->normalise($item->response_type, $value));

                // A flagged item with no explanation cannot be acted on by
                // anyone — not the contractor who must fix it, not the officer
                // who signs it off. Enforced at the point of recording rather
                // than only at submission, so the inspector is told while they
                // are still standing in front of the thing they are describing.
                if ($isFinding && $note === null) {
                    throw InspectionRuleViolation::findingNeedsNote($item->prompt);
                }

                $existing = SiteInspectionResponse::query()
                    ->where('site_inspection_id', $inspection->id)
                    ->where('inspection_checklist_template_item_id', $item->id)
                    ->lockForUpdate()
                    ->first();

                $attributes = [
                    'site_inspection_id' => $inspection->id,
                    'inspection_checklist_template_item_id' => $item->id,
                    'prompt' => $item->prompt,
                    'response_type' => $item->response_type,
                    'value_boolean' => null,
                    'value_number' => null,
                    'value_text' => null,
                    'is_finding' => $isFinding,
                    'note' => $note,
                ];

                $attributes[$item->response_type->column()] = $this->normalise($item->response_type, $value);

                if ($existing === null) {
                    SiteInspectionResponse::query()->create($attributes);
                } else {
                    $existing->fill($attributes)->save();
                }

                $recorded++;
            }

            return $recorded;
        });
    }

    /**
     * The items being answered, confirmed to belong to the instrument this
     * inspection is actually being conducted against.
     *
     * The conduct form only renders its own instrument's items, but a Livewire
     * endpoint takes any payload — and the template table is GLOBAL, shared
     * across every MDA, so an unchecked item id is the one identifier in this
     * module that a caller could supply from outside their own workspace. It
     * would not leak anything (the response row lands in the caller's own
     * tenant) but it would silently corrupt the cross-MDA comparison the
     * global checklist exists for.
     *
     * @param  list<int>  $itemIds
     * @return list<InspectionChecklistTemplateItem>
     */
    private function itemsOnInstrument(SiteInspection $inspection, array $itemIds): array
    {
        if ($inspection->inspection_checklist_template_id === null) {
            throw InspectionRuleViolation::itemNotOnInstrument();
        }

        /** @var list<InspectionChecklistTemplateItem> $items */
        $items = InspectionChecklistTemplateItem::query()
            ->where('inspection_checklist_template_id', $inspection->inspection_checklist_template_id)
            ->whereIn('id', $itemIds)
            ->orderBy('position')
            ->get()
            ->all();

        if (count($items) !== count($itemIds)) {
            throw InspectionRuleViolation::itemNotOnInstrument();
        }

        return $items;
    }

    /**
     * Coerce the submitted value into the shape its column expects. A blank
     * string is an unanswered item, not a zero — an inspector who clears a
     * field has withdrawn the answer, and storing 0 would be recording an
     * observation they did not make.
     */
    private function normalise(ChecklistResponseType $type, bool|float|int|string|null $value): bool|string|null
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        return match ($type) {
            ChecklistResponseType::YesNo => filter_var($value, FILTER_VALIDATE_BOOL),
            ChecklistResponseType::Rating, ChecklistResponseType::Numeric => is_numeric($value)
                ? number_format((float) $value, 2, '.', '')
                : null,
            ChecklistResponseType::Text => trim((string) $value),
        };
    }
}
