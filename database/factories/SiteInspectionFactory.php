<?php

namespace Database\Factories;

use App\Enums\InspectionOutcome;
use App\Enums\InspectionStatus;
use App\Enums\InspectionType;
use App\Models\InspectionChecklistTemplate;
use App\Models\Project;
use App\Models\SiteInspection;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Site inspections are tenant-owned: this factory NEVER sets tenant_id. Run it
 * inside a bound tenant context and BelongsToTenant fills it.
 *
 * `status`, `conducted_at`, `report_due_at` and the whole chain are
 * deliberately not fillable — TransitionInspectionStatus is their only writer
 * in application code. Factories run unguarded, which is the point: a fixture
 * may state where a record IS, while only an Action may move it there.
 *
 * Coordinates are decimal strings, never floats — they are printed on a
 * government report and must round-trip unchanged.
 *
 * @extends Factory<SiteInspection>
 */
class SiteInspectionFactory extends Factory
{
    protected $model = SiteInspection::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory()->ongoing(),
            'project_location_id' => null,
            'inspection_checklist_template_id' => null,
            'type' => InspectionType::Routine,
            'status' => InspectionStatus::Scheduled,
            'scheduled_date' => CarbonImmutable::now()->addDays(3)->startOfDay(),
            'conducted_at' => null,
            'lead_inspector_id' => User::factory(),
            'team' => 'Engr. A. Bello (works), Mrs T. Okon (M&E), site foreman',
            'latitude' => null,
            'longitude' => null,
            'gps_accuracy_metres' => null,
            'gps_captured_at' => null,
            'geofence_distance_metres' => null,
            'geofence_breached' => false,
            'physical_progress_observed' => null,
            'outcome' => null,
            'risk_flags' => null,
            'objectives' => 'Verify reported progress against work actually executed on site.',
            'people_met' => null,
            'methods' => null,
            'findings' => null,
            'comparison_with_previous' => null,
            'conclusions' => null,
            'recommendations' => null,
            'scheduled_by_id' => null,
            'started_at' => null,
            'submitted_by_id' => null,
            'submitted_at' => null,
            'reviewed_by_id' => null,
            'reviewed_at' => null,
            'review_notes' => null,
            'cancelled_by_id' => null,
            'cancelled_at' => null,
            'cancellation_reason' => null,
            'report_due_at' => null,
            'report_overdue_notified_at' => null,
            'report_late' => false,
            'autosaved_at' => null,
            'generated_by' => 'manual',
            'schedule_key' => null,
        ];
    }

    public function forProject(Project $project): static
    {
        return $this->state(['project_id' => $project->id]);
    }

    public function ofType(InspectionType $type): static
    {
        return $this->state(['type' => $type]);
    }

    public function ledBy(User $inspector): static
    {
        return $this->state(['lead_inspector_id' => $inspector->id]);
    }

    public function usingTemplate(InspectionChecklistTemplate $template): static
    {
        return $this->state(['inspection_checklist_template_id' => $template->id]);
    }

    public function scheduled(?CarbonImmutable $on = null): static
    {
        return $this->state([
            'status' => InspectionStatus::Scheduled,
            'scheduled_date' => ($on ?? CarbonImmutable::now()->addDays(3))->startOfDay(),
        ]);
    }

    /** The inspector is on site with the conduct form open. */
    public function inProgress(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => InspectionStatus::InProgress,
            'started_at' => CarbonImmutable::now()->subHours(2),
            'conducted_at' => CarbonImmutable::now()->subHours(2),
            'report_due_at' => CarbonImmutable::now()->addDays(3)->endOfDay(),
            'scheduled_date' => CarbonImmutable::now()->startOfDay(),
        ]);
    }

    /** The Field Trip Report is filed and awaiting sign-off. */
    public function submitted(?User $submitter = null, ?InspectionOutcome $outcome = null): static
    {
        return $this->inProgress()->state(fn (array $attributes): array => [
            'status' => InspectionStatus::Submitted,
            'submitted_by_id' => $submitter->id ?? $attributes['lead_inspector_id'],
            'submitted_at' => CarbonImmutable::now()->subHour(),
            'outcome' => $outcome ?? InspectionOutcome::Satisfactory,
            'physical_progress_observed' => '42.00',
            'people_met' => 'Site engineer, contractor’s project manager, two community representatives.',
            'methods' => 'Physical measurement of completed sections, photographic record, interview with the site engineer.',
            'findings' => 'Sub-base laid across 1.2 km of the northern section. Drainage cast at three of five crossings. '
                .'Reinforcement stacked uncovered at the site store.',
            'comparison_with_previous' => 'Progress since the last visit is broadly in line with the programme; drainage has caught up.',
            'conclusions' => 'Work is proceeding to specification, with a housekeeping concern at the store.',
            'recommendations' => 'Cover stored reinforcement. Re-inspect the two outstanding crossings next visit.',
        ]);
    }

    /** Signed off by an officer who is not the inspector. */
    public function reviewed(?User $reviewer = null): static
    {
        return $this->submitted()->state([
            'status' => InspectionStatus::Reviewed,
            'reviewed_by_id' => $reviewer->id ?? User::factory(),
            'reviewed_at' => CarbonImmutable::now(),
            'review_notes' => 'Findings accepted. Housekeeping item to be carried to the issues register.',
        ]);
    }

    /** A failing site — what the escalation path exists for. */
    public function escalating(InspectionOutcome $outcome = InspectionOutcome::MajorIssues): static
    {
        return $this->submitted(null, $outcome)->state([
            'findings' => 'Concrete cube tests failed at 21 days on two of four pours. Formwork removed early on the north abutment.',
            'recommendations' => 'Halt further pours pending re-test. Instruct the contractor to open up the north abutment.',
            'risk_flags' => ['structural_defect', 'specification_deviation'],
        ]);
    }

    public function cancelled(string $reason = 'Access road impassable after three days of rain; visit deferred to the next cycle.'): static
    {
        return $this->state([
            'status' => InspectionStatus::Cancelled,
            'cancelled_by_id' => User::factory(),
            'cancelled_at' => CarbonImmutable::now(),
            'cancellation_reason' => $reason,
        ]);
    }

    /** A visit that happened but whose report never arrived — the overdue sweep's target. */
    public function reportOverdue(): static
    {
        return $this->inProgress()->state([
            'conducted_at' => CarbonImmutable::now()->subDays(8),
            'started_at' => CarbonImmutable::now()->subDays(8),
            'scheduled_date' => CarbonImmutable::now()->subDays(8)->startOfDay(),
            'report_due_at' => CarbonImmutable::now()->subDays(5)->endOfDay(),
        ]);
    }

    /** A fix recorded on site, with the geofence judgement already made. */
    public function geotagged(string $latitude = '7.2570000', string $longitude = '5.2050000', bool $breached = false): static
    {
        return $this->state([
            'latitude' => $latitude,
            'longitude' => $longitude,
            'gps_accuracy_metres' => 12,
            'gps_captured_at' => CarbonImmutable::now()->subHours(2),
            'geofence_distance_metres' => $breached ? 8400 : 60,
            'geofence_breached' => $breached,
        ]);
    }

    /** Proposed by the scheduling engine rather than by an officer. */
    public function proposed(?string $scheduleKey = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'generated_by' => 'system',
            'schedule_key' => $scheduleKey ?? 'routine:'.CarbonImmutable::now()->format('Y-m'),
        ]);
    }
}
