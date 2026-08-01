# Testing Rules

- **Pest, feature-first.** Every module ships with feature tests that hit real routes on
  real subdomains (`$this->get('http://works.platform.test/projects')`). Unit tests for
  Actions with non-trivial logic (indicator math, status transitions, scoring).
- **The tenancy isolation test is mandatory** for every tenant-owned model: seed two
  tenants, create data in both, assert subdomain A never sees tenant B's records —
  in lists, detail pages, exports, and API responses.
- **Authorization matrix tests:** for each surface, at least one test per role proving
  what they can and cannot do (consultant cannot approve own report; stakeholder cannot
  see draft data; MDA admin cannot reach oversight routes).
- **State machines:** test every allowed transition and at least one forbidden transition
  per lifecycle (e.g. a `certified` project cannot revert to `in_progress`).
- **Factories are the source of truth for valid data.** Every model gets a factory with
  states (`Project::factory()->ongoing()`, `->completed()`, `->behindSchedule()`). Seeders
  compose factories into a realistic demo state (2 tenants, ~10 projects across statuses).
- **Time-sensitive logic** (reporting deadlines, reminder schedules, overdue flags) uses
  `Carbon::setTestNow()` — no sleeping, no real clocks.
- **Coverage expectations:** Actions and Policies near-total; Livewire components covered
  via `Livewire::test()` for each meaningful interaction; Blade purely-presentational
  markup does not need dedicated tests.
- **CI gate:** `pint --test`, `phpstan`, `pest` must all pass before merge. Do not
  weaken a test to make it pass — fix the code or renegotiate the requirement explicitly.
