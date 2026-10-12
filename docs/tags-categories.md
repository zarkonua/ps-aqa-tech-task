# Tags and Categories

Every user has their own tags and categories. A note can carry up to 10 tags and at most one category,
always taken from the taxonomy of the note's **owner**.

## Rules

- Names are trimmed, runs of whitespace become one space, and Unicode is composed (NFC).
  After that a name must be 1–30 characters.
- Names are unique per user **ignoring case** (`Work` = `WORK`), for tags and for categories separately.
  Accents are significant (`Café` ≠ `Cafe`). Two users may use the same name.
- Color is optional: `#RRGGBB` (stored lowercase) or `null`.
- At most 100 tags and 100 categories per user.
- Deleting a tag or category never deletes notes; they only lose that tag or category.
- Editing a note (`PUT /api/notes/{id}`) never changes its tags or category.

## Role matrix

| Action                                              | Owner | Editor | Viewer | No access |
|-----------------------------------------------------|:-----:|:------:|:------:|:---------:|
| See tags and category on a note                     |  yes  |  yes   |  yes   |    404    |
| `GET /api/notes/{id}/taxonomy` (what can be picked) |  200  |  200   |  403   |    404    |
| `PUT /api/notes/{id}/tags`, `PUT .../category`      |  200  |  200   |  403   |    404    |
| Create, rename, recolor, delete the owner's tags and categories | yes | 404 | 404 | 404 |

`/api/tags` and `/api/categories` only ever act on the caller's own taxonomy, so another user's tag or
category ID is simply not found. An editor can therefore change which of the owner's tags are on a shared
note, but cannot change the tags themselves.

## API

