# Audit Log

Every change that takes effect is recorded: who did what to which note, share, attachment, task, tag or
account, when, and in which request. Records can be read per note by its readers, and in full by
administrators.

## What is recorded

| Area | Event types |
|------|-------------|
| Notes | `note.created`, `note.updated`, `note.deleted`, `note.restored`, `note.tags_changed`, `note.category_changed` |
| Shares | `share.granted`, `share.role_changed`, `share.revoked`, `share.removed_with_note` |
| Attachments | `attachment.uploaded`, `attachment.deleted` |
| Tasks | `task.created`, `task.updated`, `task.transitioned`, `task.reordered`, `task.deleted` |
| Tags, categories | `tag.created`, `tag.updated`, `tag.deleted`, `category.*` likewise |
| Accounts | `account.password_reset_requested`, `account.password_reset_completed`, `account.password_changed`, `account.email_change_requested`, `account.email_changed`, `account.settings_updated` |

`note.created` and `note.updated` are new with this feature; the others were already written by the
features they belong to.

## Rules

- **One record per effective change.** A request that changes nothing (same text, same role, same order)
  writes nothing. A request that is refused — 401, 403, 404, 409, 412, 422, 429 — writes nothing either.
- **Written with the change.** The record is saved in the same database transaction as the change it
  describes: either both exist or neither.
- **No private content.** `details` summarizes the change — which fields, lengths before and after, roles,
  statuses, counts, IDs — and never contains note text, titles, file names, tag names or email addresses.
- **No credentials.** Passwords, tokens and similar values are never passed to the log, and anything
  under a key that looks like one (`password`, `token`, `secret`, `authorization`, …) is dropped before saving.
- **Immutable.** The application has no way to change a record. Records are removed only by the retention
  job and when test fixtures are purged.
- **Exact order.** Records are ordered by the microsecond they were recorded at (`created_at` itself is
  shown to the second).
- **Retention:** 365 days. `app:audit:prune [--days=N]` removes older records; schedule it to enforce the policy.

## Who can read what

| | Owner | Editor / viewer | No access | Administrator |
|---|:-----:|:---------------:|:---------:|:-------------:|
| `GET /api/notes/{id}/activity` | all note events | without `share.*` | 404 | as their role on the note |
| `GET /api/audit-events` | 403 | 403 | 403 | 200 |

- The activity of a note is for those who can see the note **now**: a revoked share loses it at once.
- Who a note is shared with is its owner's business, so sharing events are hidden from other readers.
  Task events are never part of a note's activity.
- When a note is deleted its activity returns 404 for everyone. The records stay and remain visible in
  the administrators' feed.
- Being an administrator is a flag on the account, changed only from the command line and checked on
  every request:

```sh
docker compose run --rm php-cli php bin/console app:user:admin someone@example.com
docker compose run --rm php-cli php bin/console app:user:admin someone@example.com --revoke
```

## API

