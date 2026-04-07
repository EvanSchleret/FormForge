<p align="center">
  <img src=".github/assets/banner.png" alt="FormForge banner" width="100%" />
</p>

<h1 align="center">FormForge</h1>

<p align="center">
  Deterministic dynamic forms for Laravel with immutable revisions, conditional layouts,
  strict server-side validation, robust HTTP security, and staged file workflows.
</p>

<p align="center">
  <a href="https://packagist.org/packages/evanschleret/formforge"><img src="https://img.shields.io/packagist/v/evanschleret/formforge?label=packagist" alt="Packagist Version" /></a>
  <a href="https://packagist.org/packages/evanschleret/formforge"><img src="https://img.shields.io/packagist/dt/evanschleret/formforge" alt="Packagist Downloads" /></a>
  <a href="https://packagist.org/packages/evanschleret/formforge"><img src="https://img.shields.io/packagist/l/evanschleret/formforge" alt="License" /></a>
  <img src="https://img.shields.io/badge/PHP-%3E%3D8.2-777BB4" alt="PHP >= 8.2" />
  <img src="https://img.shields.io/badge/Laravel-12.x%20%7C%2013.x-FF2D20" alt="Laravel 12.x | 13.x" />
</p>

## Read this first

This package is feature-rich and can feel heavy at first glance.  
You do not need every feature on day one.

Start with one integration mode and add the rest only when needed:

1. **Code-first only**: use the `Form` facade and skip the HTTP API
2. **Built-in HTTP API**: use the package routes directly
3. **Built-in HTTP API with custom behavior**: override package controllers without rewriting business logic

Online documentation with deeper guides is planned soon.  
For now, this README is intentionally structured to let you ship quickly first, then go deeper.

## Fast path (5 minutes)

1. Install and publish:

```bash
composer require evanschleret/formforge
php artisan formforge:install
php artisan migrate
```

2. Define your first form:

```php
<?php

declare(strict_types=1);

use EvanSchleret\FormForge\Facades\Form;

Form::define('contact')
    ->title('Contact')
    ->version('1')
    ->text('name')->required()
    ->email('email')->required()
    ->textarea('message')->required();

Form::sync();
```

3. Submit server-side:

```php
$submission = Form::get('contact')->submit([
    'name' => 'Evan',
    'email' => 'evan@example.com',
    'message' => 'Hello',
]);
```

At this point, FormForge is already usable without exposing any package HTTP route.

## Why FormForge

FormForge gives you one backend source of truth for:

- form schema with deterministic keys
- pages and conditional visibility rules
- immutable revision history (`create`, `patch`, `publish`, `unpublish`)
- strict payload validation on effective (condition-resolved) schema
- configurable endpoint security (`auth`, `guard`, `middleware`, `ability`)
- file workflows for multipart and JSON-first clients

## Integration modes

Choose one mode first. You can move to another later.

### Mode A: facade only (simplest)

- Define forms in code
- Resolve schema from PHP
- Submit from PHP
- Do not expose package HTTP routes

### Mode B: package HTTP API

- Use routes under `formforge.http.prefix`
- Configure auth/guard/middleware/abilities per endpoint group
- Use ownership resolver if you need owner-scoped management without route context

### Mode C: package HTTP API + scoped routes (recommended for owner URL context)

- Keep package controllers and business logic
- Configure contextual route prefixes in `formforge.http.scoped_routes`
- Resolve owner model automatically from route parameters
- Configure scoped authorization with `policy` or `gate` mode

### Mode D: package HTTP API + custom controllers (advanced)

- Keep package routes and services
- Override selected controller classes in config
- Inject your tenant/owner logic directly where you need it
- Keep method signatures compatible with package controllers

## Requirements

- PHP `>=8.2`
- Laravel `12.x` or `13.x`
- Note: Laravel `13.x` requires PHP `>=8.3` (Laravel framework requirement)

## Installation

```bash
composer require evanschleret/formforge
```

