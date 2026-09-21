<?php

declare(strict_types=1);

namespace App\Livewire\Oversight\Audit;

use App\Actions\Oversight\ListActivityAcrossTenants;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The state audit log: who did what, to what, when — across every entity.
 *
 * READ-ONLY BY CONSTRUCTION. There is no edit method and no delete method on
 * this component, and adding one would be a defect rather than a feature: an
 * append-only trail that a privileged user can amend proves nothing. The only
 * write anywhere near this screen is the CSV the reader takes away.
 *
 * THE CROSS-ENTITY READ LIVES IN THE ACTION, which re-checks
 * `oversight.audit.view` in the GLOBAL permission team before any tenancy
 * bypass. This component never bypasses anything itself — it asks.
 *
 * THE EXPORT AND THE SCREEN SHARE ONE BUILDER, so a row that is on screen and
 * missing from the CSV (or the reverse) cannot happen. That matters more here
 * than anywhere else on the platform: this is the file somebody hands an
 * auditor.
 */
#[Layout('layouts::oversight')]
class AuditLog extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $log = '';

    #[Url(except: '')]
    public string $subjectType = '';

    #[Url(except: '')]
    public string $tenant = '';

    /** A user's public_id — the actor whose acts are being reviewed. */
    #[Url(except: '')]
    public string $actorId = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    /** The entry whose before/after panel is open. */
    public ?int $inspecting = null;

    public function mount(): void
    {
        // The Action is the authority; asking it here turns "no oversight
        // authority" into a 403 on the page rather than an exception halfway
        // down the first render.
        abort_unless($this->actor()->holdsGlobalPermission('oversight.audit.view'), 403);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedLog(): void
    {
        $this->resetPage();
    }

    public function updatedSubjectType(): void
    {
        $this->resetPage();
    }

    public function updatedTenant(): void
    {
        $this->resetPage();
    }

    public function updatedActorId(): void
    {
        $this->resetPage();
    }

    public function updatedFrom(): void
    {
        $this->resetPage();
    }

    public function updatedTo(): void
    {
        $this->resetPage();
    }

    /** @return LengthAwarePaginator<int, Activity> */
    #[Computed]
    public function entries(): LengthAwarePaginator
    {
        return (new ListActivityAcrossTenants)($this->actor(), $this->filters());
    }

    /** @return array{actor: User|null, log: string|null, subjectType: string|null, tenant: Tenant|null, from: CarbonImmutable|null, to: CarbonImmutable|null, search: string|null} */
    private function filters(): array
    {
        return [
            'actor' => $this->selectedActor(),
            'log' => $this->log === '' ? null : $this->log,
            'subjectType' => $this->subjectType === '' ? null : $this->subjectType,
            'tenant' => $this->selectedTenant(),
            'from' => $this->date($this->from),
            // Inclusive of the whole closing day: an auditor asking for
            // "up to the 30th" means the 30th, not midnight at its start.
            'to' => $this->date($this->to)?->endOfDay(),
            'search' => $this->search === '' ? null : $this->search,
        ];
    }

    private function date(string $value): ?CarbonImmutable
    {
        if ($value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function selectedActor(): ?User
    {
        return $this->actorId === ''
            ? null
            : User::query()->where('public_id', $this->actorId)->first();
    }

    private function selectedTenant(): ?Tenant
    {
        return $this->tenant === ''
            ? null
            : Tenant::query()->where('ulid', $this->tenant)->first();
    }

    /* ------------------------------------------------------------------ */
    /* Filter options */
    /* ------------------------------------------------------------------ */

    /** @return array<string, string> */
    #[Computed]
    public function logOptions(): array
    {
        $names = (new ListActivityAcrossTenants)->logNames($this->actor());

        $options = [];

        foreach ($names as $name) {
            $options[$name] = ucfirst(str_replace('_', ' ', $name));
        }

        return $options;
    }

    /** @return array<string, string> */
    #[Computed]
    public function subjectTypeOptions(): array
    {
        return (new ListActivityAcrossTenants)->subjectTypes($this->actor());
    }

    /**
     * The people who actually appear in the log, rather than every account on
     * the platform: a filter listing 400 names nobody has ever caused an entry
     * is a filter nobody uses.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function actorOptions(): array
    {
        $causerIds = (new ListActivityAcrossTenants)->causerIds($this->actor());

        return User::query()
            ->whereIn('id', $causerIds)
            ->orderBy('name')
            ->pluck('name', 'public_id')
            ->all();
    }

    /** @return array<string, string> */
    #[Computed]
    public function tenantOptions(): array
    {
        return Tenant::query()
            ->orderBy('name')
            ->pluck('name', 'ulid')
            ->all();
    }

    /* ------------------------------------------------------------------ */
    /* Before / after */
    /* ------------------------------------------------------------------ */

    public function inspect(int $activityId): void
    {
        $this->inspecting = $this->inspecting === $activityId ? null : $activityId;
    }

    /**
     * Before/after pairs for one entry.
     *
     * @return list<array{attribute: string, from: string, to: string}>
     */
    public function changes(int|string $activityId): array
    {
        // See ActivityTimeline::changes(): resolved from this screen's own
        // page, never from the id the client sent, because activity_log is
        // unscoped and integer-keyed.
        $activity = collect($this->entries()->items())->firstWhere('id', $activityId);

        if (! $activity instanceof Activity) {
            return [];
        }

        $properties = $this->changeSet($activity);

        $old = is_array($properties['old'] ?? null) ? $properties['old'] : [];
        $new = is_array($properties['attributes'] ?? null) ? $properties['attributes'] : [];

        $rows = [];

        foreach ($new as $attribute => $value) {
            $rows[] = [
                'attribute' => ucfirst(str_replace('_', ' ', (string) $attribute)),
                'from' => $this->stringify($old[$attribute] ?? null),
                'to' => $this->stringify($value),
            ];
        }

        return $rows;
    }

    /**
     * The before/after of one entry, wherever this installation keeps it.
     *
     * spatie v5 writes a MODEL's own diff to `attribute_changes`; only a
     * hand-written activity()->withProperties(['old' => …, 'attributes' => …])
     * lands in `properties`. This platform produces both — the chokepoint
     * Actions log by model, while the settings, tenancy and IAM Actions write
     * their own pairs — so reading one column left the before/after panel
     * blank for every status change on the platform and exported `null` into
     * the CSV an auditor is handed.
     *
     * @return array<string, mixed>
     */
    private function changeSet(Activity $activity): array
    {
        $changes = $activity->attribute_changes?->toArray() ?? [];

        if (isset($changes['attributes']) || isset($changes['old'])) {
            return $changes;
        }

        return $activity->properties->toArray();
    }

    public function causerName(Activity $activity): string
    {
        $causer = $activity->causer;

        return $causer instanceof User ? $causer->name : __('The platform');
    }

    public function subjectLabel(Activity $activity): string
    {
        if ($activity->subject_type === null) {
            return '—';
        }

        return class_basename((string) $activity->subject_type).' #'.$activity->subject_id;
    }

    private function stringify(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? __('Yes') : __('No');
        }

        if (is_array($value)) {
            $encoded = json_encode($value);

            return is_string($encoded) ? $encoded : '—';
        }

        return is_scalar($value) ? (string) $value : '—';
    }

    /* ------------------------------------------------------------------ */
    /* Export */
    /* ------------------------------------------------------------------ */

    public function export(): StreamedResponse
    {
        abort_unless($this->actor()->holdsGlobalPermission('oversight.audit.view'), 403);

        $filters = $this->filters();
        $actor = $this->actor();
        $filename = 'audit-log-'.CarbonImmutable::now()->format('Y-m-d-Hi').'.csv';

        return response()->streamDownload(function () use ($actor, $filters): void {
            $handle = fopen('php://output', 'wb');

            // BOM: Excel on Windows reads UTF-8 CSV as cp1252 without it.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                __('When'), __('Log'), __('What happened'), __('Actor'),
                __('Subject'), __('Before'), __('After'),
            ]);

            (new ListActivityAcrossTenants)->chunk($actor, $filters, function (Collection $entries) use ($handle): void {
                /** @var Activity $entry */
                foreach ($entries as $entry) {
                    $properties = $this->changeSet($entry);

                    fputcsv($handle, [
                        $entry->created_at?->toIso8601String(),
                        $entry->log_name,
                        $entry->description,
                        $this->causerName($entry),
                        $this->subjectLabel($entry),
                        json_encode($properties['old'] ?? null),
                        json_encode($properties['attributes'] ?? null),
                    ]);
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    public function render(): View
    {
        return view('livewire.oversight.audit.audit-log');
    }
}
