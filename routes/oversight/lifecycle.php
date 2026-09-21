<?php

use App\Livewire\Oversight\Lifecycle\CertificateRegister;
use Illuminate\Support\Facades\Route;

/*
| The state completion register — every certificate issued by every MDA, read
| only (plan §8).
|
| Required inside routes/oversight.php's authenticated group, so `auth`,
| `active`, the oversight role middleware and `2fa.require` already apply. The
| cross-tenant read happens inside App\Actions\Oversight\
| ListCertificatesAcrossTenants, which re-checks `certificates.view` in the
| GLOBAL permission team before any tenancy bypass.
*/

Route::get('/certificates', CertificateRegister::class)->name('certificates.index');
