# Phase 0 — Auth Layer Design (build checklist)

Builds on the implemented tenancy core (`app/Tenancy/`, `ResolveTenant`, `TenantSafeBuilder`,
`AssignRole`, `Role` enum, TrustHosts). Fortify-native, no new packages.
Session strategy is **host-only cookies** (`SESSION_DOMAIN` unset) — confirmed and load-bearing below.

---

## 0. Config changes required first

`config/fortify.php`:
- **Remove `Features::registration()`.** Public self-registration on a government platform is a
  defect: anyone who can reach a subdomain could mint an account. Access is invitation-only.
  `CreateNewUser` stops being a Fortify hook and becomes a collaborator of `AcceptInvitation`.
- **Add `Features::emailVerification()`** and `implements MustVerifyEmail` on `User`. Invited users
  are stamped `email_verified_at` at acceptance (the token proves the address), so they never see the
  notice — this satisfies security.md's "mandatory email verification for staff" and keeps the
  `verified` middleware live for any future self-service path.
- `'home' => '/home'` is dead — delete the constant's use by binding the response contracts (§5.5).
- `'middleware' => ['web', ResolveSurface::class]` (§1.2).
- Passkeys (§1.4): `'relying_party_id' => config('platform.domain')`, and `allowed_origins` populated
  at runtime by `ResolveSurface`.

`config/platform.php` — new `auth` block:
```php
'auth' => [
    'invitation_ttl_days'   => env('PLATFORM_INVITATION_TTL_DAYS', 7),
    'two_factor_grace_days' => env('PLATFORM_2FA_GRACE_DAYS', 7),
    'password_min_length'   => 12,
    'check_compromised_passwords' => env('PLATFORM_CHECK_COMPROMISED_PASSWORDS', true),
],
```
`PasswordValidationRules` → `Password::min(config('platform.auth.password_min_length'))->letters()
->mixedCase()->numbers()->symbols()` plus `->uncompromised()` gated on the config flag (the HIBP call
must be switchable off — deployments behind restrictive government egress will otherwise fail closed
on password changes; off in tests).

---

## 1. Per-surface auth

