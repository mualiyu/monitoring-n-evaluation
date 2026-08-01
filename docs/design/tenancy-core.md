# Phase 0 — Tenancy Core Design (build checklist)

Target: Laravel 13.x / PHP 8.4, MySQL 8, single shared DB, subdomain-per-MDA.
Authority: `docs/PROJECT_PLAN.md` §1–4, `.claude/rules/tenancy.md`, `.claude/rules/architecture.md`.

---

## 1. Migrations: `tenants`, settings, membership

### 1.1 `create_tenants_table` — global, no `tenant_id`
```php
Schema::create('tenants', function (Blueprint $t) {
    $t->id();                                   // internal PK: bigint for cheap FK/index
    $t->ulid('public_id')->unique();            // ONLY id exposed in oversight URLs
    $t->string('name');                         // "Ministry of Works and Infrastructure"
    $t->string('short_name', 60)->nullable();   // "Works" — sidebar/breadcrumb
    $t->string('slug', 63)->unique();           // subdomain label (DNS label max = 63)
    $t->string('custom_domain')->nullable()->unique(); // RESERVED, unused in Phase 0
    $t->string('type', 24);                     // TenantType: ministry|department|agency|bureau|commission
    $t->foreignId('parent_id')->nullable()->constrained('tenants')->nullOnDelete();
    $t->string('contact_email')->nullable();
    $t->string('contact_phone', 32)->nullable();
    $t->json('branding')->nullable();           // {logo_path, primary, accent, ink} — overrides instance
    $t->boolean('is_active')->default(true);
    $t->timestamp('onboarded_at')->nullable();
    $t->timestamps();
    $t->softDeletes();
    $t->index(['is_active', 'type']);
});
```
- `slug` lowercased + validated against `config('platform.reserved_subdomains')` in `StoreTenantRequest`
  (regex `^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$`). DB cannot enforce this — app rule + test.
- Sector links deferred to Phase 1 (`sector_tenant` pivot); `sectors` is global reference data.

### 1.2 Settings — **split into two tables** (deviation from plan §4, deliberate)
The plan says one `settings` table holding instance + tenant rows. Rejected because:
(a) nullable `tenant_id` cannot use `BelongsToTenant` (trait requires non-null) → forces manual
`where('tenant_id', ...)`, which the tenancy rules ban; (b) **MySQL UNIQUE treats NULLs as
distinct**, so `unique(tenant_id, group, key)` would silently permit duplicate instance settings.

```php
// settings — instance scope, global, NO tenant_id
$t->id(); $t->string('group', 50); $t->string('key', 100);
$t->json('value')->nullable(); $t->string('type', 20)->default('string');
$t->boolean('is_public')->default(false); $t->timestamps();
$t->unique(['group', 'key']);

// tenant_settings — tenant-owned, BelongsToTenant
$t->id(); $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
$t->string('group', 50); $t->string('key', 100);
$t->json('value')->nullable(); $t->string('type', 20)->default('string');
$t->boolean('is_public')->default(false); $t->timestamps();
$t->unique(['tenant_id', 'group', 'key']);
```
- No soft deletes on settings: they are configuration, not domain records; mutations are captured by
  activitylog (append-only) which satisfies auditability without breaking the unique indexes.
- Resolution order (`App\Settings\SettingsRepository`): `tenant_settings` → `settings` →
  `config('platform.*')` default. Cached per scope (`settings:instance`, `settings:tenant:{id}`),
  busted by model observers. `groups`: `branding|terminology|deadlines|features|notifications`.

### 1.3 `add_platform_columns_to_users_table` — users are GLOBAL identity, **no `tenant_id`**
`public_id` ulid unique, `phone` (32, nullable), `is_active` bool default true,
`last_login_at`, `home_tenant_id` nullable FK → tenants nullOnDelete, `softDeletes()`.
Fortify 2FA columns come from Fortify's own migration.

### 1.4 `create_tenant_user_table` — explicit membership (do not infer from role rows)
```php
$t->id();
$t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
$t->foreignId('user_id')->constrained()->cascadeOnDelete();
$t->string('status', 20)->default('active');   // invited|active|suspended
$t->foreignId('invited_by_id')->nullable()->constrained('users')->nullOnDelete();
$t->timestamp('invited_at')->nullable(); $t->timestamp('joined_at')->nullable();
$t->timestamps();
$t->unique(['tenant_id', 'user_id']);
$t->index(['user_id', 'status']);
```
Membership is the hard gate (`EnsureTenantMembership`); permissions are the soft gate. A user with a
role but no active membership row gets 403 on that subdomain.

