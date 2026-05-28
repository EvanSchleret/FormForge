# Changelog

All notable changes to this project will be documented in this file.

The format is based on Keep a Changelog.

## v1.5.2 - 2026-05-28

### v1.5.2

#### Added

- Backend now accepts submission/validation payload keys by canonical `name` and `field_key` (default mode: `both`)
- New validation config: `formforge.validation.input_key_mode` with:
  - `name_only`
  - `field_key_only`
  - `both` (default)
  

#### Changed

- Input key resolution now follows:
  1. exact `name`
  2. fallback exact `field_key`
  
- When both `name` and `field_key` are provided for the same field, `name` takes precedence
- Payload sanitization/allowed-fields flow now supports `field_key` inputs through canonical normalization

#### Error handling

- Explicit conflict error when multiple aliases target the same field in a conflicting way
- Unknown field behavior unchanged (`reject_unknown_fields` still applies as before)

#### Tests

- Added/updated coverage for:
  - `name`-only payload
  - `field_key`-only payload
  - mixed `name + field_key` payload
  - collision/conflict scenarios
  - unknown field rejection compatibility
  

**Full Changelog**: https://github.com/EvanSchleret/FormForge/compare/v1.5.1...v1.5.2

## v1.5.1 - 2026-05-28

### v1.5.1

#### Fixed

- Added support for filtering form query routes by linked category slug using `field: "category_slug"`.
- Resolved mismatch where `category` filtering targeted stored category reference (key) and could not match category slug values.

#### Added

- New `category_slug` field support in `http.query_routes.forms.where` predicates.
- Feature test coverage for form query route filtering by category slug.

#### Documentation

- Updated Query Routes docs to clarify:
  - `category` filters by stored category reference/key
  - `category_slug` filters by related category slug
  

**Full Changelog**: https://github.com/EvanSchleret/FormForge/compare/v1.5.0...v1.5.1

## Unreleased

- Submission/validation payloads now accept `name` and `field_key` input keys (default `formforge.validation.input_key_mode=both`).
- Input key resolution now prefers exact `name`, then exact `field_key`.
- If the same target field receives multiple non-name aliases in one payload, an explicit conflict error is raised.
- Unknown fields handling remains unchanged (`reject_unknown_fields` still applies).

## v1.5.0 - 2026-05-28

### v1.5.0

#### Added

- Introduced `http.query_routes` configuration for reusable, named query routes.
  
- Added new management endpoints:
  
  - `GET /form-routes/{routeKey}`
  - `GET /category-routes/{routeKey}`
  
- Enabled scoped and non-scoped support for query routes.
  
- Added nested predicate DSL with logical groups:
  
  - `all` (AND)
  - `any` (OR)
  
- Added rich operators:
  
  - `eq`, `neq`, `gt`, `gte`, `lt`, `lte`
  - `in`, `not_in`
  - `contains`, `starts_with`, `ends_with`
  - `is_null`, `not_null`
  - `between`
  
- Added aggregate filtering support:
  
  - `responses_count` for form routes
  - `forms_count` for category routes
  

#### Changed

- HTTP route listing command now includes query route endpoints.
- Authorization action map and base policy now include:
  - `management.form_route`
  - `management.category_route`
  

#### Tests

- Added feature coverage for form route resolution and category route resolution.

**Full Changelog**: https://github.com/EvanSchleret/FormForge/compare/v1.4.0...v1.5.0

## v1.4.0 - 2026-05-27

### v1.4.0

#### Added

- Runtime locale resolution for backend validation flows:
  
  - explicit method locale
  - query param (`formforge_locale`)
  - header (`X-FormForge-Locale`)
  - config/app locale fallback chain
  
- EN/FR translation resources for FormForge backend messages.
  
- Publishable package language files via `formforge-lang`.
  

#### Changed

- Validation and field-level backend responses now use translatable message keys while preserving canonical technical keys.
- Partial validation flows continue to return canonical field keys; only message text is localized.
- Backend docs reorganized with a dedicated Validation section.

#### Notes

- Laravel native rule messages are still resolved by host app translation files.

**Full Changelog**: https://github.com/EvanSchleret/FormForge/compare/v1.3.0...v1.4.0

## v1.3.0 - 2026-05-26

### v1.3.0

#### Added

- New normalized field descriptor API for FormForge schemas:
  
  - `describeFields()` on `FormInstance`, `FormManager`, `ScopedFormManager`
  
- New centralized field resolution API:
  
  - `resolveField(...)` matching `name`, `field_key`, `key`, `id`
  
- New partial batch validation API:
  
  - `validateFields(...)` for subset-oriented field validation
  - Supports alias identifiers in `onlyFields`
  - Returns errors keyed by canonical field `name`
  - Returns explicit errors for unresolved `onlyFields` identifiers
  

#### Changed

- `validateField(...)` now uses centralized field resolution to avoid divergence in alias matching logic

