<?php

/*
|--------------------------------------------------------------------------
| Livewire overrides
|--------------------------------------------------------------------------
| Only the keys this platform must not take on default. Everything else
| comes from vendor/livewire/livewire/config/livewire.php — Laravel merges
| package config at the TOP level, so any key present here replaces the
| package's whole sub-array and must therefore be given in full.
*/

return [

    /*
    | Temporary upload endpoint. Livewire's default leaves it open to anyone
    | holding the signed URL and rate-limited only. On a platform whose
    | uploads are site photographs and award letters, an unauthenticated
    | endpoint that writes to our storage is not acceptable: `auth` and
    | `active` are added, so a signed URL captured from a page is useless
    | once the account behind it is deactivated.
    |
    | `rules` is the outer envelope (any file, 20MB ceiling); the real
    | per-collection mime and size checks happen server-side in
    | App\Actions\Documents\AttachDocument against config/documents.php.
    */

    'temporary_file_upload' => [
        'disk' => env('LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK'),
        'rules' => ['required', 'file', 'max:20480'],
        'directory' => null,
        'middleware' => ['auth', 'active', 'throttle:60,1'],
        'preview_mimes' => ['png', 'gif', 'bmp', 'svg', 'jpg', 'jpeg', 'webp'],
        'max_upload_time' => 5,
        'cleanup' => true,
    ],

];