`Authorization: Bearer <JWT>`; documented in [`/api/doc.json`](http://localhost:4444/api/doc.json) (tag *Audit Log*).

| Route | Query |
|-------|-------|
| `GET /api/notes/{id}/activity` | `cursor`, `limit` (1–100, default 20) |
| `GET /api/audit-events` | `cursor`, `limit`, `type` (`share.granted`, or a group with the dot: `share.`), `actorId`, `noteId`, `correlationId` |

```json
{
  "items": [
    {
      "id": "0d6c…",
      "type": "note.updated",
      "actor": { "id": "1525…", "email": "editor@example.com" },
      "target_user": null,
      "note_id": "fef4…",
      "subject_id": null,
      "details": { "changed": ["content"], "content": { "length_before": 120, "length_after": 164 }, "version": 3 },
      "correlation_id": "c2a4b2c4-6f0e-4b53-9f0a-2d6b3a9c7e10",
      "created_at": "2026-10-11T09:30:00+00:00"
    }
  ],
  "next_cursor": "MTc5MTgwMDAwMDEyMzQ1Nnww…",
  "limit": 20
}
```

Newest first. `next_cursor` is `null` on the last page; otherwise send it back as `cursor` for the next,
older page. A cursor names the last record delivered, so paging neither repeats nor skips records, also
when new ones are written meanwhile. `subject_id` is the share, attachment, task, tag or category
concerned; `target_user` the user it was done to. An email is `null` when the account no longer exists.
`GET /api/account/profile` now includes `is_admin`.

| Status | When |
|--------|------|
| 400 | `cursor` is not one this API handed out: `{"error": "Invalid cursor."}` |
| 401 | Missing, invalid or expired JWT |
| 403 | Full feed requested by someone who is not an administrator |
| 404 | Note missing, deleted or not visible |
| 422 | Bad `limit` or filter value |

## Data model

`audit_events`: `id`, `sequence` (microseconds since 1970), `event_type`, `actor_id`, `note_id`, `share_id`,
`subject_id`, `target_user_id`, `metadata` (JSON), `correlation_id`, `created_at`. No foreign keys, so
records outlive what they describe. Indexes: `(sequence, id)`, `(note_id, created_at)`, `actor_id`.
`users.is_admin` (default false).

### Migration and rollback

`migrations/Version20261013160000.php` adds `users.is_admin` and `audit_events.sequence`. Existing records
get the start of their second as sequence, so the order among older records of one second is not defined.

```sh
make migrate
docker compose run --rm php-cli php bin/console doctrine:migrations:migrate prev   # roll back
```

Rolling back keeps all audit records and removes administrator flags.

## UI

- **Activity** on every note card: a timeline of what was done, by whom and when, with **Show older**.
  Entries are sentences built from the summary ("changed the title and content (revision 2)").
- **Audit** (administrators only): the full feed with the event type, note ID and request ID of each
  entry, filters for type or group and note ID, and **Show older**.

## Fixtures

`app:fixtures:sharing:seed <runId>` (manifest version 10):

- persona `admin` (`admin.<runId>@qa-fixtures.test`), an administrator with no notes;
- eight audit records under `audit_events`: the life of `shared_1` (created, shared with the viewer and
  the editor, edited by the editor and by the owner), a note that was created and deleted again
  (`deleted_note_id`; it exists only in the log), and one event of the unrelated user.
  Their correlation IDs are `fixture-<runId>-NN`.

## Tests

`make test`; `tests/Application/Api/AuditLogTest.php`.

| Requirement | Tests |
|-------------|-------|
| FR-01 Actor, action, target, time, correlation ID | `testOneEventPerEffectiveChangeAndNoneForNoOps` |
| FR-02 Notes, shares, attachments, tasks | the same, and the audit assertions in each feature's tests |
| FR-03 Summary without private content | `testOneEventPerEffectiveChangeAndNoneForNoOps` |
| FR-04 Written with the change; unauthorized attempt never logged | `testRefusedRequestsLeaveNoEvents` |
| FR-06 Timeline for current readers; deleted private note hidden | `testActivityIsForCurrentReadersAndHidesSharingFromNonOwners` |
| FR-07 Privileged full feed; user global audit 403 | `testFullFeedIsForAdministratorsOnly` |
| FR-08 Stable cursor | `testCursorPagingIsStable` |
| FR-09 Retention | `testRetentionCommand` |
| FR-10 No tokens or passwords | `testCredentialsNeverReachTheAuditTable` |
| One event per change, none for a no-op | `testOneEventPerEffectiveChangeAndNoneForNoOps` |
| Fixtures | `SharingFixturesTest` |
| OpenAPI | `testOpenApiDocumentsAuditRoutes` |

## Limitations

- Immutability is enforced by the application, not by the database: someone with direct database access
  can change records. There is no signature or hash chain.
- Reads (viewing a note, downloading a file) and failed attempts are not recorded.
- The retention job has to be scheduled; nothing runs it automatically.
- No browser test suite in this repository; the UI flows (timeline with paging, hidden sharing events
  for a viewer, administrator feed with filters, refusal for a non-administrator) were checked manually
  with a headless browser.