#### Behavior notes

- Existing APIs remain intact:
  
  - `validate(...)` unchanged (including unknown-field handling)
  - `validateField(...)` signature and response shape unchanged
  
- Partial validation (`validateFields`) is field-oriented:
  
  - Unknown payload keys are ignored
  - No global unknown-fields rejection in this flow
  

#### Tests

- Added coverage for:
  - alias resolution via `name`, `field_key`, `key`, `id`
  - normalized descriptors (`options`, `required`, `lookup_keys`, etc.)
  - partial subset validation (valid/invalid)
  - `onlyFields` alias support
  - unresolved identifier errors
  - non-regression for existing `validate()` / `validateField()` behavior
  

**Full Changelog**: https://github.com/EvanSchleret/FormForge/compare/v1.2.1...v1.3.0

## v1.2.1 - 2026-05-22

### v1.2.1

#### Fixed

- `SubmissionValidator::validateField()` now resolves fields using aliases, not only `name`.
- Accepted field identifiers are now: `name`, `field_key`, `key`, and `id` (normalized via trim + string cast).
- Validation payload/rules still use the field canonical `name`, preserving existing validation behavior.
- Unknown identifiers still raise `UnknownFieldsException` when no alias matches.

#### Tests

- Added/updated feature tests to cover:
  - `validateField('name', value)` (existing behavior)
  - `validateField('field_key', value)`
  - `validateField('key', value)`
  - `validateField('id', value)`
  - unknown key still throws `UnknownFieldsException`
  

**Full Changelog**: https://github.com/EvanSchleret/FormForge/compare/v1.2.0...v1.2.1

## v1.2.0 - 2026-05-20

### v1.2.0

#### Added

- Single-field validation capability for FormForge schemas, allowing validation of one input against one question in one form without creating a submission.
  
- New public API methods:
  
  - `FormInstance::validateField(string $field, mixed $value): array`
  - `FormManager::validateField(string $formKey, string $field, mixed $value, ?string $version = null): array`
  - `ScopedFormManager::validateField(string $formKey, string $field, mixed $value, ?string $version = null): array`
  
- New HTTP endpoints (available in both scoped and non-scoped route trees):
  
  - `POST /forms/{key}/validate-field`
  - `POST /forms/{key}/versions/{version}/validate-field`
  
- New validation config option:
  
  - `formforge.validation.field.stop_on_first_failure` (default: `false`)
  

#### Changed

- Resolve endpoint group now also exposes targeted field validation routes, reusing existing endpoint middleware/auth/action routing patterns.
  
- Authorization action map extended with:
  
  - `resolve.validate_field_latest`
  - `resolve.validate_field_version`
  
- Base policy contract extended with:
  
  - `resolve_validate_field_latest(...)`
  - `resolve_validate_field_version(...)`
  

#### Notes

- Field-level validation returns a structured result (`valid`, `errors`, `validated`) instead of persisting data.
- Unknown field names are rejected consistently with existing validation behavior.

**Full Changelog**: https://github.com/EvanSchleret/FormForge/compare/v1.1.1...v1.2.0

## v1.1.1 - 2026-04-14

## v1.1.1

- Added support for `auto_publish` (and `autoPublish`) in form management `create` and `patch` requests
- `POST /forms` can now immediately publish the created form when `auto_publish` is `true`
- `PATCH /forms/{key}` can now immediately publish the updated form when `auto_publish` is `true`
- Publication now happens as part of the same management flow, returning the published revision in the response

**Full Changelog**: https://github.com/EvanSchleret/FormForge/compare/v1.1.0...v1.1.1

## v1.1.0 - 2026-04-13

### v1.1.0

#### Added

- Category name guard via config:
  
  - New key: `formforge.categories.forbidden_names`
    
  - Case-insensitive matching
    
  - Leading/trailing spaces ignored
    
  - Enforced on:
    
    - `POST /api/formforge/v1/categories`
    - `PATCH /api/formforge/v1/categories/{categoryKey}`
    
  - Returns `422` with validation error on `category` when blocked
    
  
- `FormSubmission::meta(string|array $key, mixed $value = null): self`
  
  - Convenience helper to merge and persist submission metadata
  

#### Changed

- Category handling no longer creates implicit default categories during form creation/sync paths
- Added dedicated category creation flow (command-driven) to keep category lifecycle explicit

#### Fixed

- Migration compatibility: shortened FK name for privacy overrides table
- Test fixture compatibility: removed strict dependency on `FormSubmission::meta()` in automation fixture path
- CI changelog updater now has a guaranteed target file (`CHANGELOG.md`) to avoid release job failure

#### Config Migration

```bash
php artisan formforge:install:merge --skip-migrations --no-backup









```
#### DB Migration

```bash
php artisan migrate









```
**Full Changelog**: https://github.com/EvanSchleret/FormForge/compare/v1.0.2...v1.1.0
