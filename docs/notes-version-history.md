# Notes Version History

Every save that really changes a note's title or content becomes a numbered **revision**. Revisions can be
listed, compared and restored, and a save based on an outdated version is refused instead of overwriting
newer text.

## Rules

- A note starts at version 1. Its `version` is the number of its latest revision.
- Only a change of **title or content** makes a revision. Saving the same text again changes nothing:
  no revision, same `version`, same `updated_at`. Tags, category, shares and attachments do not make revisions.
- A revision stores title, content, who saved it and when (UTC). It is never modified.
- The 100 latest revisions of a note are kept; older ones are dropped.
- Restoring revision *N* writes its title and content as a **new** revision (`kind: "restore"`,
  `restored_from: N`). Restoring the text the note already has changes nothing.
- History follows the note's permissions and is deleted with the note.

| Action | Owner | Editor | Viewer | No access |
|--------|:-----:|:------:|:------:|:---------:|
| List and read revisions | 200 | 200 | 200 | 404 |
| Restore a revision | 200 | 200 | 403 | 404 |
| `PATCH /api/notes/{id}` | 200 | 200 | 403 | 404 |

## Versions and stale writes

Note responses include `version`, and responses carrying one note send it as `ETag: "<version>"`.
A client that saves sends the version its edit is based on in `If-Match`:

| Request | Without `If-Match` | `If-Match` ≠ current version |
|---------|--------------------|------------------------------|
| `PATCH /api/notes/{id}` (new) | 428 Precondition Required | 412 Precondition Failed |
| `POST /api/notes/{id}/revisions/{rev}/restore` (new) | 428 | 412 |
| `PUT /api/notes/{id}` (existing) | accepted, last write wins, as before | 412 |

`If-Match` may be `"3"`, `3`, `W/"3"` or `*` (any version). Anything else is a 412. The check and the
version increment are one atomic step in the database, so of two simultaneous saves based on the same
version exactly one succeeds. After a 412 nothing was changed: reload the note and decide again.

## API

