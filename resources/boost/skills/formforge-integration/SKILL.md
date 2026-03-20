---
name: formforge-integration
description: Implement FormForge in Laravel APIs with secure management endpoints, immutable revisions, and deterministic schema handling.
---

# FormForge Integration Skill

## Goal

Implement FormForge end-to-end in a Laravel API with:

- deterministic schema and strict validation
- immutable revision workflow
- secure management endpoints
- robust upload strategy (managed/direct/staged)

## Recommended implementation flow

1. Install and publish FormForge package files.
2. Run migrations.
3. Configure `formforge.http.*` security options.
4. Create forms through management endpoints (`POST /forms` then `PATCH /forms/{key}`).
5. Publish with `POST /forms/{key}/publish` only when publishable.
6. Use revisions and diff endpoints for audit.
7. Add idempotency keys for mutation requests.

## Endpoint checklist

- `POST /forms`: create draft with auto-generated key
- `PATCH /forms/{key}`: create a new draft revision
- `POST /forms/{key}/publish`: create a published revision
- `POST /forms/{key}/unpublish`: create a draft revision
- `DELETE /forms/{key}`: soft delete revisions
- `GET /forms/{key}/revisions`: read history
- `GET /forms/{key}/diff/{fromVersion}/{toVersion}`: read changes

## Security checklist

- Configure `auth`, `guard`, and `middleware` per endpoint.
- Configure management `ability` and per-action `abilities`.
- Use middleware aliases for policy-style checks when needed.
- Keep public defaults only when explicitly desired.

## Validation checklist

- Keep API-provided rules to Laravel string/array format.
- Enforce title and field requirements before publish.
- Reject invalid field definitions early.

## Operational checklist

- Use staged uploads for JSON-first clients.
- Schedule staged upload cleanup command.
- Keep README and endpoint docs synced with current behavior.
- Add feature tests for auth branches and idempotency replay.
