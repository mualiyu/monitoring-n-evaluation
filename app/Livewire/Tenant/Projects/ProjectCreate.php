<?php

declare(strict_types=1);

namespace App\Livewire\Tenant\Projects;

use App\Actions\Iam\ListTenantMembers;
use App\Actions\Projects\RegisterProject;
use App\Enums\MeasurementFrequency;
use App\Enums\ProjectType;
use App\Exceptions\Projects\ProjectRuleViolation;
use App\Models\FundingSource;
use App\Models\Lga;
use App\Models\Project;
use App\Models\Sector;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\Ward;
use App\Support\Money;
use App\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Project registration wizard (design §5): identity → funding & budget →
 * locations & schedule, then one call to RegisterProject.
 *
 * The component orchestrates and validates *shape*; every domain rule —
 * the ≤100% funding split, the status ledger row, the primary-location
 * invariant — belongs to the Action and is enforced there even if this form
 * is bypassed entirely.
 *
 * Draft autosave writes the in-progress form to the session rather than to a
 * half-built `projects` row: a registry of abandoned skeleton projects is
 * exactly the data-quality mess the M&E manual warns about, and a field
 * officer on 3G losing a page of typing is the real failure mode here.
 */
#[Layout('layouts::tenant')]
class ProjectCreate extends Component
{
    public int $step = 1;

    public const LAST_STEP = 3;

    // Step 1 — identity & scope
    public string $reference = '';

    public string $title = '';

    public string $sector_id = '';

    public string $type = 'capital';

    public string $description = '';

    public string $goal = '';

    public string $objectives = '';

    public string $manager_id = '';

    public string $supervising_agency_name = '';

    // Step 2 — funding & budget
    public string $budget_allocation = '';

    public string $budget_code = '';

    /** @var list<array{funding_source_id: string, percentage: string, amount: string}> */
    public array $funding = [];

    // Step 3 — location & schedule
    public string $site_name = '';

    public string $lga_id = '';

    public string $ward_id = '';

    public string $latitude = '';

    public string $longitude = '';

    public string $start_date = '';

    public string $expected_end_date = '';

    public string $reporting_frequency = '';

    public bool $draftRestored = false;

    public ?string $draftSavedAt = null;

    public ?string $failure = null;

    public function mount(): void
    {
        $this->authorize('create', Project::class);

        if ($this->funding === []) {
            $this->funding = [$this->emptyFundingRow()];
        }

        if (session()->has($this->draftKey())) {
            $this->draftRestored = true;
            /** @var array<string, mixed> $draft */
            $draft = session($this->draftKey(), []);
            $this->fill(array_intersect_key($draft, array_flip($this->draftFields())));
            $this->draftSavedAt = $draft['saved_at'] ?? null;
        }
    }

    /** @return array{funding_source_id: string, percentage: string, amount: string} */
    private function emptyFundingRow(): array
    {
        return ['funding_source_id' => '', 'percentage' => '', 'amount' => ''];
    }

    /** @return list<string> */
    private function draftFields(): array
    {
        return [
            'step', 'reference', 'title', 'sector_id', 'type', 'description', 'goal', 'objectives',
            'manager_id', 'supervising_agency_name', 'budget_allocation', 'budget_code', 'funding',
            'site_name', 'lga_id', 'ward_id', 'latitude', 'longitude', 'start_date',
            'expected_end_date', 'reporting_frequency',
        ];
    }

    private function draftKey(): string
    {
        $tenantId = app(CurrentTenant::class)->id() ?? 0;

        return "project-wizard.{$tenantId}.".(auth()->id() ?? 0);
    }

    /** Autosave on every change — no explicit "save draft" button to forget. */
    public function updated(string $property): void
    {
        if ($property === 'lga_id') {
            $this->ward_id = '';
        }

        $this->saveDraft();
    }

    public function saveDraft(): void
    {
        $draft = collect($this->draftFields())
            ->mapWithKeys(fn (string $field): array => [$field => $this->{$field}])
            ->put('saved_at', now()->toIso8601String())
            ->all();

        session()->put($this->draftKey(), $draft);
        $this->draftSavedAt = $draft['saved_at'];
    }

    public function discardDraft(): void
    {
        session()->forget($this->draftKey());

        $this->reset($this->draftFields());
        $this->funding = [$this->emptyFundingRow()];
        $this->step = 1;
        $this->draftRestored = false;
        $this->draftSavedAt = null;
    }

    public function addFundingRow(): void
    {
        $this->funding[] = $this->emptyFundingRow();
        $this->saveDraft();
    }

    public function removeFundingRow(int $index): void
    {
        unset($this->funding[$index]);
        $this->funding = array_values($this->funding);

        if ($this->funding === []) {
            $this->funding = [$this->emptyFundingRow()];
        }

        $this->saveDraft();
    }

