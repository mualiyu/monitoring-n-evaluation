# Projects Data Layer — Implementation Review

Reviewed against `docs/design/projects-module.md` rev. 2: migrations `2026_08_03_*`, the 12 new
models + pivot, 11 enums, `Money`/`MoneyCast`, factories and seeders.

## Verdict: **REVISE**

The **schema is excellent** — I would approve it as-is. Column-for-column fidelity to rev. 2,
`tenant_id` placement verified correct on every table, delete rules deliberate, enums matching the
design vocabulary exactly, and the reasoning preserved in comments where a future reader will need it
(the `contract_value_total` column comment, the `created_by_tenant_id` misreading warning, the
"no partial unique index" note on `project_locations`).

What blocks approval is that **this slice shipped with no tests of its own**, plus two model-level
gaps that will directly undermine the Actions layer being built on top. Issues 1–3 should land before
Actions work starts; 4–10 can follow.

---

### 1. BLOCKER — nothing in this slice is tested
`tests/` contains only `Feature/Auth/*`, `Feature/Tenancy/*` and `Unit/TenancyDisciplineTest.php`.
`grep -rlE 'Project::|Contract::|Indicator::|Money::' tests/` returns **nothing**. The 121 green tests
are the auth and tenancy slices; they do not exercise one line of this one, so "all gates green" is
true and uninformative here.

This violates a non-negotiable: *"Every new tenant-owned model ships with a test that creates records
in two tenants and asserts each subdomain sees only its own"* (`.claude/rules/tenancy.md`,
`.claude/rules/testing.md`). Nine new tenant-owned models shipped without one.

Minimum to clear, per design §7:
- **Isolation ×9** — `projects`, `project_locations`, `project_funding_sources`, `contracts`,
  `project_assignments`, `project_status_events`, `indicators`, `indicator_targets`,
  `indicator_readings`. Two tenants, assert model count + a foreign-ULID lookup returns nothing.
- **`ProjectStatusTransitionTest`** — every allowed edge in §2, plus forbidden: `certified → in_progress`,
  any transition out of `cancelled`/`closed`, `draft → completed`. Include the regression guard that
  **`ProjectStatus::tryFrom('mid_term')` is null** — that finding cost a design round and must not
  silently return.
- **`MoneyCastTest`** (Unit) — float rejected on write, kobo round-trip, `SUM` correctness, and the
  SQLite-vs-MySQL read path (`is_int`/`is_float`/string) that `MoneyCast::get()` claims to normalise.
  The float-rejection branch is the single most important line in the money implementation and is
  currently unverified.
- **Factory smoke test** — every state constructs and persists inside `runAs()`, and throws outside it.

Also worth adding while you are in there: `TenancyDisciplineTest` currently greps source paths only.
A reflection case — *every model whose table has a `tenant_id` column uses `BelongsToTenant`* — would
have made this slice self-policing and costs about ten lines. I verified it by hand this round
(`grep '^\s*use .*BelongsToTenant;'` vs the migration columns); that shouldn't be a human job.

### 2. HIGH — `Project` mass-assigns the fields its own docblock reserves for Actions
`app/Models/Project.php:70-78` marks `status`, `contract_value_total`, `expenditure_to_date`,
`physical_progress`, `mid_term_flagged_at`, `status_changed_at` and `post_completion_review_due_at`
fillable, while lines 29-36 promise each is written *only* by `TransitionProjectStatus`,
`AwardContract`/`RecordContractVariation` or `RecordProjectProgress`.

One `$project->update($request->validated())` in the Actions/Livewire slice bypasses every guard in
§2 — the transition table, the award precondition, the mid-term flag, the certified freeze — with no
test to catch it. `published_at`/`published_by_id` were correctly excluded; that is the right
instinct, applied to two columns out of nine.

**Fix:** drop all seven from `#[Fillable]`. Actions assign explicitly (`forceFill([...])->save()` or
direct property writes), which is also what makes the chokepoint greppable.

### 3. HIGH — `scopeVisibleTo()` is a no-op with a TODO
`app/Models/Project.php:257-260` returns `$query` untouched. Rev. 2 §3 makes this the *single*
definition of "which projects may this user see", shared by `ProjectPolicy::view` and every list
screen so a policy and a query cannot disagree. Today a Consultant sees the entire tenant portfolio.

The TODO is honest, but the next slice builds lists and policies on top, and a caller that trusts the
name gets no filtering and a green suite. The assignments table and index already exist, so:
```php
return $query->when(
    $user->onlyHoldsProjectLevelRoles(),      // Consultant / FieldMonitor only
    fn (Builder $q) => $q->whereHas('assignments', fn (Builder $a) => $a
        ->where('user_id', $user->id)->whereNull('unassigned_at')),
);
```
If it cannot land now, make it `throw new LogicException` until implemented — a loud stub beats a
silent one. Pair it with the consultant case from §7's authorization matrix.

