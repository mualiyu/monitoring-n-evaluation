# M&E Platform — Master Project Plan

White-label Monitoring & Evaluation platform for state governments and their MDAs
(Ministries, Departments & Agencies) to plan, monitor, and evaluate public projects
executed by consultants/contractors — with oversight dashboards for the executive, a
compliance-driven monitoring lifecycle, a results framework (indicators/baselines/targets),
and a public transparency portal.

> Domain grounding: `docs/digests/ondo-manual-digest.md` (institutional framework, report
> types, deadlines, results framework) — read it before designing any module.

---

## 1. Locked decisions

| Decision | Choice |
|---|---|
| Product model | **White-label multi-client product** — one codebase, per-client deployment (one install per state), all branding/terminology/domains configurable |
| Backend | **Laravel 13.x monolith, MVC** + thin Actions layer, PHP 8.4+ |
| Frontend | **TALL** — Tailwind v4, Alpine.js, Livewire 3, Blade (no SPA) |
| Tenancy | **Single shared MySQL DB**, subdomain-per-MDA, tenant-scoped rows |
| Offline/mobile | Responsive web first; **offline PWA in Phase 3** (architecture accommodates it) |
| Testing | Pest + Pint + Larastan; CI gates on all three |

## 2. Personas & roles

Mirrors the institutional reporting chain from the Ondo manual (focal officer → MDA
Director of M&E → state M&E Secretariat → Commissioner) and the three user groups from
the concept document (Government/MDA, Consultant, Stakeholder).