Publish config + migrations:

```bash
php artisan formforge:install
```

Run migrations:

```bash
php artisan migrate
```

### Upgrade existing installation safely

When FormForge ships new config keys or updated config comments, use:

```bash
php artisan formforge:install:merge
```

What this command does:

- keeps your existing values
- keeps Laravel-style comment blocks from the latest package config
- adds missing keys introduced by newer FormForge versions
- updates changed default comments/structure without resetting your overrides
- optionally publishes missing migrations (unless `--skip-migrations` is used)

Recommended workflow:

```bash
php artisan formforge:install:merge --dry-run
php artisan formforge:install:merge
php artisan migrate
```

Available options:

- `--dry-run`: preview merge changes without writing files
- `--skip-migrations`: do not publish missing migrations during merge
- `--no-backup`: skip backup generation for `config/formforge.php` before rewrite

## Quick start (code-first)

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

Get schema:

```php
$form = Form::get('contact');
$schema = $form->toArray();
```

Submit:

```php
$submission = $form->submit([
    'name' => 'Evan',
    'email' => 'evan@example.com',
    'message' => 'Hello',
]);
```

## Schema model

Every normalized schema contains:

- `key`, `version`, `title`
- flattened `fields`
- `pages[].fields[]`
- `conditions[]`
- `drafts.enabled`

When only `fields` are provided, FormForge auto-wraps them into a default page.

### Conditional rules

Conditions support:

- `target_type`: `page|field`
- `action`: `show|hide|skip|require|disable`
- `match`: `all|any`
- operators: `eq`, `neq`, `in`, `not_in`, `gt`, `gte`, `lt`, `lte`, `contains`, `not_contains`, `is_empty`, `not_empty`

At submission time, FormForge resolves the effective schema from payload + conditions, then validates against that effective shape.

## HTTP API

Default prefix: `api/formforge/v1`

### API resource customization

You can customize HTTP serialization with Laravel `JsonResource` classes:

```php
'http' => [
    'resources' => [
        'form_definition' => \App\Http\Resources\FormDefinitionResource::class, // optional management form resource
        'submission' => null, // optional full submission resource class
        'submitter' => \App\Http\Resources\UserResource::class, // optional submitted_by resource class
        'file_urls' => [
            'enabled' => true,
            'temporary' => true,
            'ttl_seconds' => 900,
            'key' => 'url',
        ],
    ],
],
```

- `form_definition` customizes management payloads (`GET /forms`, `POST /forms`, etc.).
- `submitter` makes submission payloads include `submitted_by` through your resource.
- `file_urls.enabled=true` enriches file metadata (in both `payload` and `files`) with signed temporary URLs when supported by the disk, with fallback to regular disk URLs.

### Model overrides

You can override package models through config.  
Each custom model must extend the corresponding FormForge base model.

```php
'models' => [
    'form_definition' => \App\Models\FormForge\FormDefinition::class,
    'form_category' => \App\Models\FormForge\FormCategory::class,
    'form_submission' => \App\Models\FormForge\FormSubmission::class,
    'submission_file' => \App\Models\FormForge\SubmissionFile::class,
    'staged_upload' => \App\Models\FormForge\StagedUpload::class,
    'idempotency_key' => \App\Models\FormForge\IdempotencyKey::class,
    'form_draft' => \App\Models\FormForge\FormDraft::class,
    'submission_automation_run' => \App\Models\FormForge\SubmissionAutomationRun::class,
],
```

### Optional ownership

FormForge can scope forms and categories to a polymorphic owner (`owner_type`, `owner_id`).

```php
'ownership' => [
    'enabled' => true,
    'required' => true,
    'endpoints' => ['management'],
    'fail_closed_endpoints' => ['management'],
    'resolver' => \App\FormForge\Ownership\FormForgeOwnershipResolver::class,
    'authorizer' => \App\FormForge\Ownership\FormForgeOwnershipAuthorizer::class,
],
```

