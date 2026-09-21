<?php

use App\Livewire\Tenant\Lifecycle\CertificateRegister;
use App\Livewire\Tenant\Lifecycle\CertifyProject;
use App\Livewire\Tenant\Lifecycle\ProjectCommencement;
use Illuminate\Support\Facades\Route;

/*
| Commencement notices + completion certification (plan §8, digest §8 steps
| 1 and 5).
|
| This file is required inside routes/tenant.php's authenticated group, so
| `auth`, `active`, `tenant.member` and `2fa.require` already apply — it must
| therefore declare no middleware and open no group of its own. Every
| component authorizes in mount() AND on each mutating method regardless:
| route middleware does not protect a Livewire update POST by itself.
|
| `{project}` resolves by ULID through the TenantScope, so another MDA's
| public id is a 404 rather than a leak.
*/

Route::get('/certificates', CertificateRegister::class)->name('certificates.index');

Route::get('/projects/{project}/commencement', ProjectCommencement::class)->name('projects.commencement');
Route::get('/projects/{project}/certify', CertifyProject::class)->name('projects.certify');
