<?php

use App\Http\Controllers\Documents\DownloadDocumentController;
use Illuminate\Support\Facades\Route;

/*
| Document vault downloads (rules/security.md §Uploads). Signed, authenticated
| and policy-checked: three gates on every government record that leaves the
| platform. Bound by uuid, never by the auto-increment media id — the media
| table carries no tenant_id, so an id here would be an enumeration handle
| over every MDA's evidence. MediaPolicy answers tenancy through the owning
| record, which loads under its own global scope.
|
| This file is required inside routes/tenant.php's authenticated group, so
| `auth`, `active`, `tenant.member` and `2fa.require` already apply.
*/
Route::get('/documents/{media:uuid}/download', DownloadDocumentController::class)
    ->middleware('signed')
    ->name('documents.download');
