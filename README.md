<p align="center">
  <img src=".github/assets/banner.png" alt="FormForge banner" width="100%" />
</p>

<h1 align="center">FormForge</h1>

<p align="center">
  Deterministic dynamic forms for Laravel, with strict validation, immutable schemas,
  JSON-first submission flows, and staged file uploads.
</p>

<p align="center">
  <a href="https://packagist.org/packages/evanschleret/formforge"><img src="https://img.shields.io/packagist/v/evanschleret/formforge?label=packagist" alt="Packagist Version" /></a>
  <a href="https://packagist.org/packages/evanschleret/formforge"><img src="https://img.shields.io/packagist/dt/evanschleret/formforge" alt="Packagist Downloads" /></a>
  <a href="https://packagist.org/packages/evanschleret/formforge"><img src="https://img.shields.io/packagist/l/evanschleret/formforge" alt="License" /></a>
  <img src="https://img.shields.io/badge/PHP-%3E%3D8.2-777BB4" alt="PHP >= 8.2" />
  <img src="https://img.shields.io/badge/Laravel-12.x%20%7C%2013.x-FF2D20" alt="Laravel 12.x | 13.x" />
</p>

## Why this package

FormForge gives you one backend source of truth for form definitions, validation, and persistence.

It is built for:

- deterministic schema export
- immutable versioning
- strict server-side validation
- frontend interoperability (for example with a future FormForge client)
- robust file upload workflows, including JSON-first staged uploads

## Requirements

- PHP `>=8.2`
- Laravel `12.x` or `13.x`
- Note: Laravel `13.x` requires PHP `>=8.3` (Laravel framework requirement)

## Installation

```bash
composer require evanschleret/formforge
```

Install/publish package files:

```bash
php artisan formforge:install
```

Run migrations:

```bash
php artisan migrate
```

## Basic usage

Define forms in PHP:

```php
<?php

declare(strict_types=1);

use EvanSchleret\FormForge\Facades\Form;

Form::define('registration')
    ->title('Registration')
    ->category('survey')
    ->published()
    ->version('1')
    ->text('name')
        ->fieldKey('applicant.name')
        ->label('Full name')
        ->required()
        ->max(120)
    ->email('email')
        ->required()
    ->select('role')
        ->options([
            ['value' => 'admin', 'label' => 'Admin'],
            ['value' => 'member', 'label' => 'Member'],
        ])
        ->required();
```

Persist runtime definitions:

```bash
php artisan formforge:sync
```

Resolve and export schema:

```php
$form = Form::get('registration', '1');

$schemaArray = $form->toArray();
$schemaJson = $form->toJson();
```

## HTTP API

Default prefix: `api/formforge/v1`

Endpoints:

- `GET /api/formforge/v1/forms/{key}`
- `GET /api/formforge/v1/forms/{key}/versions`
- `GET /api/formforge/v1/forms/{key}/versions/{version}`
- `POST /api/formforge/v1/forms/{key}/submit`
- `POST /api/formforge/v1/forms/{key}/versions/{version}/submit`
- `POST /api/formforge/v1/forms/{key}/uploads/stage`
- `POST /api/formforge/v1/forms/{key}/versions/{version}/uploads/stage`

## Upload strategy

FormForge supports 3 modes:

- `managed`: file content goes in the submit request
- `direct`: submit payload contains file references
- `staged`: upload first, then submit JSON with an `upload_token`

For Nuxt/JSON-first clients, use `staged` mode.

Stage file (multipart):

```bash
curl -X POST http://localhost/api/formforge/v1/forms/registration/uploads/stage \
  -F "field=resume" \
  -F "file=@/tmp/resume.pdf"
```

Submit JSON payload with token:

```json
{
  "payload": {
    "name": "Evan",
    "resume": {
      "upload_token": "upl_xxx"
    }
  }
}
```

## Test submissions and publication lifecycle

Form lifecycle:

- `->published()` / `->unpublished()` on form definitions
- optional publication gating on schema/submission/upload endpoints

Test submissions:

- enabled with `submissions.testing.enabled`
- request-level activation via:
  - body flag (default): `_formforge_test=true`
  - header (default): `X-FormForge-Test: true`
- stored with `is_test=true` and submission `meta`

## Field types

- `text`
- `textarea`
- `email`
- `number`
- `select`
- `select_menu`
- `radio`
- `checkbox`
- `checkbox_group`
- `switch`
- `date`
- `time`
- `datetime`
- `date_range`
- `datetime_range`
- `file`

## Validation rules

FormForge automatically compiles Laravel validation rules from each field type, then applies any custom rules you define.

Global behavior:

