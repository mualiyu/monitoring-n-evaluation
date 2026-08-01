---
name: ui-review
description: >
  Review screens/views against the design system — token usage, component reuse, states,
  responsiveness, accessibility, and dashboard chart standards. Use after building a batch
  of UI or before a demo to a government client.
---

# UI / Design-System Review

Review the targeted views/components against `.claude/rules/ui-design-system.md`.
Report as `screen | issue | severity | fix`, then fix HIGHs when asked.

## 1. Token & component discipline
- `grep -rn "#[0-9a-fA-F]\{3,6\}" resources/views resources/css` — raw hex outside the
  token definitions = violation.
- Repeated multi-class Tailwind patterns across views that should be an `<x-ui.*>`
  component; forked copies of existing components.
- Arbitrary values (`w-[347px]`) where scale tokens exist.

## 2. Completeness of states
For each screen: loading (`wire:loading` skeleton present?), empty (designed, with CTA —
not just "No records"), error (failed action feedback), success confirmation. A screen
missing any state is not done.

## 3. Responsiveness
- Usable at 360px: no horizontal scroll, tables collapse to cards, touch targets ≥ 44px,
  sticky action buttons reachable with a thumb.
- Test both sidebar (app) and top-nav (portal) shells.

## 4. Accessibility (WCAG AA)
- Contrast of text/status colors on their actual surfaces (both light and dark if themed).
- Every input labeled; error messages associated (`aria-describedby`); focus-visible
  rings intact (not `outline-none` without replacement); modals trap and restore focus;
  status conveyed by icon + text, not color alone.

## 5. Data & dashboard standards
- Lists: filter bar → stat row → table → pagination; filters persist in query string;
  search debounced; export present where spec requires.
- Charts: one highlight color + neutral context from tokens; axis labels present; no 3D,
  no legend when a direct label works; every KPI card links to its drill-down; numbers
  formatted with thousands separators and currency in ₦ (instance-configurable).
- Government-grade tone: no lorem ipsum, no placeholder icons, realistic demo data
  (Nigerian project names, MDA names, ₦ amounts) in screenshots/seeds.

## 6. Performance smells
- Unpaginated `@foreach` over models, images without conversions/srcset, non-`lazy` heavy
  widgets above the fold, fonts/icon sets loaded per-page instead of via the build.
