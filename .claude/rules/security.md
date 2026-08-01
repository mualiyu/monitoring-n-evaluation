# Security Rules

Government project + financial data. Assume hostile scrutiny and public-records sensitivity.

- **AuthN:** Laravel Breeze/Fortify-style auth with mandatory email verification for staff,
  optional 2FA (TOTP) — required for oversight/admin roles. Session lifetime short for
  admin surfaces. Password policy: 12+ chars. Rate-limit login + all public endpoints.
- **AuthZ:** every route behind a Policy or explicit permission middleware. No
  `Gate::before` super-admin shortcut that bypasses tenancy checks — super admin is a
  permission set, not a scope bypass.
- **Uploads:** validate mime + size server-side; images re-encoded via medialibrary
  conversions; documents stored on private disk, served through signed, permission-checked
  routes — never from `public/`. No user-controlled filenames on disk.
- **Mass assignment:** `$guarded = []` is banned; use explicit `$fillable`. Form Requests
  whitelist input; never `->all()` into `create()`/`update()`.
- **Output:** Blade `{{ }}` only; `{!! !!}` requires a sanitized source and a comment.
  User-generated content (feedback, challenge descriptions) is always escaped.
- **Secrets:** only in `.env` (never committed, never read by tooling). Config access via
  `config()`, never `env()` outside config files.
- **Audit trail is append-only.** Activity log records actor, tenant, IP, before/after.
  No feature may edit or delete audit records; retention is a config concern.
- **Public portal is read-only** and serves pre-aggregated/published data only — a
  publishing step (explicit MDA/oversight approval) gates what becomes public. Feedback
  submission is rate-limited + spam-protected (honeypot + optional CAPTCHA).
- **Headers/transport:** HTTPS-only with HSTS (wildcard cert for subdomains), secure
  cookies, CSP for app surfaces, X-Frame-Options deny except where dashboards are
  intentionally embeddable via signed URLs.
- **Backups:** nightly encrypted DB + media backups (spatie/laravel-backup) with restore
  drills documented. This is a contractual expectation for government clients.
