---
name: formforge-integration
description: Implement FormForge in Laravel APIs with immutable revisions, conditional pages, strict validation, and secure endpoint configuration.
---

# FormForge Integration Skill

## Goal

Implement FormForge end-to-end in a Laravel API with:

- deterministic schema and strict validation
- pages with conditional resolution
- immutable revision workflow
- secure management endpoints
- robust upload strategy (managed/direct/staged)
- authenticated draft persistence

## Recommended implementation flow

1. Install and publish FormForge package files.
2. Run migrations.
3. Configure `formforge.http.*` security options.
4. Create forms through management endpoints (`POST /forms` then `PATCH /forms/{key}`).
5. Resolve effective schema with `POST /forms/{key}/resolve` while implementing client logic.
6. Implement user draft save/read/delete with `/forms/{key}/drafts*`.
7. Publish with `POST /forms/{key}/publish` only when publishable.
8. Use revisions and diff endpoints for audit.
9. Add idempotency keys for mutation requests.

## Endpoint checklist

- `POST /forms`: create draft with auto-generated UUID key
- `PATCH /forms/{key}`: create a new draft revision
- `POST /forms/{key}/publish`: create a published revision
- `POST /forms/{key}/unpublish`: create a draft revision
- `DELETE /forms/{key}`: soft delete revisions
- `GET /forms/{key}/revisions`: read history
- `GET /forms/{key}/diff/{fromVersion}/{toVersion}`: read changes
- `POST /forms/{key}/resolve`: resolve effective schema from payload
- `POST /forms/{key}/drafts`: save current user draft
- `GET /forms/{key}/drafts/current`: fetch current user draft
- `DELETE /forms/{key}/drafts/current`: delete current user draft

## Security checklist

- Configure `auth`, `guard`, and `middleware` per endpoint.
- Configure management `ability` and per-action `abilities`.
- Configure draft `ability` when using policy-style authorization.
- Use middleware aliases for policy-style checks when needed.
- Keep public defaults only when explicitly desired.

## Validation checklist

- Keep API-provided rules to Laravel string/array format.
- Apply nullish semantics from `required` only:
  - optional fields ignore `null` and empty string
  - required fields reject `null` and empty string
- Enforce title/page/field requirements before publish.
- Reject invalid field definitions early.

## Operational checklist

- Use staged uploads for JSON-first clients.
- Schedule staged upload cleanup command.
- Schedule draft cleanup command.
- Keep resolve endpoint restricted to non-production environments unless explicitly needed.
- Keep README and endpoint docs synced with current behavior.
- Add feature tests for auth branches and idempotency replay.
