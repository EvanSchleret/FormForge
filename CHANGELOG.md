# Changelog

All notable changes to this project will be documented in this file.

The format is based on Keep a Changelog.

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