`Authorization: Bearer <JWT>` everywhere; documented in [`/api/doc.json`](http://localhost:4444/api/doc.json)
(tags *Note*, *Note Version History*).

### `PATCH /api/notes/{id}`

Body: `title` and/or `content` (`application/json` or `application/merge-patch+json`), same validation as
`PUT`. Fields left out keep their value. Returns the note, like `PUT`. Errors use API Platform's format
(`{"title", "detail", "status"}`).

### Revisions

| Route | Success |
|-------|---------|
| `GET /api/notes/{id}/revisions?page=1&itemsPerPage=20` | 200, newest first, without content (see below) |
| `GET /api/notes/{id}/revisions/{rev}` | 200, one revision with `content` |
| `POST /api/notes/{id}/revisions/{rev}/restore` | 200 `{"note_id", "version", "restored_from", "title", "content"}` and the new `ETag` |

```json
{
  "items": [
    { "revision": 3, "kind": "restore", "restored_from": 1, "title": "Meeting notes",
      "actor": { "id": "f0f0…", "email": "editor@example.com" }, "created_at": "2026-10-11T09:45:00+00:00" },
    { "revision": 2, "kind": "update", "restored_from": null, "title": "Meeting notes v2",
      "actor": { "id": "97ee…", "email": "owner@example.com" }, "created_at": "2026-10-11T09:40:00+00:00" }
  ],
  "total": 3, "page": 1, "items_per_page": 20, "current_version": 3
}
```

`kind` is `create`, `update` or `restore`. `actor.email` is `null` when that account no longer exists.

| Status | When |
|--------|------|
| 403 | Restore by a viewer: `{"error": "Your role does not allow restoring revisions of this note."}` |
| 404 | Note missing or not visible (`Note not found.`); no such revision, not a positive number, or already dropped (`Revision not found.`) |
| 412, 428 | See *Versions and stale writes* |
| 422 | Bad `page` / `itemsPerPage` (1–100) |

Content is returned as JSON text exactly as saved; nothing in it is interpreted. Clients must escape it
when displaying (the app's UI sets it as text).

## Behaviour changes to existing routes

- `PUT /api/notes/{id}` no longer resets `created_at`, and no longer moves `updated_at` when nothing changed.
- `PUT` and `POST` responses for a note now have `version` and an `ETag`.

## Data model

| Table / column | Notes |
|----------------|-------|
| `notes.version` | integer, default 1 |
| `note_revisions` | `id`, `note_id` (FK → `notes`, cascade), `revision`, `kind`, `restored_from`, `title`, `content`, `actor_id` (user ID, no FK), `created_at`; `UNIQUE (note_id, revision)` |

Audit: `note.restored` with `from_revision` and `new_revision`. Ordinary saves are not audited
separately; the revision itself records who and when.

### Migration and rollback

`migrations/Version20261013100000.php` adds the column and table and gives every existing note a
revision 1 with its current text (attributed to its owner, dated by its last update).

```sh
make migrate
docker compose run --rm php-cli php bin/console doctrine:migrations:migrate prev   # roll back
```

Rolling back deletes all history; notes keep their current text.

## UI

- **Update** saves with the version the note was opened at. If someone else saved in the meantime, a
  **conflict dialog** shows their saved text next to yours with the differing lines marked and offers
  *Overwrite with mine*, *Keep their version* or *Cancel*. An overwrite is still a new revision, so their
  text stays in the history.
- **History** (everyone who sees the note) lists the revisions with author and time. Choosing one shows
  it **side by side** with the current text, removed lines marked on the left and added lines on the right.
- **Restore revision N** (owner and editors) asks for confirmation. If the note changed since the dialog
  was opened, nothing is restored and the history is reloaded first.

## Fixtures

`app:fixtures:sharing:seed <runId>` (manifest version 7): every fixture note has its revision 1, and
`shared_1` has three — a first draft by the owner (with a line of markup), a second draft by the editor
with Unicode text, and the current text — so `notes.shared_1.version` is 3. Each note's `version` is in
the manifest.

## Tests

`make test`; `tests/Application/Api/NoteHistoryTest.php`.

| Requirement | Tests |
|-------------|-------|
| 1 Revision on actual save; two saves in order | `testTwoSavesAppearInOrder` |
| 2 No-op save ignored | `testSavingTheSameTextIsNotARevision`, `testOnlyTextChangesMakeRevisions` |
| 3 Actor and UTC time | `testTwoSavesAppearInOrder` |
| 4 100 latest revisions | `testOnlyTheHundredLatestRevisionsAreKept` |
| 5 Version field and ETag | `testTwoSavesAppearInOrder`, `testIfMatchForms` |
| 6 Stale writes rejected; concurrent stale save never overwrites | `testStaleSaveNeverOverwrites`, `testIfMatchForms` |
| 7 Restore creates a new revision; 11 audit | `testRestoreCreatesANewRevision` |
| 8 Reader may view; 9 editor may restore; viewer 403; permissions inherited | `testHistoryFollowsTheNotesPermissions`, `testRestoreRefusals` |
| 10 Markup is not interpreted | `testRestoreCreatesANewRevision` (API); diff rendering checked in the browser |
| Foreign or unknown revision 404; stale restore | `testRestoreRefusals`, `testHistoryFollowsTheNotesPermissions` |
| PATCH validation and roles | `testPatchValidationAndRoles` |
| History deleted with the note | `testDeletingANoteDeletesItsHistory` |
| Fixtures | `SharingFixturesTest` |
| OpenAPI | `testOpenApiDocumentsHistoryRoutes` |

## Limitations

- `PUT` without `If-Match` still overwrites (kept for existing clients). Use `PATCH`, or send `If-Match`.
- The diff is by whole lines and is computed in the browser; very long texts are shown without marking.
- Revisions store full copies of the text, not differences.
- No browser test suite in this repository; the UI flows (history, diff with markup, restore, conflict
  with discard and with overwrite, viewer) were checked manually with a headless browser.
