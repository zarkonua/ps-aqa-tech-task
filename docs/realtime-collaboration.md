# Realtime Collaboration

While a note is open, its readers see who else has it open and are told at once when somebody saves,
deletes or loses access. Events only say **that** something happened; the text is always fetched through
the ordinary, versioned note routes.

## Transport

**Server-Sent Events** at `GET /api/realtime/notes/{id}/events`, with a JSON **polling fallback** at
`GET /api/realtime/notes/{id}/poll`. Both need `Authorization: Bearer <JWT>`. A token in the URL is never
accepted, which is why a browser client reads the stream with `fetch()` rather than `EventSource`.

```
retry: 1000

event: connected
data: {"note_id":"fef4…","version":3,"presence":[{"id":"97ee…","email":"owner@example.com"}]}

id: d452df27ad4276790de699a3334ad7a4
event: note.updated
data: {"note_id":"fef4…","version":4,"actor":{"id":"1525…","email":"editor@example.com"},"created_at":"2026-10-11T09:30:00+00:00"}

: ping
```

| Event | Sent to | Data |
|-------|---------|------|
| `connected` | the stream that just opened | `version`, `presence` (who has the note open) |
| `resync` | instead of `connected`, when `Last-Event-ID` is unknown or too old | same; reload the note |
| `note.updated` | all readers | `version`, `actor` |
| `note.deleted` | all readers; the stream then ends | – |
| `share.revoked` | only the user who lost access; the stream then ends | – |
| `presence.joined`, `presence.left` | all readers | `user` |
| `auth.expired` | the stream whose token ran out; the stream then ends | – |

`connected`, `resync` and `auth.expired` have no ID; all other events have one.

### Rules

- **Event IDs are opaque**: random 32-character strings that reveal neither order nor count.
- **Reconnect and replay.** A stream response ends after about 25 seconds. Reconnect with the last ID
  received in `Last-Event-ID` to get everything since, in order, with nothing repeated. Events are kept for
  one hour; for an older or unknown ID the answer starts with `resync`.
- **Duplicates.** A client may still see an event twice around a reconnect (for example when it lost the
  ID). IDs are stable, so remember the ones handled and ignore repeats.
- **Permissions are re-checked every second** while a stream is open. A reader whose share is revoked gets
  `share.revoked` and the stream closes; further connections and polls answer 404. A stream also ends when
  the token it was opened with expires.
- **Presence** lasts 30 seconds from the last sign of life (a stream sends one every 10 seconds, every poll
  is one). `DELETE /api/realtime/notes/{id}/presence` ends it at once. Joining and leaving are announced once.
- **No silent overwrite.** Realtime does not merge text. Saving still goes through `PATCH` with `If-Match`
  ([version history](notes-version-history.md)); a save based on an outdated version is refused with 412.

### Polling

`GET /api/realtime/notes/{id}/poll?after=<last event id>`

```json
{
  "note_id": "fef4…",
  "version": 4,
  "resync": false,
  "events": [{ "id": "d452…", "type": "note.updated", "data": { "version": 4, "actor": { "id": "1525…", "email": "editor@example.com" } }, "created_at": "2026-10-11T09:30:00+00:00" }],
  "last_event_id": "d452df27ad4276790de699a3334ad7a4",
  "presence": [{ "id": "97ee…", "email": "owner@example.com" }]
}
```

Without `after` the call returns the current state and a `last_event_id` to continue from. `resync: true`
means the bookmark was not usable: reload the note. Each call keeps the caller present.

| Status | When |
|--------|------|
| 401 | Missing, invalid or expired JWT (also when a token is sent as a query parameter) |
| 404 | Note missing, deleted or not visible |
| 422 | `after` is not an event ID |

Everyone who can see a note can use these routes, and thereby sees which other readers have it open.

## Data model

| Table | Notes |
|-------|-------|
| `realtime_events` | internal auto-increment `id` (order, never exposed), `public_id` (unique, the opaque ID), `note_id`, `type`, `payload`, `only_for_user_id`, `created_at`. Rows older than one hour are removed as new ones are written |
| `note_presence` | `(note_id, user_id)` primary key, `last_seen_at` |

Neither has foreign keys: a `note.deleted` event has to outlive its note.

### Migration and rollback

`migrations/Version20261013180000.php` adds both tables; rolling back drops them (transient data only).

### Operations

- One open stream holds one web server worker (Apache prefork here, 256 workers) and one database
  connection, and queries once a second. That suits a test environment; for many concurrent readers use an
  event server or a push service instead.
- The response length is `app.realtime.stream_seconds` (25; 0 in the test environment, where a stream
  answers once and ends).

## UI

Opening a note (**Update**, or **View** for viewers, who get a read-only dialog) connects it:

- a badge shows **Live**, **Connected (checking every few seconds)** when the stream is not available and
  polling is used, or **Offline**;
- initials of everyone who has the note open, with their email as tooltip;
- when somebody else saves: a reader's dialog shows the new version at once; an editor who has unsaved
  changes keeps them and gets a notice with **Discard my changes and load it**. Saving anyway opens the
  conflict dialog;
- when access is revoked or the note is deleted, the dialog closes and says so.

## Fixtures

The sharing fixtures provide what is needed: `shared_1` with three readers (owner, editor, viewer) and
three revisions. Transport failure is produced in the test itself, e.g. by blocking
`/api/realtime/**` in the browser. Purging a run removes its events and presence.

## Tests

`make test`; `tests/Application/Api/RealtimeTest.php`.

| Requirement | Tests |
|-------------|-------|
| FR-01 Authenticated stream; FR-04 no JWT in the URL; expired auth denied | `testAuthenticationIsByHeaderOnly` |
| FR-02 Event types | `testStreamStartsWithTheCurrentStateAndReplaysWhatWasMissed`, `testPresenceJoinsLeavesAndExpires`, `testRevokedReaderIsCutOff`, `testDeletedNoteEndsEverything` |
| FR-03 Opaque IDs | `testEventIdsAreOpaque` |
| FR-05 Permissions re-validated; FR-06 revoke disconnects; revoked viewer gets no events | `testRevokedReaderIsCutOff` |
| FR-07 Replay or resync | `testStreamStartsWithTheCurrentStateAndReplaysWhatWasMissed`, `testUnknownBookmarkAsksForAResync` |
| FR-08 No duplicates | `testStreamStartsWithTheCurrentStateAndReplaysWhatWasMissed`, `testPollingGivesTheSameEvents` |
| FR-09 No silent clobber | `NoteHistoryTest::testStaleSaveNeverOverwrites` |
| FR-10 Presence TTL 30 s | `testPresenceJoinsLeavesAndExpires` |
| FR-11 Polling fallback | `testPollingGivesTheSameEvents` |
| OpenAPI | `testOpenApiDocumentsRealtimeRoutes` |

In tests a stream is read as one pass. The long-lived behaviour — events arriving within a second,
pings, the 25-second end, re-checking access while open — was verified against the running server and
with three browsers at once (see below).

## Limitations

- No collaborative editing of the same text: concurrent edits are detected and surfaced, not merged.
- Presence is per user, not per tab or device.
- The stream polls the database once a second; it is not push from the database.
- No browser test suite in this repository. Checked manually with three headless browsers: presence of
  all three, live update in a reader's dialog, notice for an editor with unsaved text, conflict dialog on
  save, offline badge when the transport is blocked, catching up after reconnecting, and the dialog
  closing when the share is revoked.
