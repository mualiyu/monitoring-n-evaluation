# Phase 1 — Progress Reporting + Deadline Engine v1

PROJECT_PLAN §4 "Monitoring lifecycle" (progress_reports + reporting_periods only) + §5 modules 6 & 9;
grounded in `docs/digests/ondo-manual-digest.md` §3–4. Builds on projects-module rev. 2.
Inspections, exception reports, certificates and consolidation are **Phase 2** and are not designed here.

## 0. One dependency on the Projects migration — needs to land in the same batch

`projects` needs **one added column**: `reporting_frequency` (nullable string 20, null = instance
default). Obligation generation is driven per project, and a nullable column added now costs nothing
whereas an `ALTER` on a hot table later costs a deploy window. If the data-builder has already
migrated, this becomes `2026_08_04_100010_add_reporting_frequency_to_projects_table` (§8).
Distinct from `indicators.measurement_frequency` — that governs readings, not reports.

### 0.1 Configurable calendar rules (`config/platform.php` → `reporting`, overridable per instance)
`default_frequency` (monthly) · `monthly_due_days` (7) · `quarterly_due_days` (14) ·
`biannual_due_rule` (end_of_following_month — digest §4 statutory) · `annual_due_rule` (end_of_q1) ·
`reminder_days_before` ([7, 3, 1]) · `overdue_escalation_days` ([1, 7]) ·
`allow_late_submission` (true) · `require_separate_approver` (true) ·
`obligation_statuses` (`mobilized`, `in_progress`, `completed`) — which project statuses owe reports.
Read via `SettingsRepository`; no literals in code.

## 1. Entities & migrations

### 1.1 `reporting_periods` — **global, deliberately no `tenant_id`**
The statutory calendar is state-wide: every MDA reports against the same windows, and the league table
is only meaningful if the denominator is identical across MDAs. A per-tenant calendar would make
"which MDA was late" unanswerable.
```php
$table->id(); $table->ulid('ulid')->unique();
$table->string('code', 20)->unique();              // 2026-M03, 2026-Q1, 2026-H1, 2026-A
$table->string('cadence', 12);                     // ReportingCadence: monthly|quarterly|biannual|annual
$table->string('label');                           // "March 2026", "First Half 2026"
$table->date('period_start'); $table->date('period_end');
$table->timestamp('opens_at');                     // submissions accepted from
$table->timestamp('due_at');                       // statutory deadline
$table->timestamp('closes_at')->nullable();        // hard close; null = stays open, late flagged
$table->string('generated_by', 12)->default('system');
$table->timestamps();
$table->unique(['cadence', 'period_start']);
$table->index('due_at'); $table->index(['cadence', 'period_start']);
```
Status (`upcoming|open|closed`) is **derived** from `opens_at`/`closes_at` vs now — storing it would
require a cron to keep it true, and a stale status column on a deadline is a compliance defect.
Due-date rules from digest §4: monthly/quarterly = `period_end + N days`; biannual = **end of the
month following the six-month period** (H1 → 31 Jul, H2 → 31 Jan); annual = **within Q1** → 31 Mar.