### 1.1 Decision: one domain-less Fortify registration + a surface gate
Fortify's routes stay registered once (`fortify.domain = null`), so they exist on every host our
TrustHosts regex admits. Duplicating them per surface group would mean either three route-name
namespaces (breaking `route('login')` inside Fortify's own redirects) or `Fortify::ignoreRoutes()` and
hand-maintaining Fortify's route file forever. Instead a single middleware decides *which* auth
routes each surface may serve.

### 1.2 `app/Http/Middleware/ResolveSurface.php`
Runs inside `fortify.middleware` (and is cheap enough to also prepend to the three route groups).
```php
public function handle(Request $request, Closure $next): Response
```
1. `$surface = app(SurfaceResolver::class)->for($request->getHost());` → `App\Enums\Surface`
   (`Portal|Oversight|Tenant`) + optional slug. Host parsing is safe because TrustHosts already
   rejects anything outside `^([a-z0-9-]{1,63}\.)?{domain}$`.
2. Bind `App\Tenancy\CurrentSurface` (scoped singleton) so views/responses can branch without
   re-parsing the host.
3. `Surface::Tenant` → resolve + bind the tenant with the same lookup `ResolveTenant` uses (extract
   it into `App\Tenancy\TenantLocator::bySlug(string $slug): ?Tenant` and have **both** middlewares
   call it — one lookup rule, one place to add caching). Unknown/inactive → 404.
4. Enforce the per-surface Fortify route allowlist (§1.3); denied → 404 (or 302 for reset, below).
5. `config(['fortify.passkeys.allowed_origins' => [$request->schemeAndHttpHost()]])` (§1.4).

`App\Enums\Surface` also carries `dashboardRoute()` and `label()` — used by the login responses.

### 1.3 Route allowlist per surface
| Fortify route group | Tenant `{slug}.` | Oversight `oversight.` | Portal apex |
|---|---|---|---|
| `login`, `logout`, `two-factor.login` | allow | allow | **404** (Phase 0) |
| passkey login/registration | allow | allow | 404 |
| `password.confirm(ation)`, 2FA management, profile/password update | allow (auth'd) | allow (auth'd) | 404 |
| `password.request`, `password.email`, `password.reset`, `password.update` | **302 → apex** | **302 → apex** | **allow** |
| `register`, `user-registration` | 404 (feature removed) | 404 | 404 |
| `invitations.show/accept` (ours, §3) | allow | allow | allow |

**Password reset lives only on the apex.** Reason: reset mail is queued, and Fortify builds the reset
URL inside `toMail()` in the worker, where there is no request host — the link would silently fall
back to `APP_URL` and land users on the wrong host with a CSRF/session mismatch. Pinning reset to a
single deterministic host (`https://{platform.domain}/reset-password/...`) removes the entire bug
class, needs no per-user host inference, and costs one redirect. After a successful reset the user is
sent to the workspace switcher (§5.5), which deep-links them to the right subdomain to sign in.

### 1.4 Passkeys are the answer to host-only cookies
Set `relying_party_id` to the registrable domain (`config('platform.domain')`). A credential
registered once is then usable on **every** subdomain — one biometric touch per surface, no shared
cookie, no cross-site session. `allowed_origins` cannot enumerate wildcard subdomains statically, so
`ResolveSurface` writes the current request origin into it; this is safe *only* because TrustHosts
guarantees the host is ours — if TrustHosts is ever loosened, this becomes an origin-confusion hole.
Comment that dependency in both files.

### 1.5 Host-only cookie implications (confirmed)
- A session on `works.{domain}` is not sent to `health.{domain}` or `oversight.{domain}`.
  **Users authenticate once per surface they use.** Accepted deliberately: it means an XSS or stolen
  cookie in one MDA workspace cannot be replayed against oversight, which is the failure mode that
  matters most here.
- CSRF tokens, `remember_web_*` cookies and 2FA challenge state are all per host. `SESSION_COOKIE`
  stays a single name; the host scoping does the isolation.
- Mitigations, in order of impact: (1) **passkeys** (§1.4) — one credential, all hosts;
  (2) **remember-me** enabled with Laravel's default long-lived token so re-login per host is rare;
  (3) **workspace switcher** at `/{apex}/workspaces` and in the tenant sidebar, listing the user's
  active memberships and deep-linking to `https://{slug}.{domain}/login?email={their-email}` so the
  form is pre-filled (the email comes from an authenticated session, so it leaks nothing).
- **Deferred, designed, not built:** a signed single-use cross-host handoff
  (`POST /auth/handoff` → 60s signed URL → target host logs the user in). It is a login-by-URL and
  therefore a standing risk; build it only if per-host login demonstrably hurts, and only with
  TTL ≤ 60s, single-use cache key, membership re-check on the target, and an activity-log entry.

### 1.6 Portal / stakeholder auth: **later**
Phase 0 portal is anonymous and read-only. Feedback (Phase 3) launches anonymous + rate-limited +
honeypot. If stakeholder accounts arrive, they use the **same `web` guard** and the global
`stakeholder` role — a second guard would fork the auth surface for no isolation gain, since
authority already comes from roles and membership. Trigger to revisit: the first requirement that a
citizen must see something not published to everyone.

---

## 2. Membership: `tenant_user` + `EnsureTenantMembership`

### 2.1 Migration `2026_08_02_100000_create_tenant_user_table`
```php
Schema::create('tenant_user', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->string('status', 20)->default('active');     // MembershipStatus: active|suspended
    $table->foreignId('invited_by_id')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamp('joined_at')->nullable();
    $table->timestamp('suspended_at')->nullable();
    $table->timestamps();
    $table->unique(['tenant_id', 'user_id']);
    $table->index(['user_id', 'status']);               // workspace switcher lookup
});
```
No soft deletes: revocation is `status = suspended` (the row *is* the audit record; deleting it would
erase the fact that access once existed). `App\Enums\MembershipStatus` backs the column.

### 2.2 Model `App\Models\TenantMembership` — **deliberately NOT `BelongsToTenant`**
This is the one table where the trait is wrong: membership is the *gate that decides tenancy*, and a
gate scoped by its own outcome is circular. The workspace switcher and every login response must read
"which tenants may this user enter?" from an **unbound** context. Compensating controls:
- All reads go through two Actions and nowhere else:
  `App\Actions\Iam\ListUserWorkspaces` (by user, unscoped by design) and
  `App\Actions\Iam\ListTenantMembers` (explicitly `where('tenant_id', CurrentTenant::idOrFail())`).
- Add both to the tenancy-discipline test's `where('tenant_id'` allowlist, with the reason inline.
- `TenantMembership` is never exposed by route-model binding; member screens bind the `User`.

### 2.3 `app/Http/Middleware/EnsureTenantMembership.php`
Registered as alias `tenant.member`, applied **after** `auth` in `routes/tenant.php`.
```php
public function handle(Request $request, Closure $next): Response
```
- `$tenant = app(CurrentTenant::class)->getOrFail();` (ResolveTenant ran already; unbound = bug = 500).
- Look up the membership for `(tenant, auth()->id())`.
- Missing, or `status !== active` → **403** rendering `errors.no-workspace-access`, which lists the
  workspaces the user *does* have (via `ListUserWorkspaces`) and a link to each.
- **403 vs 404, settled:** *surface*-level denial is 403 and informative — the workspace's existence
  is already public via DNS, the user is authenticated, and a 404 here just generates support tickets
  in a ministry. *Record*-level denial stays 404 and silent (a project ULID from another tenant must
  not be confirmable). Write this rule in the middleware docblock; it will be cited in every later
  module review.
- **Oversight roles get no backdoor.** A StateAdmin visiting `works.{domain}` without a membership row
  is 403 like anyone else. Cross-MDA reading happens on the oversight surface through
  `withoutTenancy()`. A supervised "inspect workspace" mode (impersonation, audited, time-boxed) is a
  Phase 2 feature, not an implicit exemption — implicit exemptions are how tenancy models rot.

### 2.4 Hard gate vs soft gate
- **Membership = hard gate.** Route-level, binary: you are in this workspace or you are not.
- **Team-scoped roles = soft gate.** What you may *do* once inside. Unchanged from the tenancy core:
  `AssignRole` puts tenant roles in the tenant team, oversight roles in `GLOBAL_TEAM`.
- Both are required. They are separate because they answer different questions and change at
  different times: revoking a consultant's access (membership) must not require unpicking roles, and
  a role change must not silently grant entry.
- **`AssignRole` stays single-purpose.** Composition happens in
  `App\Actions\Iam\GrantTenantAccess(User $user, Tenant $tenant, Role $role, ?User $invitedBy)` —
  one transaction: upsert membership (`active`, `joined_at`) then `AssignRole`. Mirror:
  `RevokeTenantAccess(User, Tenant)` → status `suspended` + `removeRole` within `runAs($tenant)`.
  A membership without a role is possible but never produced by these actions (empty dashboard).

---

## 3. Invitations

### 3.1 Migration `2026_08_02_100100_create_invitations_table`
```php
Schema::create('invitations', function (Blueprint $table) {
    $table->id();
    $table->ulid('ulid')->unique();                                    // admin-facing id
    $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete(); // null = oversight invite
    $table->string('email');
    $table->string('role', 50);                                        // Role enum value
    $table->string('token_hash', 64)->unique();                        // sha256(plaintext); plaintext never stored
    $table->foreignId('invited_by_id')->constrained('users')->restrictOnDelete();
    $table->timestamp('expires_at');
    $table->timestamp('accepted_at')->nullable();
    $table->foreignId('accepted_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamp('revoked_at')->nullable();
    $table->timestamps();
    $table->index(['tenant_id', 'email']);
});
```
- `tenant_id` nullable (oversight invites have no tenant) ⇒ **no `BelongsToTenant`**; sanctioned
  exception #2, same allowlist treatment as §2.2. Reads go through the invitation Actions.
- **No unique index on `(tenant_id, email, accepted_at)`** — nullable columns defeat SQL UNIQUE
  (NULLs compare distinct), so it would enforce nothing while looking like it did. "One pending
  invitation per (tenant, email)" is enforced in `InviteUser`, which revokes any live invite for that
  pair inside the same transaction, and proven by a test.
- No soft deletes: `revoked_at` / `accepted_at` are the lifecycle; rows are permanent audit.

### 3.2 Lifecycle
`pending → accepted` | `pending → revoked` | `pending → expired` (derived, not stored: `expires_at < now()`).
Terminal states are terminal — an accepted or revoked invitation is never reopened; issue a new one.

### 3.3 Actions (`app/Actions/Iam/`)
| Action | Signature | Authorization |
|---|---|---|
| `InviteUser` | `__invoke(User $inviter, string $email, Role $role, ?Tenant $tenant = null): Invitation` | `users.invite`; if `$tenant`, inviter needs an **active membership** in it; `$role` must be in `Role::invitableBy($inviterRoles)` |
| `AcceptInvitation` | `__invoke(string $plaintextToken, array $profile): User` | guest; token is the credential |
| `RevokeInvitation` | `__invoke(User $actor, Invitation $invitation): void` | `users.invite` + same tenant (or oversight for `tenant_id = null`) |
| `ResendInvitation` | `__invoke(User $actor, Invitation $invitation): Invitation` | as revoke; **rotates the token** and resets `expires_at`; throttled 3/hour per invitation |

`Role::invitableBy()` encodes PROJECT_PLAN §2's chain — SuperAdmin/StateAdmin may invite the four
oversight roles **and** `MdaAdmin` (tenant-scoped); `MdaAdmin` may invite `MeOfficer`, `Consultant`,
`FieldMonitor` only. An MDA admin cannot mint another MDA admin, and no tenant role can invite
upward. Put it on the enum next to `oversightRoles()`/`tenantRoles()` so the whole role contract
reads in one file.

`AcceptInvitation` internals — one `DB::transaction`:
1. `Invitation::where('token_hash', hash('sha256', $token))->lockForUpdate()->first()`; abort 404 if
   missing (never distinguish "wrong token" from "no such invite").
2. Reject if `accepted_at`/`revoked_at` set or `expires_at` past → 410 with a "request a new invite" page.
3. Find user by email or create via `CreateNewUser` (password rules apply); stamp
   `email_verified_at = now()` — the token proved the address.
4. `$tenant ? GrantTenantAccess(...) : AssignRole($user, $role)`.
5. Stamp `accepted_at`, `accepted_user_id`; log activity (actor = new user, subject = invitation).
6. `event(new Registered($user))`, then log the user in on the **current host only**.

### 3.4 Notification + URLs
`App\Notifications\Iam\UserInvited` (queued; mail + database). The acceptance URL is built from
**config, not the request**, so it is identical in a worker: `App\Support\SurfaceUrl::invitation(?Tenant $t, string $token)`
→ `https://{slug|oversight}.{platform.domain}/invitations/{token}`. Apply the same helper anywhere a
queued notification needs a link — this is the general fix for the reset-link class of bug (§1.3).
Routes: `GET /invitations/{token}` + `POST /invitations/{token}` on tenant and oversight groups,
guest-only, `throttle:10,1`, token never logged.

---

## 4. 2FA policy

- **Mandatory:** `SuperAdmin`, `StateAdmin`, `MdaAdmin`. Optional (encouraged) for the rest.
  Expressed as `Role::requiresTwoFactor(): bool` on the enum.
- **Enforcement point: middleware `RequireTwoFactor`**, not a Fortify action. Fortify's pipeline only
  reacts to 2FA that is *already enabled*; forcing *enrolment* is an authorization rule that must
  apply to every request — including sessions that predate the policy and users promoted mid-session.
  Add to the oversight stack and the authenticated tenant stack (after `tenant.member`).
- Behavior: if any role held **in the current team context** requires 2FA and
  `two_factor_confirmed_at` is null → redirect to `two-factor.setup` with a warning. Always-allowed
  routes: 2FA setup/confirm/QR/recovery, `password.confirm`, `logout`, the 403 page.
- **Grace period.** New column `users.two_factor_required_at` (migration §7), stamped by `AssignRole`
  the moment a 2FA-required role is granted (and cleared when the last such role is removed). Grace =
  `two_factor_required_at + config('platform.auth.two_factor_grace_days')`. Inside the window the
  user passes with a persistent countdown banner; outside it they are hard-redirected to setup.
  **Grace is 0 for `SuperAdmin`** — the platform operator enrols before anything else.
  Anchoring on the role grant (not `created_at`) is what makes promotion-mid-life work correctly.
- **Exemption.** Column `users.two_factor_exempted_at`, written only by
  `App\Actions\Iam\ExemptFromTwoFactor`: the actor needs global `users.manage` and may never act on
  their own account; an actor-less call is the platform itself and is accepted only from the console
  (seeders, artisan). A set value releases the account from the mandate — no redirect, no countdown,
  setup page shown as a voluntary visit — and touches no second factor the user has enrolled.
  Both directions are activity-logged. The demo seeder exempts the Platform Admin so a fresh install
  does not open on the wizard; production exemptions are a deliberate, audited state-level act.
  **Residual, accepted while there is no UI writer:** the exemption never expires, a StateAdmin can
  strip the mandate from a SuperAdmin, and `UserDirectory` shows no exemption column — so it is
  invisible on screen. Surface it as a chip and decide on an expiry before shipping any UI that
  writes it.
- **Recovery codes:** Fortify default (8, regenerable, shown once at confirmation, downloadable as
  `.txt`). Regeneration sits behind `password.confirm`. Log an activity entry on generation,
  regeneration and *use* of a code — a recovery-code login on an admin account is exactly the event
  an auditor will ask about.
- `config('auth.password_timeout')` → 900 (15 min) so password confirmation is re-prompted on the
  admin surfaces rather than lasting three hours.

---

## 5. Login hardening

### 5.1 Rate limits (`FortifyServiceProvider`, extend the existing limiters)
- `login` returns **two** limits: `Limit::perMinute(5)->by(lower(email).'|'.ip.'|'.host)` and
  `Limit::perMinutes(60, 20)->by(ip)`. Including the host in the key stops one MDA's brute force from
  locking a user out of every other workspace. On the oversight host tighten the first to 3/min.
- `two-factor` 5/min by `login.id` (already present); `passkeys` 10/min (present).
- Invitation acceptance 10/min per IP; `ResendInvitation` 3/hour per invitation.
- Portal endpoints get their own limiter when the portal grows write paths (Phase 3).

### 5.2 Lockout policy
Throttling only — **no permanent account lock**, because an attacker who knows a Permanent
Secretary's email could otherwise deny them access before a budget deadline. Compensate with
detection: listen for `Illuminate\Auth\Events\Lockout` → activity-log entry (email, IP, host, UA) and,
after the second lockout in an hour, a queued "someone is trying to sign in to your account" mail.

### 5.3 `is_active` gate
`app/Http/Middleware/EnsureAccountIsActive.php` (alias `active`) on all authenticated stacks:
`is_active === false` → `Auth::logout()`, invalidate session, redirect to login with a neutral
"account is not active — contact your administrator" message. One mechanism covers both login-time and
deactivation-mid-session, which a login-pipeline check would miss. Soft-deleted users cannot
authenticate at all — the Eloquent user provider applies the `SoftDeletes` scope — assert this in a
test rather than trusting it silently.

### 5.4 `last_login_at`
Listener on `Illuminate\Auth\Events\Login` (fires for password, remember-me, passkey and post-2FA
logins alike — the only point that catches all four):
`$event->user->updateQuietly(['last_login_at' => now(), 'last_login_ip' => request()->ip()])`.
`updateQuietly` keeps it out of the activity log as a model change; the *login event itself* is logged
separately with actor/IP/host/surface. Adds column `users.last_login_ip` (§7).

### 5.5 Redirect after login
Bind Fortify's response contracts in `FortifyServiceProvider` (`app/Http/Responses/`):
`LoginResponse`, `TwoFactorLoginResponse`, `LogoutResponse`, `PasswordResetResponse`,
`FailedTwoFactorLoginResponse`. `LoginResponse` branches on `CurrentSurface`:
| Surface | Destination | Fallback |
|---|---|---|
| Tenant | `route('tenant.dashboard')` | no active membership → 403 `no-workspace-access` (lists their workspaces) |
| Oversight | `route('oversight.dashboard')` | no oversight role → 403 + link to workspace switcher |
| Portal (apex) | `route('portal.workspaces')` — the switcher | no memberships & no oversight role → "no access yet" page |
Honour `intended()` first, but **only when the intended URL's host matches the current host** —
otherwise a cross-host `intended` becomes an open-redirect-shaped hole.
Role-specific landings (ExecutiveViewer → scorecard) belong to Phase 1 dashboards, not to auth: one
dashboard route per surface, which then branches by role.

---

## 6. Test matrix (Pest, real subdomains)

`tests/Feature/Auth/` — every test hits `tenantUrl()/oversightUrl()/portalUrl()` from `tests/Pest.php`.
New helpers: `memberOf(User $u, Tenant $t, Role $r)`, `invitationFor(...)`, and
`actingAsRoleOn(Role $r, Tenant $t)` (creates user + membership + role, then `actingAs`).

| File | Cases |
|---|---|
| `SurfaceRoutingTest` | `/register` is 404 on all three surfaces; `/login` 200 on tenant + oversight, 404 on apex; `/forgot-password` 200 on apex, 302→apex from tenant + oversight; unknown subdomain 404 |
| `MembershipTest` | member of A reaches A's dashboard; **same user gets 403 on B** (the isolation case); suspended membership → 403; no membership → 403 listing their workspaces; StateAdmin without membership → 403 on a tenant host; unauthenticated → redirect to that host's login |
| `MembershipModelTest` (Unit) | `GrantTenantAccess` writes membership + role in one transaction; `RevokeTenantAccess` suspends and removes the role without deleting the row; `ListTenantMembers` in tenant A never returns tenant B's members |
| `InvitationTest` | happy path oversight→MdaAdmin and MdaAdmin→Consultant; token stored hashed (`assertDatabaseMissing` on plaintext); expired → 410; revoked → 410; **reused token → 410 and no second membership**; token from tenant A rejected on tenant B's host; `MdaAdmin` cannot invite `MdaAdmin`/oversight roles; second invite to same email revokes the first; acceptance stamps `email_verified_at`; `UserInvited` URL host matches the target surface (queued, asserted with `Notification::fake()`) |
| `TwoFactorPolicyTest` | MdaAdmin without confirmed 2FA inside grace → dashboard 200 + banner; past grace → redirect to setup; SuperAdmin gets no grace; MeOfficer never forced; setup/logout routes reachable while blocked; `two_factor_required_at` stamped by `AssignRole` and cleared on removal (`Carbon::setTestNow`) |
| `LoginHardeningTest` | 6th attempt in a minute → 429; throttle key is per host (lockout on A still allows B); `is_active = false` → logged out + neutral message; soft-deleted user cannot authenticate; `last_login_at`/`last_login_ip` set on password, remember-me, passkey and post-2FA logins; `Lockout` writes an activity entry |
| `RedirectAfterLoginTest` | one case per surface × role (the authorization matrix): tenant member → tenant dashboard; oversight role on oversight → oversight dashboard; tenant-only user on oversight → 403; apex login-less flow → switcher; cross-host `intended` is ignored |
| `PasswordResetTest` | reset link host is always the apex; link works with no prior session; post-reset redirect is the switcher; reset does not create a session on any tenant host |

Discipline tests (extend the existing tenancy test): `Features::registration()` absent from
`config/fortify.php`; `where('tenant_id'` allowlist limited to `ListTenantMembers` + invitation
Actions; no `Gate::before` anywhere in `app/`.

---

## 7. Migration order (appended to Phase 0)

```
2026_08_02_100000_create_tenant_user_table
2026_08_02_100100_create_invitations_table          (FKs → tenants, users)
2026_08_02_100200_add_auth_columns_to_users_table   (two_factor_required_at, last_login_ip)
```
All three depend only on `tenants` + `users`, both already migrated. Nothing here is MySQL-specific;
no `after()` on the new table columns, `string` over `enum`, no nullable-unique indexes.

---

## Top 3 risks

1. **`ResolveSurface` writing `fortify.passkeys.allowed_origins` from the request origin.** It is
   correct *only* while TrustHosts pins the Host header to our domain. Anyone loosening TrustHosts
   (a health-check probe, a load balancer, a staging alias) silently converts this into origin
   confusion for WebAuthn. Cross-reference the dependency in both files and cover it with a test that
   asserts an off-domain Host is rejected before reaching Fortify.
2. **403-vs-404 drift.** The surface-level 403 (§2.3) is a deliberate, informative exception to the
   otherwise-silent 404 rule. The first module that copies the 403 pattern to a *record* lookup leaks
   cross-tenant existence. The docblock and a `MembershipTest` case are the guard; every future
   module review should re-check it.
3. **Membership and roles drifting apart.** They are separate gates by design, so a bug that grants
   one without the other is invisible until someone either can't work or can work where they
   shouldn't. `GrantTenantAccess`/`RevokeTenantAccess` must be the *only* writers — direct
   `TenantMembership::create()` or bare `AssignRole` calls in later modules are the regression to
   watch for, and belong in the discipline test alongside the existing `assignRole()` ban.
