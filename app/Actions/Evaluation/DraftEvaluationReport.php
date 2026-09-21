<?php

namespace App\Actions\Evaluation;

use App\Exceptions\Evaluation\EvaluationRuleViolation;
use App\Models\Evaluation;
use App\Models\EvaluationReportSection;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Writes the report. The section builder's save — one call, any number of
 * sections, keyed by the STABLE template key rather than by row id.
 *
 * Keyed by `key`, deliberately: the builder posts `{findings: "…"}`, so a
 * payload cannot name a section belonging to another evaluation (or another
 * MDA) by guessing an id. An unknown key is refused rather than silently
 * ignored — a chapter that vanished because it was misspelt is worse than an
 * error message.
 *
 * WHAT THIS ACTION DOES NOT DO: move the status. Marking a draft complete is a
 * lifecycle decision with a completeness guard behind it, and it belongs to
 * TransitionEvaluationStatus like every other move. Autosaving a paragraph
 * must never be the thing that files a report.
 */
class DraftEvaluationReport
{
    /**
     * @param  array<string, string|null>  $sections  template key => body
     * @return int the number of sections written
     */
    public function __invoke(Evaluation $evaluation, User $actor, array $sections): int
    {
        Gate::forUser($actor)->authorize('update', $evaluation);

        if (! $evaluation->isEditable()) {
            throw EvaluationRuleViolation::notEditable($evaluation->status);
        }

        if ($sections === []) {
            return 0;
        }

        return DB::transaction(function () use ($evaluation, $actor, $sections): int {
            $rows = EvaluationReportSection::query()
                ->where('evaluation_id', $evaluation->id)
                ->whereIn('key', array_keys($sections))
                ->lockForUpdate()
                ->get()
                ->keyBy('key');

            foreach (array_keys($sections) as $key) {
                if (! $rows->has($key)) {
                    throw EvaluationRuleViolation::unknownSection((string) $key);
                }
            }

            $written = 0;

            foreach ($sections as $key => $body) {
                /** @var EvaluationReportSection $row */
                $row = $rows->get($key);

                $body = $body === null ? null : rtrim($body);

                if ($row->body === $body) {
                    continue; // nothing changed — do not restamp the author
                }

                $row->fill(['body' => $body, 'updated_by_id' => $actor->id])->save();

                // Not fillable: when a chapter was last written is an audit
                // fact about the report, and clearing a section clears it
                // rather than leaving a stamp claiming prose exists.
                $row->forceFill([
                    'drafted_at' => $body === null || trim($body) === '' ? null : now(),
                ])->save();

                $written++;
            }

            return $written;
        });
    }
}