### 1.5 Exceptions where `tenant_id` is nullable (flagged deliberately)
| Table | Why nullable / absent | Compensating control |
|---|---|---|
| `users` | global identity across MDAs | `tenant_user` + team-scoped roles |
| `settings` | instance scope by definition | separate table, no trait |
| `notifications` | oversight notifications have no tenant | add nullable `tenant_id` + index `(notifiable_type, notifiable_id, tenant_id)`; feed query object filters explicitly — the one sanctioned manual `where` |
| `media` (medialibrary) | instance branding assets have no owner tenant | nullable `tenant_id`; media is *only* reachable through its owning model (which is scoped) and the signed-URL controller re-checks `$media->model->tenant_id` |
| `roles` / `permissions` | role definitions are global (team key lives on the assignment pivot) | `model_has_roles.tenant_id` is the team key |
| `activity_log` | must record oversight actions too | nullable `tenant_id`, indexed; append-only, oversight-read-only |

---

## 2. `BelongsToTenant` — fail-closed

Files:
- `app/Models/Concerns/BelongsToTenant.php`
- `app/Models/Scopes/TenantScope.php`
- `app/Tenancy/CurrentTenant.php`
- `app/Tenancy/Exceptions/TenantNotResolvedException.php`, `CrossTenantWriteException.php`
- `app/Support/Facades/Tenancy.php` (facade → `CurrentTenant::class`)

```php
final class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenancy = app(CurrentTenant::class);
        if ($tenancy->isBypassed()) return;                       // explicit oversight/system context
        $builder->where($model->qualifyColumn('tenant_id'), $tenancy->idOrFail());
    }
}
```
**Fail-closed, always.** No bound tenant + no bypass = `TenantNotResolvedException` (rendered 404 on
tenant routes, 500 elsewhere — it is a bug elsewhere). Never return unscoped rows, never return an
empty set silently (an empty set hides the bug and produces "my data vanished" tickets).

```php
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $m): void {
            $tenancy = app(CurrentTenant::class);
            if ($m->getAttribute('tenant_id') === null) {
                $m->setAttribute('tenant_id', $tenancy->idOrFail());   // throws in unbound context
                return;
            }
            // explicit tenant_id only allowed inside a bypass block (seeders, oversight backfills)
            if (! $tenancy->isBypassed() && (int) $m->tenant_id !== $tenancy->idOrFail()) {
                throw new CrossTenantWriteException(static::class);
            }
        });

        static::updating(function (Model $m): void {
            if ($m->isDirty('tenant_id')) throw new CrossTenantWriteException('tenant_id is immutable');
        });
    }

    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }

    // Model::withoutTenancy()->... — exact name mandated by .claude/rules/tenancy.md
    public function scopeWithoutTenancy(Builder $query): Builder
    {
        return $query->withoutGlobalScope(TenantScope::class);
    }
}
```
`CurrentTenant` (singleton, bound in `App\Providers\TenancyServiceProvider`):
```php
public function set(Tenant $tenant): void;        // also setPermissionsTeamId($tenant->id)
public function setById(int $id): void;           // resolves via Tenant::query() (Tenant is unscoped)
public function forget(): void;                   // also setPermissionsTeamId(null)
public function get(): ?Tenant;  public function getOrFail(): Tenant;
public function id(): ?int;      public function idOrFail(): int;
public function check(): bool;   public function isBypassed(): bool;
public function bypass(Closure $callback): mixed;                    // nesting-safe depth counter
public function runFor(Tenant|int $tenant, Closure $callback): mixed; // swap + restore in finally
```
`set()`/`forget()`/`runFor()` must also `app(PermissionRegistrar::class)->setPermissionsTeamId(...)`
and, when a user is already resolved, `auth()->user()?->unsetRelation('roles')->unsetRelation('permissions')`
— otherwise spatie serves tenant A's cached roles on tenant B.

