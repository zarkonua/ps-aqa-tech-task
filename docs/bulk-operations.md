# Bulk Operations

`POST /api/notes/bulk` applies one operation to up to 100 notes. Every note is authorized and changed on
its own, so one note that cannot be changed does not stop the others.

## Request

`Authorization: Bearer <JWT>`; limit 20 requests per user per minute (429 with `Retry-After`).

```json
{
  "operation": "addTags",
  "ids": ["5f1d7a52-6b53-4d0c-8f0e-2d6b3a9c7e10", "…"],
  "payload": { "tag_ids": ["0b0f6f0e-3c5e-4e63-9f0a-6f2f0d0c1a11"] }
}
```

| Field | Rules |
|-------|-------|
| `operation` | `setCategory`, `addTags`, `removeTags` or `delete` (case-sensitive) |
| `ids` | 1–100 note IDs as strings. Repeated IDs (in any letter case) are processed once |
| `payload` | `setCategory`: `{"category_id": "<id>"}` or `{"category_id": null}` · `addTags` / `removeTags`: `{"tag_ids": [1–10 IDs]}` · `delete`: omitted or `{}` |

| Operation | Who may | Effect |
|-----------|---------|--------|
| `setCategory` | owner, editor | sets or clears the category |
| `addTags` | owner, editor | adds the tags the note does not have yet |
| `removeTags` | owner, editor | removes the tags the note has |
| `delete` | owner | deletes the note and its shares |

Tags and categories must belong to the **owner of each note** (see [Tags and Categories](tags-categories.md)),
so the same payload can succeed for one note and fail for another.

## Response

`200` whenever the request itself is valid, even if no note could be changed:

```json
{
  "operation": "addTags",
  "total": 3,
  "succeeded": ["5f1d7a52-6b53-4d0c-8f0e-2d6b3a9c7e10"],
  "failed": [
    { "id": "9b2c…", "code": "forbidden", "message": "Your role does not allow changing this note." },
    { "id": "1e7d…", "code": "not_found", "message": "Note not found." }
  ],
  "correlation_id": "c2a4b2c4-6f0e-4b53-9f0a-2d6b3a9c7e10"
}
```

`total` counts distinct IDs. `succeeded` lists the notes that are now in the requested state, whether or
not anything had to change.

| `code` | Meaning |
|--------|---------|
| `not_found` | no such note, a malformed ID, or a note the caller cannot see (indistinguishable on purpose) |
| `forbidden` | the caller sees the note but their role does not allow the operation |
| `tag_not_found`, `category_not_found` | an ID in the payload is not in the note owner's taxonomy; the note is not changed at all |
| `tag_limit` | adding would leave the note with more than 10 tags; the note is not changed at all |
| `error` | unexpected failure for this note |

| Status | When |
|--------|------|
| 400 | Body is not a JSON object |
| 401 | Missing, invalid or expired JWT |
| 422 | Unsupported or missing operation; `ids` missing, empty, over 100 or not strings; payload not matching the operation; unknown fields. **Nothing is processed** |
| 429 | Rate limit |

### Retrying

Repeating a request is safe. `setCategory`, `addTags` and `removeTags` report the same notes as succeeded
and change (and audit) nothing the second time. A repeated `delete` reports the already deleted notes as
`not_found`.

### Audit

Each note that actually changes gets its usual record: `note.tags_changed`, `note.category_changed`, or
`note.deleted` plus `share.removed_with_note` per share. They carry the request's correlation ID.
`note.deleted` is now also written by `DELETE /api/notes/{id}`.

## UI

- A **Select** checkbox on every note and **Select page** above the list. The selection is kept while
  paging, searching and filtering; the counter says how many selected notes are not on the current page.
- With a selection, a toolbar offers **Set category**, **Add tag**, **Remove tag**, **Delete selected**
  (after a confirmation) and **Clear selection**. It offers the signed-in user's own tags and categories.
- More than 100 selected notes are sent as several requests.
- Notes that failed stay selected. A report lists them with the reason and offers **Retry failed**.

## Fixtures

`app:fixtures:sharing:seed <runId>` (manifest version 5) adds 105 plain notes of the owner, `bulk_001` …
`bulk_105`, under `bulk_notes` (key → ID): five more than one request accepts. All have the same
`updated_at` (2026-01-10T00:00:00Z). Together with the other fixture notes this gives owner, editor,
viewer and foreign notes for mixed batches.

## Tests

`make test`; `tests/Application/Api/NoteBulkTest.php`.

| Requirement | Tests |
|-------------|-------|
| 1 Max 100 IDs | `testIdLimit` |
| 2 Deduplicate IDs | `testDuplicateIdsAreProcessedOnce` |
| 3 Partial success; 6 per-item code and message | `testMixedPermissionsGivePartialSuccess`, `testTaxonomyComesFromEachNotesOwner`, `testAddTagsRespectsTheTagLimit` |
| 4 Operations | `testTwentyNotesAreUpdatedAndARepeatIsSafe`, `testDeleteIsForOwnersOnly` |
| 5 Owner-only delete | `testDeleteIsForOwnersOnly` |
| 7 Idempotent retries | `testTwentyNotesAreUpdatedAndARepeatIsSafe`, `testDeleteIsForOwnersOnly` |
| 8 Audit each successful mutation | asserted in the same tests |
| 9 No unauthorized side effects; viewer edits forbidden; foreign ID denied | `testMixedPermissionsGivePartialSuccess` |
| Unsupported operation and malformed requests | `testInvalidRequestsAreRejectedAsAWhole` |
| Expired session, rate limit | `testAuthenticationAndThrottling` |
| OpenAPI | `testOpenApiDocumentsTheBulkRoute` |

## Limitations

- Notes are processed one after another, each in its own transaction; there is no all-or-nothing mode.
- No idempotency key: a repeated `delete` cannot tell "already deleted by this request" from "never existed".
- The toolbar adds or removes one tag at a time; the API accepts up to 10.
- No browser test suite in this repository; the UI flows (select across pages, partial failure, retry,
  105 notes deleted in two requests, viewer) were checked manually with a headless browser.
