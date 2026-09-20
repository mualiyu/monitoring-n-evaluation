# Local Testing Guide

All local domains are served by Laravel Herd. Every seeded user's password is **`password`**.
Reset the demo state anytime with `php artisan migrate:fresh --seed`.

## Domains

| URL | Surface | Auth |
|---|---|---|
| http://mne.test | Public transparency portal | none (read-only) |
| http://mne.test/styleguide | Design-system preview (local only) | none |
| http://oversight.mne.test | State oversight workspace | oversight roles only |
| http://works.mne.test | Ministry of Works & Infrastructure workspace | members only |
| http://health.mne.test | Ministry of Health workspace | members only |
| http://ghost.mne.test | (any unknown subdomain) | → 404, by design |

## Test users

### Oversight — sign in at http://oversight.mne.test/login
| Email | Role | 2FA behaviour |
|---|---|---|
| `admin@mne.test` | Super Admin | **Exempt from 2FA setup** (seeded via `ExemptFromTwoFactor`; the role itself has zero grace) |
| `state@mne.test` | State M&E Director | 7-day grace: dashboard works, countdown banner shows |
| `governor@mne.test` | Executive Viewer | 2FA optional |

### Ministry of Works — sign in at http://works.mne.test/login
| Email | Role |
|---|---|
| `mda-admin@works.mne.test` | MDA Admin (2FA grace banner) |
| `me-officer@works.mne.test` | M&E Officer |
| `consultant@works.mne.test` | Consultant |
| `field-monitor@works.mne.test` | Field Monitor |

### Ministry of Health — sign in at http://health.mne.test/login
Same pattern: `mda-admin@health.mne.test`, `me-officer@health.mne.test`,
`consultant@health.mne.test`, `field-monitor@health.mne.test`.

## What to test, feature by feature

1. **Public portal** — open http://mne.test. No login, honest zero-counters (nothing is
   published yet), transparency messaging. `/styleguide` shows every UI component.
2. **Tenant-branded login** — http://works.mne.test/login: note the tab title
   "Sign in · Ministry of Works & Infrastructure". Log in as `me-officer@works.mne.test`.
   You land on the MDA dashboard (KPI tiles are placeholder figures until the Projects
   screens land).
3. **Tenant isolation (the big one)** — while signed in at works, visit
   http://health.mne.test. You get a **403 that lists your real workspaces** — not
   Health's data, and not a confusing 404. Sign in at health as
   `mda-admin@health.mne.test` in another browser to see the reverse.
4. **No oversight backdoor** — sign in at http://oversight.mne.test/login as
   `consultant@works.mne.test`: authentication succeeds but you get 403 — a tenant
   role grants nothing on the oversight surface. Then try `state@mne.test` — you get
   the denser oversight shell with the "State-level oversight" chip.
5. **Mandatory 2FA** — the demo Platform Admin is exempt, so `admin@mne.test` lands on
   the dashboard. To see the forced path, lift the exemption in `php artisan tinker`:
   `(new App\Actions\Iam\ExemptFromTwoFactor)(null, App\Models\User::where('email','admin@mne.test')->first(), false);`
   then sign in at oversight: you are hard-redirected to the 3-step 2FA wizard (Super
   Admin has no grace). Walk it with Google/Microsoft Authenticator: QR scan (or manual
   secret), code confirm, recovery codes with copy/download. Sign out and back in → TOTP
   challenge appears. The wizard is also reachable voluntarily at `/two-factor/setup`
   while exempt.
6. **2FA grace banner** — sign in at works as `mda-admin@works.mne.test`: dashboard
   works, but a "setup required by {date}" banner shows (7-day grace from role grant).
7. **Workspace switcher** — http://works.mne.test/workspaces lists every workspace your
   account belongs to (seeded users belong to one each; invite one into both to see more).
8. **Login hardening** — fail the password 6 times inside a minute → 429 throttle.
   The lockout is recorded in the `activity_log` table. Try `works` then `health`:
   the throttle is per-host, so one workspace's lockout doesn't block another.
9. **Invitation-only registration** — `/register` is 404 on every surface. Invitations
   are issued in code for now (UI comes with the module screens):
   `php artisan tinker` →
   `(new App\Actions\Iam\InviteUser)(App\Models\User::where('email','mda-admin@works.mne.test')->first(), 'newperson@example.com', App\Enums\Role::Consultant, App\Models\Tenant::where('slug','works')->first());`
   The invite email lands in `storage/logs/laravel.log` (default `MAIL_MAILER=log`) —
   or point Herd's Mailpit at it with `MAIL_MAILER=smtp`, `MAIL_HOST=127.0.0.1`,
   `MAIL_PORT=2525` in `.env` to view it at http://localhost:8025. Open the acceptance
   URL from the mail: name + password form, then straight into the works workspace.
10. **Password reset lives on the apex** — http://works.mne.test/forgot-password
    redirects to http://mne.test/forgot-password (deliberate: reset links are built in
    queue workers and must have one deterministic host). Reset mail also lands in the
    log/Mailpit.
11. **Register a project** — http://works.mne.test/projects/create as
    `mda-admin@works.mne.test` or `me-officer@works.mne.test`. Three steps;
    every dropdown (sector, funding source, LGA, ward, manager) submits the
    row's id. A consultant gets 403 here by design. The dashboard's "Register
    project" button is the shortcut into it.
12. **Edit a project** — open any project, then "Edit details" in the header
    (or the row menu on the index). One page, three panels. On a **certified**
    project the scope, money and date fields render locked with the reason
    stated, while the project manager and reporting frequency stay editable —
    that is the certification freeze, not a bug.
13. **Oversight portfolio drill-down** — http://oversight.mne.test/portfolio as
    `state@mne.test`, then click an entity row. It lands on
    `/portfolio/works`, keyed by slug.
14. **Seeded project data** (Actions layer, via tinker):
    `php artisan tinker` →
    `App\Tenancy\CurrentTenant::class` … quickest look:
    `app(App\Tenancy\CurrentTenant::class)->runAs(App\Models\Tenant::where('slug','works')->first(), fn () => App\Models\Project::with('locations','contracts.contractor')->get(['id','title','status','physical_progress']))`
    10 projects across the two MDAs: multi-site, co-funded, contract-variation and
    cross-MDA-supervised cases included.

## Developer checks

- `vendor/bin/pest` — 858 tests (tenancy isolation, auth matrix, state machines,
  money, rendered-form option values)
- `vendor/bin/pint --test` + `php -d memory_limit=1G vendor/bin/phpstan analyse`
- `php artisan migrate:fresh --seed` — rebuild the demo state
- Emails: `storage/logs/laravel.log` (or Mailpit as in item 9)
