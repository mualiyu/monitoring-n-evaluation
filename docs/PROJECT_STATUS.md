# Project Status — M&E Platform

> Living context summary. Last updated: 2026-08-02, after closing task #4.
> Companion docs: `PROJECT_PLAN.md` (master plan) · `TESTING_GUIDE.md` (domains,
> demo users, walkthroughs) · `design/*.md` (per-module designs, validations, audits).

## What this is

White-label multi-tenant M&E platform for state governments and their MDAs.
Laravel 13 + TALL, single shared MySQL DB, subdomain-per-MDA tenancy
(`works.mne.test` locally via Herd), three surfaces (tenant / oversight / portal).
Built module-by-module through a fixed pipeline: domain validation (vs the M&E
manuals in `docs/digests/`) → architect design → build → spec-driven tests →
independent audit → findings closed → commit.

## State at last update

- **13 commits on `main`** (no remote yet — CI workflow pending a remote).
- **781 tests green** at the last clean commit; Pint + PHPStan level 6 clean.
- Working tree carries the **in-progress IAM screens slice** (see "In flight").

## Completed modules

1. **Phase 0 foundation** — fail-closed tenancy core (`BelongsToTenant` throws on
   unbound context; `TenantSafeBuilder` guards mass ops; discipline test statically
   confines dangerous operations and sweeps schema↔model in both directions);
   invitation-only auth with `tenant_user` membership hard gate, role/team contract
   (`AssignRole` is the only role writer), mandatory 2FA for admin roles (grace
   windows, SuperAdmin none), per-host login throttling; OKLCH design system,
   28 `x-ui` components, three shells; `.claude/` agent team + rules.
2. **Projects module (tasks #1–3)** — registry, contracts + variations (immutable
   award sums, `contract_value_total` denormalization), global contractor registry
   (tenant-scoped relationships), multi-site locations, co-funding pivot,
   full-width indicators, status state machine behind `TransitionProjectStatus`
   (append-only event ledger), Money VO (integer kobo over decimal 18,2), tenant +
   oversight screens, audited (findings closed in `28295e0` + task-#3 commit).
3. **Progress Reporting + Deadline Engine (task #4)** — statutory reporting
   calendar (global periods; biannual end-of-following-month rule; instance-TZ
   deadline math), per-project obligations reconciling against already-filed
   reports, report state machine with separation-of-duties in the chokepoint
   (self-approval impossible), approval propagates figures only through
   `RecordProjectProgress`, idempotent reminder/overdue/escalation engine on the
   scheduler (yearly generation covers current+next year), compliance league
   table, reports desk + wizard + review screens + live dashboard widgets, export
   with isolation tests. Audit findings all closed.

## Task board

| # | Task | Status |
|---|---|---|
| 1–4 | Projects (3 slices) + Progress Reporting | ✅ completed |
| 5 | IAM screens (tenant /team, oversight /users) | ▶️ **in flight** (see below) |
| 8 | Projects: remaining §5 screens + documents/media wiring | pending (after #5) |
| 9 | Reporting: remaining §6 screens (inbox, calendar, drill-down, cross-MDA desk) + evidence media | pending (pairs with #8) |
| 6 | Phase 2: Inspections, Indicators/logframe UI, Evaluations, Consolidation | pending |
| 7 | Phase 3: Public portal + publishing gate (`published_at` column exists, no writer yet) | pending |

## In flight (uncommitted, 13 files)

IAM screens slice (task #5), built by an agent that hit its session limit
(resets 10:20am Africa/Lagos) **during final exit gates** — its last report:
all 27 Iam tests passing, full-suite/pint/phpstan/build runs not yet confirmed.
Present: `app/Livewire/Tenant/Iam/TeamIndex.php`,
`app/Livewire/Oversight/Iam/UserDirectory.php`, `tests/Feature/Iam/{TeamScreenTest,
UserDirectoryScreenTest}.php`, a `RevokeTenantAccess` extension (actor+reason
audit logging), plus routes/sidebar/permission-seeder edits.
**Next step:** run the four gates; if green, commit and mark #5 complete; if not,
resume/respawn the builder with the failure list.

## Working conventions (hard-won, keep them)

- **Verify every agent "done" signal** against the filesystem + full gate run
  (`migrate:fresh --seed`, `pest`, `pint --test`, `phpstan`) before committing.
- **Screen tests must include authorized 200 renders**, not only denial paths —
  denial-only suites let broken screens pass (oversight-screens incident).
- Fresh-context builder agents outperform long-lived teammates; spawn per slice.
- Blade comments execute directives: escape `@@extends` in docblocks.
- Laravel middleware priority list holds the `AuthenticatesRequests` CONTRACT.
- spatie teams: global roles = null team on definitions, sentinel team 0 for
  oversight assignments; role relations cache per team — unset on context switch.
- Chokepoint columns are guarded-by-omission (not fillable); ledger writes under
  oversight bypass set `tenant_id` explicitly via property write.
- All statutory numbers (deadlines, thresholds, windows) live in
  `config/platform.php` → settings chain, never as literals.

## Demo access

See `TESTING_GUIDE.md`. Domains: `mne.test` (portal + /styleguide),
`oversight.mne.test`, `works.mne.test`, `health.mne.test`. All seeded users'
password: `password`. Rebuild demo: `php artisan migrate:fresh --seed`.
