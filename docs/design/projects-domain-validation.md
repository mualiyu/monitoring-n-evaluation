# Projects Module — Domain Validation (me-domain-expert)

Verdict: **GAPS FOUND** — core shape (projects, contractors, contracts, assignments,
reference data) is sound; two structural errors, one missing statutory field, and a
Phase-2 indicator-linkage risk must be resolved **before schema freeze**.

## Must-do before schema freeze

1. **Supervising agency is missing (statutory field).** Nasarawa commencement notification
   requires it, and procuring MDA ≠ supervising agency often (Works supervises for Health;
   PIUs supervise donor projects). Add `supervising_agency_id` (nullable FK → tenants) +
   free-text fallback on `projects`. Carry explicit "scope of works" text on `contracts`
   (the notification is contract-driven). Notice window (3 working days) → instance setting.

2. **Drop `mid_term` from the project status enum — it is an event, not a state.**
   Mid-term evaluation happens *at 50% physical completion* while the project stays
   in_progress; plan §4 already models it as inspection/evaluation type. Lifecycle:
   `draft → awarded → mobilized → in_progress → completed → certified → closed`
   (+ suspended/cancelled). At physical % ≥ configurable threshold (default 50%) raise a
   "mid-term evaluation due" flag/notification; Phase 2 inspections resolve it.
   Design the `completed → certified` guard point (final inspection) even if Phase 1
   allows manual transition.

3. **`closed` must not lock the record.** Post-completion monitoring/impact assessment
   happens 6–12 months AFTER completion. Either `certified` is the operating terminal
   state with `closed` reachable only after a configurable post-completion window, or
   post-completion inspections/evaluations explicitly attach to closed projects. Add
   `post_completion_review_due_at` (actual end + configurable window) for the deadline engine.

4. **`project_locations` child table from day one** (lga_id, ward_id, geo point, site
   name/description). Projects are frequently multi-site ("N facilities across M LGAs");
   Phase 2 inspections are per-site; migrating location off `projects` later is expensive.
   Single-site projects create one row.

5. **Indicators: create the full-width table in Phase 1** even if the UI exposes a subset.
   Field list is fixed by the manual: definition, unit (number/percentage/time/one-off),
   measurement frequency, data source, responsible collector, means of verification,
   **baseline (mandatory)**, typed target (continuous / time-bound / %-achievement).
   Include nullable `result_framework_id` + `tier` (pdo/intermediate/output) alongside
   `project_id` now so Phase 2's logframe attaches without migrating readings.
   Also add `goal`/`objectives` text on `projects` (flowchart's scope-definition step).
   Measurement frequency must exist in Phase 1 — deadline engine v1 depends on it.

6. **Contract sum lives on `contracts` only.** Project-level figure is an aggregate
   (projects can have multiple contracts/lots). Keep award sum immutable; revisions =
   contract amendment/variation records (manual's amendment-register pattern). `projects`
   keeps `budget allocation` (appropriation) + nullable `budget_code` for later finance
   linkage. `financial %`/`physical %` on projects = denormalized roll-ups from progress
   reports. Defer disbursements/SIFMIS/capex variance (Phase 4) and M&E/AWPB budgets (Phase 2).

## Recommended now / documentable deferrals

7. **Registry typing:** name it broadly (or add `type`: contractor / consultant_firm /
   supplier) + `contract type` (works/supply/consultancy) on contracts — Phase 2 evaluation
   consultants are procured firms (bidding trail is a platform feature). If contractor
   self-service accounts happen, link users → firms via nullable `firm_id`.
   `performance_score`: keep the column, rubric must be instance-configurable (no
   methodology exists in the manuals).

8. **Co-funding:** single `funding_source_id` cannot represent donor + counterpart funding
   (near-universal on WB projects). Prefer `project_funding_sources` pivot with
   amount/percentage; if deferred, document it — reports must not assume one source.

9. **Terminology via `term()` helper, never literals:** MDA, LGA (FCT: Area Councils),
   ward, sector, funding source, contractor/consultant, focal person, commissioner/
   secretariat, commencement ("mobilization" in some states). Reference data seeded per
   instance, never a fixed national list. Configurable NUMBERS too: 3-day notice, 50%
   mid-term trigger, 6–12-month post-completion window, biannual/Q1 deadlines.

10. **Two cheap-now fields:** `revised_end_date` (or via contract variations — decide now;
    planned-vs-revised-vs-actual variance is core reporting) and a publishing gate
    (`published_at`) on projects for the Phase 3 portal (security rules require an
    explicit publishing step; costs nothing now, avoids a backfill later).
