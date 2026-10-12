# File Attachments

Owners and editors can attach files to a note; everyone who can see the note can list and download them.

## Rules

| Rule | Value |
|------|-------|
| Size | up to 10 MiB (10 485 760 bytes) per file; empty files are refused |
| Count | at most 5 per note |
| Types | PNG, JPG/JPEG, PDF, TXT, CSV |
| Type check | by the file's **content**, which must agree with the extension. The `Content-Type` the client sends is ignored |
| File name | reduced to a plain name: directories, control and reserved characters (`< > : " \| ? *`) and invisible formatting characters are removed, whitespace is collapsed, the extension is lowercased, at most 150 characters. Unicode letters are kept |
| Storage | under `var/storage/<env>/attachments/`, outside the web root, named by a random key. No part of the client's file name is used in a path, and the key is never returned by the API |

## Role matrix

| Action | Owner | Editor | Viewer | No access |
|--------|:-----:|:------:|:------:|:---------:|
| `GET /api/notes/{id}/attachments` | 200 | 200 | 200 | 404 |
| `GET /api/attachments/{id}/download` | 200 | 200 | 200 | 404 |
| `POST /api/notes/{id}/attachments` | 201 | 201 | 403 | 404 |
| `DELETE /api/attachments/{id}` | 204 | 204 | 403 | 404 |

Access is checked against the note on every request, so a revoked share loses the list and the download
at once. An attachment of a note the caller cannot see is reported as missing.

## API

