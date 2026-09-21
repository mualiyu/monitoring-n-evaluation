<?php

declare(strict_types=1);

namespace App\Livewire\Oversight\Tenancy;

use App\Actions\Oversight\ProvisionTenant;
use App\Enums\Role;
use App\Enums\TenantType;
use App\Models\Sector;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Onboarding wizard: three steps that end with a live subdomain and one
 * administrator who can invite the rest of the ministry.
 *
 * THE SUBDOMAIN IS CHECKED WHILE IT IS TYPED, not on submit. It is the one
 * field on this form that cannot be changed afterwards — it is a DNS label and
 * every future link, bookmark and signed URL hangs off it — so "works is
 * already taken" has to arrive before somebody has filled in three steps and
 * chosen a permanent secretary. The same three rules run again inside
 * ProvisionTenant, which is the authority; this is only a courtesy.
 *
 * THE FIRST ADMINISTRATOR goes through the existing invitation chain
 * (App\Actions\Iam\InviteUser, via ProvisionTenant): the token is hashed, the
 * link expires, the mail is the same mail, and the invitation appears in the
 * user directory's pending list like any other. A workspace with no
 * administrator is allowed — a state may provision ahead of appointing someone
 * — and the register shows it with nobody in it until one is invited.
 */
#[Layout('layouts::oversight')]
class TenantOnboarding extends Component
{
    public int $step = 1;

    public const LAST_STEP = 3;

    /* Step 1 — identity */
    public string $name = '';

    public string $shortName = '';

    public string $slug = '';

    public string $type = TenantType::Ministry->value;

    public string $sectorId = '';

    /* Step 2 — who to call */
    public string $contactName = '';

    public string $contactEmail = '';

    public string $contactPhone = '';

    /* Step 3 — the first administrator */
    public string $administratorEmail = '';

    /** Set once the user edits the subdomain by hand, so we stop suggesting. */
    public bool $slugTouched = false;

    /** An Action's own refusal, shown verbatim. */
    public ?string $failure = null;

    public function mount(): void
    {
        $this->authorize('create', Tenant::class);
    }

    /**
     * Suggest a subdomain from the name until somebody types their own.
     * "Ministry of Works & Infrastructure" → "works-infrastructure" is a
     * guess; it is never imposed.
     */
    public function updatedName(string $value): void
    {
        if ($this->slugTouched) {
            return;
        }

        $stem = Str::of($value)
            ->replaceMatches('/\b(ministry|department|agency|bureau|commission|of|and|the|for)\b/i', ' ')
            ->replace('&', ' ')
            ->trim()
            ->toString();

        $this->slug = Str::limit(Str::slug($stem !== '' ? $stem : $value), 40, '');
    }

    public function updatedSlug(): void
    {
        $this->slugTouched = true;
        $this->slug = Str::lower(trim($this->slug));
    }

    /* ------------------------------------------------------------------ */
    /* Steps */
    /* ------------------------------------------------------------------ */

    public function next(): void
    {
        $this->authorize('create', Tenant::class);

        $this->validate($this->rulesForStep($this->step), [], $this->attributeNames());

        $this->step = min($this->step + 1, self::LAST_STEP);
    }

    public function back(): void
    {
        $this->step = max($this->step - 1, 1);
    }

    public function goToStep(int $step): void
    {
        // Backwards only: a forward jump would skip the validation that makes
        // the later steps meaningful.
        if ($step >= 1 && $step < $this->step) {
            $this->step = $step;
        }
    }

    public function provision(): mixed
    {
        $this->authorize('create', Tenant::class);

        $this->failure = null;

        for ($step = 1; $step <= self::LAST_STEP; $step++) {
            $this->validate($this->rulesForStep($step), [], $this->attributeNames());
        }

        try {
            $tenant = (new ProvisionTenant)($this->actor(), [
                'name' => $this->name,
                'short_name' => $this->shortName,
                'slug' => $this->slug,
                'type' => $this->type,
                'sector_id' => $this->sectorId === '' ? null : (int) $this->sectorId,
                'contact_name' => $this->contactName,
                'contact_email' => $this->contactEmail,
                'contact_phone' => $this->contactPhone,
            ], $this->administratorEmail === '' ? null : $this->administratorEmail);
        } catch (InvalidArgumentException $e) {
            // The Action's refusal (a reserved or taken subdomain, a role the
            // chain forbids) — shown verbatim rather than paraphrased.
            $this->failure = $e->getMessage();
            $this->step = 1;

            return null;
        }

        session()->flash('status', $this->administratorEmail === ''
            ? __(':name is live at :url. Invite its first administrator when one is appointed.', [
                'name' => $tenant->name,
                'url' => $tenant->url(),
            ])
            : __(':name is live at :url and :email has been invited as its administrator.', [
                'name' => $tenant->name,
                'url' => $tenant->url(),
                'email' => $this->administratorEmail,
            ]));

        return $this->redirectRoute('oversight.entities.show', $tenant, navigate: true);
    }

    /* ------------------------------------------------------------------ */

    /** @return array<string, string> */
    #[Computed]
    public function typeOptions(): array
    {
        $options = [];

        foreach (TenantType::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /** @return array<array-key, string> */
    #[Computed]
    public function sectorOptions(): array
    {
        return Sector::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->mapWithKeys(fn (string $name, int $id): array => [(string) $id => $name])
            ->all();
    }

    /** The workspace address the slug currently spells out, for the preview. */
    public function previewUrl(): string
    {
        $slug = $this->slug === '' ? __('subdomain') : $this->slug;

        return $slug.'.'.config('platform.domain');
    }

    public function administratorRoleLabel(): string
    {
        return Role::MdaAdmin->label();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rulesForStep(int $step): array
    {
        /** @var list<string> $reserved */
        $reserved = config('platform.reserved_subdomains', []);

        return match ($step) {
            1 => [
                'name' => ['required', 'string', 'min:3', 'max:160'],
                'shortName' => ['nullable', 'string', 'max:60'],
                'slug' => [
                    'required',
                    'string',
                    'max:63',
                    'regex:/^'.config('platform.tenant_slug_pattern').'$/',
                    Rule::notIn($reserved),
                    // withTrashed: a unique index cannot see a soft delete, so
                    // a retired workspace still owns its subdomain.
                    Rule::unique('tenants', 'slug'),
                ],
                'type' => ['required', Rule::in(array_keys($this->typeOptions()))],
                'sectorId' => ['nullable', Rule::in(array_keys($this->sectorOptions()))],
            ],
            2 => [
                'contactName' => ['nullable', 'string', 'max:160'],
                'contactEmail' => ['nullable', 'email:rfc', 'max:160'],
                'contactPhone' => ['nullable', 'string', 'max:32'],
            ],
            default => [
                'administratorEmail' => ['nullable', 'email:rfc', 'max:255'],
            ],
        };
    }

    /** @return array<string, string> */
    private function attributeNames(): array
    {
        return [
            'name' => __('entity name'),
            'shortName' => __('short name'),
            'slug' => __('subdomain'),
            'type' => __('entity type'),
            'sectorId' => __('sector'),
            'contactName' => __('contact name'),
            'contactEmail' => __('contact email'),
            'contactPhone' => __('contact phone'),
            'administratorEmail' => __('administrator email'),
        ];
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    public function render(): View
    {
        return view('livewire.oversight.tenancy.tenant-onboarding');
    }
}