### 4. MEDIUM — `Contract::$sum` immutability is unenforced at the model
Rev. 2 §1.7 makes the award sum immutable once awarded, with revisions as new rows. Nothing enforces
it yet: `sum` is fillable and only the unbuilt `UpdateContract` would object. `TenantSafeBuilder`
guards `tenant_id` on mass updates but knows nothing about `sum`.

Mirror the `BelongsToTenant` pattern with a model `updating` guard — reject a dirty `sum`,
`award_date`, `contractor_id` or `scope_of_works` when `status !== draft`. Model-level means it also
covers builder updates and future import paths, not just the Action.

### 5. MEDIUM — the delete story is inconsistent and will 500 on force-delete
`indicators.project_id` is `cascadeOnDelete` (`…100200:26`) while `indicator_readings.indicator_id`
is `restrictOnDelete` (`…100220:21`). Force-deleting a project cascades into `indicators`, which the
readings FK then refuses — an FK violation, not a clean error. Meanwhile `contracts.project_id` is
`restrictOnDelete` while `project_locations`, `project_assignments` and `project_status_events`
cascade, so a force-delete fails at different points depending on what exists.

Soft deletes make this rare, but any purge, retention job or `forceDelete()` in a test hits it. Decide
the story once: either children cascade all the way down (readings cascade from indicators) or the
domain restricts all the way up (indicators restrict from projects). I lean **restrict throughout**
for government records, with an explicit `PurgeProject` Action later if it is ever needed.

### 6. LOW — factory `financials()` fabricates the cache without its source
`database/factories/ProjectFactory.php:264-274` sets `contract_value_total` while creating no
`Contract` rows. The reconcile command in rev. 2 §1.4 would zero these fixtures, and a test that
awards a contract onto an `ongoing()` project double-counts. Either add a `->withContract()` state
that creates the matching contract, or state in the docblock that fixtures are intentionally
denormalized and reconciliation tests must build their own.

### 7. LOW — `Money::fromDecimalString()` edge cases
`app/Support/Money.php:53` — `(int) $matches[2] * self::SCALE` saturates silently at `PHP_INT_MAX`
for absurd input rather than throwing; `decimal(18,2)` allows 16 integer digits so there is real
margin, but a length guard on a money parser is cheap insurance.
Lines 55-57 round half **away from zero** for negatives (`-12.505 → -12.51`) while the docblock says
"half-up" (which would give `-12.50`). Downward contract variations are the case that will produce
negatives. Fix the behaviour or the comment — either is fine, the mismatch is not.

### 8. NIT — `after('phone')` on `users.contractor_id`
`…100050:11`. A no-op on SQLite, so column order differs between the test and production databases.
Harmless, but design §8 said avoid it precisely so the two engines stay comparable.

### 9. NIT — stale docblock reference
`app/Models/Project.php:34` says `contract_sum`; the column has been `contract_value_total` since
rev. 2. That old name is exactly the misreading the rename existed to prevent.

### 10. NIT — `project_funding_sources` has no soft deletes
`…100120`. Every other domain table in the slice soft-deletes, and this one carries financial
attribution (`amount`, `percentage`). Removing a donor split currently leaves no trace. Either add
`softDeletes()` or record in the migration comment that funding splits are intentionally hard-deleted
and the activity log is the audit trail.

---

## Explicitly verified correct
- **`tenant_id` placement** — all nine tenant-owned tables carry it and use the trait; `contractors`,
  `sectors`, `funding_sources`, `lgas`, `wards` correctly do not. `Contractor` has no trait and
  carries the reviewer warning inline; `created_by_tenant_id` is documented as provenance.
- **`ProjectStatus`** — transition table matches §2 minus `mid_term` exactly; `isTerminal()`,
  `isFrozen()` and `isAwardedOrBeyond()` give the Actions layer the predicates it needs, and
  `isFrozen()` correctly means *fields frozen*, not *record locked* (finding 3 of the domain review).
- **Money** — floats rejected on both the read and write boundary, `percentageOf()` isolates the only
  division and returns a ratio rather than money, `format()` groups digits without `number_format()`.
  No float path exists in the module.
- **Immutable-value discipline** — `financial_progress` is derived with a throwing setter;
  `published_at` is unfillable.
- **Indexes and migration order** match the design, including the composites the oversight aggregate
  and the deadline sweep depend on, and `reporting_frequency` landed as flagged.
- **Seeders** — `DemoProjectSeeder` covers the awkward cases the UI must survive (multi-site,
  co-funded, variation, cross-MDA supervision, behind-schedule, closed past review date) and writes
  everything inside `runAs()` without touching `tenant_id` by hand.
