# FormForge - Package Specification

## 1. Objective

Build a package named `formforge`.

The package provides a deterministic dynamic forms engine for Laravel.
Developers define forms in PHP, export a stable JSON schema for frontend
rendering, validate submissions (including file uploads) on the backend, and
persist responses.

The package must be designed from the start to support a future official
frontend integration:

-   `FormForgeClient` (`formforge-client`)

The Laravel package remains the **single source of truth** for form
definitions and validation. Frontend libraries such as `FormForgeClient`
must only consume the exported schema.

Use cases:

-   onboarding flows
-   surveys
-   internal tools
-   questionnaires
-   configurable business forms
-   admin-defined business forms

## 2. Runtime and Compatibility

-   Laravel: `12.x`
-   PHP: `>=8.2`

## 3. Out of Scope (Do Not Implement)

The package must remain a backend engine only.

Do not implement:

-   Drag and drop form builder
-   Hosted form pages
-   Payments
-   WYSIWYG editors
-   Conditional UI builders
-   Analytics dashboards
-   Frontend rendering logic
-   Vue / React / Nuxt components
-   Form layout engines
-   Visual form designer

Frontend rendering will be handled later by **FormForgeClient**.

## 4. Public API

### 4.1 Form Definition

Example:

``` php
Form::define('registration')
    ->title('Registration')
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
            ['value' => 'member', 'label' => 'Member']
        ])
        ->required()
```

Requirements:

-   unique form keys
-   unique `(form_key, version)`
-   unique field names per form version
-   unique field keys per form version
-   deterministic field ordering
-   immutable versions once persisted
-   field definitions must produce deterministic schema output

The backend definition must always be considered the **canonical schema**.

### 4.2 Supported Fields

Initial supported field types:

-   text
-   textarea
-   email
-   number
-   select
-   select_menu
-   radio
-   checkbox
-   checkbox_group
-   switch
-   date
-   time
-   datetime
-   date_range
-   datetime_range
-   file

Common field methods:

-   `label()`
-   `fieldKey()`
-   `required()`
-   `nullable()`
-   `default()`
-   `placeholder()`
-   `helpText()`
-   `rules()`
-   `replaceRules()`
-   `meta()`
-   `min()`
-   `max()`
-   `step()`
-   `options()`
-   `multiple()`
-   `disabled()`
-   `readonly()`
-   `accept()`
-   `maxSize()`
-   `maxFiles()`
-   `storageDisk()`
-   `storageDirectory()`
-   `visibility()`

Rules for field methods:

-   `required()` and `nullable()` are mutually exclusive
-   `rules()` merges with auto-generated rules and deduplicates deterministically
-   `replaceRules()` fully overrides auto-generated rules (expert mode)
-   `fieldKey()` is immutable once a form version is persisted
-   if `fieldKey()` is not provided, the package auto-generates a deterministic key
    (`fk_<hash>`) from the canonical field signature
-   for `file` fields, uploaded files are stored through Laravel filesystem and
    only normalized metadata is persisted in submission payload
-   `meta.ui.*` namespace is reserved for future frontend concerns (Nuxt UI
    alignment)

Field definitions must produce a frontend-safe schema compatible with future
renderers such as **FormForgeClient**.

### 4.3 Form Registry

API:

``` php
Form::get('registration')
Form::get('registration', version: '1')
Form::versions('registration')
Form::all()
```

Requirements:

-   deterministic resolution
-   explicit version lookup
-   clear exceptions for missing forms
-   runtime registry is canonical at execution time
-   database stores immutable schema snapshots for retrieval and auditing

### 4.4 Submission

Example:

``` php
$form = Form::get('registration')

$form->submit([
    'name' => 'Evan',
    'email' => 'evan@example.com',
    'role' => 'member'
])
```

Rules:

-   validation always happens on Laravel before persistence
-   payload strictly validated against the exact resolved schema version
-   unknown field behavior controlled by config (default: reject)
-   upload mode is configurable per application:
    `managed`, `direct`, or `staged`
-   file fields support multipart/form-data inputs
-   uploaded files are validated and stored before final submission persistence
-   payload normalized before storage
-   only normalized validated payload is persisted

Upload modes:

-   `managed`: file content is posted to Laravel and stored with
    `Storage::disk(...)`
-   `direct`: client uploads directly to object storage through signed upload
    instructions, then submits finalized references