**Discipline enforcement (cheap, boring):** `tests/Architecture/TenancyTest.php` asserts
(1) `withoutTenancy(` appears only under `app/Actions/Oversight`, `app/Livewire/Oversight`,
`app/Tenancy`; (2) `where('tenant_id'` appears nowhere in `app/` except the notifications feed
query object; (3) every model whose table has a `tenant_id` column uses the trait (reflection loop).

---

## 3. `CurrentTenant` binding + `ResolveTenant` middleware

### 3.1 `config/platform.php`
```php
'domain'              => env('PLATFORM_DOMAIN', 'mne.test'),  // host only, no scheme/port
'oversight_subdomain' => env('PLATFORM_OVERSIGHT_SUBDOMAIN', 'oversight'),
'reserved_subdomains' => ['www','oversight','admin','api','app','portal','mail','smtp','ftp',
                          'assets','cdn','static','status','docs','help','ns1','ns2','autodiscover'],
'currency' => 'NGN', 'timezone' => 'Africa/Lagos',
'terminology' => ['tenant' => 'MDA', 'tenant_plural' => 'MDAs', 'oversight' => 'State Secretariat'],
'branding' => ['name' => env('PLATFORM_NAME', 'M&E Platform'), 'primary' => '#0f5132', ...],
'tenant_cache_ttl' => 60, // seconds
```
Local (Herd): `PLATFORM_DOMAIN=mne.test`; dnsmasq resolves `*.test`, and Herd/Valet serves
`{anything}.mne.test` from the `mne` site directory — no per-tenant vhost needed.
Production: `PLATFORM_DOMAIN=me.<client>.gov.ng` + wildcard DNS + wildcard TLS.

### 3.2 `app/Tenancy/TenantHostResolver.php`
```php
public function __construct(private readonly string $baseDomain) {}
/** @return string|null null = apex host */
public function subdomainFor(string $host): ?string;   // throws UnknownHostException
```
Rules: strip port, `Str::lower()`, trim trailing dot. `$host === base` → `null`.
`str_ends_with($host, '.'.$base)` → the prefix. Otherwise throw (→404).
**Reject multi-label prefixes** (`a.b.mne.test`) — exactly one DNS label or 404. Also register
`Route::pattern('tenant', '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?')` so Laravel's default domain-param
regex (which matches dots) cannot swallow `evil.works.mne.test`.

### 3.3 `app/Http/Middleware/ResolveTenant.php`
```php
public function handle(Request $request, Closure $next): Response
```
1. `$slug = $resolver->subdomainFor($request->getHost())`; `null` or in `reserved_subdomains` → `abort(404)`.
2. `$tenant = Cache::remember("tenant:slug:{$slug}", config('platform.tenant_cache_ttl'), fn () => Tenant::query()->where('slug',$slug)->first())`.
   Bust in `TenantObserver::saved/deleted`. TTL stays 60s so a suspension takes effect fast.
3. Not found / soft-deleted → `abort(404)` (never leak existence).
4. `is_active === false` → `abort(503, view('errors.tenant-suspended'))` — staff need to know it is
   suspended, not broken; the subdomain already reveals existence.
5. `$currentTenant->set($tenant)`; `URL::defaults(['tenant' => $tenant->slug])` so `route()` works
   without passing the domain param; `$request->route()->forgetParameter('tenant')` so controller
   signatures stay clean; share `$tenant` to views via `View::share('currentTenant', $tenant)`.
6. Octane safety: `TenancyServiceProvider` listens to `RequestTerminated` → `CurrentTenant::forget()`.

**Surface distinction is by route group, not by sniffing** — `oversight.` and apex/`www` groups are
registered *before* the wildcard group, so a literal `oversight.` host never reaches `ResolveTenant`.
The reserved-subdomain check in step 1 is defence in depth.

---

## 4. Route architecture + session strategy

