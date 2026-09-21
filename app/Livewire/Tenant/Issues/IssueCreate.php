<?php

declare(strict_types=1);

namespace App\Livewire\Tenant\Issues;

use App\Actions\Iam\ListTenantMembers;
use App\Actions\Issues\RaiseIssue;
use App\Enums\IssueCategory;
use App\Enums\IssueSeverity;
use App\Exceptions\Issues\IssueRuleViolation;
use App\Models\Issue;
use App\Models\Project;
use App\Models\TenantMembership;
use App\Models\User;
use App\Support\InstanceTime;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Raising a challenge (plan §4).
 *
 * ONE SCREEN, NO WIZARD, deliberately: the person filling this in is often
 * standing on a site on a phone, and the whole value of the register depends
 * on the raise being cheap enough that it actually happens. Only four fields
 * are required — what, where, what kind, how bad — and the corrective action
 * and owner can be added later by whoever picks it up.
 *
 * The project arrives pre-selected when the screen is opened from a project
 * (?project=<ulid>), which is the common path.
 *
 * The component validates SHAPE; every domain rule — who may raise, which
 * projects they may raise against, the opening ledger row — belongs to
 * RaiseIssue and is enforced there even if this form is bypassed entirely.
 */
#[Layout('layouts::tenant')]
class IssueCreate extends Component
{
    /** Pre-selection from a project screen, carried by ULID (never an id). */
    #[Url(as: 'project', except: '')]
    public string $projectUlid = '';

    public string $title = '';

    public string $description = '';

    public string $category = '';

    public string $severity = 'medium';

    public string $ownerId = '';

    public string $correctiveAction = '';

    public string $dueDate = '';

    /** An Action's own refusal, shown verbatim. */
    public ?string $failure = null;

    public function mount(): void
    {
        $this->authorize('create', Issue::class);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            // exists against the tenant-scoped table: a ULID from another MDA
            // matches nothing here, so the field fails validation rather than
            // reaching the Action with a foreign project.
            'projectUlid' => ['required', 'string', Rule::exists('projects', 'ulid')->where(
                fn ($query) => $query->whereNull('deleted_at'),
            )],
            'title' => ['required', 'string', 'min:5', 'max:255'],
            'description' => ['required', 'string', 'min:10', 'max:5000'],
            'category' => ['required', Rule::enum(IssueCategory::class)],
            'severity' => ['required', Rule::enum(IssueSeverity::class)],
            'ownerId' => ['nullable', 'integer', Rule::in($this->ownerIds())],
            'correctiveAction' => ['nullable', 'string', 'max:2000'],
            // A corrective-action deadline in the past is almost always a
            // typo, and one that is accepted immediately shows up as overdue
            // on the board — which teaches officers to distrust the flag.
            'dueDate' => ['nullable', 'date', 'after_or_equal:today'],
        ];
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return [
            'projectUlid.required' => __('Say which project this is blocking.'),
            'projectUlid.exists' => __('That project is not one this workspace can raise an issue against.'),
            'title.required' => __('Give the issue a one-line title — it is what every board shows.'),
            'description.required' => __('Describe the obstruction. A title alone cannot be acted on by someone who was not there.'),
            'description.min' => __('Give the reader something to work with — a sentence at least.'),
            'ownerId.in' => __('An issue can only be assigned to someone who works in this entity.'),
            'dueDate.after_or_equal' => __('A corrective-action deadline cannot be in the past.'),
        ];
    }

    public function save(RaiseIssue $raise): mixed
    {
        // Re-authorized on the mutating method: route middleware does not
        // protect a Livewire update POST by itself.
        $this->authorize('create', Issue::class);

        $validated = $this->validate();

        $this->failure = null;

        $project = Project::query()->where('ulid', $validated['projectUlid'])->firstOrFail();

        try {
            /** @var User $actor */
            $actor = auth()->user();

            $issue = $raise($project, $actor, [
                'title' => $validated['title'],
                'description' => $validated['description'],
                'category' => IssueCategory::from($validated['category']),
                'severity' => IssueSeverity::from($validated['severity']),
                'owner_id' => $validated['ownerId'] === '' ? null : (int) $validated['ownerId'],
                'corrective_action' => $validated['correctiveAction'] === '' ? null : $validated['correctiveAction'],
                'due_date' => $validated['dueDate'] === '' ? null : $validated['dueDate'],
            ]);
        } catch (IssueRuleViolation $exception) {
            $this->failure = $exception->getMessage();

            return null;
        }

        session()->flash('status', __('Issue raised. It is on the register and counts against this project until it is cleared.'));

        return $this->redirectRoute(
            'tenant.issues.show',
            ['tenant' => $project->tenant->slug, 'issue' => $issue->ulid],
            navigate: true,
        );
    }

    /**
     * Keyed by ULID, because that is what the form and the URL carry.
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
            ->orderBy('title')
            ->pluck('title', 'ulid')
            ->all();
    }

    /**
     * Id-keyed, so the select submits the user id (see the note in
     * components/ui/form/select.blade.php about array_is_list).
     *
     * @return array<int, string>
     */
    #[Computed]
    public function ownerOptions(): array
    {
        return $this->members()->pluck('name', 'id')->all();
    }

    /** @return list<int> */
    private function ownerIds(): array
    {
        return $this->members()->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /** @return Collection<int, User> */
    private function members(): Collection
    {
        return (new ListTenantMembers)()
            ->filter(fn (TenantMembership $membership): bool => $membership->isActive())
            ->map(fn (TenantMembership $membership): ?User => $membership->user)
            ->filter()
            ->unique('id')
            ->sortBy('name')
            ->values();
    }

    /** @return array<string, string> */
    public function categoryOptions(): array
    {
        return IssueCategory::options();
    }

    /** @return array<string, string> */
    public function severityOptions(): array
    {
        return IssueSeverity::options();
    }

    /** The earliest date the picker accepts, on the instance's wall clock. */
    public function today(): string
    {
        return InstanceTime::now()->toDateString();
    }

    public function render(): View
    {
        return view('livewire.tenant.issues.issue-create');
    }
}
