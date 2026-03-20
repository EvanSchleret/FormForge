<p align="center">
  <img src=".github/assets/banner.png" alt="FormForge banner" width="100%" />
</p>

<h1 align="center">FormForge</h1>

<p align="center">
  Deterministic dynamic forms for Laravel with immutable revisions, strict validation,
  configurable HTTP security, and file upload workflows.
</p>

<p align="center">
  <a href="https://packagist.org/packages/evanschleret/formforge"><img src="https://img.shields.io/packagist/v/evanschleret/formforge?label=packagist" alt="Packagist Version" /></a>
  <a href="https://packagist.org/packages/evanschleret/formforge"><img src="https://img.shields.io/packagist/dt/evanschleret/formforge" alt="Packagist Downloads" /></a>
  <a href="https://packagist.org/packages/evanschleret/formforge"><img src="https://img.shields.io/packagist/l/evanschleret/formforge" alt="License" /></a>
  <img src="https://img.shields.io/badge/PHP-%3E%3D8.2-777BB4" alt="PHP >= 8.2" />
  <img src="https://img.shields.io/badge/Laravel-12.x%20%7C%2013.x-FF2D20" alt="Laravel 12.x | 13.x" />
</p>

## Why FormForge

FormForge gives you a single backend source of truth for:

- form schema and field rules
- immutable revisions and version history
- submission validation and storage
- configurable HTTP auth/middleware per endpoint
- managed/direct/staged file workflows

## Requirements

- PHP `>=8.2`
- Laravel `12.x` or `13.x`
- Note: Laravel `13.x` requires PHP `>=8.3` (Laravel framework requirement)

## Installation

```bash
composer require evanschleret/formforge
```

Publish package files:

```bash
php artisan formforge:install
```

Run migrations:

```bash
php artisan migrate
```

## Basic usage (runtime DSL)

```php
<?php

declare(strict_types=1);

use EvanSchleret\FormForge\Facades\Form;

Form::define('contact')
    ->title('Contact')
    ->version('1')
    ->category('contact')
    ->text('name')->required()->max(120)
    ->email('email')->required()
    ->textarea('message')->required();

Form::sync();
```

Get a resolved form schema:

```php
$form = Form::get('contact');
$schema = $form->toArray();
```

Submit payload:

```php
$submission = $form->submit([
    'name' => 'Evan',
    'email' => 'evan@example.com',
    'message' => 'Hello',
]);
```

## HTTP API

Default prefix: `api/formforge/v1`

### Schema endpoints

- `GET /forms/{key}`
- `GET /forms/{key}/versions`
- `GET /forms/{key}/versions/{version}`

### Submission endpoints

- `POST /forms/{key}/submit`
- `POST /forms/{key}/versions/{version}/submit`

### Staged upload endpoints

- `POST /forms/{key}/uploads/stage`
- `POST /forms/{key}/versions/{version}/uploads/stage`

### Management endpoints (production-ready)

- `POST /forms` (create draft, key auto-generated)
- `PATCH /forms/{key}` (creates a new draft revision)
- `POST /forms/{key}/publish` (creates a new published revision)
- `POST /forms/{key}/unpublish` (creates a new draft revision)
- `DELETE /forms/{key}` (soft delete all revisions for this key)
- `GET /forms/{key}/revisions` (history; supports `include_deleted=1`)
- `GET /forms/{key}/diff/{fromVersion}/{toVersion}`

### Management payload notes

- `POST /forms` expects at least:
  - `title` (string)
  - `fields` (array)
- `key` is auto-generated from title (slug + collision suffix)
- revision numbers are auto-incremented integers (`1`, `2`, `3`, ...)

### Publishability rule

A form can be published only when:

- `title` is not empty
- `fields` has at least one field

## Endpoint security model

Security is layered:

1. Global route middleware: `formforge.http.middleware`
2. Endpoint-level options (`schema`, `submission`, `upload`, `management`):
   - `auth`: `public|optional|required`
   - `guard`: auth guard name
   - `middleware`: dynamic middleware stack
   - `ability` and per-action `abilities` (management)

Example:

```php
'http' => [
    'middleware' => ['api'],
    'management' => [
        'auth' => 'required',
        'guard' => 'sanctum',
        'middleware' => ['throttle:30,1'],
        'ability' => null,
        'abilities' => [
            'create' => 'formforge.create',
            'update' => 'formforge.update',
            'publish' => 'formforge.publish',
            'unpublish' => 'formforge.unpublish',
            'delete' => 'formforge.delete',
            'revisions' => 'formforge.read-revisions',
            'diff' => 'formforge.read-diff',
        ],
    ],
],
```

You can also inject custom middleware aliases via Laravel router middleware registration and then use them in FormForge config.

## Idempotency

Management mutations support the `Idempotency-Key` header.

- Same key + same payload: replayed response
- Same key + different payload: `409 Conflict`

TTL is configurable:

```php
'http' => [
    'idempotency' => [
        'ttl_minutes' => 1440,
    ],
],
```

## Validation rules

Field rules are deterministic:

- auto-rules by field type
- `rules(...)` appends custom rules
- `replaceRules(...)` overrides auto-rules completely

Laravel rules from API payload are string/array based.

Reference: [Laravel validation rules](https://laravel.com/docs/validation#available-validation-rules)

## Upload strategy

`uploads.mode` supports:

- `managed`: multipart file in submit request
- `direct`: payload references existing files
- `staged`: upload first, then submit JSON with `upload_token`

Cleanup command for expired staged tokens:

```bash
php artisan formforge:uploads:cleanup
php artisan formforge:uploads:cleanup --dry-run
php artisan formforge:uploads:cleanup --keep-files
```

## Useful commands

```bash
php artisan formforge:install
php artisan formforge:sync
php artisan formforge:list
php artisan formforge:describe contact
php artisan formforge:http:options
php artisan formforge:http:routes
php artisan formforge:http:resolve contact --endpoint=submission --form-version=1
php artisan formforge:uploads:cleanup
```

## Laravel Boost AI assets

FormForge now ships package-level Boost assets for AI-assisted implementation:

- `resources/boost/guidelines/core.blade.php`
- `resources/boost/skills/formforge-integration/SKILL.md`

These assets define implementation constraints, recommended workflows, and endpoint/security patterns for AI agents.

## Roadmap

- [ ] Add first-class signed upload URL flow for object storage direct upload
- [ ] Add optional per-form policy hooks for fine-grained authorization
- [ ] Add dry-run migration report command for schema and submission migrations
- [ ] Add cleanup command for expired idempotency keys
- [ ] Add OpenAPI export command for FormForge HTTP endpoints and payload schemas
- [ ] Add policy scaffold command for management abilities

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
