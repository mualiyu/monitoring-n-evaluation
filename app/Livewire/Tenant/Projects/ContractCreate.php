<?php

declare(strict_types=1);

namespace App\Livewire\Tenant\Projects;

use App\Actions\Projects\AwardContract;
use App\Actions\Projects\RecordContractVariation;
use App\Enums\ContractType;
use App\Exceptions\Projects\ProjectRuleViolation;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Project;
use App\Models\User;
use App\Support\InstanceTime;
use App\Support\Money;
use App\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Record a contract on a project (design §5, `/projects/{project}/contracts/create`).
 *
 * ONE screen for the two things the domain calls a contract row, because they
 * are the same act — committing money to a firm — with different paper behind
 * them:
 *
 *  - **Award**: the head contract. AwardContract writes the row, refreshes
 *    `projects.contract_value_total` and moves a draft project to `awarded`,
 *    all in one transaction.
 *  - **Variation**: an amendment to an existing award. RecordContractVariation
 *    writes a NEW row pointing at the one it varies — the manual's
 *    amendment-register pattern. The original award is never edited, which is
 *    why `sum`, `award_date`, `contractor_id` and `scope_of_works` are
 *    immutable on the model itself (Contract::IMMUTABLE_TERMS) and why this
 *    screen offers no "edit contract" path at all.
 *
 * The variation form therefore shows the original's contractor and category as
 * read-only facts rather than fields: RecordContractVariation copies them from
 * the award and would ignore anything typed here, and a disabled input that
 * silently does nothing is worse than no input.
 *
 * A variation's own `sum` is the DELTA and may be negative — a downward
 * variation (an omission) is a real instrument. Money::FORM_RULE deliberately
 * refuses a sign, so the form takes a positive magnitude plus a direction and
 * composes the signed decimal string here. That is better UX than a typed
 * minus anyway: "Omission — ₦12,000,000" cannot be misread the way "-12000000"
 * can.
 */
#[Layout('layouts::tenant')]
class ContractCreate extends Component
{
    public const MODE_AWARD = 'award';

    public const MODE_VARIATION = 'variation';

    public const DIRECTION_INCREASE = 'increase';

    public const DIRECTION_DECREASE = 'decrease';

    public Project $project;

    /** Which of the two paths the officer is on. */
    #[Url(except: self::MODE_AWARD)]
    public string $mode = self::MODE_AWARD;

    /**
     * The award being varied, by ULID.
     *
     * ULID rather than the row id on purpose: this property is both the picker
     * value and the deep link the contract screen sends people here with
     * (`?mode=variation&varies=…`), and an auto-increment id in a URL is an
     * enumeration handle over another MDA's contract volumes. A ULID-keyed map
     * is still a *map* to <x-ui.form.select> (array_is_list() is false), so the
     * option value is the key — the same contract the contractor picker below
     * honours with its id-keyed map.
     */
    #[Url(as: 'varies', except: '')]
    public string $variesUlid = '';

    // Award path ---------------------------------------------------------
    public string $contractorId = '';

    public string $contractType = 'works';

    public string $contractSum = '';

    public string $commencementDate = '';

    public string $durationDays = '';

    public string $retentionPercentage = '';

    // Variation path -----------------------------------------------------
    public string $variationDirection = self::DIRECTION_INCREASE;

    public string $variationAmount = '';

    public string $variationReason = '';

    // Both ---------------------------------------------------------------
    public string $contractNumber = '';

    public string $scopeOfWorks = '';

    public string $awardDate = '';

    public string $expectedCompletionDate = '';

