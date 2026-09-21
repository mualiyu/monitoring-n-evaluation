<?php

declare(strict_types=1);

namespace App\Livewire\Tenant\Inspections;

use App\Actions\Inspections\ScheduleInspection;
use App\Enums\InspectionType;
use App\Enums\ProjectStatus;
use App\Enums\Role;
use App\Exceptions\Inspections\InspectionRuleViolation;
use App\Models\InspectionChecklistTemplate;
use App\Models\Project;
use App\Models\ProjectLocation;
use App\Models\SiteInspection;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Puts a visit in the diary.
 *
 * One screen, not a wizard: scheduling an inspection is six fields and an
 * officer does it between two meetings. The multi-step treatment belongs to
 * the conduct form, which is filled in standing on a building site.
 *
 * Every rule stated here is re-asserted by ScheduleInspection — the project is
 * inspectable, the date is not in the past, the named lead can actually
 * conduct a visit. This screen exists to stop a doomed round trip and to say
 * WHY in the officer's language; it is not the control, because a Livewire
 * endpoint takes any payload.
 */
#[Layout('layouts::tenant')]
class InspectionSchedule extends Component
{
    /** Pre-selected from a project's detail screen: /inspections/create?project=… */
    #[Url(as: 'project', except: '')]
    public string $projectUlid = '';

    public string $type = 'routine';

    public string $scheduledDate = '';

    public string $leadInspectorId = '';

    public string $locationId = '';

    public string $templateId = '';

    public string $team = '';

    public string $objectives = '';

    /** An Action's own refusal, shown verbatim. */
    public ?string $failure = null;

    public function mount(): void
    {
        $this->authorize('create', SiteInspection::class);

        // Tomorrow, in the instance's timezone: today's visit is normally
        // already happening, and a date field that opens on a date the form
        // will reject is a small daily insult.
        $this->scheduledDate = CarbonImmutable::now()->addDay()->toDateString();
    }

    /**
     * Projects this officer may schedule against. `visibleTo()` is the single
     * definition of project visibility, so the option list and the Action's
     * own authorize() can never disagree.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function projectOptions(): array
    {
        /** @var User $user */
        $user = auth()->user();

