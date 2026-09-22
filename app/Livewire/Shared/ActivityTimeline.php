<?php

declare(strict_types=1);

namespace App\Livewire\Shared;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Spatie\Activitylog\Models\Activity;

/**
 * One record's history, for embedding on any detail screen:
 *
 *   <livewire:shared.activity-timeline :model="$project" />
 *
 * READ-ONLY, BY CONSTRUCTION. There is no edit method and no delete method on
 * this component, and there must never be one: the activity log is
 * append-only, so a screen that could amend it would make the whole trail
 * worthless. Retention is a scheduled, configured purge by age — never a
 * button.
 *
 * AUTHORIZATION IS THE RECORD'S. Whoever may view the record may read its
 * history, so the component asks the record's own policy rather than inventing
 * a second rule that could drift from it. A model with no policy falls back to
 * "must be signed in" — deliberately conservative, and it means a module that
 * forgets its policy gets a locked panel rather than an open one.
 *
 * A WORD ON THE MODEL PROPERTY. It is tempting to say the owning record
 * "re-hydrates through its own global scope, so another MDA's record cannot be
 * smuggled in by editing the payload". That is FALSE, and it used to be
 * written here as a security argument. Livewire restores a model property with
 * `newQueryForRestoration()`, which is `newQueryWithoutScopes()->whereKey()` —
 * the TenantScope is OFF for every model property on every update request.
 *
 * What actually protects this component is the snapshot checksum (the client
 * cannot edit the key) and, decisively, the authorize() call on every method
 * below, whose Policy compares tenant_id. Never rely on the scope here.
 */
class ActivityTimeline extends Component
{
    public Model $model;

    /** Heading shown above the panel. */
    public ?string $heading = null;

    /** How many entries before "show everything". */
    public int $limit = 8;

    public bool $expanded = false;

    public function mount(Model $model, ?string $heading = null, int $limit = 8): void
    {
        $this->model = $model;
        $this->heading = $heading;
        $this->limit = max(1, $limit);

        $this->authorizeRead();
    }

    /**
     * Every request after the first. mount() never runs again on an update, so
     * a check that lives only there authorizes the page and then nothing: a
     * snapshot replayed later — by someone else, or on another host — re-renders
     * entries() and answers changes() with no check at all. A `shared.*`
     * component is reachable from both app surfaces, which makes this the only
     * gate that follows the component wherever its snapshot goes.
     */
    public function hydrate(): void
    {
        $this->authorizeRead();
    }

    public function expand(): void
    {
        $this->authorizeRead();

        $this->expanded = true;
        unset($this->entries);
    }

    /** @return Collection<int, Activity> */
    #[Computed]
    public function entries(): Collection
    {
        /** @var Collection<int, Activity> $entries */
        $entries = Activity::query()
            ->where('subject_type', $this->model->getMorphClass())
            ->where('subject_id', $this->model->getKey())
            ->with('causer')
            ->orderByDesc('id')
            ->limit($this->expanded ? 200 : $this->limit)
            ->get();

        return $entries;
    }

    #[Computed]
    public function total(): int
    {
        return Activity::query()
            ->where('subject_type', $this->model->getMorphClass())
            ->where('subject_id', $this->model->getKey())
            ->count();
    }

    /**
     * Before/after pairs for one entry, as rows a human can read.
     *
     * @return list<array{attribute: string, from: string, to: string}>
     */
    public function changes(int|string $activityId): array
    {
        // Resolved from THIS component's own page of entries, never from the
        // id the client sent. A public Livewire method with an Eloquent-typed
        // parameter is bound by implicit route-model binding from
        // CLIENT-SUPPLIED call params — and `activity_log` carries no tenant
        // scope and an auto-increment key, so the model-typed version of this
        // method let a caller walk 1, 2, 3… and read every MDA's audit diffs:
        // contract sums, project figures, IAM changes, settings.
        $activity = $this->entries()->firstWhere('id', $activityId);

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
     * lands in `properties`. This platform produces both, so reading one
     * column left this panel blank for every chokepoint status change — which
     * is the one thing a record's history is opened to see.
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

        if ($causer instanceof User) {
            return $causer->name;
        }

        // A null causer is the platform itself — a scheduled job, a console
        // command, a deadline that simply arrived. Saying "the platform" is
        // honest; saying "system" invites people to look for a user named
        // System.
        return __('The platform');
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

    /**
     * The record's own policy decides. Gate::getPolicyFor returns null for a
     * model nobody wrote a policy for, and that case fails closed to "signed
     * in only" rather than open.
     */
    private function authorizeRead(): void
    {
        abort_unless(auth()->check(), 403);

        if (Gate::getPolicyFor($this->model) !== null) {
            $this->authorize('view', $this->model);
        }
    }

    public function render(): View
    {
        return view('livewire.shared.activity-timeline');
    }
}
