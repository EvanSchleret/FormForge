<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Database
    |--------------------------------------------------------------------------
    |
    | Configure table names and optional connection override used by FormForge.
    | Keep these tables isolated if you want to move forms/submissions to a
    | dedicated database connection.
    |
    */

    'database' => [
        'connection' => env('FORMFORGE_DB_CONNECTION', null),
        'forms_table' => 'formforge_forms',
        'submissions_table' => 'formforge_submissions',
        'submission_files_table' => 'formforge_submission_files',
        'staged_uploads_table' => 'formforge_staged_uploads',
    ],

    /*
    |--------------------------------------------------------------------------
    | Forms
    |--------------------------------------------------------------------------
    |
    | Default metadata automatically applied to form definitions when omitted.
    | - default_category: used for list/filter use-cases (survey, contact, ...)
    | - default_published: when false, submissions can be gated by HTTP config.
    |
    */

    'forms' => [
        'default_category' => env('FORMFORGE_DEFAULT_CATEGORY', 'general'),
        'default_published' => env('FORMFORGE_DEFAULT_PUBLISHED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Submissions
    |--------------------------------------------------------------------------
    |
    | Controls persistence of request context and the test/debug submission mode.
    | Test mode can be enabled per request and is stored on each submission.
    |
    */

    'submissions' => [
        'store_ip' => true,
        'store_user_agent' => true,
        'testing' => [
            'enabled' => env('FORMFORGE_TEST_SUBMISSIONS_ENABLED', true),
            'allow_on_unpublished' => env('FORMFORGE_TEST_ON_UNPUBLISHED', true),
            'flag' => env('FORMFORGE_TEST_FLAG', '_formforge_test'),
            'header' => env('FORMFORGE_TEST_HEADER', 'X-FormForge-Test'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    |
    | Unknown field handling:
    | - true: reject payload keys not present in schema
    | - false: ignore unknown keys before persistence
    |
    */

    'validation' => [
        'reject_unknown_fields' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Uploads
    |--------------------------------------------------------------------------
    |
    | Upload modes:
    | - managed: files are sent with the submit request and stored by Laravel
    | - direct: submit payload contains already-uploaded file references
    | - staged: upload first via stage endpoint, then submit JSON with tokens
    |
    */

    'uploads' => [
        'mode' => env('FORMFORGE_UPLOAD_MODE', 'managed'),
        'disk' => env('FORMFORGE_UPLOAD_DISK', config('filesystems.default')),
        'directory' => env('FORMFORGE_UPLOAD_DIRECTORY', 'formforge'),
        'visibility' => env('FORMFORGE_UPLOAD_VISIBILITY', 'private'),
        'preserve_original_filename' => env('FORMFORGE_UPLOAD_PRESERVE_FILENAME', false),

        'temporary_disk' => env('FORMFORGE_UPLOAD_TEMP_DISK', config('filesystems.default')),
        'temporary_directory' => env('FORMFORGE_UPLOAD_TEMP_DIRECTORY', 'formforge/tmp'),
        'temporary_ttl_minutes' => env('FORMFORGE_UPLOAD_TEMP_TTL', 1440),

        'staged' => [
            'require_same_user' => env('FORMFORGE_STAGED_REQUIRE_SAME_USER', true),
        ],

        'direct' => [
            'signature_ttl_seconds' => env('FORMFORGE_DIRECT_SIGNATURE_TTL', 900),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP API
    |--------------------------------------------------------------------------
    |
    | Global route settings and per-endpoint auth/middleware rules.
    | Auth mode accepts: public, optional, required.
    |
    */

    'http' => [
        'enabled' => true,
        'prefix' => 'api/formforge/v1',
        'middleware' => ['api'],
        'schema' => [
            'public' => true,
            'auth' => 'public',
            'guard' => null,
            'middleware' => [],
            'require_published' => false,
        ],
        'submission' => [
            'auth' => 'public',
            'guard' => null,
            'middleware' => ['throttle:60,1'],
            'require_published' => true,
        ],
        'upload' => [
            'auth' => 'required',
            'guard' => null,
            'middleware' => ['throttle:60,1'],
            'require_published' => false,
        ],
    ],
];