    /** @return array<string, mixed> */
    private function rulesForStep(int $step): array
    {
        return match ($step) {
            1 => [
                'title' => ['required', 'string', 'min:6', 'max:255'],
                'reference' => [
                    'required', 'string', 'max:40',
                    // Uniqueness is checked THROUGH the model, not with a
                    // hand-written tenant_id filter on the table: Project::query()
                    // already carries the TenantScope, so this cannot drift from
                    // however tenancy is scoped, and cannot leak another MDA's
                    // references by reporting a clash across workspaces.
                    //
                    // withTrashed() because the unique index is (tenant_id,
                    // reference) on the TABLE and archiving only soft-deletes:
                    // an archived project still owns its reference, so without
                    // this the form would pass validation and the insert would
                    // die on a raw integrity error. Archived clashes get their
                    // own message — "already used" sends the officer hunting
                    // through a register the row is no longer in.
                    function (string $attribute, mixed $value, Closure $fail): void {
                        $existing = Project::query()->withTrashed()->where('reference', $value)->first();

                        if ($existing === null) {
                            return;
                        }

                        $fail($existing->trashed()
                            ? __('This reference belongs to an archived project in this workspace. Choose another, or restore that project.')
                            : __('Another project in this workspace already uses this reference.'));
                    },
                ],
                'sector_id' => ['required', Rule::exists('sectors', 'id')->where('is_active', true)],
                'type' => ['required', Rule::enum(ProjectType::class)],
                'description' => ['nullable', 'string', 'max:5000'],
                'goal' => ['nullable', 'string', 'max:2000'],
                'objectives' => ['nullable', 'string', 'max:5000'],
                // Not `exists:users,id` — that accepts EVERY account on the
                // platform, including staff of another ministry. The option
                // list is the constraint: validate against exactly what
                // managers() renders, so what the form offers and what it
                // accepts cannot drift. RegisterProject re-checks membership
                // independently for callers that never touch this form.
                'manager_id' => [
                    'nullable',
                    function (string $attribute, mixed $value, Closure $fail): void {
                        $isMember = $this->managers()
                            ->contains(fn (User $member): bool => (string) $member->id === (string) $value);

                        if (! $isMember) {
                            $fail(__('Choose a project manager from this workspace’s active members.'));
                        }
                    },
                ],
                'supervising_agency_name' => ['nullable', 'string', 'max:255'],
            ],
            2 => [
                'budget_allocation' => ['nullable', 'numeric', Money::FORM_RULE, 'min:0', 'max:9999999999999.99'],
                'budget_code' => ['nullable', 'string', 'max:60'],
                'funding' => ['array'],
                'funding.*.funding_source_id' => ['nullable', 'distinct', Rule::exists('funding_sources', 'id')],
                'funding.*.percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
                'funding.*.amount' => ['nullable', 'numeric', Money::FORM_RULE, 'min:0'],
            ],
            default => [
                'site_name' => ['nullable', 'string', 'max:255'],
                'lga_id' => ['nullable', Rule::exists('lgas', 'id')],
                'ward_id' => ['nullable', Rule::exists('wards', 'id')],
                'latitude' => ['nullable', 'numeric', 'between:-90,90'],
                'longitude' => ['nullable', 'numeric', 'between:-180,180'],
                'start_date' => ['nullable', 'date'],
                'expected_end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
                'reporting_frequency' => ['nullable', Rule::enum(MeasurementFrequency::class)],
            ],
        };
    }

    /** @return array<string, string> */
    protected function validationAttributes(): array
    {
        return [
            'sector_id' => __('sector'),
            'lga_id' => __('LGA'),
            'ward_id' => __('ward'),
            'manager_id' => __('project manager'),
            'expected_end_date' => __('expected completion date'),
            'funding.*.funding_source_id' => __('funding source'),
            'funding.*.percentage' => __('share'),
            'funding.*.amount' => __('amount'),
        ];
    }

    public function next(): void
    {
        $this->validate($this->rulesForStep($this->step));

        // The Action owns the ≤100% rule; catching it here only saves the user
        // from carrying a doomed form to the last step.
        if ($this->step === 2 && $this->fundingPercentageTotal() > 100.0) {
            $this->addError('funding', __('The funding shares add up to :total%. They cannot exceed 100%.', [
                'total' => rtrim(rtrim(number_format($this->fundingPercentageTotal(), 2), '0'), '.'),
            ]));

            return;
        }

        $this->step = min($this->step + 1, self::LAST_STEP);
        $this->saveDraft();
    }

