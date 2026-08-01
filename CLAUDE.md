# M&E Platform — State Government Monitoring & Evaluation System

White-label, multi-tenant platform for state governments (and their MDAs — Ministries,
Departments & Agencies) to monitor and evaluate public projects executed by
consultants/contractors. Built as a Laravel MVC monolith with subdomain-per-entity tenancy.

**Master plan:** `docs/PROJECT_PLAN.md` — read it before starting any feature work.
**Domain source documents:** `docs/*.pdf` and digests in `docs/digests/`.

## Stack (decided — do not substitute)

- **Backend:** Laravel 13.x, PHP 8.4+, MVC + thin services/actions layer
- **Frontend:** TALL — Tailwind CSS v4, Alpine.js, Livewire 3, Blade (no Inertia, no SPA)
- **Database:** MySQL 8 — single shared database, tenant-scoped rows (no DB-per-tenant)
- **Tenancy:** subdomain-per-MDA (e.g. `works.example-state.gov.ng`), resolved by middleware;
  central/oversight app on the apex + `oversight.` subdomain; public portal on `www`/apex
- **Queues/cache:** Redis + Laravel Horizon; scheduled jobs via `schedule:work`
- **Testing:** Pest; **Lint:** Laravel Pint; **Static analysis:** PHPStan/Larastan
- **Key packages:** spatie/laravel-permission (team = tenant), spatie/laravel-activitylog,
  spatie/laravel-medialibrary, maatwebsite/excel, barryvdh/laravel-dompdf, Leaflet (maps),
  ApexCharts (dashboards)

## Core rules

@.claude/rules/architecture.md
@.claude/rules/tenancy.md
@.claude/rules/coding-standards.md
@.claude/rules/ui-design-system.md
@.claude/rules/security.md
@.claude/rules/testing.md

## Commands

- `composer dev` — run local server + queue + vite together (once app is scaffolded)
- `vendor/bin/pint --dirty` — format changed PHP files (run before every commit)
- `vendor/bin/pest` — run test suite; `vendor/bin/pest --filter=Name` for one test
- `php artisan migrate:fresh --seed` — rebuild local DB with demo tenants/projects

## Agent team

Specialized subagents live in `.claude/agents/`. Use them deliberately:

- **laravel-architect** — module/schema design, migration review, architectural decisions
- **me-domain-expert** — validates features against the M&E manuals in `docs/`
- **tall-ui-builder** — builds Livewire/Blade/Tailwind UI per the design system
- **code-reviewer** — reviews diffs for tenancy leaks, security, and quality before merge
- **test-writer** — writes Pest feature/unit tests for new modules

Typical feature flow: me-domain-expert (validate requirements) → laravel-architect (design)
→ implement (main session or tall-ui-builder for UI) → test-writer → code-reviewer.
