# Ondo State M&E Implementation Manual — Digest

> Source: `docs/ONDO-STATE-MONITORING-AND-EVALUATION-IMPLEMENTATION-MANUAL.pdf` (44 pp, scanned).
> This digest is the working domain reference for feature design. The manual was produced by
> Ondo State MEP&B with World Bank assistance (Ernst & Young, 2015–2017). Treat its
> structures as the *generic* Nigerian state M&E pattern — the platform is white-label.

## 1. Institutional framework (→ roles & tenancy model)

- **Ministry of Economic Planning & Budget (MEP&B)** — apex M&E institution. Its M&E
  Department supervises all state M&E: demands & harmonizes MDA reports, consolidates them
  for the Commissioner, prepares state M&E plan/guidelines/manual, conducts evaluations,
  applies **rewards and sanctions**, provides feedback/backstopping to MDAs.
  → maps to our **Oversight surface** (state-level admin).
- **MDAs** — each has a **Department of M&E** (Director of M&E) plus **M&E Focal Persons**
  directly responsible for M&E activities. Reporting chain: *MDA focal officer → MDA
  Director of M&E → MEP&B M&E Secretariat → Commissioner*. → maps to **tenant workspaces**.
- **State Bureau of Statistics** — data-quality authority; "all data used in the M&E system
  should pass the standard test of SBS"; can act as third-party Data Quality Reviewer;
  provides baselines from surveys/census. → third-party reviewer role + data validation states.
- **Fiduciary agencies** (Ministry of Finance, Budget Office, Accountant-General,
  Auditor-General) — budget-performance data; SIFMIS financial statements. → budget linkage.
- **Office of the Governor / House of Assembly** — demand and use M&E results; oversight.
  → read-heavy executive dashboards.
- **LGAs, Development Partners, Non-state actors** (CSOs, NGOs, faith-based orgs, academia,
  media, traditional authorities) — participate in PM&E, advocacy, citizen feedback.
  → stakeholder register + public portal.

## 2. Results framework (→ indicators module)

- **Results chain:** Inputs → Activities → Outputs → Outcomes → Impacts (logframe / theory
  of change basis). Indicators exist at all levels.
- **Three indicator tiers:** PDO (project-wide) → Intermediate outcome (per component) →
  Output indicators (one per annual-work-plan activity).
- **Indicator record fields (from matrices):** outcome linked to | indicator focus |
  indicator definition | unit of measure (Number / Percentage / Time / One-off event) |
  frequency (Monthly / Quarterly / Annually / One-off) | source of data | responsibility
  for data collection | means of verification | baseline | target.
- **Baselines:** required for every indicator; from official statistics, surveys, rapid
  assessments; retroactive baselines via anecdotal data or control groups.
- **Targets:** quantitative or qualitative; typed as **continuous**, **time-bound**, or
  **percentage-achievement**; same unit as baseline; periods weekly/monthly/quarterly/yearly.
- **Indicator lifecycle:** review/selection every Q1; indicator review retreat Q4; new
  indicators added from report recommendations; monitoring system modified accordingly.
- SMART criteria; KPIs; indicator database table is an explicit manual artifact.

## 3. M&E processes (→ monitoring module workflows)

Core work-plan activities: implementation monitoring (field inspection of ongoing/completed
projects), data collection, data processing (validation + analysis), evaluations
(relevance/efficiency/effectiveness/impact/sustainability), participatory M&E (biennial,
with traditional authorities & CBOs), review meetings (monthly), report preparation,
dissemination.

**Report preparation workflow (8 steps):** identify stakeholders → inception meeting →
data collection (primary + secondary) → **data validation meetings with stakeholders** →
draft report → **stakeholder validation of draft** → publication → dissemination.

**Field visits:** every field visit must produce a **Field Trip Report**: 1 objectives,
2 people/groups met & sites visited, 3 methods used, 4 findings, 5 comparison with earlier
visits (trends), 6 conclusions, 7 recommendations for action.

## 4. Reports & cadence (→ reporting module + deadline engine)

**Monitoring report types (Table 5.2):**
| Type | When |
|---|---|
| Inception Report | at project commencement |
| Progress Report | routine, planned targets vs actuals |
| Exception Report | on critical incidence / high deviation |
| On-Demand Report | special request |
| Completion Report | final evaluation |

**Statutory-style deadlines:**
- **Biannual Progress Report** — every MDA, due *end of the month following each six-month
  period*; consolidated by M&E Secretariat for the Commissioner.
- **Annual M&E Report** — per MDA, due *within Q1 of the following year*; MEP&B consolidates
  all into one state report against a **predetermined indicator list**.
