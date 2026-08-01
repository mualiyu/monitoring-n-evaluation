# UI / Design System Rules (TALL)

Government users span permanent secretaries to field monitors on old Android phones.
The UI must be fast, legible, obvious, and beautiful — in that order.

- **Design tokens over hard-coded values.** All colors come from CSS custom properties
  defined per tenant/instance (`--brand-*` scale + semantic tokens: `--surface`, `--ink`,
  `--positive`, `--warning`, `--critical`). Tailwind config maps to these variables.
  Never type a raw hex color in a Blade file.
- **Component library first.** Reusable Blade components live in
  `resources/views/components/` (`<x-ui.button>`, `<x-ui.card>`, `<x-ui.stat>`,
  `<x-ui.badge>`, `<x-ui.table>`, `<x-ui.modal>`, `<x-ui.form.*>`). Before writing new
  markup, check the component exists; extend it rather than fork it. Pages compose
  components — a page file with 300 lines of raw Tailwind classes is a smell.
- **Layout system:** three app shells — `layouts.oversight`, `layouts.tenant`,
  `layouts.portal`. Sidebar navigation for app surfaces, top-nav for the public portal.
  Mobile-first responsive; every screen must be usable at 360px width.
- **Data-heavy screens follow the pattern:** filter bar → summary stat row → table/cards
  → pagination. Tables collapse to cards on mobile. Every list screen gets search,
  filter persistence (query string), and Excel/PDF export where the domain needs it.
- **Forms:** Livewire-driven with inline validation messages, dirty-state warning,
  autosave for long M&E report forms (draft status), and clear step indicators for
  multi-step wizards (e.g. project creation).
- **Dashboards:** ApexCharts via a thin Blade/Alpine wrapper component. Charts follow
  the dataviz conventions (one highlight color, neutral context, no 3D, no gratuitous
  gradients). Every metric card links to the drill-down list that explains it.
- **States are designed, not defaulted:** every list has an empty state (with call to
  action), loading skeletons (`wire:loading`), and error states. No blank white screens.
- **Accessibility:** WCAG AA contrast, focus-visible states, semantic HTML, labels on all
  inputs, keyboard-navigable modals/menus. Status conveyed by icon + text, never color alone.
- **Performance:** paginate everything (25 default), lazy-load heavy Livewire components
  (`lazy`), debounce search inputs (300ms), images via responsive `srcset` from medialibrary
  conversions.
