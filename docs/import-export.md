# Import and Export

Notes can be exported to CSV or JSON and imported from either. An import is checked row by row first,
can be previewed without changing anything, and by default goes in completely or not at all.

## File formats

### CSV

- UTF-8. Exports start with a byte order mark (so spreadsheet programs read them as UTF-8), quote every
  field and end lines with CR LF. Imports accept files with or without the mark, quoted or not, with LF
  or CR LF.
- Standard quoting: a field containing a comma, a quote or a line break is wrapped in quotes, and a quote
  inside is doubled.
- The first row is a header. `title` and `content` are required; `external_id`, `tags`, `category`,
  `created_at` and `updated_at` are optional. Columns may be in any order; any other column is refused.
- `tags` holds tag names separated by semicolons (`work;idea`). A semicolon or backslash inside a name is
  written `\;` or `\\`.

```csv
"external_id","title","content","tags","category","created_at","updated_at"
"a-1","Plan","First line
Second line, with a comma","idea;work","Projects","2026-10-11T09:30:00+00:00","2026-10-12T10:00:00+00:00"
```

**Formula injection.** A spreadsheet runs a cell that starts with `=`, `+`, `-`, `@`, tab or carriage
return as a formula. Exports put an apostrophe in front of such values (`'=SUM(1+1)`), the spreadsheet
convention for "this is text", and also in front of values that already start with an apostrophe. Imports
remove exactly that apostrophe again — one that stands before one of those characters or before another
apostrophe — so an exported file imports back to the same text. Any other leading apostrophe is kept.

### JSON

```json
{
  "schema": "notes-export",
  "schema_version": 1,
  "exported_at": "2026-10-12T10:00:00+00:00",
  "count": 1,
  "notes": [
    { "external_id": "a-1", "title": "Plan", "content": "First line\nSecond line", "tags": ["idea", "work"],
      "category": "Projects", "created_at": "2026-10-11T09:30:00+00:00", "updated_at": "2026-10-12T10:00:00+00:00" }
  ]
}
```

`schema` and `schema_version` must be exactly these; a file of another version is refused. Only `title`
and `content` are required in a note; unknown fields are an error on that note. JSON text needs no
formula protection and is carried as is.

Timestamps are UTC. Unicode is preserved in both formats.

## Rules

| Rule | Value |
|------|-------|
| Limits | 1000 rows and 5 MiB per file |
| `title`, `content` | as for notes: 1–255 and 1–10000 characters |
| `external_id` | optional; 1–64 letters, digits, `.`, `-`, `_`, `:`; unique among **my** notes |
| `tags` | up to 10 names of 1–30 characters; `category` one name |
| `created_at`, `updated_at` | must be ISO 8601 with a time zone if present, but are **not applied**: imported notes get the time of the import |

- **Ownership.** Everything imported is the importer's: the notes, and any tags or categories named in
  the file that the importer does not have yet (existing ones are reused, ignoring case; the limit of 100
  each still applies). External IDs are per user, so another user's ID is neither a conflict nor a way to
  reach their note.
- **Mode.** `atomic` (default): if any row is invalid nothing is imported. `partial`: valid rows are
  imported, the others are reported. Either way an import is one database transaction.
- **Duplicates** — a row whose `external_id` I already use: `reject` (default) makes the row an error,
  `skip` leaves my note as it is, `upsert` updates it. The same `external_id` twice in one file is always
  an error. Rows without an `external_id` always create a new note.
- An `upsert` behaves like an edit in the app: a new revision and audit record when the text changed,
  nothing when it did not.
- **Export scope.** `owned` (default) exports my notes. `shared` and `all` include notes shared with me
  right now. Notes I cannot see are never exported.

## API

