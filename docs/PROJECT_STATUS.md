# Project Status — M&E Platform

> Living context summary. Last updated: 2026-09-21, after closing Phases 1 and 2.
> Companion docs: `PROJECT_PLAN.md` (master plan) · `TESTING_GUIDE.md` (domains,
> demo users, walkthroughs) · `design/*.md` (per-module designs, validations, audits).

## What this is

White-label multi-tenant M&E platform for state governments and their MDAs.
Laravel 13 + TALL, single shared MySQL DB, subdomain-per-MDA tenancy
(`works.mne.test` locally via Herd), three surfaces (tenant / oversight / portal).

## State at last update

- **Phases 0, 1 and 2 are complete**, and the Phase 3 public portal + publishing
  gate shipped early because "every link connected" could not be true while the
  portal's hero CTAs pointed nowhere.
- **~94 named application routes** across the three surfaces; 33 new migrations;
  514 PHP files in `app/`; 145 Blade views; 110 test files.
- `migrate:fresh --seed` green, Pint clean, PHPStan level 6 at 0 errors.
- The component library did **not** fork: eleven modules were built against the
  existing 28 `x-ui` components and added exactly one (`x-ui.chart`).

## Completed modules

1. **Phase 0 foundation** — fail-closed tenancy core, invitation-only auth with
   the `tenant_user` membership gate, role/team contract, mandatory 2FA for admin
   roles, OKLCH design system + three shells, `.claude/` agent team and rules.
2. **Projects** — registry, contracts + variations, global contractor registry,
   multi-site locations, co-funding, status state machine behind
   `TransitionProjectStatus`, Money VO, tenant + oversight screens.
3. **Progress Reporting + Deadline Engine** — statutory calendar, per-project
   obligations, report state machine with separation of duties, idempotent
   reminder/overdue/escalation engine, compliance league table, reports desk,
   review inbox, M&E calendar, cross-MDA reports desk.
4. **Document & evidence vault** — one path with three gates: per-collection
   mime/size/role rules in `config/documents.php`, server-side re-validation,
   generated filenames, EXIF GPS lifted into evidence metadata, and signed +
   authenticated + policy-checked downloads off a private disk.
5. **Results framework** — logframe tiers, a state-wide reusable indicator
   library, targets, readings with a `draft → submitted → validated → published`
   workflow, achievement maths and traffic lights in one place, and the Data
   Quality Reviewer's validation queue.
6. **Monitoring lifecycle** — site inspections (all five types) with checklist
   templates, GPS/photo evidence and the manual's Field Trip Report structure;
   commencement notices and completion certification, both with generated PDFs.
7. **Issues + exception reports** — the challenges register and a threshold
   engine that raises a deviation report which explains itself (the measurement,
   the tolerance, the date) and does not raise it twice.
8. **Workplans** — annual plans, activities linked to output indicators per the
   manual's rule, an accessible HTML/CSS Gantt, and a weighted roll-up.
9. **Evaluations** — the OECD-DAC lifecycle, criterion scores as rows (so a state
   can add its own criterion), the structured report, and the recommendations
   follow-up register that makes an evaluation a control rather than a document.
10. **Consolidation & reporting** — the secretariat workspace, the state APR with
    a snapshot frozen at approval, an ad-hoc report builder, and an export
    register with re-download.
11. **Platform administration** — MDA provisioning and branding, instance and
    per-workspace settings over the `SettingsRepository` chain, the append-only
    audit trail, and a notification centre with per-user preferences.
12. **Publishing gate + public portal** — a field-level whitelist
    (`PublicProjectPayload`), publishing queues on both app surfaces, and a
    read-only portal with a Leaflet map and moderated, rate-limited feedback.

## Working conventions (hard-won, keep them)

- **Verify every agent "done" signal** against the filesystem + full gate run
  (`migrate:fresh --seed`, `pest`, `pint --test`, `phpstan --no-parallel`).
- **Write the class, then register its route.** A route pointing at a class that
  does not exist yet throws at REGISTRATION, so it does not break one screen —
  it takes the whole application down, including every other module's tests.
- **Screen tests must include authorized 200 renders**, not only denial paths.
  `tests/Feature/Smoke/EveryScreenRendersTest.php` now walks the route table
  itself, so a new screen is covered the moment it is registered.
- **Named subdomains register before the `{tenant}` wildcard, in EVERY registrar**,
  and inside a surface file **literal segments are required before sibling
  wildcards** — `routes/tenant/` and `routes/oversight/` are auto-included first
  for exactly that reason. Assert dispatch, not registration.
- **`Livewire::test()` is not the surface.** It sets properties directly and never
  renders markup or crosses HTTP. At least one test per form must read the
  rendered HTML and feed the value it finds back through validation. Two related
  traps: `->call('export')` returns the TESTABLE, not the streamed file (assert on
  `->instance()->export()` bytes or you are asserting on a file you never
  looked at); and a component whose `mount()` aborts has no snapshot, so
  `->call(...)->assertForbidden()` fails on a Livewire internals error instead of
  the authorization it meant to prove — assert on the mount.
- **Never link with a hand-built `url('/path/'.$id)` string.** `route()` fails
  loudly on a missing route or the wrong binding key; a string 404s silently.
  This is only safe because `CurrentTenant::set()` binds the `{tenant}` URL
  default — before that, `route('tenant.*')` threw outside an HTTP request.
- **`Rule::exists()` runs on the query builder and never sees the TenantScope**,
  so it will confirm another MDA's id back to the form that posted it. The
  obvious patch (`->where('tenant_id', …)`) is banned platform-wide. Use
  `App\Rules\BelongsToCurrentTenant`, which queries through the model.
- **`Model::fresh()` is `newQueryWithoutScopes()`** — an unscoped cross-tenant
  read in innocuous clothing. Re-query through the model instead.
- **The discipline sweep matches on a left word boundary.** `TenantMembership::`
  as a plain substring also matched `CheckTenantMembership::class`, i.e. the
  sanctioned Iam action other modules are meant to ask through. The matcher has
  its own test, so loosening it later has to break that first.
- **A unit test gets the application but no database.** Unit tests were plain
  PHPUnit, which held only until an enum's `label()` needed the translator.
- **`numeric` is not enough for a money field** — pair it with `Money::FORM_RULE`.
- **`UploadedFile::fake()->create()` writes a ZERO-BYTE file**, and both
  medialibrary and our own `mimetypes:` rule sniff the bytes on disk. Use
  `->createWithContent()` or `->image()`.
- **`Gate::policy()` must register `MediaPolicy` explicitly** — `Media` lives in
  the package, so auto-discovery never finds it and `can('view', $media)` would
  answer false for everyone.
- Fresh-context builder agents outperform long-lived teammates; spawn per slice,
  give each strict file ownership, and keep the shared files (surface route
  files, configs, seeders, layouts, the discipline test) with the integrator.
- Blade comments execute directives: escape `@@extends` in docblocks.
- All statutory numbers live in `config/platform.php` → settings chain.

## Demo access

See `TESTING_GUIDE.md`. Domains: `mne.test` (portal + /styleguide),
`oversight.mne.test`, `works.mne.test`, `health.mne.test`. All seeded users'
password: `password`. Rebuild demo: `php artisan migrate:fresh --seed`.