### 1.2 `report_obligations` — tenant-owned; the roll-up the league table reads
```php
$table->id();
$table->foreignId('tenant_id')->constrained()->restrictOnDelete();
$table->foreignId('reporting_period_id')->constrained()->restrictOnDelete();
$table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete(); // null = MDA-level (Phase 2)
$table->timestamp('due_at');                       // copied at generation — see below
$table->string('status', 12)->default('pending');  // pending|fulfilled|waived|missed
$table->foreignId('progress_report_id')->nullable()->constrained()->nullOnDelete();
$table->timestamp('fulfilled_at')->nullable();
$table->boolean('submitted_late')->default(false);
$table->unsignedTinyInteger('reminder_stage')->default(0);   // monotonic; index into reminder_days_before
$table->timestamp('reminder_last_sent_at')->nullable();
$table->timestamp('overdue_notified_at')->nullable();
$table->unsignedTinyInteger('escalation_stage')->default(0);
$table->timestamp('escalated_at')->nullable();
$table->foreignId('waived_by_id')->nullable()->constrained('users')->nullOnDelete();
$table->timestamp('waived_at')->nullable(); $table->text('waiver_reason')->nullable();
$table->timestamps();
$table->index(['tenant_id', 'reporting_period_id', 'status']);   // league table
$table->index(['status', 'due_at']);                             // deadline sweep
$table->index(['tenant_id', 'project_id', 'reporting_period_id']);
```
**Why materialize obligations rather than derive "who hasn't reported".** It answers the standing
question — *how does the state-wide board aggregate across 40 MDAs without scanning raw rows?* — with
one indexed `GROUP BY`, and it gives every reminder a durable per-row stage counter, which is what
makes the deadline engine idempotent (§3). Volume is bounded: 40 MDAs × ~2 000 projects × 12 months ≈
1 M rows/year, trivially indexed. No DB unique on `(period, project)` because `project_id` is nullable
and SQL treats NULLs as distinct (the same trap flagged in tenancy §1.2 and projects §1.5) — the
generator upserts on the triple inside a transaction, with a test.
`due_at` is copied from the period at generation; `reporting:generate-obligations` refreshes it for
**unfulfilled** rows only, so moving a future deadline works while history stays immutable.

### 1.3 `progress_reports` — tenant-owned
```php
$table->id(); $table->ulid('ulid')->unique();
$table->foreignId('tenant_id')->constrained()->restrictOnDelete();
$table->foreignId('project_id')->constrained()->restrictOnDelete();
$table->foreignId('reporting_period_id')->constrained()->restrictOnDelete();
$table->foreignId('report_obligation_id')->nullable()->constrained()->nullOnDelete();
$table->string('status', 12)->default('draft');    // draft|submitted|reviewed|approved|returned
$table->text('narrative_work_done');               // work done vs timeline (Nasarawa §3)
$table->text('narrative_challenges')->nullable();  // challenges…
$table->text('narrative_mitigation')->nullable();  // …and mitigation (manual pairs them)
$table->text('narrative_next_period')->nullable();
$table->decimal('physical_progress_claimed', 5, 2);          // cumulative claim
$table->decimal('physical_progress_before', 5, 2)->nullable(); // project value at approval (audit)
$table->text('progress_decrease_reason')->nullable();        // required if claim < current
$table->decimal('period_expenditure', 18, 2)->default(0);    // the fact: spend in THIS period
$table->decimal('cumulative_expenditure_snapshot', 18, 2)->nullable(); // project total after approval
$table->string('entry_mode', 12)->default('self_service');   // self_service|on_behalf
$table->foreignId('contractor_id')->nullable()->constrained()->nullOnDelete(); // firm reported for
$table->timestamp('due_at');                       // snapshot from the obligation — on-time is judged against this
$table->boolean('submitted_late')->default(false);
$table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
$table->foreignId('submitted_by_id')->nullable()->constrained('users')->nullOnDelete();
$table->timestamp('submitted_at')->nullable();
$table->foreignId('reviewed_by_id')->nullable()->constrained('users')->nullOnDelete();
$table->timestamp('reviewed_at')->nullable();
$table->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
$table->timestamp('approved_at')->nullable();
$table->foreignId('returned_by_id')->nullable()->constrained('users')->nullOnDelete();
$table->timestamp('returned_at')->nullable(); $table->text('return_reason')->nullable();
$table->timestamp('autosaved_at')->nullable();
$table->timestamps(); $table->softDeletes();
$table->index(['tenant_id', 'status']);
$table->index(['tenant_id', 'reporting_period_id', 'status']);
$table->index(['tenant_id', 'project_id', 'reporting_period_id']);
$table->index(['tenant_id', 'due_at']);
```
- **Autosave needs no extra structure**: the draft row *is* the autosave target; `SaveProgressReportDraft`
  updates it and stamps `autosaved_at`. No JSON payload column, no second storage shape.
