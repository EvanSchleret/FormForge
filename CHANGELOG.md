# Changelog

All notable changes to this project will be documented in this file.

The format is based on Keep a Changelog.

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