### 4.1 `bootstrap/app.php`
```php
->withRouting(using: function (): void {
    $domain    = config('platform.domain');
    $oversight = config('platform.oversight_subdomain');

    // ORDER MATTERS: literal hosts before the wildcard group.
    Route::domain("{$oversight}.{$domain}")->middleware('oversight')
        ->group(base_path('routes/oversight.php'));

    Route::domain("www.{$domain}")->middleware('portal')
        ->group(fn () => Route::any('{any?}', fn (Request $r) =>
            redirect()->away('https://'.$domain.$r->getRequestUri(), 301))->where('any', '.*'));

    Route::domain($domain)->middleware('portal')->group(base_path('routes/portal.php'));

    Route::domain('{tenant}.'.$domain)->middleware('tenant')
        ->group(base_path('routes/tenant.php'));
}, health: '/up')
```
`www` is a 301 to apex (also do it at Nginx) so portal routes/names are registered exactly once.

### 4.2 Middleware groups — spelled out, not nested
Nesting `'web'` inside a group would place `SubstituteBindings` *before* `ResolveTenant`, so route-model
binding of tenant-owned models would run unbound and throw. Write the stacks explicitly:
```php
$middleware->group('tenant', [
    EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class,
    ShareErrorsFromSession::class, ValidateCsrfToken::class,
    ResolveTenant::class,            // BEFORE bindings
    SubstituteBindings::class,
    ApplyTenantBranding::class,      // CSS custom properties from tenant_settings
]);
$middleware->group('oversight', [
    EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class,
    ShareErrorsFromSession::class, ValidateCsrfToken::class, SubstituteBindings::class,
    EnsureOversightAccess::class,    // auth + membership-free, permission `oversight.access`
    RequireStepUpAuth::class,        // 2FA freshness for admin surface
]);
$middleware->group('portal', [
    EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class,
    ShareErrorsFromSession::class, ValidateCsrfToken::class, SubstituteBindings::class,
]);
$middleware->alias([
    'tenant.member' => EnsureTenantMembership::class,
    'role' => RoleMiddleware::class, 'permission' => PermissionMiddleware::class,
]);
```
`routes/tenant.php` wraps authenticated routes in `->middleware(['auth','verified','tenant.member'])`.

### 4.3 Session
- **One guard (`web`), one user provider.** Per-surface guards would break legitimate multi-MDA users
  and add auth surface for no gain; separation is enforced by roles + membership, not by guard.
- `SESSION_DOMAIN=.${PLATFORM_DOMAIN}` (wildcard) — required: a consultant on two MDAs and an
  oversight officer drilling into a workspace must not re-login per subdomain.
- Hardening that makes the wildcard acceptable: `SESSION_SECURE_COOKIE=true`, `HttpOnly`,
  `SESSION_SAME_SITE=lax`, CSP on app surfaces, `SESSION_COOKIE={instance-slug}_session`,
  `SESSION_DRIVER=redis`, short `SESSION_LIFETIME` (e.g. 120) plus `RequireStepUpAuth` on oversight
  (re-prompt 2FA if `session('oversight_verified_at')` older than `config('platform.stepup_ttl', 30)` min).
- Session data is shared; **surface authority is not**. Every oversight route carries an
  `oversight.*` permission; every tenant route carries `tenant.member`.

---

## 5. spatie/laravel-permission (teams mode)

`config/permission.php`: `'teams' => true`, `'column_names.team_foreign_key' => 'tenant_id'`,
`cache.expiration_time` 24h, `cache.store` redis.
Team id is set **only** by `CurrentTenant::set()/forget()/runFor()` — never called ad hoc.
Do **not** add an FK on `model_has_roles.tenant_id` (it participates in spatie's composite PK and
complicates their migration); index it and rely on `cascadeOnDelete` cleanup in `DeleteTenant`.

**Role definitions are global (`roles.tenant_id = null`); the *assignment* carries the tenant.**
spatie supports null-team roles usable in any team. This avoids 40 tenants × 4 roles of duplicated
rows and permission-sync drift on deploy.

| Role | Assignment scope | Surface |
|---|---|---|
| `super_admin` | global (team null) | oversight |
| `state_me_admin` | global | oversight |
| `executive_viewer` | global | oversight |
| `data_quality_reviewer` | global | oversight |
| `mda_admin` | per-tenant | tenant |
| `me_officer` (focal) | per-tenant | tenant |
| `consultant` | per-tenant | tenant |
| `field_monitor` | per-tenant | tenant |
| `stakeholder` | global | portal |