Contracts:

- `resolver` must implement `EvanSchleret\FormForge\Ownership\Contracts\ResolvesOwnership`
- `authorizer` must implement `EvanSchleret\FormForge\Ownership\Contracts\AuthorizesOwnership`

Resolver can return:

- `null`
- an Eloquent model (owner inferred from morph class + key)
- `['type' => '...', 'id' => '...']`

When ownership is enabled, management responses include `owner_type` and `owner_id` on forms and categories.

`fail_closed_endpoints` defaults to `['management']`.  
If ownership is enabled and no owner is resolved for those endpoints, the request is rejected with `403`.

### Scoped HTTP routes (recommended for owner URL context)

If your API uses contextual prefixes (for example `/users/{user}` or `/teams/{team}`), configure scoped routes instead of overriding every controller method.

```php
'http' => [
    'endpoints' => [
        'management' => false, // disable unscoped management routes if needed
    ],
    'scoped_routes' => [
        [
            'name' => 'user',
            'enabled' => true,
            'prefix' => 'users/{user}',
            'middleware' => ['auth:sanctum'],
            'endpoints' => [
                'management' => true,
                'schema' => false,
                'submission' => false,
                'upload' => false,
                'resolve' => false,
                'draft' => false,
            ],
            'owner' => [
                'route_param' => 'user',
                'model' => \App\Models\User::class,
                'route_key' => null, // defaults to model getRouteKeyName()
                'type' => null, // optional fallback when model is not used
                'required' => true,
            ],
            'authorization' => [
                'mode' => 'policy', // none|gate|policy
                'policy' => \App\Policies\FormForge\UserFormForgePolicy::class,
                'abilities' => [], // used only for gate mode
            ],
        ],
    ],
],
```

With this config, FormForge automatically exposes the package endpoints under:

- `/api/formforge/v1/users/{user}/forms`
- `/api/formforge/v1/users/{user}/categories`
- and all other enabled endpoint groups for that scoped route

The owner is resolved from route context, then injected into package services (create/list/update/publish/delete/category ops) without controller rewrites.

### Scoped authorization modes

`authorization.mode` supports:

- `none`: no additional scoped authorization layer
- `gate`: per-route Gate abilities using keys like `management.index`, `management.create`, `schema.latest`
- `policy`: policy class extending package base policy (recommended)

Policy mode is fail-closed by default because the package base policy returns `false` for every action.

### Policy scaffold command

Generate a scoped policy with all FormForge HTTP action methods:

```bash
php artisan formforge:make:policy UserFormForgePolicy --model="App\\Models\\User" --param=user
```

This generates a policy extending `EvanSchleret\FormForge\Http\Authorization\BaseFormForgePolicy`.

### Override HTTP controllers (advanced)

Use controller overrides only when you need behavior that cannot be expressed through scoped routes and policy/gate configuration.

Each custom controller must extend the corresponding package controller.

Generate scaffolds:

```bash
php artisan formforge:make:http-controller --controller=management --controller=schema
```

Common options:

- `--all` to scaffold all override controllers
- `--path=` to customize the destination directory
- `--namespace=` to customize the generated namespace
- no `--controller` option triggers an interactive selection prompt

`FormManagementController` also exposes extension hooks:

- `resolveOwner(Request $request, string $action): ?OwnershipReference`
- `authorizeAction(Request $request, string $action, ?OwnershipReference $owner): void`

### Scoped owner API (code-first)

You can scope management operations directly without HTTP resolver plumbing:

```php
use EvanSchleret\FormForge\Facades\Form;

$forms = Form::for($user); // Model|array|OwnershipReference

$created = $forms->create([
    'title' => 'Owner form',
    'fields' => [
        ['type' => 'text', 'name' => 'name'],
    ],
], $request->user());

$list = $forms->paginateActive();
$single = $forms->latestActive((string) $created->key);
```

### Builder + Resource access for custom response layers

