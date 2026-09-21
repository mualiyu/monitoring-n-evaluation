<?php

use App\Livewire\Tenant\Notifications\NotificationCentre;
use App\Livewire\Tenant\Settings\NotificationPreferences;
use App\Livewire\Tenant\Settings\WorkspaceSettings;
use Illuminate\Support\Facades\Route;

/*
| Workspace settings + the notification centre. Required inside
| routes/tenant.php's authenticated group, so auth, active, tenant.member and
| 2fa.require already apply.
|
| `/settings/notifications` is declared before `/settings` would ever grow a
| wildcard sibling; today neither is parameterised, but the ordering rule
| holds pre-emptively rather than after the first 404.
*/
Route::get('/settings/notifications', NotificationPreferences::class)->name('settings.notifications');
Route::get('/settings', WorkspaceSettings::class)->name('settings.index');

Route::get('/notifications', NotificationCentre::class)->name('notifications.index');