All routes need `Authorization: Bearer <JWT>` and are documented in
[`/api/doc.json`](http://localhost:4444/api/doc.json) (tags *Tags*, *Categories*, *Note Tags and Category*).
Errors use the same shapes and the same `X-Correlation-ID` header as [sharing](notes-sharing-rbac.md#api).

### Tags and categories

The two resources are identical; replace `tags` with `categories`.

```json
{
  "id": "0b0f6f0e-3c5e-4e63-9f0a-6f2f0d0c1a11",
  "name": "Work",
  "color": "#1f77b4",
  "version": 1,
  "created_at": "2026-10-11T09:30:00+00:00",
  "updated_at": "2026-10-11T09:30:00+00:00"
}
```

| Route | Request | Success |
|-------|---------|---------|
| `GET /api/tags?q=&page=1&itemsPerPage=50` | `q`: part of the name, case-insensitive, max 30; `itemsPerPage` 1–100 | 200, `{ "items": [tag], "total", "page", "items_per_page" }`, ordered by name |
| `POST /api/tags` | `name` (string, required), `color` (string or null, optional) | 201, tag |
| `PATCH /api/tags/{id}` | `name` and/or `color`, optional `version` (integer) | 200, tag |
| `DELETE /api/tags/{id}` | – | 204 |

| Status | When |
|--------|------|
| 400 | Body is not a JSON object |
| 401 | Missing, invalid or expired JWT |
| 404 | ID missing, malformed or belonging to another user |
| 409 | Name already used (ignoring case); stale `version`; limit of 100 reached |
| 422 | Missing, empty, mistyped or too long name; bad color; unexpected field; `PATCH` with neither name nor color |

A `PATCH` that changes nothing returns 200 and keeps the version.

### Tags and category of a note

| Route | Request | Success |
|-------|---------|---------|
| `GET /api/notes/{id}/taxonomy` | – | 200, `{ "tags": [tag], "categories": [category] }` of the note owner |
| `PUT /api/notes/{id}/tags` | `tag_ids`: list of 0–10 distinct tag IDs; replaces the current tags | 200, see below |
| `PUT /api/notes/{id}/category` | `category_id`: category ID or `null` to clear | 200, see below |

```json
{
  "note_id": "5f1d7a52-6b53-4d0c-8f0e-2d6b3a9c7e10",
  "category": { "id": "…", "name": "Projects", "color": null },
  "tags": [{ "id": "…", "name": "Work", "color": "#1f77b4" }]
}
```

- 422: more than 10 tags (`A note can have at most 10 tags.`), duplicate or malformed IDs, missing field.
- 404: the note is not visible, or an ID is not one of the **note owner's** tags or categories. This
  includes the editor's own tags and any ID that does not exist; nothing is changed in that case.
- 403: the caller is a viewer.

### Notes (existing routes, extended)

Note responses have two new read-only fields; nothing was removed.

| Field | Type | Description |
|-------|------|-------------|
| `tags` | array of `{id, name, color}` | ordered by name, at most 10 |
| `category` | `{id, name, color}` or `null` | |

Null fields of a note are now always present as `null` instead of being left out.

`GET /api/notes` has three new filters, combined with each other and with `q`, `scope`, sorting and
pagination by AND. They never widen what the caller may see.

| Parameter | Value |
|-----------|-------|
| `tagIds` | 1–10 tag IDs separated by commas |
| `tagMatch` | `any` (default): at least one of the tags; `all`: every tag |
| `categoryId` | a category ID |

Malformed values are a 422. An ID that matches nothing gives an empty list.

## Data model

| Table | Notes |
|-------|-------|
| `tags`, `categories` | `id`, `owner_id` (FK → `users`, cascade), `name`, `name_key`, `color`, `version`, `created_at`, `updated_at`; `UNIQUE (owner_id, name_key)` |
| `note_tags` | `(note_id, tag_id)` primary key; both FKs `ON DELETE CASCADE` |
| `notes.category_id` | FK → `categories`, `ON DELETE SET NULL` |

`name_key` is the lowercased name in a binary collation, so the unique index enforces exactly
"same name ignoring case", also under concurrent requests (the loser gets 409). Replacing a note's tags
locks the note row, so concurrent replacements cannot add up to more than 10.

Audit events (same `audit_events` table, which gained a `subject_id` column and a nullable `note_id`):
`tag.created`, `tag.updated`, `tag.deleted`, `category.created`, `category.updated`, `category.deleted`
(with `subject_id`), `note.tags_changed` (`added`, `removed` IDs) and `note.category_changed` (`from`, `to`).
They hold IDs only, never names.

### Migration and rollback

`migrations/Version20261012200000.php` adds the tables and columns above; existing rows are untouched.

```sh
make migrate
docker compose run --rm php-cli php bin/console doctrine:migrations:migrate prev   # roll back
```

Rolling back deletes all tags, categories and their links to notes, and the audit records about tags
and categories. Notes, shares and the other audit records are kept.

## UI

- **Tags** (`/account/tags`): manage tags and categories — add with an optional color picker, edit, delete
  with a confirmation.
- Note cards show the category and the tags as chips.
- **Tags** on a note (owner and editors): category dropdown and tag chips with autocomplete
  (arrow keys and Enter pick, Backspace removes the last chip, Escape closes the list, then the dialog).
- **Tag** filter above the notes list, offering the signed-in user's own tags.

## Fixtures

`app:fixtures:sharing:seed <runId>` (see [sharing fixtures](notes-sharing-rbac.md#fixtures)) also seeds
taxonomy; the manifest (version 2) lists it under `tags`, `categories` and per note.

| Who | Tags | Categories |
|-----|------|------------|
| owner | 11: `Work`, `Über`, `日本語`, `Café`, `Idea 💡`, `Urgent`, `Later`, `Home`, `Reading`, `Travel`, `Spare` | `Projects`, `Personal` |
| editor | `WORK` (same name as the owner's, other case) | `PROJECTS` |

| Note | Tags | Category |
|------|------|----------|
| `private_1`, `shared_2` | none | none |
| `private_2` | 10 (all but `Spare`) | `Projects` |
| `shared_1` | `Work`, `Über` | `Personal` |

## Tests

`make test`. All API-level tests are in `tests/Application/Api/TaxonomyTest.php`.

| Requirement | Tests |
|-------------|-------|
| FR-01 Unique per user ignoring case; FR-06 category name unique | `testNamesAreUniquePerUserIgnoringCase` |
| FR-02 Trim and normalize | `testCrudPersists`, `tests/Unit/TaxonomyNameTest` |
| FR-03 Name length 1–30 | `testCreateValidation`, `testNameLengthBoundariesAndUnsafeText`, `testUpdateValidationAndConflicts` |
| FR-04 Maximum 10 tags per note | `testANoteHoldsAtMostTenTags` |
| FR-05 One category per note | `testNoteGetsTagsAndCategory` |
| FR-07 Hex color optional | `testCrudPersists`, `testCreateValidation` |
| FR-08 Delete detaches notes | `testDeletingTagOrCategoryDetachesNotes`, `testDeletingANoteKeepsTheTaxonomy` |
| FR-09 Editor changes associations, not taxonomy | `testEditorChangesAssociationsWithTheOwnersTaxonomyOnly`, `testTaxonomyIsPrivateToItsOwner` |
| FR-10 Foreign taxonomy IDs rejected | `testEditorChangesAssociationsWithTheOwnersTaxonomyOnly`, `testAssociationValidation` |
| Tag CRUD persists; associations persist | `testCrudPersists`, `testNoteGetsTagsAndCategory`, `testEditingANoteKeepsItsTagsAndCategory` |
| Filter notes by tag | `testFilterNotesByTagAndCategory` |
| Viewer cannot alter tags | `testViewerAndStrangersCannotChangeAssociations` |
| Malformed JSON, missing / null / mistyped / unexpected fields, Unicode and XSS strings, expired JWT, ID guessing | `testCreateValidation`, `testAssociationValidation`, `testTaxonomyIsPrivateToItsOwner` |
| Concurrent updates | `testUpdateValidationAndConflicts` (stale version) |
| Pagination and limits | `testListPaginationAndLimit` |
| Fixtures | `tests/Application/Command/SharingFixturesTest` |
| OpenAPI | `testOpenApiDocumentsTaxonomyRoutes` |

## Limitations

- Tags and category are set through their own routes, not through the note's `POST`/`PUT` body, so
  creating a tagged note takes two or three requests.
- The tag filter in the UI offers one tag at a time and only the user's own tags; the API accepts up to 10
  IDs with `any`/`all`.
- No rate limiting (429) and no browser test suite in this repository; the UI flows were checked manually
  with a headless browser.
- Categories have the same 1–30 character limit as tags; the spec only states it for tags.