If you use a custom response helper (for example `ResourceTool::paginate`), you can reuse package builder + resource:

```php
use EvanSchleret\FormForge\Facades\Form;
use EvanSchleret\FormForge\Http\Resources\FormDefinitionHttpResource;

$query = Form::for($user)->queryActive([
    'category' => request('category'),
    'is_published' => request('is_published'),
]);

return ResourceTool::paginate(
    request(),
    new FormDefinitionHttpResource(null),
    $query,
    20,
);
```

### Schema endpoints

- `GET /forms/{key}`
- `GET /forms/{key}/versions`
- `GET /forms/{key}/versions/{version}`

### Submission endpoints

- `POST /forms/{key}/submit`
- `POST /forms/{key}/versions/{version}/submit`

### Resolve endpoints (debug/effective schema)

- `POST /forms/{key}/resolve`
- `POST /forms/{key}/versions/{version}/resolve`

By default, these endpoints are only enabled in `local` and `testing` environments.

### Upload staging endpoints

- `POST /forms/{key}/uploads/stage`
- `POST /forms/{key}/versions/{version}/uploads/stage`

### Draft endpoints (authenticated owner-based state)

- `POST /forms/{key}/drafts`
- `GET /forms/{key}/drafts/current`
- `DELETE /forms/{key}/drafts/current`

Draft behavior:

- user-bound (polymorphic owner)
- last write wins
- one draft per `(form_key, owner)`
- optional TTL cleanup
- rejected when form-level drafts are disabled

### Management endpoints

- `GET /forms` (paginated list of active forms, payload in `data.data`)
- `GET /categories` (paginated list of categories)
- `GET /categories/{categoryKey}` (single category detail)
- `POST /categories` (create category)
- `PATCH /categories/{categoryKey}` (update category metadata/state)
- `DELETE /categories/{categoryKey}` (delete category when not linked to forms and not `is_system`)
- `POST /forms` (create revision 1, UUID key auto-generated)
- `PATCH /forms/{key}` (creates a new draft revision)
- `POST /forms/{key}/publish` (creates a new published revision)
- `POST /forms/{key}/unpublish` (creates a new draft revision)
- `DELETE /forms/{key}` (soft delete all revisions)
- `GET /forms/{key}/revisions` (`include_deleted=1` supported)
- `GET /forms/{key}/diff/{fromVersion}/{toVersion}`
- `GET /forms/{key}/responses` (paginated list of form submissions)
- `GET /forms/{key}/responses/{submissionUuid}` (single submission detail)
- `DELETE /forms/{key}/responses/{submissionUuid}` (delete one submission)

Category key rule:

- `categoryKey` is always a UUID generated by FormForge (never derived from the category name)
- categories also expose a stable `slug` generated from the name (or explicitly set on create/update)
- form management accepts both category UUID keys and category slugs for `"category"` payload/filter values
- categories support `is_system`; system categories cannot be deleted

Creation rule:

- `POST /forms` requires only `title`
- if both `fields` and `pages` are omitted (or empty), FormForge seeds:
  - one default page
  - one default field (`type: text`, `name: short_text`)
- `PATCH /forms/{key}` with `"category": null` clears the form category (sets it to uncategorized)

## Publishability

A form can be published only when:

- `title` is non-empty
- at least one page exists
- at least one field exists

## Endpoint security model

Security layers:

1. Global route middleware (`formforge.http.middleware`)
2. Endpoint options (`schema`, `submission`, `upload`, `resolve`, `draft`, `management`):
   - `auth`: `public|optional|required`
   - `guard`
   - `middleware`
   - `ability` (and per-action `abilities` for management)

Example:

