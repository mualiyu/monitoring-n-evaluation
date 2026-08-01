# UI Design Brief — copy-paste prompt for a Claude design session

> Paste everything below the line into a fresh Claude session (claude.ai) to run the UI
> design track in parallel with backend development. Deliverables come back as HTML
> mockups that translate 1:1 into our Blade/Tailwind components.

---

You are the lead product designer for **an M&E Platform** — a white-label Monitoring &
Evaluation system used by Nigerian state governments to track public projects executed by
contractors/consultants. Think "government-grade delivery dashboard": serious, trustworthy,
data-dense but calm. It must feel like modern civic software (GOV.UK clarity + Linear-level
polish), never like a bootstrap admin template.

## Users & contexts
1. **Executives** (Governor's office, Commissioners) — read-only dashboards on laptops/tablets; want the state of the state in 10 seconds.
2. **MDA M&E officers** — power users doing daily data entry/review on mid-range laptops.
3. **Consultants/contractors** — submit monthly progress reports + photo evidence, often from the field on mid-range Android phones over 3G.
4. **Citizens** — a public transparency portal; low digital literacy assumed.

## Hard constraints
- **Tailwind CSS v4 utility classes** (will be ported to Blade + Livewire; no React/Vue).
- **All colors via CSS custom properties** — never raw hex in markup. Define exactly:
  `--brand-50 … --brand-900` (primary scale), `--surface`, `--surface-raised`, `--ink`,
  `--ink-muted`, `--line`, `--positive`, `--warning`, `--critical`, `--info`.
  Pick a dignified default palette (deep green or deep blue works for Nigerian government
  contexts; it will be re-skinned per state), plus light + dark values.
- **Mobile-first**: every screen must work at 360px. Tables collapse to cards.
- **WCAG AA**: contrast, visible focus rings, labels on every input, status = icon + text
  never color alone.
- Number formatting: ₦ amounts with thousand separators; dates like `14 Mar 2026`.
- Charts: clean and flat — one highlight color, neutral gray context series, no 3D, no
  gradients, direct labels over legends where possible.

## Design-system deliverable (do this first)
A single HTML page ("styleguide") containing: the token definitions (light + dark),
typography scale, buttons (primary/secondary/ghost/destructive + sizes), form controls
(input, select, textarea, file/photo upload dropzone, date picker shell), badges/status
pills (draft, submitted, under review, approved, rejected, overdue, on track, behind,
completed, certified), card, stat/KPI tile, table (+ its mobile card collapse), tabs,
modal, toast, empty state, loading skeleton, step indicator (wizard), sidebar nav item,
top-nav for the portal.

## Screens to design (one self-contained HTML file each, realistic Nigerian demo data —
e.g. "Ministry of Works & Infrastructure", "Reconstruction of Igbara-Oke–Ilara Road",
₦1,240,500,000 — never lorem ipsum)

**App shell A — MDA workspace (sidebar layout):**
1. MDA dashboard — KPI row (active projects, ₦ portfolio, % on track, overdue reports),
   projects-by-status chart, upcoming deadlines list, recent activity feed.
2. Projects list — filter bar (status, sector, LGA, year, search) → summary stats →
   table (name, contractor, contract sum, physical %, financial %, status, last report)
   → pagination. Show the 360px card-collapse variant too.
3. Project detail — header with status + key figures, tabbed: Overview (progress ring,
   milestone timeline, map placeholder, budget vs spend bar), Reports, Inspections,
   Indicators, Documents, Issues, Activity.
4. Progress report form (consultant view) — multi-step wizard: Period & work done →
   Physical/financial progress (sliders/inputs with variance hints) → Challenges →
   Photo evidence upload (GPS-tag chips) → Review & submit. Show autosave "Draft saved"
   state and one inline validation error.
5. Report review screen (officer view) — report content left, review panel right
   (approve / request changes with comment), approval-chain stepper (Consultant →
   Focal Officer → Director → Secretariat), audit trail strip.
6. Site inspection form (mobile-first, design at 360px) — checklist groups, pass/fail/NA
   toggles, photo capture tiles, GPS status chip, offline-ready "will sync" banner.

**App shell B — State oversight (sidebar, denser):**
7. Executive dashboard — state-wide KPIs, MDA league table (on-time reporting compliance,
   sparklines), budget vs expenditure by sector chart, projects map placeholder,
   flagged/at-risk projects list.

**Shell C — Public portal (top-nav, friendly):**
8. Portal home + projects browser — hero with state branding slot, search, project cards
   with progress bars, map toggle, and a "give feedback on this project" affordance.

## Working method
Deliver the styleguide first for approval, then screens in the numbered order, each as a
complete standalone HTML file (tokens inlined in a `<style>` block) so it renders
perfectly in an artifact preview. After each screen, list which styleguide components it
used and any new component it introduced (which must then be added back to the styleguide).
Ask me at most one clarifying question per screen; otherwise use your judgment and note
the assumption.
