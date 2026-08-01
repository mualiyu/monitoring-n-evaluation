# Phase 1 — Project Registry Module Design (rev. 2 — schema freeze)

PROJECT_PLAN §4 + §5 module 3. Supersedes rev. 1; incorporates
`docs/design/projects-domain-validation.md` (me-domain-expert, verdict GAPS FOUND).
Conventions follow shipped code: `#[Fillable([...])]`, `ulid` public ids, `casts()`, verb-first
Actions. Nothing here touches the portal.

## 0. Response to domain validation

| # | Finding | Disposition | Where |
|---|---|---|---|
| 1 | `supervising_agency_id` + scope of works + notice window setting | **Adopted** (with an access caveat — §9.1) | §1.4, §1.7, §2.4 |
| 2 | Drop `mid_term` from the status enum | **Adopted in full** — it was my own flagged doubt | §2 |
| 3 | `closed` must not lock the record | **Adopted, with the lock redefined** — freeze applies to scope/financial *fields*, never to attaching monitoring artifacts | §2.3 |
| 4 | `project_locations` from day one | **Adopted in full** — location columns leave `projects` entirely | §1.5 |
| 5 | Full-width indicators tables in Phase 1 | **Adopted**, with baseline mandated at activation rather than by `NOT NULL` (§1.10) | §1.10 |
| 6 | Contract sum on `contracts` only, immutable, variations as records | **Adopted with one deviation:** the project-level aggregate survives as an explicitly-named cache, `contract_value_total` | §1.4, §1.7 |
| 7 | Registry typing + firm link | **Adopted**; table stays `contractors` (rename to `firms` considered and rejected — §1.3) | §1.3 |
| 8 | Co-funding pivot | **Adopted now** — scalar `funding_source_id` removed | §1.6 |
| 9 | `term()` + configurable numbers | **Adopted** | §0.1 |
| 10 | `revised_end_date` + `published_at` | **Adopted** | §1.4 |

### 0.1 Configurable domain numbers (instance setting, `config/platform.php` default)
`commencement_notice_days` (3) · `initial_inspection_days` (10) · `mid_term_trigger_percent` (50) ·
`post_completion_review_months` (6) · `require_final_inspection_for_certification` (false in Phase 1).
Read through `SettingsRepository` (tenant → instance → config), never as literals. Every domain noun
in Blade goes through `term()`: MDA, LGA/Area Council, ward, sector, funding source, contractor,
focal person, commencement/mobilization.

## 1. Entities & migrations

### 1.1 Money: `decimal(18,2)` column, integer-kobo `Money` VO in PHP
Auditors, DBAs and Excel exports read this database directly: `4500000.00` is unambiguous where
`450000000` kobo invites a factor-of-100 error — in a government audit that is a finding, not a bug.
MySQL `SUM()` on DECIMAL is exact; PHP arithmetic stays integer, so no float touches a naira.
`app/Support/Money.php` (readonly VO) + `app/Casts/MoneyCast.php`. Float columns/casts banned
(discipline test). Indicator values use `decimal(18,4)` — ratios and rates need the extra places.

### 1.2 Reference data — global, **no `tenant_id`**, seeded per instance
| Table | Columns |
|---|---|
| `sectors` | `id`, `code` (unique), `name`, `parent_id` (nullable self FK), `sort_order`, `is_active` |
| `funding_sources` | `id`, `code` (unique), `name`, `type` (`FundingSourceType`: internal_revenue\|federal_allocation\|loan\|grant\|donor\|ppp\|counterpart), `is_active` |
| `lgas` | `id`, `code` (unique), `name`, `is_active` |
| `wards` | `id`, `lga_id` (restrict), `code`, `name`, `is_active`, unique(`lga_id`,`code`) |

No `tenant_id`: one deployment = one state, and a shared taxonomy is what makes the cross-MDA
dashboard aggregatable at all. No soft deletes — `is_active = false` retires a row without orphaning
a `restrictOnDelete` FK.

### 1.3 `contractors` — global registry, **deliberately no `tenant_id`**
```php
$table->id(); $table->ulid('ulid')->unique();
$table->string('name'); $table->string('rc_number', 40)->nullable()->unique();
$table->string('type', 30)->default('contractor');   // FirmType: contractor|consultant_firm|supplier
$table->string('category', 60)->nullable();          // civil works, ICT, medical supply...
$table->string('contact_name')->nullable(); $table->string('contact_email')->nullable();
$table->string('contact_phone', 32)->nullable(); $table->text('address')->nullable();
$table->boolean('is_blacklisted')->default(false); $table->text('blacklist_reason')->nullable();
$table->decimal('performance_score', 4, 2)->nullable();   // rubric is instance-configurable; no Phase 1 computation
$table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
$table->foreignId('created_by_tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
$table->timestamps(); $table->softDeletes();
$table->index(['type', 'is_blacklisted']);
```
Plus `users.contractor_id` (nullable FK, **reserved and unused in Phase 1**) so contractor
self-service accounts (plan §9 open question 4) do not require a later migration on `users`.