- **One live report per (project, period)** enforced in `StartProgressReport` with `lockForUpdate`,
  not by a DB unique — soft deletes make `unique(project_id, period_id)` either block re-creation
  after a discard or (with `deleted_at` in the key) enforce nothing.
- **Money split, deliberately:** `period_expenditure` is the reported fact; the project total is the
  roll-up (§2.3); `cumulative_expenditure_snapshot` is an audit snapshot written at approval. Storing
  a claimed cumulative *and* summing periods would give two answers to one question.
- `due_at` is snapshotted so a later calendar edit cannot retroactively make a filed report late.

### 1.4 `progress_report_events` — tenant-owned chain ledger
`id`, `tenant_id`, `progress_report_id` (cascade), `from_status` (nullable), `to_status`, `actor_id`,
`reason` (nullable; required on `returned`), `occurred_at`, timestamps.
`index(tenant_id, progress_report_id, occurred_at)`.
The `*_by_id`/`*_at` columns on the report carry the *current* chain state for lists and the league
table; this table carries the *history*, which the columns cannot — a report returned twice has two
return events. Same pattern as `project_status_events`; append-only, no update/delete Action.

### 1.5 `tenant_id` summary
**Carries it:** `report_obligations`, `progress_reports`, `progress_report_events`.
**Deliberately not:** `reporting_periods` (state-wide statutory calendar — §1.1).

## 2. State machine & guards

`app/Enums/ProgressReportStatus.php`: `draft | submitted | reviewed | approved | returned`.
`consolidated` is **not** in the Phase 1 enum — it arrives with the Phase 2 consolidation workspace,
and the column is a string, so adding it is a code change, not a migration.

| From | To | Actor | Permission | Guard |
|---|---|---|---|---|
| `draft` / `returned` | `submitted` | author (Consultant assigned, or MeOfficer/MdaAdmin on behalf) | `reports.submit` | period open or `allow_late_submission`; required narratives present; decrease reason if claim < project's current % |
| `submitted` | `reviewed` | MeOfficer (focal) | `reports.review` | **reviewer ≠ submitter** |
| `submitted` | `returned` | MeOfficer | `reports.review` | reason required |
| `reviewed` | `approved` | MdaAdmin (Director M&E) | `reports.approve` | **approver ≠ submitter**; approver ≠ reviewer when `require_separate_approver` |
| `reviewed` | `returned` | MdaAdmin | `reports.approve` | reason required |
| `approved` | — terminal in Phase 1 | — | — | — |

### 2.1 Two entry paths (plan §9 Q4 — both designed, neither assumed)
- **Self-service:** an assigned Consultant creates and submits; `entry_mode = self_service`.
- **On behalf:** an MeOfficer/MdaAdmin enters the contractor's return; `entry_mode = on_behalf` and
  `contractor_id` records *whose* figures these are. Provenance is recorded rather than blurred —
  an auditor must be able to see that an officer typed a contractor's numbers.
Switching a client between models is a permission-matrix change (grant/revoke `reports.create` +
`reports.submit` to Consultant), not a schema change.

### 2.2 A consultant never approves their own report
Consultants hold neither `reports.review` nor `reports.approve` — that is the structural guard. The
actor-identity guards exist for the *on-behalf* path, where an MeOfficer submits and could otherwise
review their own submission. Enforced in `TransitionProgressReportStatus`, not in the UI.

### 2.3 Approval propagates through the Projects chokepoint
`ApproveProgressReport` runs one transaction: transition → append event → call
`App\Actions\Projects\RecordProjectProgress` with `physical_progress_claimed` and
`+= period_expenditure` → snapshot `physical_progress_before` and
`cumulative_expenditure_snapshot` onto the report. Nothing else writes project progress, so the
mid-term flag (projects §2.2) and the `certified` field-freeze (§2.3 there) keep working unchanged.
**Submission and review do not move project figures** — only approval does. `RecordProjectProgress`
requires `projects.progress.update`, which MdaAdmin holds, so the chain grants no extra authority.

