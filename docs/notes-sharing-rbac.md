# Notes Sharing and RBAC

An owner can share a note with other registered users. A **viewer** can read it, an **editor** can also
edit it, and only the **owner** can delete it or manage who has access.

## Role matrix

Every check is made on the server, against the database, on each request. Revoking a share or changing a
role therefore applies to the very next request, including requests made with a token issued earlier.

| Action                                   | Owner | Editor | Viewer | No access |
|------------------------------------------|:-----:|:------:|:------:|:---------:|
| `GET /api/notes/{id}`                    |  200  |  200   |  200   |    404    |
| `PUT /api/notes/{id}`                    |  200  |  200   |  403   |    404    |
| `DELETE /api/notes/{id}`                 |  204  |  403   |  403   |    404    |
| `GET /api/notes/{id}/shares`             |  200  |  403   |  403   |    404    |
| `POST /api/notes/{id}/shares`            |  201  |  403   |  403   |    404    |
| `PATCH /api/notes/{id}/shares/{shareId}` |  200  |  403   |  403   |    404    |
| `DELETE /api/notes/{id}/shares/{shareId}`|  204  |  403   |  403   |    404    |

A note the caller cannot see is always reported as missing (404), never as forbidden, so note IDs and
share lists cannot be probed. Requests without a valid, unexpired JWT get 401.

Ownership never changes: an editor saving a note stays an editor, and a note cannot be shared with its owner.

## API

