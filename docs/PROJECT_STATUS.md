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

- **14 commits on `main`** (no remote yet — CI workflow pending a remote).
- **858 tests green**; Pint clean; **PHPStan level 6 at 0 errors**; seeds green.
- Working tree carries the 2FA-exemption change, the oversight Livewire routing
  fix, and the usability sweep below — none committed yet.

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
| 5 | IAM screens (tenant /team, oversight /users) | ✅ completed (phpstan blocker closed) |
| 8 | Projects: remaining §5 screens + documents/media wiring | in progress — `/projects/{project}/edit` landed; `{project}/contracts/create` + media still pending |
| 9 | Reporting: remaining §6 screens (inbox, calendar, drill-down, cross-MDA desk) + evidence media | pending (pairs with #8) |
| 6 | Phase 2: Inspections, Indicators/logframe UI, Evaluations, Consolidation | pending |
| 7 | Phase 3: Public portal + publishing gate (`published_at` column exists, no writer yet) | pending |

## Task #5 — IAM screens, complete

Delivered: tenant `/team` (members, invitations, revoke/resend,
role-chain-limited invite form), oversight `/users` (directory with cross-team
role chips, activate/deactivate with cannot-flip-self, oversight invitations
with tenant picker), `SetUserActive` + `ListPendingInvitations` actions,
`RevokeTenantAccess` actor+reason audit logging, sidebars wired, 27+ IAM tests.
The last phpstan error is closed: `UserDirectory` keeps its concrete
`LengthAwarePaginator` return type and reads ids via `items()` instead of
`getCollection()->modelKeys()`.

Still owed on this slice: the independent audit pass (small).

## Usability sweep — dead navigation and broken form values

A pass over what the UI actually offers, prompted by a 404 on
`/projects/{ulid}/edit`. Everything here was reachable in the running app and
invisible to the suite. All fixed, all covered by new tests:

- Every record-picking `<select>` submitted the row's NAME instead of its id
  (`x-ui.form.select`). The project wizard could not be completed at all.
- `/projects/{project}/edit` was linked from the detail header and the index
  row menu but had no route. Built (`ProjectEdit`), including the
  certification freeze as read-only fields.
- The oversight portfolio drill-down passed `tenant_id` to a `{tenant:slug}`
  binding, so every entity row 404'd.
- Tenant dashboard "Register project" and "Export portfolio", oversight
  dashboard Excel/PDF/Filters/workspace/publishing buttons, the account-menu
  entries, the mobile brand lockups and the sidebar "Help & guidance" were all
  `<button>`s with no action or `href="#"`. Wired where a screen exists,
  removed where none does.
- `resources/views/welcome.blade.php` deleted: unreferenced, linked a
  non-existent `/dashboard`, and called `route('register')`, removed with
  self-registration — it would have 500'd if ever rendered.

**Known-wrong and deliberately left:** the four tenant dashboard KPI figures
are still hard-coded literals (24, ₦8.6bn, 6, 1), now labelled "sample
figure". They must not be shown to a client as live data. Replacing them is
the summary-table widget work in #8/#9. The public portal's hero CTAs also
still point nowhere, because the portal itself is #7.

Next: finish #8/#9 (contracts/create screen, media plumbing, remaining
reporting screens, real dashboard aggregates), then Phase 2 (#6) and the
portal (#7).

## Working conventions (hard-won, keep them)

- **Verify every agent "done" signal** against the filesystem + full gate run
  (`migrate:fresh --seed`, `pest`, `pint --test`, `phpstan`) before committing.
- **Screen tests must include authorized 200 renders**, not only denial paths —
  denial-only suites let broken screens pass (oversight-screens incident).
- **Named subdomains register before the `{tenant}` wildcard, in EVERY route
  registrar** — not just `bootstrap/app.php`. `{tenant}.<domain>` matches any
  single-label host, so a wildcard registered first swallows `oversight.` and
  `ResolveTenant` 404s it as a reserved slug. This shipped broken in the
  Livewire update routes (`TenancyServiceProvider`) and killed every oversight
  component interaction while 820 tests stayed green: `Livewire::test()` never
  touches HTTP, and the route test asserted the routes EXISTED rather than
  which one a host resolves to. Assert dispatch, not registration.
- **`Livewire::test()` is not the surface.** It sets properties directly and
  never renders markup or crosses HTTP, so a whole class of defect passes a
  green suite. Three shipped this way: the oversight Livewire 404 above; every
  record-picking `<select>` submitting the row's NAME instead of its id
  (`is_int($key)` cannot tell an id-keyed `pluck('name','id')` from a plain
  list — use `array_is_list()`), which made the project wizard unusable with
  "The selected sector is invalid"; and dead links built as hand-written
  `url('/…')` strings that `route()` would have failed on at render. At least
  one test per form must read the rendered HTML and feed the value it finds
  back through validation.
- **Never link with a hand-built `url('/path/'.$id)` string when a named route
  exists.** `route()` fails loudly on a missing route and on the wrong binding
  key; a string 404s silently in production. Two live examples: the project
  edit links pointed at a route that did not exist, and the oversight portfolio
  drill-down passed a `tenant_id` to a `{tenant:slug}` binding.
- **Route middleware does NOT protect Livewire component updates.** The update
  routes carry only `web` + the header guard, so `active`, `tenant.member` and
  `2fa.require` ran when the page was served and never again: a deactivated
  account could keep POSTing writes through `wire:click` until it next
  requested a full page. Policies do not close it — `is_active` is not a
  permission, and `SetUserActive` deliberately leaves roles and memberships
  intact. `EnsureAccountIsActive` + `RequireTwoFactor` are now explicit
  middleware on the tenant and oversight update routes. Livewire's own
  persistent-middleware list CANNOT do this: it fires only for a route whose
  name ends in `livewire.update`, and ours are named `*.livewire-update` on
  purpose. Any new per-request gate must be added there by hand.
- **`Model::fresh()` is `newQueryWithoutScopes()`** — an unscoped cross-tenant
  read in innocuous clothing. It shipped twice (project edit, report review
  refresh), safe both times only because an `authorize()` happened to run
  first. Re-query through the model instead; the discipline sweep now bans it.
- **A comment that names a guarded token trips the discipline sweep** unless
  its line starts with `//`, `*` or `/*`. Inside a `/* … */` block, prefix the
  continuation lines or reword.
- **`numeric` is not enough for a money field.** `is_numeric('5.')` and
  `is_numeric('1e5')` are true while `Money::fromDecimalString()` rejects both,
  so a trailing dot passed validation and 500'd in the cast. Pair `numeric`
  (which keeps `min`/`max` numeric rather than string-length) with
  `Money::FORM_RULE` at every typed-money field.
- **A form that reads a class cast must hand the Action a re-fetched model.**
  Reading a `Money` cast to populate a field makes Eloquent re-serialize it on
  the next `getDirty()`, so an untouched `budget_allocation` looks changed and
  the certification freeze refuses an edit nobody made. `ProjectEdit::save()`
  passes `$this->project->fresh()`. Do not narrow the guard in
  `UpdateProjectDetails` to "fields the caller passed" — that would let a
  pre-dirtied model slip a frozen change past it.
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
