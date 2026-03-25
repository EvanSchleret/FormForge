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
        'idempotency_keys_table' => 'formforge_idempotency_keys',
        'drafts_table' => 'formforge_drafts',
        'automation_runs_table' => 'formforge_submission_automation_runs',
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
    | Drafts
    |--------------------------------------------------------------------------
    |
    | Draft persistence settings used by HTTP draft endpoints.
    | Drafts are tied to authenticated users and form keys.
    |
    */

    'drafts' => [
        // Enable draft persistence by default for forms that do not override drafts.enabled in schema.
        'default_enabled' => env('FORMFORGE_DRAFTS_DEFAULT_ENABLED', false),
        // Number of days before an inactive draft expires. Set to 0 to disable expiration.
        'ttl_days' => env('FORMFORGE_DRAFTS_TTL_DAYS', 30),
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
            // Restrict test submissions to these environments.
            'enabled_environments' => ['local', 'testing'],
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
    | Submission Automations
    |--------------------------------------------------------------------------
    |
    | Runtime automations are registered in code (ServiceProvider/boot logic),
    | not in configuration. This section only controls infrastructure behavior.
    |
    */

    'automations' => [
        'enabled' => true,
        'queue' => [
            'enabled' => true,
            'connection' => null,
            'name' => null,
        ],
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
        'resources' => [
            // Optional JsonResource class for submission payloads.
            // Example: App\Http\Resources\FormForgeSubmissionResource::class
            'submission' => null,
            // Optional JsonResource class for submitted_by relation.
            // Example: App\Http\Resources\UserResource::class
            'submitter' => null,
            // File URL enrichment for payload/files metadata returned by default resource.
            'file_urls' => [
                'enabled' => env('FORMFORGE_HTTP_FILE_URLS_ENABLED', false),
                'temporary' => env('FORMFORGE_HTTP_FILE_URLS_TEMPORARY', true),
                'ttl_seconds' => env('FORMFORGE_HTTP_FILE_URLS_TTL', 900),
                'key' => env('FORMFORGE_HTTP_FILE_URL_KEY', 'url'),
            ],
        ],
        'idempotency' => [
            'ttl_minutes' => env('FORMFORGE_HTTP_IDEMPOTENCY_TTL', 1440),
        ],
        'resolve' => [
            'auth' => 'public',
            'guard' => null,
            'middleware' => [],
            // Resolve endpoint is intended for local/debug tooling by default.
            'enabled_environments' => ['local', 'testing'],
        ],
        'schema' => [
            'public' => true,
            'auth' => 'public',
            'guard' => null,
            'middleware' => [],
            'require_published' => false,
        ],
        'draft' => [
            'auth' => 'required',
            'guard' => null,
            'middleware' => ['throttle:60,1'],
            // Optional Gate ability applied to draft save/current/delete endpoints.
            'ability' => null,
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
        'management' => [
            'auth' => 'public',
            'guard' => null,
            'middleware' => ['throttle:60,1'],
            'ability' => null,
            'abilities' => [
                'index' => null,
                'create' => null,
                'update' => null,
                'publish' => null,
                'unpublish' => null,
                'delete' => null,
                'revisions' => null,
                'diff' => null,
                'responses' => null,
                'response' => null,
                'response_delete' => null,
                'drafts' => null,
            ],
        ],
    ],
];
