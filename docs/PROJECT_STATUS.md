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

## Security audit — findings closed

An independent audit of the Phase 1 + 2 build, with executable proofs of
concept. Every finding below was reproduced against a green tree and is now
fixed and regression-tested (`tests/Feature/Auth/CrossTenantReplayTest.php`,
`tests/Feature/Documents/VaultSecurityTest.php`).

1. **CRITICAL — cross-tenant read via Livewire replay.** Fortify is registered
   domain-less, so a user can authenticate on a foreign MDA's host even though
   the workspace answers 403. A snapshot legitimately obtained from their own
   workspace, POSTed to the neighbour's update endpoint, validated (the
   checksum covers the snapshot, not the host), `ResolveTenant` bound the
   neighbour, Livewire did not re-run `mount()` — and `Project::scopeVisibleTo`
   added NO constraint for a user holding no role in the bound team. 200, with
   another ministry's register. Closed twice over: `EnsureTenantMembership` is
   now on the tenant update route, and the visibility scope fails closed.
2. **HIGH — document IDOR.** `DocumentPanel::downloadUrl(Media $media)` was a
   public Livewire method with an Eloquent parameter; Livewire binds those from
   CLIENT-SUPPLIED call params, and `media` has no tenant scope and an
   auto-increment key. It now takes a uuid and resolves from the record's own
   collection, and `MediaPolicy` asks the OWNER's own visibility rule — permission
   plus tenant match says nothing about a consultant's assignments.
3. **HIGH — confidential findings to the wrong MDA.** A recommendation
   addressee was validated as `integer`, and the notification quotes the
   finding verbatim. Now guarded in `RaiseRecommendation` with
   `IsWorkspaceMember` in front of it.
4. **MEDIUM — a false tenancy claim in three docblocks.** Livewire restores a
   model property with `newQueryForRestoration()` = `newQueryWithoutScopes()`.
   The scope is OFF for every model property on every update. Corrected, and
   pinned by a test in the discipline sweep.
5. **MEDIUM — enumerable IDOR on the audit log**, same Livewire binding
   mechanism on an unscoped, integer-keyed model. Both accessors now take an id
   and resolve from the screen's own page.
6. **MEDIUM — oversight document downloads were permanently 403**, because the
   owner load hit the fail-closed scope and the policy swallowed it. The
   oversight path now goes through `app/Actions/Oversight/ResolveMediaOwner`,
   which asks for the global permission BEFORE it bypasses tenancy.
7. **MEDIUM — the apex Livewire endpoint was unauthenticated.** It cannot carry
   `auth` (the public portal has a component), so the fix is structural:
   `MatchLivewireComponentToSurface` refuses a component whose namespace does
   not match the surface the request arrived on.
8. **MEDIUM — no rate limit on public portal reads.** Added, keyed on a hashed IP.
9. **LOW — unbound raw SQL** in two orderings (values were enum cases, so not
   injectable) — now bound, matching `ExceptionReport::scopeWorstFirst`.

**Knowingly left:** `Contract::$fillable` includes `status`. Both write sites
place the trusted value after the payload spread and document why, and the
contract lifecycle has no chokepoint Action to move it to; changing it without
one would be churn, not safety.

## Working conventions (hard-won, keep them)

- **Run `composer gate`, never the bare tools.** `vendor/bin/phpstan analyse
  --no-parallel` reports NOTHING and exits non-zero: `--no-parallel` is not a
  PHPStan option, and `laravel/pao` silences stdout, so the usage error is
  swallowed and only a bare exit code survives. Three agents and the integrator
  all reported "level 6, 0 errors" from runs that analysed nothing; the real
  count was 86. See `PHPSTAN.md`. **When a checker comes back clean and you are
  surprised, prove it with a canary** — a method declared `: int` returning a
  string — and believe it only once you have seen the tool fail.
- **Verify every agent "done" signal** against the filesystem and a full gate run.
- **A first-class callable of an Eloquent SCOPE is a silent bug.**
  `Model::someScope(...)` handed to `ofMany()` starts a FRESH query and
  discards the builder it was given, so the constraint quietly does nothing.
  It looks tidier than a closure and passes static analysis. When a scope needs
  to be visible to the type checker, give the model a real Builder class
  (`app/Models/Builders/`) — that is what `IndicatorReadingBuilder` is for.
- **Concurrent `pest` processes share `Storage::fake()`.** Every process roots
  the fake disk at the same `storage/framework/testing/disks/documents` and
  cleans it on entry, so parallel runs delete each other's artifacts mid-test.
  Run the suite once, or expect phantom failures in the media tests.
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
- **The two mime checks DISAGREE on a truncated upload, and the gap was a 500.**
  Our `mimetypes:` rule reads the type Laravel reports for the upload;
  medialibrary re-reads the bytes. A connection that drops mid-upload declares
  `application/pdf` and sniffs as `application/x-empty` — it clears ours and is
  refused by theirs, which is `FileUnacceptableForCollection`, not a validation
  error. `DocumentPanel::save()` catches it and turns it into a field message.
  Nothing was ever stored; what was missing was telling the officer why, and an
  upload that vanishes into a crashed screen gets filed by email instead.
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