All routes need `Authorization: Bearer <JWT>` and are documented with examples in
[`/api/doc.json`](http://localhost:4444/api/doc.json) (tag *Note Sharing*).

### Notes (existing routes, extended)

`GET /api/notes?scope=owned|shared|all`

- `owned` is the default, so the list behaves exactly as before when `scope` is not sent.
- `shared` lists notes shared with the caller, `all` lists both.
- Any other value is a 422. Search, sorting and pagination work the same in every scope.

Note responses have three new read-only fields. Nothing was removed or renamed.

| Field         | Type   | Description                                        |
|---------------|--------|----------------------------------------------------|
| `role`        | string | Caller's role on the note: `owner`, `editor`, `viewer` |
| `owner_id`    | string | ID of the note's owner                             |
| `owner_email` | string | Email of the note's owner                          |

### Shares

**Share object**

```json
{
  "id": "0b0f6f0e-3c5e-4e63-9f0a-6f2f0d0c1a11",
  "note_id": "5f1d7a52-6b53-4d0c-8f0e-2d6b3a9c7e10",
  "user": { "id": "f0f04231-8b66-4e5f-9d55-2eead364ea02", "email": "viewer@example.com" },
  "role": "viewer",
  "version": 1,
  "created_at": "2026-10-11T09:30:00+00:00",
  "updated_at": "2026-10-11T09:30:00+00:00"
}
```

Timestamps are UTC. `version` starts at 1 and increases with every change.

| Route | Request body | Success |
|-------|--------------|---------|
| `POST /api/notes/{id}/shares` | `email` (string, required, max 190), `role` (`viewer` \| `editor`, required) | 201, share object, `Location` header |
| `GET /api/notes/{id}/shares?page=1&itemsPerPage=50` | – (`itemsPerPage` 1–100) | 200, `{ "items": [share], "total", "page", "items_per_page" }` |
| `PATCH /api/notes/{id}/shares/{shareId}` | `role` (required), `version` (integer, optional) | 200, share object |
| `DELETE /api/notes/{id}/shares/{shareId}` | – | 204 |

Unknown fields are rejected. When `version` is sent on `PATCH` and is not the current one, the request
fails with 409 and nothing changes; without it the last write wins. Setting the role a share already has
returns 200 and changes nothing.

**Errors**

| Status | When | Body |
|--------|------|------|
| 400 | Body is not valid JSON or not a JSON object | `{ "error": "Invalid JSON payload." }` |
| 401 | Missing, invalid or expired JWT | `{ "code": 401, "message": "Expired JWT Token" }` |
| 403 | Caller can see the note but is not its owner | `{ "error": "Only the note owner can manage sharing." }` |
| 404 | Note or share missing or not visible; no registered, confirmed user with that email | `{ "error": "User not found." }` |
| 409 | Note already shared with that user; stale `version` | `{ "error": "Note is already shared with this user." }` |
| 422 | Missing, null, wrongly typed, too long or unexpected field; bad `role`; bad pagination | `{ "error": "Validation failed.", "violations": [{ "propertyPath": "role", "message": "..." }] }` |
| 500 | Unexpected failure (no internal details are returned) | `{ "error": "Internal server error." }` |

The notes routes keep API Platform's own error format (`detail`, `violations`).

**Correlation ID.** Every `/api` response carries `X-Correlation-ID`. A client-supplied value is kept when it
is 8–64 characters of `A-Z a-z 0-9 . _ -`; otherwise one is generated. It is stored on audit records.

## Data model

`note_shares`

| Column | Notes |
|--------|-------|
| `id` | UUID, primary key |
| `note_id` | FK → `notes.id`, `ON DELETE CASCADE` |
| `user_id` | FK → `users.id`, `ON DELETE CASCADE` |
| `role` | `viewer` or `editor` |
| `version` | optimistic-lock counter |
| `created_at`, `updated_at` | UTC |

`UNIQUE (note_id, user_id)` guarantees one share per note and user, also under concurrent requests
(the loser gets 409). `user_id` is indexed for the "shared with me" list.

`audit_events` is append-only: `id`, `event_type`, `actor_id`, `note_id`, `share_id`, `target_user_id`,
`metadata` (JSON), `correlation_id`, `created_at`. It has no foreign keys on purpose, so records survive
the notes, shares and users they describe. Indexed on `(note_id, created_at)` and `actor_id`.

| Event | Written when | `metadata` |
|-------|--------------|------------|
| `share.granted` | a share is created | `role` |
| `share.role_changed` | a role actually changes | `from`, `to` |
| `share.revoked` | a share is revoked | `role` |
| `share.removed_with_note` | a shared note is deleted (one per share) | `role` |

Audit records are written in the same transaction as the change. They hold IDs only, no emails or tokens.
There is no API to read them yet; query the table.

### Migration and rollback

`migrations/Version20261012180000.php` only adds the two tables; existing tables and rows are untouched.

```sh
make migrate                                                             # apply (dev and test databases)
docker compose run --rm php-cli php bin/console doctrine:migrations:migrate prev   # roll back
```

Rolling back drops both tables, which deletes all shares and the audit history, so export `audit_events`
first if it must be kept. Notes and users are not affected, and with the previous code version the app
behaves as before. Deploy order: migrate, then release the code; roll back in the reverse order.

## UI

- **Share** on each owned note opens the share dialog: look a member up by email, pick a role, see who
  has access, change a role, or revoke access (with a confirmation).
- **Shared with me** (`/account/shared`) lists notes shared with the signed-in user, with their role and
  the owner's email. Editors get **Update**; viewers get a read-only card with no actions.
- If access is revoked or downgraded while a tab is open, the next action reports it and the list is
  refreshed. The list also refreshes when the tab becomes visible again.
- The UI only hides actions a role cannot use; the server rejects them regardless.

## Fixtures

Deterministic personas and notes, isolated by a run ID (lowercase letters, digits, dashes; max 32):

```sh
docker compose run --rm php-cli php bin/console app:fixtures:sharing:seed my-run    # prints a JSON manifest
docker compose run --rm php-cli php bin/console app:fixtures:sharing:purge my-run
```

| Persona | Email | Notes |
|---------|-------|-------|
| owner | `owner.<runId>@qa-fixtures.test` | owns `private_1`, `private_2`, `shared_1`, `shared_2` |
| viewer | `viewer.<runId>@qa-fixtures.test` | viewer on `shared_1` and `shared_2` |
| editor | `editor.<runId>@qa-fixtures.test` | editor on `shared_1` |
| unrelated | `unrelated.<runId>@qa-fixtures.test` | no access |

All personas are confirmed and share one password (default `Fixture-Passw0rd!`, override with `--password`).
User, note and share IDs are derived from the run ID, so the same run ID always produces the same manifest.
Seeding again resets the run to its initial state; purging removes its users, notes, shares and audit
records and nothing else. Seeding is refused in the `prod` environment. Add `--env=test` to target the
test database.

The same run also carries tags and categories; see [Tags and Categories](tags-categories.md#fixtures).

## Tests

```sh
make test
```

- `tests/Application/Api/NoteSharingTest.php` – role matrix, validation and error contract, conflicts,
  immediate revocation, scope filter, cascade on note delete, audit records, expired tokens, OpenAPI.
- `tests/Application/Command/SharingFixturesTest.php` – fixtures are repeatable, isolated and removable.
- `tests/Unit` – ID and run-ID validation, correlation ID handling.

Tests create their own users under a random prefix in the test database and delete them afterwards.

### Coverage map

All tests below are API-level and live in `NoteSharingTest` unless noted.

| Requirement | Tests |
|-------------|-------|
| FR-01 Roles owner / editor / viewer | `testOwnerSharesNoteWithViewerAndEditor`, `testScopeAllMixesOwnedAndSharedWithRoles` |
| FR-02 Ownership cannot change implicitly | `testEditorCanEditButNotDeleteAndOwnershipIsKept`, `testNoteCannotBeSharedWithItsOwner` |
| FR-03 Unique share per note and user | `testDuplicateShareIsRejected` |
| FR-04 Owner grants, changes, revokes | `testOwnerSharesNoteWithViewerAndEditor`, `testOwnerChangesRole`, `testRevocationTakesEffectImmediately`, `testOwnerListsSharesWithPagination` |
| FR-05 Editor cannot manage permissions or delete | `testOnlyOwnerManagesShares`, `testEditorCanEditButNotDeleteAndOwnershipIsKept` |
| FR-06 Viewer read-only | `testViewerIsReadOnly`, `testOnlyOwnerManagesShares` |
| FR-07 Revocation is immediate | `testRevocationTakesEffectImmediately`, `testOwnerChangesRole` |
| FR-08 Scope filters respect access | `testScopeFilterRespectsAccess`, `testInvalidScopeIsRejected` |
| FR-09 Note delete removes shares | `testDeletingNoteRemovesItsShares` |
| FR-10 Share events are audited | asserted in the grant, role change, revoke and note delete tests |
| Unknown user 404, non-owner 403, enumeration | `testUnknownOrUnconfirmedUserIsNotFound`, `testOnlyOwnerManagesShares`, `testShareIdMustBelongToTheNote` |
| Malformed JSON, missing / null / mistyped / oversized / unexpected fields, Unicode and XSS strings | `testGrantValidation`, `testRoleChangeValidation` |
| Expired or invalid JWT | `testExpiredAndInvalidTokensAreRejected` |
| Concurrent updates | `testStaleVersionIsAConflict` (stale version); the duplicate-share race is covered by the unique constraint, not by a parallel test |
| Fixtures: deterministic, isolated, removable | `SharingFixturesTest` |
| No regression in notes CRUD and search | `testNotesCrudStillWorksForOwner`, `ApiDocControllerTest` |
| OpenAPI | `testOpenApiDocumentsSharingRoutes` |

## Limitations

- No rate limiting (429). `POST .../shares` answers 404 for an unknown email, as specified, which lets a
  signed-in user test whether an email is registered; a limiter on that route is the missing mitigation.
- No browser test suite in this repository; the UI flows were checked manually with a headless browser.
- Sharing sends no email notification.
- Notes have no version field: two people saving the same note at once is last-write-wins.
- Audit records are readable only in the database.