`Authorization: Bearer <JWT>`; documented in [`/api/doc.json`](http://localhost:4444/api/doc.json) (tag *Import and Export*).
Limits: 20 imports or checks and 20 exports per user per minute.

| Route | Request | Success |
|-------|---------|---------|
| `POST /api/imports/notes/validate` | multipart: `file`, optional `mode`, `duplicates`, `format` | 200, preview; nothing changes |
| `POST /api/imports/notes` | the same | 200, result |
| `GET /api/exports/notes?format=csv\|json&scope=owned\|shared\|all` | – | 200, the file (`Content-Disposition: attachment`, `X-Total-Count`); at most 5000 notes |

`format` is taken from the file name (`.csv`, `.json`) unless given.

```sh
curl -H "Authorization: Bearer $TOKEN" -F file=@notes.csv -F mode=partial -F duplicates=upsert \
     http://localhost:4444/api/imports/notes/validate
```

```json
{
  "format": "csv", "mode": "partial", "duplicates": "upsert",
  "total_rows": 5, "valid_rows": 4, "invalid_rows": 1,
  "create": 3, "update": 1, "skip": 0,
  "importable": true,
  "errors": [{ "line": 3, "field": "title", "message": "Title is required." }],
  "preview": [{ "line": 2, "action": "create", "external_id": "a-1", "title": "Plan", "content_length": 37, "tags": ["idea", "work"], "category": "Projects" }]
}
```

`line` is the line of the CSV file a row starts on (the header is line 1), or the position in `notes` for
JSON. `importable` says whether the import route would accept the file with these options. `preview` has
the first 20 valid rows; `errors` at most 200. The import route returns the same summary with
`"imported": true` and `notes` (`line`, `action`: `created` / `updated` / `skipped`, `id`) instead of `preview`.

| Status | When |
|--------|------|
| 401 | Missing, invalid or expired JWT |
| 413 | File larger than 5 MiB |
| 422 `{"error", "violations"}` | The file cannot be read at all: no header row or a required column missing, unknown column, not UTF-8, not JSON, wrong schema or version, no rows, more than 1000 rows; no file; bad `mode`, `duplicates`, `format` or `scope`; unknown fields |
| 422 preview with `"imported": false` | Import in `atomic` mode with at least one invalid row; nothing was imported |
| 429 | Rate limit |

## Data model

`notes.external_id` (nullable), `UNIQUE (owner_id, external_id)`. Note responses now include `external_id`.
Audit: `notes.imported` (format, mode, duplicates, counts) and `notes.exported` (format, scope, count),
plus the usual `note.created` / `note.updated` per note, marked `"imported": true`.

### Migration and rollback

`migrations/Version20261013200000.php` adds the column and index; rolling back drops them (notes stay,
but can no longer be matched by a later import).

## UI

On the Notes list:

- **Import** opens a three-step wizard: choose the file and options → see how many rows would be created,
  updated, skipped or are invalid, the problems line by line and a preview of the rows → confirm → result.
  Nothing is imported before the confirmation.
- **Export** offers the format and which notes, and downloads the file.

## Sample files

`tests/fixtures/imports/`:

| File | Use |
|------|-----|
| `notes.csv` | BOM, quoted commas and quotes, a value spanning three lines, Unicode, tags and categories |
| `valid.json`, `invalid.json`, `unsupported-version.json` | a good export, one with broken entries, one of a newer schema version |
| `invalid-rows.csv` | two good rows and three bad ones (no title, bad external ID, 11 tags) |
| `duplicates.csv` | the same external ID twice |
| `malicious.csv` | formulas, markup and SQL-like text |
| `missing-header.csv` | no header row |

## Tests

`make test`; `tests/Application/Api/NoteExchangeTest.php`, `tests/Unit/NoteExchangeFormatTest.php`.

| Requirement | Tests |
|-------------|-------|
| FR-01 BOM; FR-03 quoted comma and newline; FR-12 Unicode and UTC | `NoteExchangeFormatTest`, `testCsvExportAndReimportAreEquivalent`, `testJsonRoundTripAndSchema` |
| FR-02 Versioned JSON schema | `testJsonRoundTripAndSchema`, `testFilesThatCannotBeUsedAtAll` |
| FR-04 1000 rows, 5 MiB | `testRowAndSizeLimits` |
| FR-05 Preview validation | `testValidateShowsWhatWouldHappenAndChangesNothing` |
| FR-06 Atomic; FR-07 per-line errors; bad import changes nothing | `testInvalidRowBlocksAtomicImportAndIsLeftOutOfAPartialOne` |
| FR-08 Formula injection | `NoteExchangeFormatTest`, `testMaliciousInputIsStoredAsTextAndNeutralizedOnExport` |
| FR-09 Duplicates reject / skip / upsert | `testDuplicatePolicies` |
| FR-10 Ownership | `testImportedNotesBelongToTheImporter` |
| FR-11 Shared notes guarded | `testExportOnlyHoldsWhatTheCallerMaySee` |
| Missing header, unusable files, bad options | `testFilesThatCannotBeUsedAtAll` |
| Audit, rate limit | `testImportsAreAuditedAndThrottled` |
| OpenAPI | `testOpenApiDocumentsExchangeRoutes` |

## Limitations

- Shares, attachments, revisions and tasks are not exported or imported.
- Tag and category colors are not part of the files; tags created by an import have none.
- Timestamps in a file are not applied.
- Notes created within the same second have no fixed order relative to each other in an export.
- An import runs while the request waits; 1000 rows take a few seconds.
- No browser test suite in this repository; the UI flows (wizard with an unusable file, invalid rows in
  both modes, confirmation, result, Unicode and malicious files, CSV and JSON export) were checked manually
  with a headless browser.
