# Coding Standards

- **PHP:** PSR-12 via Pint (`vendor/bin/pint --dirty` before commit). PHP 8.3+ features
  encouraged: enums, readonly, constructor promotion, first-class callables.
- **Types everywhere.** All methods declare parameter + return types. Model attributes
  documented with `@property` blocks or IDE-helper. PHPStan level 6 minimum must pass.
- **Naming:**
  - Models singular (`Project`, `SiteInspection`); tables plural snake (`site_inspections`).
  - Actions verb-first (`SubmitProgressReport`, `ScheduleInspection`).
  - Livewire components: `app/Livewire/{Surface}/{Domain}/` e.g.
    `App\Livewire\Tenant\Monitoring\ProgressReportForm`.
  - Enums in `app/Enums/`, suffixed by concept not "Enum": `ProjectStatus`, `ReportingFrequency`.
- **Migrations:** one concern per migration; always reversible; foreign keys with explicit
  `constrained()->cascadeOnDelete()` or `restrictOnDelete()` decided deliberately (default
  to restrict for domain data — government records are rarely hard-deleted). Soft deletes
  on all domain models.
- **Eloquent over query builder; query builder over raw SQL.** Raw SQL requires a comment
  explaining why and must use bindings.
- **No N+1.** Eager-load in Livewire `render()`/computed properties. `Model::preventLazyLoading()`
  is enabled in non-production — do not silence it, fix the query.
- **Money is integers (kobo/cents) or `decimal(18,2)`** — never floats. Use a `Money` cast.
  Budgets/contract sums in Nigerian Naira by default, currency configurable per instance.
- **Dates:** store UTC, display in instance timezone (`Africa/Lagos` default). Use `immutable_datetime` casts.
- **Translations-ready:** user-facing strings in Blade go through `__()`. Terminology
  (e.g. "MDA" vs "Agency") is configurable per instance, so use the `term()` helper for
  domain nouns once it exists.