    public function back(): void
    {
        $this->step = max($this->step - 1, 1);
        $this->saveDraft();
    }

    public function goToStep(int $step): void
    {
        // Backwards only: skipping ahead past unvalidated fields is how you get
        // a wizard that fails on the last screen.
        if ($step < $this->step && $step >= 1) {
            $this->step = $step;
            $this->saveDraft();
        }
    }

    public function fundingPercentageTotal(): float
    {
        return collect($this->funding)
            ->sum(fn (array $row): float => (float) $row['percentage']);
    }

    public function save(RegisterProject $registerProject): mixed
    {
        $this->authorize('create', Project::class);

        $this->validate(array_merge(
            $this->rulesForStep(1),
            $this->rulesForStep(2),
            $this->rulesForStep(3),
        ));

        $this->failure = null;

        /** @var User $actor */
        $actor = auth()->user();

        try {
            $project = $registerProject(
                $actor,
                $this->projectAttributes(),
                $this->primaryLocationAttributes(),
                $this->fundingRows(),
            );
        } catch (ProjectRuleViolation $exception) {
            $this->failure = $exception->getMessage();

            return null;
        }

        session()->forget($this->draftKey());
        session()->flash('status', __('Project “:title” registered as a draft.', ['title' => $project->title]));

        return $this->redirect(url('/projects/'.$project->ulid), navigate: true);
    }

    /** @return array<string, mixed> */
    private function projectAttributes(): array
    {
        return array_filter([
            'reference' => trim($this->reference),
            'title' => trim($this->title),
            'description' => $this->nullIfBlank($this->description),
            'goal' => $this->nullIfBlank($this->goal),
            'objectives' => $this->nullIfBlank($this->objectives),
            'sector_id' => (int) $this->sector_id,
            'type' => $this->type,
            'supervising_agency_name' => $this->nullIfBlank($this->supervising_agency_name),
            'budget_allocation' => $this->nullIfBlank($this->budget_allocation),
            'budget_code' => $this->nullIfBlank($this->budget_code),
            'start_date' => $this->nullIfBlank($this->start_date),
            'expected_end_date' => $this->nullIfBlank($this->expected_end_date),
            'reporting_frequency' => $this->nullIfBlank($this->reporting_frequency),
            'manager_id' => $this->manager_id !== '' ? (int) $this->manager_id : null,
        ], fn (mixed $value): bool => $value !== null);
    }

    /** @return array<string, mixed> */
    private function primaryLocationAttributes(): array
    {
        $location = array_filter([
            'site_name' => $this->nullIfBlank($this->site_name),
            'lga_id' => $this->lga_id !== '' ? (int) $this->lga_id : null,
            'ward_id' => $this->ward_id !== '' ? (int) $this->ward_id : null,
            'latitude' => $this->nullIfBlank($this->latitude),
            'longitude' => $this->nullIfBlank($this->longitude),
        ], fn (mixed $value): bool => $value !== null);

        return $location;
    }

    /** @return list<array<string, mixed>> */
    private function fundingRows(): array
    {
        return collect($this->funding)
            ->filter(fn (array $row): bool => $row['funding_source_id'] !== '')
            ->values()
            ->map(fn (array $row, int $index): array => array_filter([
                'funding_source_id' => (int) $row['funding_source_id'],
                'percentage' => $this->nullIfBlank($row['percentage']),
                'amount' => $this->nullIfBlank($row['amount']),
                'is_primary' => $index === 0,
            ], fn (mixed $value): bool => $value !== null))
            ->all();
    }

    private function nullIfBlank(string $value): ?string
    {
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /** @return Collection<int, Sector> */
    #[Computed]
    public function sectors(): Collection
    {
        return Sector::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    /** @return Collection<int, FundingSource> */
    #[Computed]
    public function fundingSources(): Collection
    {
        return FundingSource::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    /** @return Collection<int, Lga> */
    #[Computed]
    public function lgas(): Collection
    {
        return Lga::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    /** @return Collection<int, Ward> */
    #[Computed]
    public function wards(): Collection
    {
        if ($this->lga_id === '') {
            return collect();
        }

        return Ward::query()
            ->where('lga_id', $this->lga_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * Workspace members who can be handed a project. Membership is read through
     * the Iam action, never by querying the gate table here — that boundary is
     * enforced by the tenancy discipline sweep.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function managers(): Collection
    {
        return (new ListTenantMembers)()
            ->filter(fn (TenantMembership $membership): bool => $membership->isActive())
            ->map(fn (TenantMembership $membership): ?User => $membership->user)
            ->filter()
            ->unique('id')
            ->sortBy('name')
            ->values();
    }

    public function render(): View
    {
        return view('livewire.tenant.projects.project-create');
    }
}
