---
name: tenancy-audit
description: >
  Sweep the codebase for multi-tenancy leaks — unscoped models, manual tenant_id clauses,
  scope bypasses outside oversight code, jobs without tenant context, and missing isolation
  tests. Run before every release and after any large merge.
---

# Tenancy Leak Audit

Run these sweeps in order; report findings as a table (`severity | file:line | issue | fix`).

## 1. Model coverage
- List every migration table with MDA-created data; cross-check each corresponding model
  `use`s `BelongsToTenant`. Models with `tenant_id` column but no trait = CRITICAL.
- `grep -rn "tenant_id" app/ --include="*.php"` — any manual `where('tenant_id'` outside
  the trait/middleware = HIGH (signals a model missing the trait).

## 2. Scope bypasses
- `grep -rn "withoutTenancy\|withoutGlobalScope" app/ resources/` — every hit must live in
  `app/Actions/Oversight/` or `app/Livewire/Oversight/`. Anything else = CRITICAL.

## 3. Entry points
- Route files: confirm `routes/tenant.php` group carries `ResolveTenant` middleware and no
  tenant-surface route is also registered on oversight/portal groups.
- Route-model bindings: bindings on tenant-owned models must resolve through the scoped
  query (implicit binding + global scope is fine; custom `resolveRouteBinding` overrides
  must keep the scope).
- Livewire: public methods/properties accepting IDs — verify the lookup goes through a
  scoped model, not `Model::withoutGlobalScopes()` or raw DB.

## 4. Out-of-request contexts
- Jobs/listeners/commands touching tenant-owned models: must accept/serialize tenant
  context and re-bind it in `handle()`. Scheduled commands iterating all tenants must
  scope each iteration.
- Exports/reports/PDF generation: confirm the query source is scoped; check maatwebsite
  export classes especially.
- Cache keys for tenant data include the tenant id; broadcast channel names include and
  authorize the tenant.

## 5. Test coverage
- Every tenant-owned model has the two-tenant isolation test (list, detail, search,
  export, direct-UUID 404). List models missing it.

Finish with a verdict: PASS (no critical/high) or FAIL, and if FAIL, fix criticals
immediately before any other work.