- `rules(...)`: appends rules on top of auto-generated rules (merge + deduplicate)
- `replaceRules(...)`: fully replaces auto-generated rules
- final validation runs through `Illuminate\\Validation\\Validator`

Main auto-generated rules:

- `text`, `textarea`: `string`
- `email`: `string`, `email`
- `number`: `numeric` (+ `min/max/multiple_of` from field settings)
- `checkbox`, `switch`: `boolean`
- `checkbox_group`: `array` (+ option validation)
- `select`, `select_menu`, `radio`: option validation (`Rule::in(...)`)
- `date`: `date_format:Y-m-d`
- `time`: `date_format:H:i:s`
- `datetime`: `date`
- `date_range`, `datetime_range`: `array` shape + `start/end` + `after_or_equal`
- `file`:
  - `managed` mode: `file` / `array` + `extensions`, `mimetypes`, `max` constraints
  - `direct`/`staged` modes: metadata validation (`upload_token` or `path`, `disk`, `size`, etc.)

Laravel rules support:

- You can use standard Laravel rules in `rules(...)` or `replaceRules(...)`.
- Official reference: [Laravel validation rules](https://laravel.com/docs/validation#available-validation-rules)

## Schema guarantees

- deterministic field ordering
- deterministic `field_key` generation when omitted
- immutable persisted versions
- strict validation against resolved schema version
- unknown field handling via config (`reject` or `ignore`)

## Configuration

Publish config:

```bash
php artisan vendor:publish --provider="EvanSchleret\\FormForge\\FormForgeServiceProvider" --tag=formforge-config
```

Published file:

- `config/formforge.php`

### Published default config

```php
<?php

declare(strict_types=1);

return [
    'database' => [
        'connection' => env('FORMFORGE_DB_CONNECTION', null),
        'forms_table' => 'formforge_forms',
        'submissions_table' => 'formforge_submissions',
        'submission_files_table' => 'formforge_submission_files',
        'staged_uploads_table' => 'formforge_staged_uploads',
    ],
    'forms' => [
        'default_category' => env('FORMFORGE_DEFAULT_CATEGORY', 'general'),
        'default_published' => env('FORMFORGE_DEFAULT_PUBLISHED', true),
    ],
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
    'validation' => [
        'reject_unknown_fields' => true,
    ],
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
```

## Useful commands

```bash
php artisan formforge:install
php artisan formforge:sync
php artisan formforge:list
php artisan formforge:list --category=survey --published=yes
php artisan formforge:describe registration
php artisan formforge:http:options
php artisan formforge:http:routes
php artisan formforge:http:resolve registration --endpoint=submission --form-version=1
php artisan formforge:uploads:cleanup
php artisan formforge:uploads:cleanup --dry-run
php artisan formforge:uploads:cleanup --keep-files
```

## Roadmap

- [ ] Integrate Laravel Boost support for AI-first package usage (`resources/boost/guidelines/core.blade.php`)
- [ ] Add FormForge Boost skill docs for implementation workflows (`resources/boost/skills/formforge-integration/SKILL.md`)
- [ ] Publish AI-ready contracts (`docs/ai`) with canonical request/response and error payload examples
- [ ] Add optional Laravel MCP integration blueprint with secure defaults (`auth`, middleware, tool allowlist)
- [ ] Add first-class signed upload URL flow for object storage direct upload
- [ ] Add optional per-form policy hooks for fine-grained authorization
- [ ] Add migration tooling between schema versions (`v1 -> v2`)
- [ ] Add submission migration DSL (`rename`, `default`, `drop`, `transform`)
- [ ] Add dry-run report command for schema and submission migrations
- [ ] Add idempotency key support for submission endpoints to prevent duplicates
- [ ] Add per-form/per-endpoint rate-limit presets in config and HTTP resolver
- [ ] Add hookable file security scanning pipeline before final file persistence
- [ ] Add submission lifecycle events/webhooks (`submitted`, `validated`, `failed`)
- [ ] Add OpenAPI export for FormForge HTTP endpoints and payload schemas

## Other packages

If you want to explore more of my Laravel packages:

- [evanschleret/lara-mjml](https://github.com/EvanSchleret/lara-mjml)
- [evanschleret/laravel-user-presence](https://github.com/EvanSchleret/laravel-user-presence)
- [evanschleret/laravel-typebridge](https://github.com/EvanSchleret/laravel-typebridge)

## Open source

- Contributing guide: [CONTRIBUTING.md](CONTRIBUTING.md)
- Security policy: [SECURITY.md](SECURITY.md)
- Code of conduct: [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md)
- License: [LICENSE](LICENSE)