    /** An Action's own refusal, shown verbatim. */
    public ?string $failure = null;

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);
        // A variation is a contract row too, so both paths begin here. The
        // variation path additionally authorizes `update` on the award it
        // amends, in the method AND again inside the Action.
        $this->authorize('create', Contract::class);

        $this->project = $project;
        $this->awardDate = InstanceTime::now()->toDateString();

        if (! in_array($this->mode, [self::MODE_AWARD, self::MODE_VARIATION], true)) {
            $this->mode = self::MODE_AWARD;
        }

        // A link that names a contract is asking for the variation form.
        if ($this->variesUlid !== '') {
            $this->mode = self::MODE_VARIATION;
        }

        // Nothing to amend yet: offering the variation form over an empty
        // picker is a dead end, and the award is what this project needs.
        if ($this->mode === self::MODE_VARIATION && $this->headContracts->isEmpty()) {
            $this->mode = self::MODE_AWARD;
            $this->variesUlid = '';
        }

        if ($this->mode === self::MODE_VARIATION && $this->variesUlid === '' && $this->headContracts->count() === 1) {
            $this->variesUlid = (string) $this->headContracts->first()?->ulid;
        }
    }

    /** Switching path must not carry the other path's field errors with it. */
    public function updatedMode(): void
    {
        $this->resetValidation();
        $this->failure = null;

        if ($this->mode === self::MODE_AWARD) {
            $this->variesUlid = '';
        }

        unset($this->variedContract);
    }

    public function updatedVariesUlid(): void
    {
        $this->resetErrorBag('variesUlid');
        unset($this->variedContract);
    }

    public function isVariation(): bool
    {
        return $this->mode === self::MODE_VARIATION;
    }

    /* ------------------------------------------------------------------ */
    /* Save */
    /* ------------------------------------------------------------------ */

    public function save(AwardContract $award, RecordContractVariation $recordVariation): mixed
    {
        // Re-authorized on the update request: route middleware does not gate
        // a Livewire component update by itself.
        $this->authorize('view', $this->project);
        $this->authorize('create', Contract::class);

        // Cleared BEFORE validating — validation throws, so clearing after it
        // would leave a stale Action refusal beside fresh field errors.
        $this->failure = null;

        $this->validate();

        /** @var User $actor */
        $actor = auth()->user();

        // Held before the write so the redirect and the flash message both
        // name the AWARD, not the amendment: a variation has no screen of its
        // own — it is read on the contract it varies, which is where the chain
        // and the revised value live.
        $original = $this->variedContract;

        try {
            $contract = $this->isVariation()
                ? $this->recordVariation($recordVariation, $actor)
                : $award($this->project, $this->contractor(), $actor, $this->awardAttributes());
        } catch (ProjectRuleViolation|AuthorizationException $exception) {
            // The Action is the authority on the domain rule (unawardable
            // status, blacklisted firm, variation of a variation); its wording
            // is shown rather than second-guessed here.
            $this->failure = $exception->getMessage();

            return null;
        }

        $target = $contract->isVariation() && $original instanceof Contract ? $original : $contract;

        session()->flash('status', $contract->isVariation()
            ? __('Variation :number recorded against contract :original.', [
                'number' => $contract->contract_number,
                'original' => $target->contract_number,
            ])
            : __('Contract :number recorded.', ['number' => $contract->contract_number]));

        return $this->redirect($this->tenantRoute('tenant.projects.contracts.show', [
            'project' => $this->project,
            'contract' => $target,
        ]), navigate: true);
    }

    private function recordVariation(RecordContractVariation $recordVariation, User $actor): Contract
    {
        $original = $this->variedContract;

        if (! $original instanceof Contract) {
            // Unreachable: the picker is validated by resolving it through
            // this project's own contracts relation, which fails the field
            // when it finds nothing.
            abort(404);
        }

        $this->authorize('update', $original);

        return $recordVariation($original, $actor, $this->variationAttributes($original), trim($this->variationReason));
    }

    private function contractor(): Contractor
    {
        return Contractor::query()->findOrFail($this->contractorId);
    }

    /** @return array<string, mixed> */
    private function awardAttributes(): array
    {
        return [
            'contract_number' => trim($this->contractNumber),
            'type' => $this->contractType,
            'sum' => $this->contractSum,
            'scope_of_works' => trim($this->scopeOfWorks),
            'award_date' => $this->awardDate,
            'commencement_date' => $this->nullIfBlank($this->commencementDate),
            'duration_days' => $this->durationDays === '' ? null : (int) $this->durationDays,
            'expected_completion_date' => $this->nullIfBlank($this->expectedCompletionDate),
            'retention_percentage' => $this->nullIfBlank($this->retentionPercentage),
        ];
    }

    /** @return array<string, mixed> */
    private function variationAttributes(Contract $original): array
    {
        return [
            'contract_number' => trim($this->contractNumber),
            // Carried from the award rather than asked for: a variation is an
            // amendment to a works contract, not a chance to re-categorise it.
            // RecordContractVariation copies the contractor for the same reason.
            'type' => $original->type,
            'sum' => $this->signedVariationSum(),
            'scope_of_works' => trim($this->scopeOfWorks),
            'award_date' => $this->awardDate,
            'expected_completion_date' => $this->nullIfBlank($this->expectedCompletionDate),
        ];
    }

    /**
     * The delta as a signed decimal string. The form collects a magnitude and
     * a direction; this is the one place the two become one number.
     */
    public function signedVariationSum(): string
    {
        $amount = trim($this->variationAmount);

        return $this->variationDirection === self::DIRECTION_DECREASE ? '-'.$amount : $amount;
    }

    private function nullIfBlank(string $value): ?string
    {
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /* ------------------------------------------------------------------ */
    /* Validation */
    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        $shared = [
            'contractNumber' => ['required', 'string', 'max:60', $this->uniqueContractNumber()],
            'scopeOfWorks' => ['required', 'string', 'min:20', 'max:10000'],
            'awardDate' => ['required', 'date'],
            'expectedCompletionDate' => ['nullable', 'date', 'after_or_equal:awardDate'],
        ];

        if ($this->isVariation()) {
            return [
                ...$shared,
                'variesUlid' => ['required', $this->variedContractExists()],
                'variationDirection' => ['required', Rule::in([self::DIRECTION_INCREASE, self::DIRECTION_DECREASE])],
                // Money::FORM_RULE pairs with `numeric`: `numeric` alone
                // accepts '5.' and '1e5', both of which 500 in the cast.
                // `bail` so the closure below only ever parses a value the
                // format and range rules have already accepted.
                'variationAmount' => [
                    'bail', 'required', 'numeric', Money::FORM_RULE, 'min:0.01', 'max:9999999999999.99',
                    $this->withinRevisedValue(),
                ],
                'variationReason' => ['required', 'string', 'min:20', 'max:2000'],
            ];
        }

        return [
            ...$shared,
            'contractorId' => ['required', Rule::exists('contractors', 'id')],
            'contractType' => ['required', Rule::enum(ContractType::class)],
            'contractSum' => ['required', 'numeric', Money::FORM_RULE, 'min:0.01', 'max:9999999999999.99'],
            'commencementDate' => ['nullable', 'date', 'after_or_equal:awardDate'],
            // unsignedSmallInteger on the column.
            'durationDays' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'retentionPercentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }

    /**
     * Unique within this workspace — the table's unique index is
     * (tenant_id, contract_number).
     *
     * Asked through the model so the TenantScope decides the scope: a plain
     * Rule::unique('contracts', …) would compare against every MDA's numbers,
     * refuse a number another ministry happens to use, and tell the officer so.
     * withTrashed() because the index is on the TABLE and a soft-deleted
     * contract still owns its number.
     */
    private function uniqueContractNumber(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $taken = Contract::query()
                ->withTrashed()
                ->where('contract_number', trim((string) $value))
                ->exists();

            if ($taken) {
                $fail(__('Another contract in this workspace already uses this number.'));
            }
        };
    }

    /**
     * The picker's value must name a head contract ON THIS PROJECT. Resolved
     * through the project's own relation, so a ULID from another project — or
     * another MDA — simply is not found.
     */
    private function variedContractExists(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! $this->variedContract instanceof Contract) {
                $fail(__('Choose the contract this variation amends.'));
            }
        };
    }

    /**
     * A downward variation cannot take a contract below zero: the register
     * records amendments to a sum, and a negative engagement is not a thing
     * the state can hold. Screen-level input sanity — the immutability of the
     * award itself is enforced on the model, where no form can reach it.
     */
    private function withinRevisedValue(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $original = $this->variedContract;

            if (! $original instanceof Contract || $this->variationDirection !== self::DIRECTION_DECREASE) {
                return;
            }

            $current = $original->revisedValue();

            if (Money::fromDecimalString(trim((string) $value))->minor() > $current->minor()) {
                $fail(__('An omission cannot exceed the contract’s current value of :value.', [
                    'value' => $current->format(),
                ]));
            }
        };
    }

    /** @return array<string, string> */
    protected function validationAttributes(): array
    {
        return [
            'contractorId' => __('contractor'),
            'contractNumber' => $this->isVariation() ? __('variation number') : __('contract number'),
            'contractType' => __('contract type'),
            'contractSum' => __('award sum'),
            'scopeOfWorks' => __('scope of works'),
            'awardDate' => $this->isVariation() ? __('variation date') : __('award date'),
            'commencementDate' => __('commencement date'),
            'expectedCompletionDate' => __('expected completion date'),
            'durationDays' => __('contract duration'),
            'retentionPercentage' => __('retention'),
            'variesUlid' => __('contract being varied'),
            'variationDirection' => __('variation type'),
            'variationAmount' => __('variation amount'),
            'variationReason' => __('reason for the variation'),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Reads */
    /* ------------------------------------------------------------------ */

    /**
     * Head contracts only: a variation attaches to an award, never to another
     * variation (RecordContractVariation refuses the chain).
     *
     * @return Collection<int, Contract>
     */
    #[Computed]
    public function headContracts(): Collection
    {
        return $this->project->contracts()
            ->with(['contractor:id,name', 'variations'])
            ->whereNull('varies_contract_id')
            ->orderByDesc('award_date')
            ->get();
    }

    /** The award this variation amends, or null. */
    #[Computed]
    public function variedContract(): ?Contract
    {
        if ($this->variesUlid === '') {
            return null;
        }

        return $this->headContracts->firstWhere('ulid', $this->variesUlid);
    }

    /**
     * Firms available for an award. Blacklisted ones are not listed, and
     * AwardContract refuses one anyway — the registry is state-wide precisely
     * so a firm barred by one ministry stops winning work in the next.
     *
     * @return Collection<int, Contractor>
     */
    #[Computed]
    public function contractors(): Collection
    {
        return Contractor::query()
            ->where('is_blacklisted', false)
            ->orderBy('name')
            ->get(['id', 'name', 'rc_number']);
    }

    /** @return array<string, string> */
    #[Computed]
    public function contractorOptions(): array
    {
        // pluck-style id-keyed map: the KEY is the submitted value, so this
        // select posts the contractor's id and not its name.
        return $this->contractors
            ->mapWithKeys(fn (Contractor $c): array => [
                (string) $c->id => $c->name.($c->rc_number ? ' — '.$c->rc_number : ''),
            ])
            ->all();
    }

    /**
     * The instance's currency symbol for the money field adornments.
     *
     * Read off a zero amount rather than typed into the Blade: the currency is
     * an instance setting (`platform.instance.currency`), Money owns the symbol
     * table, and a "₦" in a view is a white-label violation waiting for the
     * first deployment that is not in naira.
     */
    #[Computed]
    public function currencySymbol(): string
    {
        return rtrim(str_replace('0.00', '', Money::zero()->format()));
    }

    /** @return array<string, string> */
    #[Computed]
    public function contractOptions(): array
    {
        return $this->headContracts
            ->mapWithKeys(fn (Contract $c): array => [
                $c->ulid => $c->contract_number.' — '.$c->sum->format(),
            ])
            ->all();
    }

    /* ------------------------------------------------------------------ */
    /* Links */
    /* ------------------------------------------------------------------ */

    #[Computed]
    public function projectUrl(): string
    {
        return $this->tenantRoute('tenant.projects.show', ['project' => $this->project]);
    }

    #[Computed]
    public function projectsUrl(): string
    {
        return $this->tenantRoute('tenant.projects.index');
    }

    /**
     * A named-route URL on THIS workspace's subdomain.
     *
     * route() is the rule — it fails loudly on a missing route or the wrong
     * binding key, where a hand-built string 404s silently in front of a user.
     * The tenant surface's routes carry a `{tenant}` domain parameter that
     * ResolveTenant fills through URL::defaults on a real request; nothing
     * fills it inside Livewire::test(), which never crosses HTTP. Passing the
     * bound tenant explicitly makes both paths generate the same URL.
     *
     * @param  array<string, mixed>  $parameters
     */
    private function tenantRoute(string $name, array $parameters = []): string
    {
        return route($name, [
            'tenant' => app(CurrentTenant::class)->getOrFail()->slug,
            ...$parameters,
        ]);
    }

    public function render(): View
    {
        return view('livewire.tenant.projects.contract-create');
    }
}