-   `staged`: file is uploaded to temporary storage and committed only when
    final form submission succeeds

### 4.5 Schema Export

Expose normalized schema:

``` php
$form->toArray()
$form->toJson()
```

The exported schema must be stable and deterministic.

Required schema fields:

-   key
-   version
-   title
-   ordered fields
-   field key (`field_key`)
-   field type
-   field name
-   label
-   required flag
-   nullable flag
-   default value
-   validation rules
-   metadata
-   upload constraints (for `file` fields)

For option-based fields, options must be normalized to Nuxt UI-friendly shape:

-   `value`
-   `label`
-   `description` (optional)
-   `disabled` (optional)
-   `meta` (optional)

For file fields, schema must expose deterministic upload constraints:

-   `accept`
-   `max_size`
-   `max_files`
-   `multiple`
-   `storage` (`disk`, `directory`, `visibility`)

Example schema structure:

``` json
{
  "key": "registration",
  "version": "1",
  "title": "Registration",
  "fields": [
    {
      "field_key": "applicant.name",
      "type": "text",
      "name": "name",
      "label": "Name",
      "required": true,
      "nullable": false,
      "default": null,
      "rules": ["required", "string", "max:120"],
      "meta": {
        "ui": {}
      }
    }
  ]
}
```

Schema must remain strict and stable across versions while allowing evolution
through new versions.

### 4.6 Artisan Commands

Commands:

    php artisan formforge:install
    php artisan formforge:list
    php artisan formforge:describe {formKey}
    php artisan formforge:sync

Command behavior:

-   `formforge:sync` persists runtime registry definitions as immutable DB snapshots
-   `formforge:list` and `formforge:describe` use persisted definitions and may expose
    runtime/db drift diagnostics

## 5. Configuration

Config file:

`config/formforge.php`

Keys:

-   `database.forms_table`
-   `database.submissions_table`
-   `database.submission_files_table`
-   `database.connection`
-   `submissions.store_ip`
-   `submissions.store_user_agent`
-   `validation.reject_unknown_fields`
-   `uploads.mode`
-   `uploads.disk`
-   `uploads.directory`
-   `uploads.visibility`
-   `uploads.preserve_original_filename`
-   `uploads.temporary_disk`
-   `uploads.temporary_directory`
-   `uploads.temporary_ttl_minutes`
-   `uploads.direct.signature_ttl_seconds`

Upload configuration rules:

-   `uploads.mode` accepts `managed`, `direct`, `staged`
-   `uploads.disk` and `uploads.temporary_disk` must target Laravel Filesystem
    disks (`local`, `s3`, and compatible drivers)

Defaults:

-   `validation.reject_unknown_fields = true`
-   `uploads.mode = managed`
-   `submissions.store_ip = true`
-   `submissions.store_user_agent = true`
-   `uploads.disk = default`
-   `uploads.directory = formforge`
-   `uploads.visibility = private`
-   `uploads.preserve_original_filename = false`
-   `uploads.temporary_disk = default`
-   `uploads.temporary_directory = formforge/tmp`
-   `uploads.temporary_ttl_minutes = 1440`
-   `uploads.direct.signature_ttl_seconds = 900`

## 6. Persistence

### 6.1 Forms Table

Columns:

-   id
-   key
-   version
-   title
-   schema JSON
-   schema_hash
-   is_active
-   timestamps

Constraints:

-   unique `(key, version)`
-   schema stored exactly as exported
-   `schema_hash` is computed from canonical schema JSON for idempotency and drift
    checks

### 6.2 Submissions Table

Columns:

-   id
-   form_key
-   form_version
-   payload JSON
-   submitted_by_type
-   submitted_by_id
-   ip_address
-   user_agent
-   timestamps

Rules:

-   payload stores normalized validated values only
-   submitter relation is polymorphic (`submitted_by_type` + `submitted_by_id`)
-   `ip_address` and `user_agent` are captured from request when available,
    otherwise `null`
-   payload stores file metadata references only (not raw file binaries)
-   upload storage targets rely on Laravel Filesystem disks (`local`, `s3`, and
    compatible drivers)

### 6.3 Submission Files Table

Columns:

-   id
-   form_submission_id
-   field_key
-   disk
-   path
-   original_name
-   stored_name
-   mime_type
-   extension
-   size
-   checksum
-   metadata JSON
-   timestamps

