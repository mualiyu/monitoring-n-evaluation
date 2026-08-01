# Architecture Rules

- **MVC + thin domain layer.** Controllers stay thin: validate (Form Request), call an
  Action/Service, return a response. Business logic lives in `app/Actions/{Domain}/` as
  single-purpose invokable classes (e.g. `App\Actions\Monitoring\SubmitProgressReport`).
  Livewire components may call Actions directly but never contain business rules themselves.
- **Modules are folders by domain, not by type.** Organize under domain namespaces:
  `Projects`, `Monitoring`, `Evaluation`, `Indicators`, `Tenancy`, `Reporting`, `Feedback`,
  `Portal`. Models stay in `app/Models` (Laravel convention) but everything else
  (Actions, Livewire components, Policies, Notifications) groups by domain.
- **Three route groups, three entry surfaces:**
  1. `routes/oversight.php` — apex + `oversight.` subdomain: state-level admin, cross-MDA
     dashboards, tenant/user management.
  2. `routes/tenant.php` — `{tenant}.` wildcard subdomain: MDA workspace (MDA staff,
     M&E officers, consultants assigned to that MDA).
  3. `routes/portal.php` — public transparency portal + stakeholder feedback (no auth or
     lightweight stakeholder auth).
- **State machines for lifecycle fields.** Project status, report status, inspection stage
  are enum-backed state machines (`spatie/laravel-model-states` or native enums + guarded
  transitions in Actions). Never assign raw status strings in controllers/components.
- **Everything auditable.** All create/update/delete on domain models logs via
  activitylog. Evidence (photos, documents) attaches via medialibrary with GPS/EXIF metadata
  preserved.
- **Notifications are queued and preference-aware.** Use Laravel notifications with
  database + mail channels; SMS/WhatsApp channels are added behind the same abstraction.
- **No premature APIs.** Livewire covers interactivity. The versioned JSON API
  (`routes/api.php`) is introduced only when the Phase-3 offline PWA needs it.
- **White-label constraint.** No hard-coded state names, logos, colors, or domains in code
  or Blade. Branding, domain, and terminology come from tenant/instance config
  (`config/platform.php` + `tenants`/`settings` tables). If you type a state's name outside
  seeders/tests/docs, you are doing it wrong.
