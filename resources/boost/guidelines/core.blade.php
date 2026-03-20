# FormForge Package Guidelines

Use these rules when implementing or extending FormForge integrations in Laravel applications.

## Core principles

- Keep form schemas deterministic and immutable by revision.
- Treat `key` as a stable public identifier.
- Create new revisions for changes (`PATCH`, `publish`, `unpublish`), never mutate an existing revision.
- Keep management operations idempotent when `Idempotency-Key` is provided.

## HTTP contract

- Management endpoints:
  - `POST /forms`
  - `PATCH /forms/{key}`
  - `POST /forms/{key}/publish`
  - `POST /forms/{key}/unpublish`
  - `DELETE /forms/{key}`
  - `GET /forms/{key}/revisions`
  - `GET /forms/{key}/diff/{fromVersion}/{toVersion}`
- Schema/submission/upload endpoints remain independent and backward-compatible.

## Security

- Use `formforge.http.management` options for auth, guard, middleware, and abilities.
- Prefer explicit abilities for each management action.
- Keep public defaults only when intentional.
- For stricter access control, combine abilities with custom middleware aliases.

## Validation and schema rules

- Use string/array Laravel rules in API payloads.
- Do not rely on runtime closures or object rules from API payloads.
- Enforce publishability constraints before publishing:
  - non-empty title
  - at least one field

## Revision strategy

- Use integer auto-increment revisions (`1`, `2`, `3`, ...).
- Expose history and diff for auditability.
- Keep soft-deleted revisions queryable for admin read-only workflows.

## File workflows

- Use `managed`, `direct`, or `staged` modes per project requirements.
- For JSON-first clients, prefer staged upload + token submission.

## DX expectations

- Add feature tests for every endpoint and security branch.
- Keep docs aligned with real endpoint behavior and payload format.
- Keep config examples production-oriented.
