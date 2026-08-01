---
name: livewire-component
description: >
  Create a Livewire 3 component the project way — right namespace/surface, Form objects,
  design-system Blade, loading/empty/error states, and a Livewire test. Use for any new
  interactive screen or widget.
---

# Livewire Component Checklist

## Placement & naming
- Class: `app/Livewire/{Surface}/{Domain}/{Name}.php` where Surface ∈ `Oversight`,
  `Tenant`, `Portal` — e.g. `App\Livewire\Tenant\Monitoring\ProgressReportForm`.
- View: `resources/views/livewire/{surface}/{domain}/{kebab-name}.blade.php`.
- Full-page components register in the matching surface route file with its middleware
  stack (tenant components get `ResolveTenant` + auth + permission middleware).

## Class rules
- Forms use Livewire **Form objects** (`app/Livewire/Forms/`) with attribute-based
  validation; long M&E report forms get draft autosave (`updated()` hook persisting a
  draft model, debounced).
- Data access through computed properties with explicit eager loading; queries on
  tenant-owned models rely on the global scope — never add manual `tenant_id` clauses.
- Business logic delegates to Action classes; the component orchestrates, it never decides.
- Authorize in `mount()` AND in every public method that mutates (`$this->authorize(...)`)
  — Livewire methods are network-callable endpoints.
- Pagination 25/page via `WithPagination`; search inputs `wire:model.live.debounce.300ms`;
  filters sync to the query string; heavy dashboard widgets declared `lazy`.

## View rules
- Compose from `<x-ui.*>` components; design tokens only (no raw hex, no arbitrary
  one-off spacing values when a scale token exists).
- Ship all states: `wire:loading` skeleton, designed empty state with CTA, inline
  validation errors under each field, error flash for failed actions.
- Mobile-first: verify the layout works at 360px; tables collapse to cards.
- Accessibility: label every input, `aria-live` on async result regions, focus trap in
  modals, status shown as icon + text.

## Verify
- `Livewire::test()` covering: renders for an authorized user, denied for an
  unauthorized role, validation errors surface, the happy-path mutation calls the Action
  and the UI reflects it.
- `vendor/bin/pint --dirty`, then run the new tests and show output.