`Authorization: Bearer <JWT>` on every route, including the download. Documented in
[`/api/doc.json`](http://localhost:4444/api/doc.json) (tag *Note Attachments*).

### Upload — `POST /api/notes/{id}/attachments`

`multipart/form-data` with exactly one part named `file`. Limit: 30 uploads per user per minute.

```sh
curl -H "Authorization: Bearer $TOKEN" -F "file=@report.pdf" http://localhost:4444/api/notes/$NOTE/attachments
```

`201` with a `Location` header:

```json
{
  "id": "7c1e0d3a-2b4f-4d5e-8a9b-0c1d2e3f4a5b",
  "note_id": "5f1d7a52-6b53-4d0c-8f0e-2d6b3a9c7e10",
  "filename": "report.pdf",
  "mime_type": "application/pdf",
  "size": 48213,
  "sha256": "9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08",
  "uploaded_by": "f0f04231-8b66-4e5f-9d55-2eead364ea02",
  "created_at": "2026-10-11T09:30:00+00:00",
  "download_url": "/api/attachments/7c1e0d3a-2b4f-4d5e-8a9b-0c1d2e3f4a5b/download"
}
```

| Status | When | Body |
|--------|------|------|
| 401 | Missing, invalid or expired JWT | `{"code": 401, "message": "…"}` |
| 403 | Caller is a viewer of the note | `{"error": "Your role does not allow changing the attachments of this note."}` |
| 404 | Note missing or not visible | `{"error": "Note not found."}` |
| 413 | File larger than 10 MiB | `{"error": "File is larger than 10 MiB."}` |
| 415 | Type not allowed, or content does not match the extension (a text file named `.jpg`) | `{"error": "Only PNG, JPG, PDF, TXT and CSV files are allowed, and the content must match the extension."}` |
| 422 | No `file` part, several files, an empty file, other fields, or the note already has 5 files | `{"error": "Validation failed.", "violations": [{"propertyPath": "file", "message": "A note can have at most 5 attachments."}]}` |
| 429 | Rate limit | `{"error": "…", "retry_after": 42}` |

A refused upload leaves nothing behind: no record and no file. If saving the record fails after the file
was stored, the file is removed again.

### Other routes

| Route | Success |
|-------|---------|
| `GET /api/notes/{id}/attachments` | 200 `{"items": [attachment], "total": 2, "max": 5}`, ordered by upload time, then name |
| `GET /api/attachments/{id}/download` | 200, the bytes exactly as uploaded |
| `DELETE /api/attachments/{id}` | 204; the file is removed from storage |

The download is always sent as a file to save, never to display:
`Content-Disposition: attachment; filename="…"; filename*=UTF-8''…` (ASCII fallback plus the real name),
the stored `Content-Type`, `X-Content-Type-Options: nosniff`,
`Content-Security-Policy: default-src 'none'; sandbox` and `Cache-Control: private, no-store`.

Deleting a note (single or in bulk) removes its attachments and their files.

## Data model

`note_attachments`: `id`, `note_id` (FK → `notes`, cascade), `uploaded_by` (user ID, no FK),
`filename`, `storage_key` (unique), `mime_type`, `size`, `sha256`, `created_at`.
Uploads to one note are serialized by locking the note row, so concurrent uploads cannot exceed 5.

Audit events: `attachment.uploaded` (`mime_type`, `size`) and `attachment.deleted`, with the attachment ID
as `subject_id`. File names are not written to the audit log.

### Migration and rollback

`migrations/Version20261013080000.php` adds the table.

```sh
make migrate
docker compose run --rm php-cli php bin/console doctrine:migrations:migrate prev   # roll back
```

Rolling back drops the records but not the files. `var/storage` must be writable by the web server and
be part of backups. To remove files no record refers to (after a rollback and re-migration, or after
deleting users directly in the database):

```sh
docker compose run --rm php-cli php bin/console app:attachments:prune
```

## UI

**Files** on every note card opens the list of attached files with **Download**. Owners and editors also get:

- a drop zone and a file chooser (several files at once);
- a progress bar per file;
- immediate feedback for a wrong type, an oversize or empty file, or a full note, and the server's
  reason for anything it refuses, per file;
- **Delete** with a confirmation.

## Fixtures and sample files

`app:fixtures:sharing:seed <runId>` (manifest version 6) attaches three files to `shared_1`, listed under
`notes.shared_1.attachments` with ID, name, type, size and SHA-256: `report.pdf`, `pixel.png` and
`нотатки 日本語.txt`. Purging the run removes the files as well.

Ready-made upload inputs are in `tests/fixtures/attachments/`:

| File | Use |
|------|-----|
| `valid.pdf`, `pixel.png`, `photo.jpg`, `notes.txt`, `data.csv` | one accepted file of each type |
| `файл нотатки.txt` | Unicode file name |
| `fake.jpg` | text content with an image extension → 415 |

For the size boundary, generate a file of exactly 10 485 760 bytes (accepted) and one byte more (413),
e.g. `head -c 10485761 /dev/zero | tr '\0' a > big.txt`.

## Tests

`make test`; `tests/Application/Api/NoteAttachmentTest.php`, `tests/Unit/AttachmentPolicyTest.php`.

| Requirement | Tests |
|-------------|-------|
| 1 Max 10 MiB | `testSizeLimit` |
| 2 Five per note | `testAtMostFiveAttachmentsPerNote` |
| 3 Allowed types; 4 content MIME | `testAllowedTypes`, `testContentMustMatchAnAllowedType`, `AttachmentPolicyTest` |
| 5 Sanitized file name; path traversal | `AttachmentPolicyTest::testSanitizeFilename`, `testAllowedTypes` |
| 6 Opaque key; 7 outside web root | `testFileIsStoredUnderAnOpaqueKeyOutsideTheWebRoot` |
| 8 Viewer download; 9 editor upload/delete; foreign download | `testRolesAndVisibility` |
| 10 Revocation blocks fetch | `testRevokedUserLosesDownload` |
| 11 Failed upload leaves no orphan | `testContentMustMatchAnAllowedType`, `testMalformedUploads`, `testSizeLimit`, `testPruneRemovesOrphanedFilesOnly` |
| 12 Safe Content-Disposition | `testPdfIsStoredAndDownloadedByteForByte`, `testAllowedTypes` |
| Byte-for-byte download | `testPdfIsStoredAndDownloadedByteForByte` |
| Note deletion removes files | `testDeletingANoteRemovesItsFiles` |
| Rate limit | `testUploadsAreThrottled` |
| Fixtures | `SharingFixturesTest` |
| OpenAPI | `testOpenApiDocumentsAttachmentRoutes` |

## Limitations

- No virus scanning, and images are stored as uploaded (metadata such as EXIF is not stripped).
- A request larger than PHP's own limit (`post_max_size`, 256 MB here) is answered 413 without reading it.
- Files are on the local disk of the web container; several web nodes would need shared storage.
- A stored file whose record was removed outside the application stays until `app:attachments:prune` runs.
- Attachments in one list are ordered by upload time to the second, then by name.
- No browser test suite in this repository; the UI flows (multi-file upload with refusals, drag and drop,
  byte-identical download, limit, delete, viewer, revocation with the dialog open) were checked manually
  with a headless browser.
