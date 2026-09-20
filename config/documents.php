<?php

use App\Enums\Role;

/*
|--------------------------------------------------------------------------
| Document & evidence vault
|--------------------------------------------------------------------------
| Every file this platform holds is a government record: an award letter, a
| BOQ, a GPS-stamped site photograph, a signed completion certificate. None of
| them may sit on a public disk, and none of them may be named by the person
| uploading them (rules/security.md §Uploads).
|
| This file is the single place that answers three questions for every upload:
| which disk, which mime types and size, and who may put a file into which
| collection. The permission name (`documents.upload`) only says a user may
| upload SOMETHING — the collection rules below say what.
*/

return [

    /*
    | The private disk every media conversion and original lands on. Never
    | `public`: served files go through a signed, permission-checked route.
    */

    'disk' => env('DOCUMENTS_DISK', 'documents'),

    /*
    | Minutes a signed download URL stays valid. Short enough that a link
    | pasted into a group chat expires, long enough for a slow 3G download.
    */

    'signed_url_minutes' => 15,

    /*
    |--------------------------------------------------------------------------
    | Collections
    |--------------------------------------------------------------------------
    | key => [
    |   label, accept (mime allow-list), max_kb, roles (null = any role that
    |   holds documents.upload), single (replaces rather than appends)
    | ]
    |
    | Mime types are the SERVER-side allow-list — the `accept` attribute in the
    | browser is a convenience, not a control.
    */

    'images' => ['image/jpeg', 'image/png', 'image/webp', 'image/heic'],

    'papers' => [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ],

    'collections' => [
        // Project-level vault
        'project_documents' => [
            'label' => 'Project documents',
            'max_kb' => 20480,
            'roles' => null,
        ],
        'project_photos' => [
            'label' => 'Project photographs',
            'max_kb' => 10240,
            'images_only' => true,
            'roles' => null,
        ],
        // Contract vault — award letter, BOQ, variation instruments
        'contract_documents' => [
            'label' => 'Contract documents',
            'max_kb' => 20480,
            'roles' => [Role::SuperAdmin, Role::MdaAdmin, Role::MeOfficer],
        ],
        // Progress report evidence (progress-reporting.md §5)
        'report_evidence' => [
            'label' => 'Report evidence',
            'max_kb' => 10240,
            'roles' => null,
        ],
        // Inspection evidence — GPS/EXIF metadata is preserved on the original
        'inspection_photos' => [
            'label' => 'Inspection photographs',
            'max_kb' => 10240,
            'images_only' => true,
            'roles' => null,
        ],
        'inspection_documents' => [
            'label' => 'Inspection attachments',
            'max_kb' => 20480,
            'roles' => null,
        ],
        // Governed single-artifact collections
        'commencement_notice' => [
            'label' => 'Commencement notice',
            'max_kb' => 10240,
            'single' => true,
            'roles' => [Role::SuperAdmin, Role::MdaAdmin, Role::MeOfficer],
        ],
        'certificate' => [
            'label' => 'Completion certificate',
            'max_kb' => 10240,
            'single' => true,
            'roles' => [Role::SuperAdmin, Role::MdaAdmin],
        ],
        'evaluation_documents' => [
            'label' => 'Evaluation documents',
            'max_kb' => 20480,
            'roles' => [Role::SuperAdmin, Role::StateAdmin, Role::MdaAdmin, Role::MeOfficer],
        ],
        'issue_evidence' => [
            'label' => 'Issue evidence',
            'max_kb' => 10240,
            'roles' => null,
        ],
    ],

];