| Role | Surface | Summary |
|---|---|---|
| **Super Admin** (platform operator = you) | oversight | Instance config, tenants, branding, billing |
| **State M&E Admin** (MEP&B Secretariat / Director M&E) | oversight | Cross-MDA dashboards, consolidation, deadlines calendar, sanctions/compliance board, user & indicator-framework management |
| **Executive Viewer** (Governor's office, House committee, Commissioner) | oversight | Read-only state-wide dashboards, published reports |
| **MDA Admin** (Permanent Sec / Director of M&E) | tenant | MDA workspace owner; approves reports upward; manages MDA users |
| **M&E / Focal Officer** | tenant | Creates projects, indicators, workplans; reviews consultant submissions; files field reports |
| **Consultant / Contractor** | tenant | Sees only assigned projects; submits progress reports, challenges, evidence |
| **Field Monitor / Inspector** | tenant | Conducts site inspections; GPS-tagged photo evidence; inspection checklists |
| **Data Quality Reviewer** (e.g. Bureau of Statistics) | oversight | Third-party validation of submitted data (validation states) |
| **Stakeholder / Citizen** | portal | Published project data, progress maps, feedback submission |

RBAC: `spatie/laravel-permission` in **teams mode** (`tenant_id` = team). Oversight roles
have `tenant_id = null`. Policies check permission **and** tenant match.

## 3. Surfaces & subdomains

One deployment per state client. Wildcard DNS + wildcard TLS per deployment.

| Subdomain | Surface | Routes file | Audience |
|---|---|---|---|
| `www` / apex | Public portal | `routes/portal.php` | Citizens, stakeholders — published data only |
| `oversight.` | State oversight app | `routes/oversight.php` | MEP&B, executive, data reviewers, super admin |
| `{mda-slug}.` | MDA workspace | `routes/tenant.php` | MDA staff, consultants, field monitors |

`ResolveTenant` middleware maps subdomain → `Tenant`; unknown subdomain = 404. Tenant-owned
models use a `BelongsToTenant` global scope; cross-tenant reads only via explicit
`withoutTenancy()` inside oversight code. Public IDs are ULIDs.

## 4. Domain model (core entities)

### Tenancy & identity
- `tenants` — MDA: name, slug (subdomain), type (ministry/department/agency), sector links, branding overrides, active flag
- `users` — global identity; `role` via permission teams; profile, phone (SMS), avatar
- `settings` — instance + tenant key/value (branding, terminology map, deadline rules)

### Project registry
- `projects` — tenant_id, title, description, sector_id, funding_source_id, type
  (capital/programme), status (state machine: `draft → awarded → mobilized → in_progress
  → mid_term → completed → certified → closed`, plus `suspended/cancelled`), contract sum,
  budget allocation, start/expected-end/actual-end dates, physical vs financial % complete,
  LGA/ward, geo point(s)
- `contractors` — company registry (global per instance, linkable across MDAs): name, RC
  number, contacts, category, performance score
- `contracts` — project ↔ contractor: sum, duration, award date, documents (BOQ, award letter)
- `project_assignments` — consultant/monitor users ↔ projects with role on project
- Reference: `sectors`, `funding_sources`, `lgas`/`wards` (state-configurable)

### Results framework (from the manual's indicator matrices)
- `result_frameworks` — per project or per MDA programme; levels: impact/outcome/output
- `indicators` — framework link, tier (PDO/intermediate/output), definition, unit
  (number/percentage/time/one-off), measurement frequency (weekly…annual/one-off),
  data source, means of verification, responsible collector, SMART text
- `indicator_targets` — period-typed target (continuous / time-bound / %-achievement), value, period
- `indicator_readings` — actual value, period, source (primary/secondary + method),
  validation state (`draft → submitted → validated → published`), reader, evidence links
- `workplans` / `workplan_activities` — annual work plan per MDA; each activity linked to
  an output indicator (manual rule), owner, month/week schedule, budget line

### Monitoring lifecycle (Nasarawa BPP 6 steps + manual report types)
- `commencement_notices` — within N days of award: scope, sum, duration, contractor,
  supervising agency, expected completion (N configurable)
- `site_inspections` — type (initial / routine / mid_term / final / post_completion),
  scheduled vs actual date, inspector(s), checklist responses, findings, risk flags,
  GPS + photos (medialibrary), outcome, report (Field Trip Report structure: objectives,
  people met, methods, findings, comparison with previous, conclusions, recommendations)
- `progress_reports` — periodic (monthly default): period, work done vs timeline,
  physical %, financial expenditure, challenges + mitigation, attachments; approval chain
  states: `draft → submitted → reviewed(focal) → approved(director) → consolidated(secretariat)`
  with rejection loops; deadline tracking
- `exception_reports` — deviation/critical-incident reports (threshold-triggered or manual)
- `certificates` — completion certification: final inspection link, issued by, PDF artifact
- `issues` (challenges register) — raised on any report/inspection, severity, owner,
  corrective action, status, due date

### Evaluation
- `evaluations` — type (mid_term / terminal / ex_post / impact), criteria scores
  (relevance, efficiency, effectiveness, impact, sustainability), ToR document, team,
  sponsor, phases, structured 11-section report, status
- `recommendations` — from evaluations/reports: text, addressee, priority, cost, timeline,
  implementation status (follow-up register — the manual's knowledge-management loop)

### Reporting & consolidation
- `reporting_periods` — instance calendar: monthly/quarterly/biannual/annual windows with
  due dates (biannual due end of month after half-year; annual due within Q1 — configurable)
- `consolidated_reports` — secretariat roll-ups of MDA submissions against the
  predetermined indicator list; state APR artifact
- `report_exports` — generated PDF/Excel artifacts with audit metadata
- Dashboard **summary tables** (nightly/near-real-time roll-ups): per-tenant and state-wide
  project counts by status, budget vs expenditure, indicator achievement %, compliance rates

### Engagement
- `stakeholders` — register: person/org, classification (primary/secondary),
  importance/influence, audience group, contact, language, dissemination channels
- `feedback` — portal submissions + stakeholder comments: subject (project), text, media,
  moderation state, response thread
- `notifications` (Laravel) — deadline reminders (measurement-frequency driven), submission/
  approval events, escalations for overdue items; channels: database + mail (SMS later)
- `activity_log` — spatie activitylog on all domain mutations (append-only)
- `documents` — medialibrary collections on projects/reports/contracts (private disk,
  signed URLs)

## 5. Module map & key features

1. **Platform & Tenancy** — tenant CRUD/onboarding wizard, subdomain provisioning,
   branding (logo, colors → CSS variables), terminology config, feature flags per client.
2. **IAM** — auth (Fortify), email verification, 2FA for admin roles, role management UI,
   invitation flow (oversight invites MDA admin; MDA admin invites staff/consultants).
3. **Project Registry** — project CRUD wizard, contractor registry, contract & document
   vault, map view (Leaflet), portfolio list with filters/exports.
4. **Results Framework** — logframe builder (impact→outcome→output tree), indicator
   library (reusable definitions), baseline/target capture, reading submission with
   validation workflow, achievement auto-computation, traffic-light status.
5. **Workplans** — annual work plan builder (activity ↔ indicator ↔ budget line), Gantt
   view, progress roll-up from activities.
6. **Monitoring** — commencement notices, inspection scheduling & mobile-friendly
   checklist forms with photo/GPS evidence, progress report wizard with approval chain,
   exception reports with threshold alerts, issues/corrective-action register,
   completion certification.
7. **Evaluation** — evaluation lifecycle with ToR, criteria scoring, structured report
   builder, recommendations follow-up register.
8. **Reporting & Analytics** — role dashboards (executive scorecard, MDA workspace,
   consultant "my projects"), compliance board (on-time submission league table —
   the manual's rewards/sanctions support), consolidation workspace for the secretariat,
   report builder with PDF/Excel export, state APR generation.
9. **Deadline Engine** — reporting-period calendar, scheduled reminders (queued,
   frequency-aware), overdue escalation up the chain, M&E calendar view.
10. **Public Portal** — published projects browser (map + list), progress stats,
    published reports, feedback submission (rate-limited, moderated).
11. **Audit & Data Quality** — activity timeline per record, validation queue for the
    Data Quality Reviewer role, data-quality flags (accuracy/timeliness), amendment
    register pattern for governed documents.

## 6. Non-functional requirements

- **Security:** see `.claude/rules/security.md` — 2FA for admins, signed private media,
  append-only audit, publishing gate for portal data, HTTPS/HSTS + wildcard cert.
- **Performance:** dashboards from summary tables, not raw scans; p95 < 500ms on tenant
  pages at 50 concurrent users; pagination everywhere; queue heavy exports.
- **Availability & DR:** nightly encrypted backups (DB + media) with documented restore;
  target RPO 24h (configurable per contract), RTO 4h.
- **Usability:** WCAG AA; usable on 360px/3G; Nigerian context defaults (₦, Africa/Lagos,
  MDA vocabulary) — all configurable.
- **Auditability:** every domain mutation attributable (actor, tenant, IP, before/after).
- **Deployability:** single VPS per client (Forge/Ploi-style): PHP-FPM + Nginx wildcard
  vhost + MySQL + Redis + Horizon + scheduler. Dockerized local dev via Sail.

## 7. Stack & packages

Laravel 13, PHP 8.4+, MySQL 8, Redis. Key packages: `livewire/livewire`,
`spatie/laravel-permission` (teams), `spatie/laravel-activitylog`,
`spatie/laravel-medialibrary`, `spatie/laravel-backup`, `laravel/horizon`,
`laravel/fortify` (auth + 2FA), `maatwebsite/excel`, `barryvdh/laravel-dompdf`,
`archtechx/enums` (optional helpers). Frontend: Tailwind v4 + Alpine + ApexCharts +
Leaflet via Vite. No Filament — custom UI per the design system.

## 8. Roadmap

### Phase 0 — Foundation (≈ 2 weeks)
- [x] Laravel 13 scaffold (Herd local, `mne.test` wildcard), packages, Pest/Pint/PHPStan
- [x] Tenancy core: `tenants`, `ResolveTenant`, fail-closed `BelongsToTenant`,
      wildcard routing, three surface route files, per-surface Livewire update
      routes, TrustHosts, job tenancy (`TenantAware`), reviewed + hardened
      (see `docs/design/tenancy-core.md`)
- [x] Spatie permission teams (team = tenant, sentinel 0 global) + `AssignRole`
      contract action + role seeder
- [x] Auth surfaces: per-surface login UI, 2FA for admin roles, tenant_user
      membership gate (`EnsureTenantMembership`)
- [x] Design system: OKLCH token system (light/dark, per-tenant override ready),
      28 `<x-ui.*>` components, three app shells, styleguide at `/styleguide`
      (local-only route) — placeholder palette until the client styleguide lands
- [x] Demo seeders: 2 tenants, users per role (non-production only)
- [x] Tenancy isolation + role isolation + discipline test harness (33 tests)
- [ ] CI workflow (pint --test, phpstan, pest) once repo has a remote

### Phase 1 — MVP: Registry + Progress Reporting (≈ 4 weeks) — **complete**
- [x] Project registry + contractors + contracts + documents
- [x] Project assignment (consultants) + invitation flows
- [x] Progress reports with approval chain + deadline engine v1 (periods, reminders, overdue)
- [x] Basic indicator support: per-project indicators, targets, readings, traffic lights
- [x] MDA dashboard + oversight portfolio dashboard (live aggregates, no rollup table yet)
- [x] Issues/challenges register
- [x] Notifications (database + mail) + per-user preferences + notification centre

### Phase 2 — Full M&E lifecycle (≈ 5 weeks) — **complete**
- [x] Inspections (all 5 types) with checklists, GPS/photo evidence, field trip reports
- [x] Commencement notices + completion certification
- [x] Full results-framework builder (logframe tiers, indicator library, validation
      workflow + Data Quality Reviewer role)
- [x] Workplans + Gantt
- [x] Evaluations + recommendations register
- [x] Consolidation workspace + state APR export + compliance league table
- [x] Exception reports + threshold alerts
- [x] Report builder with PDF/Excel exports

### Phase 3 — Reach (≈ 4 weeks)
- [x] Public transparency portal (published projects, map, feedback) — **delivered early**,
      with the publishing gate that governs it (`PublicProjectPayload` whitelist, oversight
      and MDA publishing queues, moderated feedback)
- [ ] Offline PWA for inspections/progress capture (service worker, background sync,
      versioned JSON API `routes/api.php`, conflict policy: server-wins + draft rescue)
- [ ] SMS channel (Termii or similar), stakeholder register + dissemination lists
- [ ] GIS dashboard (project map by status/sector/LGA)

### Phase 4 — Productization
- [ ] Client onboarding playbook (new-state deployment in < 1 day)
- [ ] Instance configurator (terminology, deadline rules, report templates per client)
- [ ] Optional integrations: e-procurement, SIFMIS-style finance feeds, budget import
- [ ] Capacity/training records module (manual §6.4, Component B indicators)

## 9. Open questions (revisit before Phase 1 ends)

1. First paying client and their domain (affects DNS/TLS setup and demo branding).
2. SMS/WhatsApp provider preference and budget (Termii vs Twilio).
3. Hosting target per client — client-procured VPS vs your managed hosting (affects backup
   custody and contracts).
4. Should contractors get *accounts* in MVP (self-service submissions) or do MDA officers
   enter contractor reports on their behalf initially? (Flowchart implies accounts; some
   states start with officer data entry.)
5. Data residency requirements — any client mandating Nigeria-hosted data (Galaxy
   Backbone/local DC)?