Permission naming `{domain}.{action}`: `tenants.manage`, `users.invite`, `oversight.access`,
`oversight.dashboard.view`, `projects.view|create|update`, `reports.submit|review|approve|consolidate`,
`indicators.validate`, `feedback.moderate`. Seeder: `database/seeders/RolePermissionSeeder.php`,
idempotent (`Permission::findOrCreate`, `Role::findOrCreate`), runs with `setPermissionsTeamId(null)`.

**No `Gate::before` super-admin bypass** (security rule). Super admin is a permission set.
Policy base `app/Policies/Concerns/ChecksTenantOwnership.php`:
```php
protected function permits(User $user, string $permission, ?Model $model = null): bool
{
    if (! $user->hasPermissionTo($permission)) return false;          // team-aware via registrar
    if ($model === null) return true;
    return app(CurrentTenant::class)->check()
        && (int) $model->tenant_id === app(CurrentTenant::class)->id(); // permission AND tenant match
}
```
Cross-tenant oversight reads bypass the *scope*, never the *policy*: `app/Actions/Oversight/*` checks
`oversight.*` permissions, then `Model::withoutTenancy()`.

---

## 6. Queued-job tenancy propagation

**Baseline is automatic, not per-job** — because the jobs that leak most are framework-owned
(`SendQueuedNotifications`, `CallQueuedListener`, medialibrary conversions) and cannot use our trait.
In `TenancyServiceProvider::boot()`:
```php
Queue::createPayloadUsing(fn () => ['tenant_id' => app(CurrentTenant::class)->id()]);
Queue::before(function (JobProcessing $e): void {
    $id = $e->job->payload()['tenant_id'] ?? null;
    $id ? app(CurrentTenant::class)->setById((int) $id) : app(CurrentTenant::class)->forget();
});
Queue::after(fn () => app(CurrentTenant::class)->forget());
Queue::exceptionOccurred(fn () => app(CurrentTenant::class)->forget());
Queue::looping(fn () => app(CurrentTenant::class)->forget());   // belt and braces per worker loop
```
Because the fail-closed scope throws when unbound, a job that *should* have been tenant-scoped but was
dispatched from an unbound context fails loudly in the worker instead of reading another tenant's rows.

Optional explicitness for domain jobs: `app/Jobs/Concerns/TenantAware.php` exposing
`public ?int $tenantId` + `middleware(): array { return [new BindTenant]; }` — use only where a job must
run for a tenant *other* than the dispatcher's (e.g. oversight fan-out).

Scheduled/system commands iterate explicitly:
`Tenant::query()->where('is_active', true)->cursor()->each(fn ($t) => app(CurrentTenant::class)->runFor($t, fn () => ...));`

Notifications: `notifications.tenant_id` is filled from the payload-bound tenant in a
`DatabaseNotification` observer; the bell feed filters `tenant_id = current` on tenant surfaces and
`whereNull('tenant_id')` on oversight. Mail/SMS templates read branding from `SettingsRepository`
for the bound tenant, so a queued mail cannot render tenant A's logo on tenant B's message.

---

## 7. Test harness

`tests/Pest.php`:
```php
uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class)->in('Feature');
function tenant(array $attrs = []): Tenant;                       // Tenant::factory()
function onTenant(Tenant|string $t, string $path = '/'): string;  // "http://works.mne.test/path"
function oversightUrl(string $path = '/'): string;
function portalUrl(string $path = '/'): string;
```
`tests/Concerns/InteractsWithTenancy.php` (used by `TestCase`):
```php
protected function actingAsRole(string $role, ?Tenant $tenant = null, array $attrs = []): User;
// creates user, tenant_user row (status=active) when $tenant, setPermissionsTeamId($tenant?->id),
// assignRole($role), $this->actingAs($user); returns the user.
protected function withTenant(Tenant $t): static;                 // binds CurrentTenant (unit tests)
protected function tenantGet(Tenant $t, string $uri): TestResponse;
```
`config('platform.domain')` is forced to `mne.test` in `phpunit.xml` so URLs are deterministic.

