<?php

use App\Livewire\Oversight\Audit\AuditLog;
use App\Livewire\Oversight\Notifications\NotificationCentre;
use App\Livewire\Oversight\Settings\InstanceSettings;
use App\Livewire\Oversight\Settings\NotificationPreferences;
use App\Livewire\Oversight\Tenancy\TenantDirectory;
use App\Livewire\Oversight\Tenancy\TenantOnboarding;
use App\Livewire\Oversight\Tenancy\TenantSettings;
use Illuminate\Support\Facades\Route;

/*
| Platform administration (plan module map §1 and §11): the MDA workspace
| register and onboarding wizard, instance settings, the append-only audit
| trail, and the notification centre. Required inside routes/oversight.php's
| authenticated group, so auth, active, the oversight role gate and
| 2fa.require already apply.
|
| `/entities/create` before `/entities/{tenant:slug}` — "create" is a valid
| single DNS label and would otherwise bind as a tenant slug and 404.
|
| The binding is `{tenant:slug}`, matching /portfolio/{tenant:slug}: the slug
| IS the workspace's public identity on this platform (it is the subdomain),
| and passing a tenant_id to a slug binding is a 404 this project has already
| shipped once.
*/
Route::get('/entities', TenantDirectory::class)->name('entities.index');
Route::get('/entities/create', TenantOnboarding::class)->name('entities.create');
Route::get('/entities/{tenant:slug}', TenantSettings::class)->name('entities.show');

Route::get('/settings', InstanceSettings::class)->name('settings.index');

// Read-only by construction: the activity log is append-only and no screen
// may edit or delete a record in it (rules/security.md).
Route::get('/audit', AuditLog::class)->name('audit.index');

Route::get('/notifications', NotificationCentre::class)->name('notifications.index');

// Same path as the workspace copy, so a shared link (the account menu, the
// notification centre) works on both app surfaces.
Route::get('/settings/notifications', NotificationPreferences::class)->name('settings.notifications');
