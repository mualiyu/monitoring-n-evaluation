<?php

declare(strict_types=1);

namespace App\Actions\Lifecycle\Concerns;

use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Has a final inspection actually happened on this project?" — the question
 * `monitoring.require_final_inspection_for_certification` turns into a guard.
 *
 * DELIBERATELY NOT AN ELOQUENT MODEL CALL. `site_inspections` belongs to the
 * inspections module, which ships on its own schedule; certification must
 * behave correctly both before that table exists (the setting is off by
 * default, and a state that turns it on with no inspections gets a clear
 * refusal rather than a 500) and after it lands. A query builder read behind
 * Schema::hasTable() is the honest expression of a soft dependency — a
 * belongsTo on a class that may not be there is not.
 *
 * TENANCY: the filter is `project_id`, never a tenant clause. Project ids are
 * globally unique and the project was loaded through the TenantScope, so a
 * foreign project cannot be the subject in the first place; a manual
 * `tenant_id` predicate here would be both redundant and a rules violation.
 */
trait FindsFinalInspection
{
    /** Inspection states that mean the visit happened and the report exists. */
    private const COMPLETED_INSPECTION_STATES = ['submitted', 'reviewed'];

    /**
     * The most recent completed final inspection, as a plain row — or null
     * when there is none (or the module is not installed).
     *
     * @return array{id: int, conducted_at: string|null, outcome: string|null, status: string}|null
     */
    protected function finalInspectionFor(Project $project): ?array
    {
        if (! Schema::hasTable('site_inspections')) {
            return null;
        }

        $row = DB::table('site_inspections')
            ->where('project_id', $project->id)
            ->where('type', 'final')
            ->whereIn('status', self::COMPLETED_INSPECTION_STATES)
            ->whereNull('deleted_at')
            ->orderByDesc('conducted_at')
            ->orderByDesc('id')
            ->first(['id', 'conducted_at', 'outcome', 'status']);

        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'conducted_at' => is_string($row->conducted_at) ? $row->conducted_at : null,
            'outcome' => is_string($row->outcome) ? $row->outcome : null,
            'status' => (string) $row->status,
        ];
    }

    protected function hasFinalInspection(Project $project): bool
    {
        return $this->finalInspectionFor($project) !== null;
    }
}