- Monthly review meetings; quarterly progress reports/meetings; APR cycle (prep Q1, peer
  review + print + disseminate Q2); indicator retreat Q4; PM&E every 2 years.
- M&E calendar = yearly schedule with specific dates for all M&E activities.

**Evaluation report format (11 sections):** title page, ToC, acknowledgments, executive
summary (2–3 standalone pages), introduction/background, objectives & scope (evaluation
questions), methodology & limitations, findings (graphical, comparisons, reasons),
recommendations (per user type, prioritized, costed, timetabled), lessons learned,
appendices (ToR, instruments, persons interviewed, sites visited).

## 5. Data management (→ data quality features)

- Primary sources (surveys, interviews, FGDs, observation, case studies) vs secondary
  (SBS/NBS statistics, MDA reports, census). Tag every indicator reading with its source.
- Collection prerequisites: ethics, written guidelines, pre-tested instruments, trained staff.
- Processing lifecycle: editing → coding → data entry → **cleaning/validation** → analysis.
  → implies submission states: draft → submitted → validated → published.
- QA: third-party data quality reviews (accuracy, reliability, timeliness, objectivity);
  triangulation; spot checks; publishing data as a quality mechanism.
- Amendment governance pattern (for the manual itself, reusable system-wide): Master
  Register of Amendments (`no | approval date | effective date | section | description |
  approved by`) + Update Log; superseded versions retained for audit trail.

## 6. Management artifacts (→ planning & budget modules)

- **M&E Work Plan** (costed, multi-year): activities | timeline | actors.
- **M&E Budget:** `ID | item | activities | inputs | qty | frequency | unit cost | amount`
  with sub-totals/totals/grand total; best practice reserves **2–5% of project budget for M&E**.
- **Annual Work Plan (AWPB)** per MDA: every activity carries an output indicator.
- **Implementation plan** (Appendix A): Gantt-style `no | activity | owner | month × week`
  covering: design program logic → identify stakeholders → develop indicators/baselines/
  targets → conduct monitoring → collect data → write reports → conduct evaluation
  (ToR, bidding to contract evaluation consultants, desk review, fieldwork, publication)
  → disseminate & communicate (incl. stakeholder contact register, feedback measurement).
- **Stakeholder matrices:** classification (primary/secondary), importance/influence matrix,
  responsibilities table, dissemination strategy (`audience | key messages | strategies/tools`).

## 7. Feature cues extracted for the platform

1. Role hierarchy mirroring the reporting chain (focal person → director → secretariat →
   commissioner) with review/approval steps at each hop.
2. Deadline engine: biannual/annual report deadlines, M&E calendar, automated reminders,
   overdue flagging, compliance scoreboard (rewards & sanctions support).
3. Consolidation/roll-up: state report auto-aggregated from MDA submissions against a
   predetermined indicator list.
4. Exception reporting: threshold-based deviation alerts (target vs actual variance).
5. Data validation workflow states + third-party reviewer role (SBS).
6. Stakeholder CRM: register, classification, influence matrix, dissemination lists,
   validation-meeting participation, feedback capture.
7. Evaluation module: typed evaluations, ToR, consultant procurement/bidding trail,
   structured 11-section report, recommendations register with follow-up tracking.
8. Budget linkage: project budget vs expenditure variance, M&E budget (2–5% rule),
   AWPB linkage, SIFMIS-style financial statement references.
9. Knowledge management: lessons learned, recommendations feeding decisions.
10. Capacity module (later): training records, M&E human capacity worksheet.

## 8. Companion docs (also in `docs/`)

- **Mukeey - M&E.pdf** — product concept: 3 primary user groups (Government/MDA admin,
  Consultant, Stakeholder), dashboards, KPI tracking, data entry, reports, feedback.
- **MONITORING_OF_ONGOING_PROJECTS-1.pdf** — Nasarawa BPP project-monitoring lifecycle:
  1. Commencement notification (within 3 working days of contract award: title, scope,
     contract sum, duration, contractor, supervising agency, expected completion).
  2. Initial inspection & compliance review (7–10 working days after notification).
  3. Routine monitoring + contractor monthly progress reports (work vs timeline, financial
     expenditure, challenges & mitigation).
  4. Mid-term evaluation & quality assessment at 50% completion.
  5. Final inspection & Project Completion Certificate.
  6. Post-completion monitoring & impact assessment (6–12 months after).
- **Monitoring & Evaluation System Flowchart.pdf** — role flows: Agency defines scope/
  indicators/baselines/targets/frequency → uploads & assigns consultants → automated
  notifications per measurement frequency → consultant updates progress + challenges →
  status transitions (In-Progress / Finished / Cancelled) → dashboards, filter/sort/export.