**Why global** (the one table that reads like a tenancy violation and isn't): a contractor is a legal
entity in the *state's* vendor registry. A per-MDA table would let a firm blacklisted by Works keep
winning in Health — the exact failure the BPP lifecycle exists to prevent. The tenant-owned boundary
is `contracts`. `created_by_tenant_id` is **provenance, never a scope key** — say so in the docblock.
**Rename to `firms` considered and rejected:** PROJECT_PLAN §4 names it `contractors`, "contractor" is
the government-facing word, and `type` carries the breadth the expert asked for; display naming is a
`term()` concern, not a schema one.
Writes: any tenant user with `contractors.create` may **add** (deduped on `rc_number`); editing and
blacklisting are oversight-only (`contractors.manage`) — one MDA must not rewrite a vendor record
another MDA's contracts depend on.

### 1.4 `projects` — tenant-owned
```php
$table->id(); $table->ulid('ulid')->unique();
$table->foreignId('tenant_id')->constrained()->restrictOnDelete();
$table->string('reference', 40);
$table->string('title'); $table->text('description')->nullable();
$table->text('goal')->nullable();                      // flowchart scope-definition step
$table->text('objectives')->nullable();
$table->foreignId('sector_id')->constrained()->restrictOnDelete();
$table->string('type', 20);                            // ProjectType: capital|programme|recurrent
$table->string('status', 20)->default('draft');
$table->foreignId('supervising_agency_id')->nullable()->constrained('tenants')->nullOnDelete();
$table->string('supervising_agency_name')->nullable(); // non-tenant supervisor (federal PIU, donor)
$table->decimal('budget_allocation', 18, 2)->nullable();  // appropriation — the project's own figure
$table->string('budget_code', 60)->nullable();            // finance linkage (SIFMIS, Phase 4)
$table->decimal('contract_value_total', 18, 2)->nullable(); // DERIVED CACHE — see below
$table->decimal('expenditure_to_date', 18, 2)->default(0);  // roll-up from approved reports (Phase 2)
$table->decimal('physical_progress', 5, 2)->default(0);     // roll-up, human-attested
$table->date('start_date')->nullable();
$table->date('expected_end_date')->nullable();         // planned
$table->date('revised_end_date')->nullable();          // revised (variation or approved extension)
$table->date('actual_end_date')->nullable();
$table->date('post_completion_review_due_at')->nullable(); // actual_end + post_completion_review_months
$table->timestamp('mid_term_flagged_at')->nullable();  // set once when physical % crosses the threshold
$table->timestamp('published_at')->nullable();         // Phase 3 portal gate
$table->foreignId('published_by_id')->nullable()->constrained('users')->nullOnDelete();
$table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
$table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
$table->timestamp('status_changed_at')->nullable();
$table->timestamps(); $table->softDeletes();
$table->unique(['tenant_id', 'reference']);
$table->index(['tenant_id', 'status']); $table->index(['tenant_id', 'sector_id']);
$table->index(['tenant_id', 'expected_end_date']); $table->index(['status']);
$table->index(['tenant_id', 'post_completion_review_due_at']); $table->index(['published_at']);
```
- **Location columns are gone** → `project_locations` (§1.5). **`funding_source_id` is gone** →
  `project_funding_sources` (§1.6).
- **`financial_progress` is not stored** — accessor over `expenditure_to_date / contract_value_total`.
- **`contract_value_total` — the one deviation from finding 6.** The expert is right that the
  authoritative sum belongs on `contracts` and must be immutable; that is now enforced (§1.7). But
  the project-level figure still has to appear on the portfolio list, the oversight board and every
  export, and computing it per row is the N+1 that kills the hottest query in the module. So it stays
  as a **cache with an honest name** — never `contract_sum`, which reads as authoritative —
  documented in the column comment as "derived: Σ active contracts + variations; source of truth is
  `contracts`", written only by `AwardContract`/`RecordContractVariation` inside their transaction,
  and reconciled by `projects:reconcile-contract-values` (Phase 2). If the reviewer prefers the join,
  the change is one accessor and one index — but I would take the cache.
- **`reporting_frequency`** (nullable string 20, null = instance default) — required by the Progress
  Reporting module (`docs/design/progress-reporting.md` §0) to drive per-project obligation
  generation. **Include it in this migration**: adding it later is an `ALTER` on the hottest table in
  the system. Distinct from `indicators.measurement_frequency`, which governs readings, not reports.

### 1.5 `project_locations` — tenant-owned (finding 4)
```php
$table->id(); $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
$table->foreignId('project_id')->constrained()->cascadeOnDelete();
$table->string('site_name')->nullable();               // "Ward 3 PHC", "Km 4–7 alignment"
$table->text('description')->nullable();
$table->foreignId('lga_id')->nullable()->constrained()->restrictOnDelete();
$table->foreignId('ward_id')->nullable()->constrained()->restrictOnDelete();
$table->decimal('latitude', 10, 7)->nullable(); $table->decimal('longitude', 10, 7)->nullable();
$table->boolean('is_primary')->default(false);
$table->timestamps(); $table->softDeletes();
$table->index(['tenant_id', 'lga_id']); $table->index(['project_id', 'is_primary']);
```
Single-site projects get exactly one row (`is_primary = true`), created by `RegisterProject` — the UI
still shows one location field on the wizard's step 3. Single-primary is enforced in
`SetPrimaryProjectLocation` (unset others in the same transaction) rather than by a unique index:
MySQL has no partial unique index, and `unique(project_id, is_primary)` would wrongly cap non-primary
sites at one. Covered by a test. Phase 2 inspections FK to `project_location_id`.
Consequence to accept: "projects by LGA" counts a multi-site project in several LGAs — correct for a
map, so state-wide *project* counts must aggregate `DISTINCT project_id`.

### 1.6 `project_funding_sources` — tenant-owned pivot (finding 8)
```php
$table->id(); $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
$table->foreignId('project_id')->constrained()->cascadeOnDelete();
$table->foreignId('funding_source_id')->constrained()->restrictOnDelete();
$table->decimal('amount', 18, 2)->nullable();
$table->decimal('percentage', 5, 2)->nullable();
$table->boolean('is_primary')->default(false);
$table->timestamps();
$table->unique(['project_id', 'funding_source_id']);
$table->index(['tenant_id', 'funding_source_id']);
```
Donor + counterpart funding is near-universal on World Bank projects, and a scalar FK would have
forced every future report to lie about one of them. Filtering by funding source now costs a join —
accepted; misattributed donor money is a reporting failure, a join is not.

### 1.7 `contracts` — tenant-owned; award sum immutable (finding 6)
```php
$table->id(); $table->ulid('ulid')->unique();
$table->foreignId('tenant_id')->constrained()->restrictOnDelete();
$table->foreignId('project_id')->constrained()->restrictOnDelete();
$table->foreignId('contractor_id')->constrained()->restrictOnDelete();
$table->string('contract_number', 60);
$table->string('type', 20)->default('works');          // ContractType: works|supply|consultancy|service
$table->string('status', 20)->default('awarded');      // awarded|active|completed|terminated
$table->decimal('sum', 18, 2);                         // ORIGINAL award sum — immutable after award
$table->text('scope_of_works');                        // statutory: quoted in the commencement notice
$table->date('award_date'); $table->date('commencement_date')->nullable();
$table->unsignedSmallInteger('duration_days')->nullable();
$table->date('expected_completion_date')->nullable();
$table->decimal('retention_percentage', 5, 2)->nullable();
$table->foreignId('varies_contract_id')->nullable()->constrained('contracts')->restrictOnDelete();
$table->text('variation_reason')->nullable();          // required when varies_contract_id is set
$table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
$table->timestamps(); $table->softDeletes();
$table->unique(['tenant_id', 'contract_number']);
$table->index(['tenant_id', 'project_id']); $table->index('contractor_id');
$table->index('varies_contract_id');
```
Immutability: once `status != draft`, `UpdateContract` rejects any change to `sum`, `award_date`,
`contractor_id` or `scope_of_works` — a revision is a **new row** with `varies_contract_id` and a
`variation_reason` (the manual's amendment-register pattern; superseded values stay readable).
Revised contract value = `sum` + Σ variations, exposed as an accessor.

### 1.8 `project_assignments` — tenant-owned
`id`, `tenant_id`, `project_id` (cascade), `user_id` (restrict), `role`
(`ProjectRole`: consultant|field_monitor|focal_officer|supervisor), `assigned_by_id`, `assigned_at`,
`unassigned_at` (nullable), timestamps. `unique(project_id, user_id, role)`;
`index(tenant_id, user_id)` for "my projects". Re-assignment reactivates the row.

### 1.9 `project_status_events` — tenant-owned transition ledger
`id`, `tenant_id`, `project_id` (cascade), `from_status` (nullable), `to_status`, `actor_id`,
`reason` (nullable; required for suspend/cancel/close-override), `occurred_at`, timestamps.
`index(tenant_id, project_id, occurred_at)`. Activitylog records *that* something changed; this typed
table makes "average days from award to mobilization per MDA" one indexed query. Append-only — no
update/delete Action exists.

### 1.10 Indicators — full-width schema frozen now (finding 5)
Schema only. The Results Framework **module** (Actions, validation workflow, logframe UI, traffic
lights) is a separate deliverable; freezing the shape now is what prevents a readings migration later.
```php
// indicators (tenant-owned, softDeletes)
ulid, tenant_id, project_id (nullable FK — MDA-programme indicators exist),
result_framework_id (nullable, Phase 2), tier (nullable: pdo|intermediate|output),
name, definition (text), unit (number|percentage|time|one_off),
measurement_frequency (weekly|monthly|quarterly|biannual|annual|one_off),  // deadline engine v1 needs this
data_source (text), means_of_verification (text),
responsible_collector_id (nullable FK users), responsible_collector_text (nullable),
baseline_value decimal(18,4) nullable, baseline_date date nullable, baseline_source (nullable),
target_type (continuous|time_bound|percentage_achievement),
smart_justification (text, nullable), is_active bool, activated_at, created_by_id
index(tenant_id, project_id), index(tenant_id, measurement_frequency), index(tenant_id, is_active)

// indicator_targets (tenant-owned)
tenant_id, indicator_id (cascade), period_type, period_start date, period_end date,
target_value decimal(18,4), notes  → unique(indicator_id, period_start, period_end)

// indicator_readings (tenant-owned, softDeletes)
ulid, tenant_id, indicator_id (restrict), period_start, period_end, actual_value decimal(18,4),
source_type (primary|secondary), collection_method (nullable), notes,
status (draft|submitted|validated|published),
submitted_by_id/submitted_at, validated_by_id/validated_at, published_at
index(tenant_id, indicator_id, period_start), index(tenant_id, status)
```
**Baseline: mandatory, enforced at activation, not by `NOT NULL`.** A `NOT NULL` baseline forces a
placeholder value into every half-drafted indicator, and a fabricated zero baseline is worse data
quality than an explicit null — the manual's own point about baselines. So `ActivateIndicator`
refuses without `baseline_value` + `baseline_date` + `baseline_source`, only active indicators accept
readings or appear in reports, and a test asserts both halves. If the expert wants the DB-level
constraint too, it becomes a `CHECK` in a MySQL-only migration branch — say the word.

### 1.11 `tenant_id` summary
**Carries it:** `projects`, `project_locations`, `project_funding_sources`, `contracts`,
`project_assignments`, `project_status_events`, `indicators`, `indicator_targets`,
`indicator_readings`.
**Deliberately not:** `sectors`, `funding_sources`, `lgas`, `wards` (global reference, required for
cross-MDA aggregation); `contractors` (state vendor registry; the tenant-owned link is `contracts`);
`media` (reachable only through its owning tenant-scoped model — the signed route re-checks the
owner's policy).

## 2. State machine

`app/Enums/ProjectStatus.php` — backed enum + `canTransitionTo()` + `isTerminal()`.
**`mid_term` is not a status** (finding 2 — my own flagged doubt, now resolved).

| From | Allowed to | Trigger role | Permission |
|---|---|---|---|
| `draft` | `awarded`, `cancelled` | MeOfficer, MdaAdmin | `projects.award` / `projects.cancel` |
| `awarded` | `mobilized`, `suspended`, `cancelled` | MeOfficer, MdaAdmin | `projects.status.update` |
| `mobilized` | `in_progress`, `suspended`, `cancelled` | MeOfficer, MdaAdmin | `projects.status.update` |
| `in_progress` | `completed`, `suspended`, `cancelled` | MeOfficer, MdaAdmin | `projects.status.update` |
| `completed` | `certified`, `in_progress` (defects) | MdaAdmin / MeOfficer | `projects.certify` / `projects.status.update` |
| `certified` | `closed` | MdaAdmin, StateAdmin | `projects.close` |
| `suspended` | `in_progress`, `cancelled` | MdaAdmin, StateAdmin | `projects.suspend` |
| `cancelled` | — terminal | — | — |

### 2.1 Guards (all inside `App\Actions\Projects\TransitionProjectStatus` — the only writer of `status`)
1. `$from->canTransitionTo($to)` or throw `InvalidStatusTransition`.
2. Per-target permission above **plus** tenant match via policy.
3. Preconditions: `→ awarded` needs ≥1 non-draft contract; `→ completed` needs
   `physical_progress == 100`; `→ suspended|cancelled` needs a reason; `→ certified` needs a final
   inspection **when `require_final_inspection_for_certification` is true** (false in Phase 1, so the
   Phase 2 guard point exists from day one and flips by setting); `→ closed` needs
   `post_completion_review_due_at` to be past, or a StateAdmin override with a reason.
4. Writes `status` + `status_changed_at`, appends a `project_status_events` row, fires
   `ProjectStatusChanged` (queued: notify assignees, bust the oversight cache).
5. `→ completed` also sets `actual_end_date` and computes
   `post_completion_review_due_at = actual_end_date + post_completion_review_months`.

### 2.2 Mid-term evaluation is an event, not a state
`RecordProjectProgress` sets `mid_term_flagged_at` the first time `physical_progress` crosses
`mid_term_trigger_percent` (default 50), raising a "mid-term evaluation due" notification and
dashboard flag. The project stays `in_progress`. Phase 2 inspections/evaluations resolve the flag.

### 2.3 `closed` does **not** lock the record (finding 3)
The freeze applies to **scope and financial fields only** — title, goal, budget, contract links,
dates — from `certified` onward, so the certified figures cannot be quietly rewritten. It never
blocks **attaching new artifacts**: post-completion inspections, impact evaluations, documents,
recommendations, indicator readings and issues can all be created against `certified` and `closed`
projects, which is exactly what 6–12-month post-completion monitoring requires. `UpdateProjectDetails`
enforces the field freeze; no child-record Action consults project status for permission to exist.
Corrections after certification go through the Phase 2 amendment register, not an unlocked edit form.

### 2.4 Progress and supervision fields
- `physical_progress` — human-attested, never derived; only `RecordProjectProgress`
  (`projects.progress.update` → MeOfficer, MdaAdmin) writes it. **Consultants cannot write it
  directly**: they submit a report and approval propagates the figure (Phase 2). A contractor does not
  declare their own project 80% done.
- `expenditure_to_date` — same Action; Phase 2 sources it from approved reports.
- `financial_progress` — derived accessor, writable by no one.
- `supervising_agency_id` / `supervising_agency_name` — set at registration or award; at least one is
  required before the Phase 2 commencement notice can be issued. **Informational only** — see §9.1.

## 3. Actions & authorization

Permission convention `{resource}.{action}`. New: `projects.view|create|update|delete|award|
status.update|certify|close|suspend|cancel|assign|progress.update|publish`,
`contracts.view|create|update|delete`, `contractors.view|create|manage`,
`indicators.view|create|update|activate`, `documents.view|upload|delete`, `oversight.portfolio.view`.

`app/Actions/Projects/` — each invokable, each authorizes before mutating:
| Action | Permission | Scope rule |
|---|---|---|
| `RegisterProject` | `projects.create` | current tenant; creates the primary `project_locations` row + funding rows |
| `UpdateProjectDetails` | `projects.update` | same tenant; scope/financial fields frozen from `certified` |
| `TransitionProjectStatus` | per-target (§2) | same tenant; only writer of `status` |
| `AwardContract` | `contracts.create` + `projects.award` | same tenant; creates contract, refreshes `contract_value_total`, transitions `draft → awarded` in one transaction |
| `RecordContractVariation` | `contracts.update` | same tenant; **new** contract row + `variation_reason`; refreshes the cache; never edits the original |
| `ReviseProjectSchedule` | `projects.update` | same tenant; sets `revised_end_date` with a reason |
| `RecordProjectProgress` | `projects.progress.update` | same tenant; physical % + expenditure; raises the mid-term flag |
| `AddProjectLocation` / `SetPrimaryProjectLocation` / `RemoveProjectLocation` | `projects.update` | same tenant; single-primary invariant |
| `SetProjectFundingSources` | `projects.update` | same tenant; percentages total ≤ 100 |
| `AssignProjectMember` / `UnassignProjectMember` | `projects.assign` | same tenant **and** target holds an active `tenant_user` membership and a Consultant/FieldMonitor/MeOfficer role |
| `AttachProjectDocument` / `RemoveProjectDocument` | `documents.upload` / `.delete` | same tenant + per-collection rule (§4) |
| `PublishProject` | `projects.publish` | MdaAdmin + StateAdmin only; sets `published_at`; **no portal route consumes it in Phase 1** |
| `ArchiveProject` | `projects.delete` | same tenant; `draft`/`cancelled` only; soft delete |
| `RegisterContractor` | `contractors.create` | **global write**; dedupes on `rc_number` |
| `UpdateContractor` / `BlacklistContractor` | `contractors.manage` | oversight only |
`app/Actions/Oversight/`: `ListProjectsAcrossTenants`, `BuildPortfolioSummary`
(`oversight.portfolio.view`) — the only `withoutTenancy()` calls in this module.

Policies: `ProjectPolicy`, `ContractPolicy`, `ContractorPolicy`, `ProjectAssignmentPolicy`,
`IndicatorPolicy`, `MediaPolicy` — all on the "permission AND `tenant_id` match" helper.
`ProjectPolicy::view` adds the assignment rule (a user holding **only** Consultant or FieldMonitor
needs an active `project_assignments` row), backed by `Project::scopeVisibleTo()` so lists and
policies share one definition.

**Permission → role matrix** (new `PermissionSeeder`; roles already seeded):
| Permission | SuperAdmin | StateAdmin | ExecViewer | DQReviewer | MdaAdmin | MeOfficer | Consultant | FieldMonitor |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `projects.view` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | assigned | assigned |
| `projects.create` / `.update` | ✓ | — | — | — | ✓ | ✓ | — | — |
| `projects.award` / `.status.update` | ✓ | — | — | — | ✓ | ✓ | — | — |
| `projects.certify` | ✓ | — | — | — | ✓ | — | — | — |
| `projects.close` / `.suspend` / `.cancel` | ✓ | ✓ | — | — | ✓ | — | — | — |
| `projects.progress.update` | ✓ | — | — | — | ✓ | ✓ | — | — |
| `projects.assign` / `.delete` | ✓ | — | — | — | ✓ | ✓ / — | — | — |
| `projects.publish` | ✓ | ✓ | — | — | ✓ | — | — | — |
| `contracts.*` | ✓ | view | view | view | ✓ | create/update | — | — |
| `contractors.view` / `.create` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — | — |
| `contractors.manage` | ✓ | ✓ | — | — | — | — | — | — |
| `indicators.view` / `.create|update` | ✓ | ✓ / — | ✓ / — | ✓ / — | ✓ | ✓ | ✓ / — | — |
| `indicators.activate` | ✓ | — | — | — | ✓ | ✓ | — | — |
| `documents.view` / `.upload` | ✓ | ✓ / — | ✓ / — | ✓ / — | ✓ | ✓ | ✓ / photos | ✓ / photos |
| `oversight.portfolio.view` | ✓ | ✓ | ✓ | ✓ | — | — | — | — |
SuperAdmin holds every permission **explicitly seeded** — still no `Gate::before` bypass.

## 4. Documents (medialibrary)
`config/documents.php` maps collection → `[upload_permission, view_permission, mimes, max_kb]`.
Project: `award_letter`, `designs`, `boq`, `photos`, `other`. Contract: `agreement`, `boq`,
`variation_docs`. Upload = MdaAdmin/MeOfficer, plus Consultant/FieldMonitor for `photos` only.
View = tenant staff + oversight.
- Private disk only, served via `GET /documents/{media:uuid}` behind `signed` + `MediaPolicy`, which
  resolves `$media->model` and delegates to that model's `view` policy — tenancy is enforced by the
  owner, never by the media row.
- `pdf,doc,docx,xls,xlsx` ≤ 20 MB; `jpg,jpeg,png,webp` ≤ 10 MB re-encoded via conversions (`thumb`
  320px, `preview` 1024px) — re-encoding strips hostile payloads, but EXIF/GPS is copied into
  `custom_properties` first (Phase 2 inspections need it). Disk filenames are library-generated.

## 5. Surface mapping
**Tenant** (`auth` + `tenant.member` + `active`), `App\Livewire\Tenant\Projects\`: `/projects`
(filter bar → stat row → table, query-string filters, Excel export) · `/projects/create` (wizard:
identity & scope → funding & budget → sites & schedule) · `/projects/{project}` (tabs: overview ·
contracts · sites · team · indicators · documents · timeline) · `/projects/{project}/edit` ·
`/projects/{project}/contracts/create` · `/contractors`. Consultant/FieldMonitor use the same index
filtered by `visibleTo` — one code path, isolation proven once.

**Oversight**, `App\Livewire\Oversight\Projects\`: `/portfolio` (cross-MDA list +
MDA/sector/status/funding filters) · `/portfolio/{tenant}` · `/projects/{project}` (read-only) ·
`/contractors`. Mutations limited to suspend/close/cancel via the same Actions; reads via
`app/Actions/Oversight/`.

**Portal:** no routes. `published_at` exists so Phase 3 does not backfill, and nothing reads it yet.

## 6. Dashboard aggregates — no rollup table in Phase 1
- The oversight board is **one** grouped query, not one per card: `SELECT tenant_id, status,
  COUNT(*), SUM(contract_value_total), SUM(expenditure_to_date) FROM projects WHERE deleted_at IS
  NULL GROUP BY tenant_id, status` via `withoutTenancy()`, served by the two `status` indexes.
- Cache 5 minutes in Redis (`oversight:portfolio:v1`), busted by the `ProjectStatusChanged` listener.
- LGA/map aggregates read `project_locations` and must count `DISTINCT project_id` (§1.5).
- **Build trigger:** > 20,000 project rows or > 300 ms p95. Shape it now, build later:
  `project_status_daily_summaries(summary_date, tenant_id, status, project_count,
  contract_value_total, expenditure_total, avg_physical_progress)`, unique on the first three,
  nightly command. **Do not create it in Phase 1.**

## 7. Test matrix
- **Isolation**, one per tenant-owned model (9 now: projects, locations, funding sources, contracts,
  assignments, status events, indicators, targets, readings) — create in A and B; assert index,
  detail (404 on foreign ULID), export and model count each see only their own.
- **State machine** `ProjectStatusTransitionTest`: every allowed transition; forbidden ones —
  `certified → in_progress`, any transition out of `cancelled`, `draft → completed`, `→ awarded`
  without a contract, `→ completed` at 99%, `→ closed` before `post_completion_review_due_at` without
  an override, suspend without a reason. **`mid_term` is not a valid status value** (regression guard
  for finding 2). Each success asserts a `project_status_events` row with actor + timestamp.
- **Post-completion openness** `ClosedProjectTest`: a `closed` project still accepts documents,
  indicator readings and (stubbed) inspections, while `UpdateProjectDetails` rejects scope/financial
  edits — the two halves of finding 3.
- **Contract immutability** `ContractVariationTest`: editing `sum` after award rejected; variation
  creates a new row with a reason; `contract_value_total` follows a variation and a soft delete.
- **Locations** `ProjectLocationTest`: single-primary invariant across two Actions; multi-site project
  counts once in a state-wide count and in each LGA on the map.
- **Funding** `ProjectFundingTest`: donor + counterpart split; percentages > 100 rejected.
- **Indicators** `IndicatorActivationTest`: activation without baseline/date/source rejected; readings
  rejected against an inactive indicator; frequency drives the (stubbed) due date.
- **Authorization matrix** per role: Consultant cannot change status, see unassigned projects, or
  write `physical_progress`; MeOfficer cannot certify or publish; MdaAdmin cannot reach `/portfolio`;
  StateAdmin cannot create a tenant project; ExecViewer read-only; FieldMonitor uploads photos only.
- **Contractor globality**: created in A, visible in B (deliberate) while A's *contracts* are not;
  MeOfficer cannot blacklist, StateAdmin can.
- **Documents**: private disk, signed route, foreign-tenant media 404, consultant blocked from
  `award_letter`, oversized/wrong-mime rejected. **Money** (Unit): kobo round-trip, no floats, `SUM`.
- **Oversight** `PortfolioSummaryTest`: aggregates both tenants; tenant-surface user denied; whole
  board ≤ 3 queries.

Factory states: `Project::factory()` → `->draft()`, `->awarded()`, `->ongoing()`, `->completed()`,
`->certified()`, `->closed()`, `->suspended()`, `->behindSchedule()`, `->multiSite()`,
`->coFunded()`; plus `ContractorFactory(->consultantFirm(), ->blacklisted())`,
`ContractFactory(->variation())`, `IndicatorFactory(->active(), ->withoutBaseline())`. Factories run
inside `runAs($tenant)` — an unbound factory throws, which is the intended teaching moment.

## 8. Migration order & seeders
```
100000_create_sectors_table            100010_create_funding_sources_table
100020_create_lgas_table               100030_create_wards_table              (FK → lgas)
100040_create_contractors_table        100050_add_contractor_id_to_users_table
100100_create_projects_table           (FK → tenants, sectors, users; supervising_agency → tenants)
100110_create_project_locations_table  100120_create_project_funding_sources_table
100130_create_contracts_table          100140_create_project_assignments_table
100150_create_project_status_events_table
100200_create_indicators_table         100210_create_indicator_targets_table
100220_create_indicator_readings_table
```
Reference tables precede `projects` (all `restrictOnDelete`); `contractors` precedes `contracts` and
the `users` alter; indicators last. Nothing MySQL-specific — no `enum`, spatial, generated columns or
`fulltext`; lat/lng are `decimal(10,7)`, so the suite stays SQLite-runnable.

Seeders (`DatabaseSeeder`: Role → **Permission** → Reference → DemoTenant → DemoProject):
- `PermissionSeeder` — idempotent, §3 matrix, team id null.
- `SectorSeeder` — generic taxonomy (Agriculture, Education, Health, Works & Transport, Water,
  Environment, Justice & Security, Commerce & Industry, ICT, Social Development); no state name.
- `FundingSourceSeeder` — IGR, Federal Allocation, World Bank Credit, Donor Grant, Counterpart, PPP.
- `LgaWardSeeder` — **fictional** LGAs/wards (Central, Riverside, Northgate, Hilltop, Lakeside).
- `ContractorSeeder` — ~6 fictional firms across all `type` values, one blacklisted, one without RC.
- `DemoProjectSeeder` — 10 projects via `runAs($tenant)`: Works gets road/bridge/drainage/township
  roads (draft, awarded, in_progress ×2, behindSchedule, completed); Health gets PHC/maternity/
  cold-chain/equipment (draft, in_progress, closed, certified). At least one multi-site project, one
  co-funded (donor + counterpart), one with a contract variation, one supervised by the other MDA,
  and 2–3 indicators with baselines and targets. Every awarded+ project gets a contract, assignments
  and a status-event trail.

## 9. Risks
1. **`supervising_agency_id` implies access it does not grant.** Where Works supervises a Health
   project, Works' monitors still have no route to that project: membership is per tenant and the
   project belongs to Health. Phase 1 treats the field as **statutory metadata only**; the workable
   path today is assigning a user who holds Health membership. Cross-tenant supervision (a scoped,
   audited grant) is a Phase 2 design question — **flagging it now because the field's presence will
   read as a promise**, and someone will otherwise "fix" it by loosening the membership gate.
2. **`contract_value_total` cache drift.** Two writers plus soft-deleted contracts and variations can
   desynchronize a money field on an executive dashboard. Both Actions write it inside the contract's
   transaction; the invariant goes in the column comment and in a test; Phase 2 adds the reconcile
   command. This is the deviation from finding 6 and the place it can bite.
3. **N+1 grew with the schema.** Rows now want sector, funding sources (pivot), primary location,
   contracts→contractor and counts. Eager-load `sector:id,name`, `fundingSources:id,name`,
   `primaryLocation.lga:id,name`, `contracts.contractor:id,name` + `withCount('assignments')`;
   budget ≤ 6 queries per page at any size, asserted.
4. **Multi-site double counting.** Any LGA/sector/map aggregate over `project_locations` must count
   `DISTINCT project_id`, or a 12-site project inflates the state-wide count twelvefold on the
   Governor's dashboard.
5. **Migration/seed ordering** with `restrictOnDelete` FKs: `projects` needs the four reference
   tables; `DemoProjectSeeder` needs `DemoTenantSeeder`. Wrong order fails loudly at seed time — do
   not "fix" it by making FKs nullable.

## 10. Remaining questions for me-domain-expert
1. **Baseline enforcement point** (§1.10) — activation-time guard vs DB `NOT NULL`. I argue a
   fabricated zero baseline is worse than an explicit null; confirm.
2. **`closed` entry rule** — I gate it on `post_completion_review_due_at` having passed, with a
   StateAdmin override. Is a hard window right, or should `closed` simply require a post-completion
   review *record* (Phase 2) instead of a date?
3. **`revised_end_date` ownership** — I let `ReviseProjectSchedule` set it directly *and* let contract
   variations carry their own completion dates. Should the project date be strictly derived from the
   latest variation, or is an approved extension without a contract variation a real case?
4. **`budget_allocation` vs multi-year appropriation** — one figure per project, or does the MTSS
   cycle need a per-year allocation child table in Phase 2?
5. **Indicator ownership when `project_id` is null** (MDA-programme indicators) — does Phase 1 need
   them, or may the UI restrict to project indicators while the column stays nullable?