## 3. Deadline engine v1

Five scheduled commands, all `->withoutOverlapping()`, all iterating tenants via
`CurrentTenant::runAs()` so the existing queue payload carries tenancy into every notification.

| Command | Cadence | Behaviour |
|---|---|---|
| `reporting:generate-periods {--year=}` | yearly (1 Dec) + install | `updateOrCreate` on `code` for all four cadences from the §0.1 rules — idempotent |
| `reporting:generate-obligations` | daily 00:30 | for each open period × active tenant × project in `obligation_statuses` whose `reporting_frequency` matches the cadence → upsert obligation; refresh `due_at` on unfulfilled rows only |
| `reporting:send-reminders` | daily 07:00 | pending obligations whose days-to-due matches a `reminder_days_before` stage **greater than** `reminder_stage` → notify, then bump `reminder_stage` + `reminder_last_sent_at` in the same locked transaction |
| `reporting:flag-overdue` | daily 07:15 | pending + past due → notify once (`overdue_notified_at` null check), then escalate at `overdue_escalation_days` stages: MdaAdmin, then StateAdmin, guarded by `escalation_stage` |
| `reporting:close-periods` | daily 01:00 | periods past `closes_at` → remaining `pending` obligations become `missed` |

**Idempotency is structural, not defensive.** Every send is gated by a monotonic counter on the
obligation row, incremented inside `DB::transaction(fn () => …lockForUpdate())` in the same statement
batch as the dispatch. A double cron run, an overlapping worker or a replayed job cannot double-send,
because the second attempt reads the already-advanced stage. No "have I sent this?" lookup table.

Notifications (queued, database + mail): `ProgressReportDueSoon`, `ProgressReportOverdue`,
`ProgressReportEscalated` (→ MdaAdmin, then StateAdmin), `ProgressReportSubmitted` (→ reviewers),
`ProgressReportReturned` (→ author), `ProgressReportApproved` (→ author + project manager).
Recipients resolve from `project_assignments` + tenant roles; each is `TenantAware` so branding and
`term()` labels render for the right MDA.

### 3.1 Compliance league table (oversight)
```sql
SELECT tenant_id,
       COUNT(*)                                                        AS expected,
       COUNT(CASE WHEN status = 'fulfilled' THEN 1 END)                AS submitted,
       COUNT(CASE WHEN status = 'fulfilled' AND submitted_late = 0 THEN 1 END) AS on_time,
       COUNT(CASE WHEN status = 'missed' THEN 1 END)                   AS missed
FROM report_obligations
WHERE reporting_period_id = ?
GROUP BY tenant_id
```
One indexed query for all MDAs (`CASE` rather than `SUM(bool)` for SQLite/MySQL parity), served by
`(tenant_id, reporting_period_id, status)`, cached 5 minutes and busted on submission. No progress
report row is scanned to compute compliance — that is the whole point of §1.2.
**Fulfilment is measured at submission, not approval** (the manual's rewards/sanctions are about
*submitting on time*); approval quality is a separate metric. Flagged for the domain expert (§9.1).

## 4. Actions & permissions

New permissions extending the projects §3 convention: `reports.view|create|submit|review|approve|waive`,
`oversight.compliance.view`.

`app/Actions/Monitoring/`:
| Action | Permission | Notes |
|---|---|---|
| `StartProgressReport` | `reports.create` | returns the existing live draft or creates one (locked); stamps `due_at`, `entry_mode`, `contractor_id` |
| `SaveProgressReportDraft` | `reports.create` | author-only; `draft`/`returned` only; stamps `autosaved_at` |
| `SubmitProgressReport` | `reports.submit` | sets `submitted_late = due_at < now`; fulfils the obligation |
| `ReviewProgressReport` | `reports.review` | reviewer ≠ submitter |
| `ApproveProgressReport` | `reports.approve` | separation guards + propagation (§2.3) |
| `ReturnProgressReport` | `reports.review` or `.approve` | reason required |
| `DiscardProgressReportDraft` | `reports.create` | drafts only; obligation returns to `pending` |
| `TransitionProgressReportStatus` | per-target | the only writer of `status` |
| `WaiveReportObligation` | `reports.waive` | MdaAdmin/StateAdmin, reason required |
| `AttachReportEvidence` / `RemoveReportEvidence` | `documents.upload` / `.delete` | editable states only (§5) |
`app/Actions/Oversight/`: `BuildComplianceLeagueTable`, `ListReportsAcrossTenants`
(`oversight.compliance.view`) — the only `withoutTenancy()` calls in this module.