Constraints:

-   indexed `form_submission_id`
-   indexed `(form_submission_id, field_key)`
-   file record links to one submission and one field identity

## 7. Versioning

Rules:

-   explicit version strings
-   schema immutable after creation
-   new schema requires new version
-   submissions tied to exact version
-   schema changes must never mutate previous versions
-   same `(key, version)` with identical schema is idempotent (no-op)
-   same `(key, version)` with different schema raises an immutability exception
-   `field_key` is the stable field identity across versions

## 8. Validation and Normalization

Validation rules are generated from field definitions.

Requirements:

-   deterministic rule generation
-   backend always validates all submitted data
-   unknown fields rejected by default
-   if unknown field rejection is disabled, unknown keys are ignored and not
    persisted
-   normalization applied before persistence

Rules behavior:

-   `rules()` merges with generated rules and deduplicates in deterministic order
-   `replaceRules()` replaces generated rules entirely

Normalization:

-   checkbox/switch -> boolean
-   checkbox_group -> array
-   number -> int / float
-   date -> `Y-m-d`
-   time -> `H:i:s`
-   datetime -> ISO-8601 string
-   date_range -> `{ "start": "Y-m-d", "end": "Y-m-d" }`
-   datetime_range -> `{ "start": "<ISO-8601>", "end": "<ISO-8601>" }`
-   text/textarea/email -> string
-   file (single) -> `{ disk, path, original_name, stored_name, mime_type, extension, size, checksum }`
-   file (multiple) -> array of normalized file metadata objects
-   empty string remains empty string unless explicit transformer/rules handle it

## 9. Querying

Models:

-   `FormDefinition`
-   `FormSubmission`

Scopes:

``` php
FormSubmission::whereForm('registration')
FormSubmission::whereVersion('1')
```

Relations:

-   `FormSubmission` exposes polymorphic submitter relation

## 10. Future Integration (Frontend)

The schema format must support a future frontend integration named:

`FormForgeClient` (`formforge-client`)

That module will:

-   fetch form schema from Laravel
-   render forms using Nuxt UI
-   handle client validation
-   submit data to Laravel endpoints

Because of this future integration:

-   schema must remain frontend-neutral
-   schema must remain deterministic
-   field types must remain stable
-   options shape must be Nuxt UI-friendly (`value`, `label`, `description`,
    `disabled`)
-   metadata must support UI extensions under `meta.ui.*`
-   file schema must map cleanly to Nuxt UI `FileUpload` capabilities

No frontend logic must exist in the Laravel package.

## 11. Tests

Use Pest + Testbench.

Coverage must include:

-   form registration
-   duplicate version detection
-   schema export determinism
-   field key determinism and explicit override behavior
-   `required()` / `nullable()` conflict behavior
-   rules merge behavior (`rules()`)
-   rules override behavior (`replaceRules()`)
-   submission validation
-   unknown field rejection / ignore behavior
-   normalization (including date/time/datetime/range)
-   file upload validation (`accept`, max size, max files)
-   file storage and metadata persistence
-   idempotent `(key, version)` behavior
-   persistence
-   submitter polymorphic persistence
-   artisan commands (`install`, `list`, `describe`, `sync`)
-   config behavior

## 12. Documentation

README must include:

-   installation
-   config publishing
-   form definition example
-   field types
-   file upload usage and storage behavior
-   `field_key` behavior
-   `rules()` vs `replaceRules()`
-   submission example
-   schema export example
-   querying submissions
-   explanation of schema usage for frontend libraries
-   roadmap section

## 13. Acceptance Criteria

1.  Package installs on Laravel 12 with PHP \>= 8.2.
2.  Forms are declared in PHP.
3.  Schema export is deterministic.
4.  Versioning is immutable.
5.  Submissions are validated and persisted.
6.  Unknown fields are rejected by default.
7.  File uploads are validated, stored, and persisted as metadata references.
8.  Schema is stable and usable by external renderers (ex: FormForgeClient).
9.  `field_key` is present and deterministic.
10. Commands including `formforge:sync` work deterministically.
11. Tests pass deterministically.

## 14. Roadmap (Post-V1)

Planned form migration feature:

-   schema migration pipelines between versions (`v1 -> v2`)
-   migration DSL (`rename`, `default`, `drop`, `transform`)
-   dry-run migration reports
-   batched historical submission migrations