```php
'http' => [
    'middleware' => ['api'],
    'draft' => [
        'auth' => 'required',
        'guard' => 'sanctum',
        'middleware' => ['throttle:60,1'],
        'ability' => 'formforge.draft',
    ],
    'management' => [
        'auth' => 'required',
        'guard' => 'sanctum',
        'abilities' => [
            'create' => 'formforge.create',
            'index' => 'formforge.index',
            'categories' => 'formforge.categories',
            'category' => 'formforge.category',
            'category_create' => 'formforge.category-create',
            'category_update' => 'formforge.category-update',
            'category_delete' => 'formforge.category-delete',
            'update' => 'formforge.update',
            'publish' => 'formforge.publish',
            'unpublish' => 'formforge.unpublish',
            'delete' => 'formforge.delete',
            'revisions' => 'formforge.read-revisions',
            'diff' => 'formforge.read-diff',
            'responses' => 'formforge.read-responses',
            'response' => 'formforge.read-response',
            'response_delete' => 'formforge.delete-response',
        ],
    ],
],
```

### Endpoint toggles

You can disable endpoint groups when you expose your own app routes:

```php
'http' => [
    'endpoints' => [
        'schema' => true,
        'submission' => true,
        'upload' => true,
        'resolve' => true,
        'draft' => true,
        'management' => false,
    ],
],
```

## Test mode submissions

`_formforge_test` (or configured header) enables test submissions when:

- `formforge.submissions.testing.enabled = true`
- current environment is in `formforge.submissions.testing.enabled_environments`

By default, allowed environments are `local` and `testing`.

## Validation rules

Validation is Laravel-native:

- automatic deterministic rules by field type
- `rules(...)` appends custom rules
- `replaceRules(...)` fully overrides generated rules
- API payload rules are string/array Laravel rules
- nullish handling is driven by `required`:
  - `required: false` => `null` and empty string are treated as absent
  - `required: true` => `null` and empty string are rejected