        return Project::query()
            ->visibleTo($user)
            ->whereNotIn('status', [ProjectStatus::Draft, ProjectStatus::Cancelled])
            ->orderBy('title')
            ->pluck('title', 'ulid')
            ->all();
    }

    #[Computed]
    public function project(): ?Project
    {
        if ($this->projectUlid === '') {
            return null;
        }

        return Project::query()
            ->with(['locations' => fn ($q) => $q->orderByDesc('is_primary')])
            ->where('ulid', $this->projectUlid)
            ->first();
    }

    /**
     * Who can be named as lead. Only users who hold `inspections.conduct` in
     * THIS workspace — scheduling a visit for someone who cannot file its
     * report guarantees an overdue report a fortnight later.
     *
     * Read through the role names rather than the permission, because spatie's
     * permission lookup per user would be a query per row; the roles that hold
     * the permission are fixed by the seeded matrix.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function inspectorOptions(): array
    {
        return User::query()
            ->role([Role::FieldMonitor->value, Role::MeOfficer->value, Role::MdaAdmin->value])
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Sites on the chosen project. A multi-site project ("12 PHCs across 4
     * LGAs") needs the visit to name which one; a single-site project has one
     * option and the field is a formality.
     *
     * @return array<array-key, string>
     */
    #[Computed]
    public function locationOptions(): array
    {
        $project = $this->project();

        if ($project === null) {
            return [];
        }

        return ProjectLocation::query()
            ->where('project_id', $project->id)
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get(['id', 'site_name', 'is_primary'])
            ->mapWithKeys(fn (ProjectLocation $location): array => [
                // An id-keyed map: <x-ui.form.select> submits the KEY, which
                // is what chosenLocation() then resolves through the project.
                (string) $location->id => (string) ($location->site_name
                    ?? ($location->is_primary ? __('Primary site') : __('Site :id', ['id' => $location->id]))),
            ])
            ->all();
    }

    /**
     * The state's instruments for this visit. Global reference data, offered
     * narrowest-first so a sector-specific checklist beats the general sweep.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function templateOptions(): array
    {
        $type = InspectionType::tryFrom($this->type) ?? InspectionType::Routine;

        return InspectionChecklistTemplate::query()
            ->active()
            ->for($type, $this->project()?->sector_id)
            ->orderByRaw('CASE WHEN sector_id IS NULL THEN 1 ELSE 0 END')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /** @return array<string, string> */
    #[Computed]
    public function typeOptions(): array
    {
        return collect(InspectionType::cases())
            ->mapWithKeys(fn (InspectionType $case) => [$case->value => $case->label()])
            ->all();
    }

    public function updatedProjectUlid(): void
    {
        // A site belongs to a project; carrying the old one across would offer
        // a location that is not on the project now selected.
        $this->locationId = '';
        unset($this->project, $this->locationOptions, $this->templateOptions);
    }

    public function updatedType(): void
    {
        // The instrument list is type-specific: a final-inspection checklist
        // must not survive a switch back to a routine visit.
        $this->templateId = '';
        unset($this->templateOptions);
    }

    public function schedule(ScheduleInspection $schedule): void
    {
        $this->authorize('create', SiteInspection::class);

        $validated = $this->validate([
            'projectUlid' => ['required', 'string'],
            'type' => ['required', 'string', 'in:'.implode(',', array_column(InspectionType::cases(), 'value'))],
            // `date` alone accepts "next thursday" and every other string
            // strtotime() tolerates; the format rule pins it to what the input
            // actually submits.
            'scheduledDate' => ['required', 'date', 'date_format:Y-m-d', 'after_or_equal:today'],
            'leadInspectorId' => ['required', 'integer'],
            'locationId' => ['nullable', 'integer'],
            'templateId' => ['nullable', 'integer'],
            'team' => ['nullable', 'string', 'max:1000'],
            'objectives' => ['nullable', 'string', 'max:2000'],
        ], [
            'projectUlid.required' => __('Choose the project this visit is for.'),
            'leadInspectorId.required' => __('Name the officer who will lead the visit — a visit with no inspector is a report nobody owes.'),
            'scheduledDate.after_or_equal' => __('A visit cannot be scheduled for a date that has already passed.'),
        ]);

        $project = $this->project();

        if ($project === null) {
            $this->addError('projectUlid', __('That project is not available in this workspace.'));

            return;
        }

        $inspector = User::query()->whereKey($validated['leadInspectorId'])->first();

        if ($inspector === null) {
            $this->addError('leadInspectorId', __('That officer is no longer on the platform.'));

            return;
        }

        $this->failure = null;

        try {
            /** @var User $actor */
            $actor = auth()->user();

            $inspection = $schedule(
                project: $project,
                type: InspectionType::from($validated['type']),
                scheduledDate: CarbonImmutable::parse($validated['scheduledDate'])->startOfDay(),
                leadInspector: $inspector,
                actor: $actor,
                template: $this->chosenTemplate(),
                location: $this->chosenLocation($project),
                team: $this->nullIfBlank($this->team),
                objectives: $this->nullIfBlank($this->objectives),
            );
        } catch (InspectionRuleViolation $exception) {
            $this->failure = $exception->getMessage();

            return;
        }

        session()->flash('status', __('Visit scheduled. The lead inspector has been notified.'));

        $this->redirectRoute('tenant.inspections.show', [
            'tenant' => app(CurrentTenant::class)->getOrFail()->slug,
            'inspection' => $inspection,
        ], navigate: true);
    }

    /**
     * The chosen instrument, confirmed to be one this screen actually offers.
     * The template table is GLOBAL and shared across every MDA, so an id from
     * a payload is the one identifier here that could come from outside the
     * option list — it would leak nothing, but it would attach a clinic
     * checklist to a road inspection.
     */
    private function chosenTemplate(): ?InspectionChecklistTemplate
    {
        if ($this->templateId === '' || ! array_key_exists((int) $this->templateId, $this->templateOptions())) {
            return null;
        }

        return InspectionChecklistTemplate::query()->whereKey($this->templateId)->first();
    }

    /**
     * The chosen site, resolved THROUGH the project — so a location id
     * belonging to another project (or another MDA, which the TenantScope
     * already refuses) matches nothing rather than being attached.
     */
    private function chosenLocation(Project $project): ?ProjectLocation
    {
        if ($this->locationId === '') {
            return null;
        }

        return ProjectLocation::query()
            ->where('project_id', $project->id)
            ->whereKey($this->locationId)
            ->first();
    }

    private function nullIfBlank(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : trim($value);
    }

    public function render(): View
    {
        return view('livewire.tenant.inspections.inspection-schedule');
    }
}