| Permission | SuperAdmin | StateAdmin | ExecViewer | DQReviewer | MdaAdmin | MeOfficer | Consultant | FieldMonitor |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `reports.view` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | assigned | assigned |
| `reports.create` / `.submit` | ✓ | — | — | — | ✓ | ✓ | ✓ | — |
| `reports.review` | ✓ | — | — | — | ✓ | ✓ | — | — |
| `reports.approve` | ✓ | — | — | — | ✓ | — | — | — |
| `reports.waive` | ✓ | ✓ | — | — | ✓ | — | — | — |
| `oversight.compliance.view` | ✓ | ✓ | ✓ | ✓ | — | — | — | — |

`ProgressReportPolicy` / `ReportObligationPolicy` use the existing "permission AND `tenant_id` match"
helper; `view` additionally applies `Project::scopeVisibleTo()` so a consultant sees only reports on
projects they are assigned to.

## 5. Media
Collections on `ProgressReport`: `evidence_photos` (`jpg,jpeg,png,webp` ≤ 10 MB, conversions
`thumb` 320 / `preview` 1024, EXIF+GPS copied to `custom_properties` before re-encoding — Phase 2
inspections consume it) and `supporting_documents` (`pdf,doc,docx,xls,xlsx` ≤ 20 MB: valuations,
measurement sheets). Uploads allowed to the author in `draft`/`returned` only — **attachments freeze
at submission**, because evidence that can change after review is not evidence. Reviewers add to a
separate `review_attachments` collection. Private disk, signed route, `MediaPolicy` delegating to the
report's policy (same mechanism as projects §4).