Reference: [Laravel validation rules](https://laravel.com/docs/validation#available-validation-rules)

## Upload modes

`formforge.uploads.mode` supports:

- `managed`: multipart submit with file objects
- `direct`: submit payload references already-uploaded files
- `staged`: upload first, then submit JSON with `upload_token`

For JSON-first clients, staged mode is recommended:

1. `POST /forms/{key}/uploads/stage` (multipart)
2. `POST /forms/{key}/submit` with JSON payload containing `upload_token`

## Idempotency

Management mutations support `Idempotency-Key`:

- same key + same payload => replayed response
- same key + different payload => `409 Conflict`

TTL:

```php
'http' => [
    'idempotency' => [
        'ttl_minutes' => 1440,
    ],
],
```

## Generate automation scaffold

Use the generator command as the default entrypoint for submission automations:

```bash
php artisan formforge:make:automation CreateUserFromSubmission --form=user-registration --sync
```

Common variants:

```bash
php artisan formforge:make:automation User/CreateUserFromSubmission --form=user-registration --sync
php artisan formforge:make:automation CreateUserFromSubmission --form=user-registration --queue=formforge
```

Useful options:

- `--form=`: target form key for generated registration snippet
- `--sync`: generate sync registration snippet
- `--queue=` and `--connection=`: generate queued registration snippet
- `--path=` and `--namespace=`: customize destination and namespace
- `--force`: overwrite existing file

Generate an automation resolver scaffold:

```bash
php artisan formforge:make:automation-resolver ResolveActiveForm
```

Useful options:

- `--path=` and `--namespace=`: customize destination and namespace
- `--force`: overwrite existing file

## Submission automations (code-first)

FormForge can run custom application code right after a submission is persisted.

Automations are registered in code, not in config mappings.

Example in `AppServiceProvider::boot()`:

```php
<?php

declare(strict_types=1);

use App\FormForge\Automations\CreateUserFromSubmission;
use EvanSchleret\FormForge\Facades\Form;

Form::automation('user-registration')
    ->sync()
    ->handler(CreateUserFromSubmission::class, 'create_user');
```

Queued execution:

```php
Form::automation('user-registration')
    ->queue('formforge')
    ->handler(CreateUserFromSubmission::class);
```

Resolver-based execution (dynamic form resolution at runtime):

```php
use App\FormForge\AutomationResolvers\ResolveActiveForm;

Form::automationForResolver(ResolveActiveForm::class)
    ->sync()
    ->handler(CreateUserFromSubmission::class, 'create_user');
```

Handler contract:

```php
<?php

declare(strict_types=1);

namespace App\FormForge\Automations;

use EvanSchleret\FormForge\Automations\Contracts\SubmissionAutomation;
use EvanSchleret\FormForge\Models\FormSubmission;

final class CreateUserFromSubmission implements SubmissionAutomation
{
    public function handle(FormSubmission $submission): void
    {
        // business logic using $submission->payload
    }
}
```

Resolver contract:

```php
<?php

declare(strict_types=1);

namespace App\FormForge\AutomationResolvers;

use EvanSchleret\FormForge\Automations\Contracts\SubmissionAutomationResolver;
use EvanSchleret\FormForge\Models\FormSubmission;

final class ResolveActiveForm implements SubmissionAutomationResolver
{
    public function matches(FormSubmission $submission): bool
    {
        return (string) $submission->form_key === 'user-registration';
    }
}
```

Notes:

- run tracking is persisted in `formforge_submission_automation_runs`
- execution is idempotent per `(submission_id, automation_key)`
- infrastructure defaults are configurable under `formforge.automations.*`

## Useful commands

```bash
php artisan formforge:install
php artisan formforge:install:merge
php artisan formforge:sync
php artisan formforge:make:automation CreateUserFromSubmission --form=user-registration --sync
php artisan formforge:make:automation-resolver ResolveActiveForm
php artisan formforge:make:http-controller --controller=management --controller=schema
php artisan formforge:make:policy UserFormForgePolicy --model="App\\Models\\User" --param=user
php artisan formforge:list
php artisan formforge:describe contact
php artisan formforge:http:options
php artisan formforge:http:routes
php artisan formforge:http:resolve contact --endpoint=submission --form-version=1
php artisan formforge:uploads:cleanup
php artisan formforge:drafts:cleanup
```

## Laravel Boost AI assets

FormForge ships package-level Laravel Boost assets:

- `resources/boost/guidelines/core.blade.php`
- `resources/boost/skills/formforge-integration/SKILL.md`

Use them to guide AI agents implementing FormForge in Laravel APIs.

## Roadmap

- [ ] Add first-party Laravel MCP integration (resources + tools) for AI-assisted FormForge implementation
- [ ] Add first-class signed upload URL flow for object storage direct upload
- [ ] Add cleanup command for expired idempotency keys
- [ ] Add OpenAPI export command for FormForge HTTP contracts
- [ ] Add revision migration tooling (dry-run + apply) for schema evolution
- [ ] Add full FormForge page analytics helpers
- [ ] Add first-party Laravel Boost template pack for FormForge + auth presets
- [ ] Add submissions export command (CSV/JSONL) with filters
- [ ] Add webhook delivery for submission lifecycle events with retry + signature
- [ ] Add retention/anonymization command for old submissions (GDPR-friendly)
- [ ] Add response search/filter API (by date, test/live, version, submitter)
- [ ] Add configurable submission notifications (global and per-form) for channels like Telegram, Discord, Slack, and custom webhooks

## Other packages

If you want to explore more of my packages:

- [evanschleret/formformclient (FormForge Client)](https://github.com/EvanSchleret/formforgeclient)
- [evanschleret/laravel-typebridge](https://github.com/EvanSchleret/laravel-typebridge)
- [evanschleret/lara-mjml](https://github.com/EvanSchleret/lara-mjml)
- [evanschleret/laravel-user-presence](https://github.com/EvanSchleret/laravel-user-presence)

## Open source

- Contributing guide: [CONTRIBUTING.md](CONTRIBUTING.md)
- Security policy: [SECURITY.md](SECURITY.md)
- Code of conduct: [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md)
- License: [LICENSE](LICENSE)
