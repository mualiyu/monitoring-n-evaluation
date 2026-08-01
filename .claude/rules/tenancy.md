# Tenancy Rules (single DB, subdomain-scoped) — CRITICAL

A tenancy leak in a government system is a security incident. These rules are absolute.

- **Every tenant-owned table has `tenant_id`** (FK to `tenants`), indexed, non-nullable.
  Tenant-owned means: projects, contracts, reports, inspections, indicators, media,
  feedback — anything an MDA creates. Global reference data (sectors, funding sources,
  roles, states/LGAs) has no `tenant_id`.
- **Scoping is automatic, never manual.** Tenant-owned models use the `BelongsToTenant`
  trait (adds a global scope bound to the resolved tenant + auto-fills `tenant_id` on
  create). Never write `where('tenant_id', ...)` in queries — if you need it, the model
  is missing the trait.
- **Tenant resolution happens once, in middleware.** `ResolveTenant` middleware maps the
  request subdomain → `Tenant` model → binds it into the container (`app(CurrentTenant::class)`).
  Anything on a tenant route without a resolvable tenant is a 404, not a fallback.
- **Cross-tenant reads are an explicit privilege.** Only oversight-surface code may call
  `Model::withoutTenancy()` (scope bypass), and only inside `app/Actions/Oversight/` or
  oversight Livewire components. A bypass anywhere else must be treated as a bug.
- **IDs in URLs are UUIDs/ULIDs**, not auto-increment integers, for all tenant-owned
  models — prevents cross-tenant enumeration and information leakage about volumes.
- **Authorization is tenant-aware.** spatie/laravel-permission runs in teams mode with
  `tenant_id` as the team key. A user's role in MDA A grants nothing in MDA B. Policies
  must check both permission AND that the model's `tenant_id` matches the current tenant.
- **Queued jobs carry tenancy.** Jobs touching tenant data serialize the tenant id and
  re-initialize tenancy in `handle()`. Never assume container tenant state inside a queue
  worker.
- **Tests must prove isolation.** Every new tenant-owned model ships with a test that
  creates records in two tenants and asserts each subdomain sees only its own.