## 6. Surfaces
**Tenant:** `/reports` (filter bar: period · status · project → stat row → table, Excel export) ·
`/reports/inbox` (review/approval queue, the officer's and director's landing card) ·
`/projects/{project}/reports/create?period=` · `/reports/{report}` (detail + chain timeline from
`progress_report_events`) · `/reports/{report}/edit` (autosaving wizard: work done → progress &
expenditure → challenges & mitigation → evidence) · `/calendar` (periods, obligations, due/overdue).
Consultants see the same `/reports` index filtered by `visibleTo`; their dashboard card is
"reports due" driven by `report_obligations`.
**Oversight:** `/compliance` (league table by period — the manual's rewards/sanctions board) ·
`/compliance/{tenant}` · `/reports` (cross-MDA, read-only).
**Portal:** nothing.

## 7. Test matrix
Deadline cases all use `Carbon::setTestNow()`; no sleeping, no real clocks.
- **Isolation**: `progress_reports`, `report_obligations`, `progress_report_events` — two tenants,
  list + detail (404 on foreign ULID) + export + model count.
- **Calendar** `ReportingPeriodGenerationTest`: monthly/quarterly/biannual/annual due dates match
  §0.1 (H1 → 31 Jul, annual → 31 Mar); running the command twice creates no duplicates; changing a
  future due date refreshes unfulfilled obligations but not fulfilled ones.
- **Obligations** `ObligationGenerationTest`: only `obligation_statuses` projects generate; a project
  with a non-matching `reporting_frequency` is skipped; re-running is idempotent.
- **Reminders** `DeadlineReminderTest`: notifications fire at 7/3/1 days before, **exactly once each**
  when the command runs twice on the same day; overdue notified once; escalation reaches MdaAdmin at
  stage 1 and StateAdmin at stage 2; a waived obligation is silent.
- **State machine** `ProgressReportTransitionTest`: every allowed transition; forbidden —
  `submitted → approved` (skipping review), consultant attempting review/approve, submitter reviewing
  own report, reviewer approving own review when `require_separate_approver`, editing an `approved`
  report, transitions out of `approved`. Each success writes a `progress_report_events` row.
- **Propagation** `ReportApprovalPropagationTest`: approval updates `projects.physical_progress` and
  `expenditure_to_date`; submit/review do **not**; crossing 50 % sets `mid_term_flagged_at` once;
  a decrease without `progress_decrease_reason` is rejected.
- **Lateness** `LateSubmissionTest`: submitting after `due_at` flags `submitted_late`; a later
  calendar edit does not change a filed report's lateness; period close marks pending → `missed`.
- **Compliance** `ComplianceLeagueTableTest`: correct expected/submitted/on-time/missed per tenant;
  ≤ 2 queries; a tenant-surface user is denied.
- **Authorization matrix** per role, per surface; **Media**: attachment blocked after submission,
  foreign-tenant media 404, wrong mime rejected.

Factories: `ReportingPeriodFactory(->monthly(), ->biannual(), ->closed())`,
`ReportObligationFactory(->pending(), ->fulfilled(), ->missed(), ->dueIn(days))`,
`ProgressReportFactory(->draft(), ->submitted(), ->reviewed(), ->approved(), ->returned(), ->late())`.

## 8. Migration order & seeders
```
2026_08_04_100000_create_reporting_periods_table              (global)
2026_08_04_100010_add_reporting_frequency_to_projects_table   (§0 — fold into the projects migration if unshipped)
2026_08_04_100020_create_report_obligations_table             (FK → tenants, reporting_periods, projects, users)
2026_08_04_100030_create_progress_reports_table               (FK → …, report_obligations, contractors, users)
2026_08_04_100040_create_progress_report_events_table
```
`report_obligations` precedes `progress_reports` (FK), and `progress_reports.id` is back-referenced by
`report_obligations.progress_report_id` — add that FK in the `progress_reports` migration to avoid a
circular dependency. Nothing MySQL-specific.

Seeders (after `DemoProjectSeeder`): `ReportingPeriodSeeder` — current year, all four cadences.
`DemoProgressReportSeeder` — for each `in_progress` demo project, three monthly obligations with a
deliberate spread so every screen has content on first run: one `approved` (on time), one `reviewed`
awaiting approval, one `returned` with a reason, one `draft`, one `missed`, one late-submitted. That
also gives the league table two visibly different MDAs.

## 9. Flags for me-domain-expert
1. **Fulfilment measured at submission, not approval** (§3.1). Rewards/sanctions in the manual read as
   "did the MDA submit on time", but if the secretariat counts only *approved* reports the denominator
   changes and MDAs can be punished for their director's inaction. Confirm.
2. **Level of the Phase 1 league table.** I measure *project-level* monthly submissions. The manual's
   statutory instruments are the *MDA-level* biannual and annual reports, which arrive with Phase 2
   consolidation. Confirm MVP compliance should be project-level, or whether biannual MDA obligations
   must appear (unfulfillable) in Phase 1.
3. **Reviewer/approver separation** is configurable (`require_separate_approver`, default on). Is a
   single-M&E-officer MDA a real case that needs it off, or should separation be absolute?
4. **Downward physical progress.** I allow a decrease with a mandatory reason (re-measurement,
   defective work removed). Should the system forbid it outright and require an exception report?
5. **Late submission.** I always accept late with a flag rather than hard-closing periods
   (`allow_late_submission` default true) — a blocked MDA simply never reports, which is worse for the
   data. Confirm the statutory reading permits it.
6. **Return does not reset the clock:** on-time is judged at *first* submission, so a report returned
   and resubmitted after the deadline stays on-time. Confirm this matches secretariat practice.
7. **Which project statuses owe reports** (`obligation_statuses`) — I include `completed` until
   certification, on the assumption that retention-period work still reports. Confirm.