Reusable isolation helper (`tests/Support/Isolation.php`):
```php
function itIsolatesTenants(string $model, string $routeName, string $attribute = 'title'): void
```
Asserts, for tenants A and B: (1) `Tenancy::runFor($a, fn () => $model::count()) === 1`;
(2) index page on A's host shows A's record and not B's; (3) detail route with B's ULID from A's host
→ 404 (not 403 — no existence leak); (4) export/download route likewise; (5) `$model::factory()` inside
`runFor($b)` never writes `tenant_id = $a->id`. Every tenant-owned model registers one line in its
feature test file. Ship it in Phase 0 so Phase 1 models inherit it for free.

**SQLite in-memory (default) vs MySQL 8 (production)** — features to avoid in migrations:
- No `enum()` columns → `string` + PHP enum cast (already our standard).
- No `->after()` / `->change()` on existing columns in shared migrations; keep migrations additive.
- No `fulltext()` indexes; no `spatial`/`point` columns → store `decimal(10,7)` lat / `decimal(10,7)` lng.
- No generated columns (`virtualAs`/`storedAs`), no JSON path indexes, no `dropForeign` (SQLite).
- No reliance on MySQL's case-insensitive collation: lowercase slugs/emails before querying.
- No raw SQL with MySQL-only functions in Phase 0 roll-ups.
- CI runs the suite twice: SQLite (fast, every push) **and** MySQL 8 service (required before merge) —
  money `decimal(18,2)`, JSON casts and index semantics must be proven on the real engine.

---

## 8. Migration order (exact)

```
0001_01_01_000000_create_users_table                  (framework: users, password_reset_tokens, sessions)
0001_01_01_000001_create_cache_table
0001_01_01_000002_create_jobs_table
2026_08_01_000100_create_tenants_table
2026_08_01_000110_create_settings_table               (instance scope)
2026_08_01_000120_create_tenant_settings_table        (FK → tenants)
2026_08_01_000130_add_platform_columns_to_users_table (public_id, phone, is_active, home_tenant_id, softDeletes)
2026_08_01_000140_create_tenant_user_table            (FKs → tenants, users)
2026_08_01_000150_create_invitations_table            (tenant_id nullable = oversight invite; token, role, expires_at)
2026_08_01_000200_create_permission_tables            (spatie; team key tenant_id, no FK)
2026_08_01_000210_add_two_factor_columns_to_users     (Fortify)
2026_08_01_000300_create_activity_log_table           (spatie ×3: base, event column, batch uuid)
2026_08_01_000330_add_tenant_id_to_activity_log_table (nullable + index)
2026_08_01_000400_create_notifications_table          (+ nullable tenant_id + composite index)
2026_08_01_000410_create_media_table                  (medialibrary)
2026_08_01_000420_add_tenant_id_to_media_table        (nullable + index)
```
Constraints: `tenants` precedes every `tenant_id` FK; `users` is altered before `tenant_user`;
spatie permission tables come after `tenants` only for readability (no FK dependency); media/activity
log get `tenant_id` **now** so Phase 1+ never backfills a hot table.

---

## Top 3 risks

1. **Wildcard session cookie crosses surfaces.** `SESSION_DOMAIN=.{domain}` means one stolen cookie is
   valid on `oversight.` too. Accepted for UX (multi-MDA users), mitigated by HttpOnly + CSP + secure
   cookies + short lifetime + step-up 2FA on oversight + per-surface permission checks. If a client
   contract forbids it, the fallback is host-only cookies + a signed cross-subdomain SSO handoff
   route — design that now, build it only if demanded.
2. **Fail-closed scope meets unbound contexts.** Queue workers, artisan commands, seeders, Octane
   request reuse and scheduled roll-ups will throw `TenantNotResolvedException` if tenancy is not
   bound. The dangerous failure mode is a developer "fixing" it by making the scope a no-op. Controls:
   one sanctioned API (`runFor`/`bypass`), `Queue::createPayloadUsing` + `Queue::before` baseline,
   Octane `RequestTerminated` reset, and the architecture tests in §2.
3. **Package readiness on Laravel 13 / PHP 8.4.** spatie/laravel-permission (teams), activitylog,
   medialibrary, Livewire 3 and Fortify must all resolve on L13 before Phase 0 starts — permission
   teams mode is load-bearing for the entire authorization design and has no cheap substitute.
   Verify constraints in one `composer require` dry run on day 1; if teams mode is not L13-ready,
   the fallback is pinning to the last compatible tag, not hand-rolling RBAC.
