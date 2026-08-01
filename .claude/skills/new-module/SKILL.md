---
name: new-module
description: >
  Scaffold a complete domain module (e.g. Projects, Inspections, Indicators) end-to-end
  following project conventions — design review, migrations, models, actions, policies,
  Livewire components, routes, factories, seeders, and tests. Use whenever starting a new
  feature area from the roadmap.
---

# New Domain Module Workflow

Build modules in this exact order. Do not skip the design or test steps.

## 1. Spec & design (before any code)
1. Read the module's section in `docs/PROJECT_PLAN.md` and the relevant parts of
   `docs/digests/ondo-manual-digest.md`.
2. Launch **me-domain-expert** to validate the feature spec against the manuals; fold its
   findings into the spec.
3. Launch **laravel-architect** for the schema/state-machine/actions design. Its output is
   the build checklist for the rest of this workflow.

## 2. Data layer
4. Migrations: tenant-owned tables get `tenant_id` FK (indexed) + `ulid` public id +
   soft deletes + timestamps. Restrict deletes on domain FKs unless the design says cascade.
5. Models: `BelongsToTenant` trait for tenant-owned models, `$fillable`, casts (enums,
   `immutable_datetime`, money), relationships, `LogsActivity`.
6. Enums in `app/Enums/` with transition guards where the design specifies a state machine.
7. Factory with named states + seeder additions so `migrate:fresh --seed` shows the module
   populated across two demo tenants.

## 3. Domain layer
8. One Action class per verb from the architect's design (`app/Actions/{Domain}/`).
   Authorization inside the Action or its Form Request — never trust the caller.
9. Policy per model; register it; wire permissions into the role seeder.
10. Notifications (queued) for every workflow hop the design defines (submission,
    approval, rejection, deadline reminder).

## 4. UI layer
11. Launch **tall-ui-builder** (or follow `.claude/rules/ui-design-system.md` directly)
    for the screens: list (filter bar → stats → table → pagination), detail, form
    (wizard + autosave for long forms). Routes into the correct surface file
    (`routes/tenant.php`, `routes/oversight.php`, or `routes/portal.php`).

## 5. Verification
12. Launch **test-writer** for the module test suite — tenancy isolation, authorization
    matrix, state transitions, Livewire interactions. All tests must pass.
13. Run `vendor/bin/pint --dirty` and `vendor/bin/phpstan`.
14. Launch **code-reviewer** on the full diff. Fix findings, re-run tests.

## Done means
Migrations reversible, seeder demo data visible on both demo tenant subdomains, tests
green, pint/phpstan clean, code-reviewer verdict clean, `docs/PROJECT_PLAN.md` roadmap
checkbox updated.
