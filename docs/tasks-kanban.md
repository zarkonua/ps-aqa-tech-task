# Tasks and Kanban

Tasks live on a board with four columns. A task belongs to the user who created it (its **owner**) and can
be assigned to one other person and linked to one note.

## Rules

| Field | Rule |
|-------|------|
| `title` | required, 1–150 characters after trimming and collapsing whitespace |
| `description` | optional, up to 5000 characters |
| `status` | `backlog`, `todo`, `in_progress`, `done`. New tasks start in `backlog` |
| `priority` | `low`, `medium` (default), `high` |
| `due_at` | optional. ISO 8601 date-time **with a time zone** (`2026-10-20T15:00:00+03:00`, `…Z`); stored and returned in UTC. `null` clears it |
| `assignee_id` | optional. Me or one of my **collaborators**: people I shared a note with, and owners of notes shared with me |
| `note_id` | optional. A note I can see. If the task has an assignee, the assignee must be able to see it too |

### Who can do what

| Action | Owner | Assignee | Anyone else |
|--------|:-----:|:--------:|:-----------:|
| See the task (list, get) | yes | yes | 404 |
| Move it to another status | yes | yes | 404 |
| Change fields, assign, link a note | yes | 403 | 404 |
| Delete | yes | 403 | 404 |
| Reorder a column | own tasks only | – | – |

### Status moves

The server allows one step at a time; a finished task can be reopened.

```
backlog ⇄ todo ⇄ in_progress ⇄ done
```

Any other move — a jump such as `backlog → done`, or "moving" to the status the task already has — is
refused with **409** and a body that says what is possible:

```json
{ "error": "A task cannot move from backlog to done.", "current_status": "backlog", "allowed": ["todo"] }
```

A moved task goes to the end of its new column. Every task response lists its `allowed_statuses`.

### Order

`position` is the task's place in its status column **on its owner's board**, from 0. `GET /api/tasks`
returns tasks by status (backlog, todo, in_progress, done), then position. An assignee sees assigned tasks
among their own by the same numbers.

`PATCH /api/tasks/reorder` takes the IDs of **all** my own tasks of one status in the wanted order. If the
column no longer consists of exactly those tasks — one was added, moved away or deleted since the client
read it — nothing changes and the answer is **409**. Otherwise all positions are rewritten in one
transaction with the column locked.

### Concurrent changes

Each task has a `version` that grows with every change. `PATCH` and the transition accept `version`; when
it is not the current one the answer is 409 and nothing changes. Without it the last write wins.

## API

`Authorization: Bearer <JWT>`; documented in [`/api/doc.json`](http://localhost:4444/api/doc.json) (tag *Tasks*).

| Route | Body | Success |
|-------|------|---------|
| `GET /api/tasks?status=&priority=&assignee=me\|none\|<id>&noteId=&q=&page=&limit=` | – | 200 `{"items", "total", "page", "items_per_page"}`; `limit` 1–100, default 50 |
| `POST /api/tasks` | `title` + optional fields | 201, task, `Location` |
| `GET /api/tasks/{id}` | – | 200, task |
| `PATCH /api/tasks/{id}` | fields to change, optional `version` | 200, task |
| `POST /api/tasks/{id}/transition` | `status`, optional `version` | 200, task |
| `PATCH /api/tasks/reorder` | `status`, `ordered_ids` | 200 `{"status", "items"}` |
| `DELETE /api/tasks/{id}` | – | 204 |
| `GET /api/tasks/assignees?noteId=` | – | 200 `{"items": [{"id", "email"}]}`, me first; with `noteId` only those who can see that note |

```json
{
  "id": "3d2c1b0a-9f8e-4d7c-8b6a-5f4e3d2c1b0a",
  "title": "Prepare release notes",
  "description": "Collect changes since 1.4.",
  "status": "todo",
  "priority": "high",
  "due_at": "2026-10-20T12:00:00+00:00",
  "position": 0,
  "version": 3,
  "role": "owner",
  "owner": { "id": "97ee…", "email": "owner@example.com" },
  "assignee": { "id": "1525…", "email": "editor@example.com" },
  "note": { "id": "fef4…", "title": "Release plan" },
  "allowed_statuses": ["backlog", "in_progress"],
  "created_at": "2026-10-11T09:30:00+00:00",
  "updated_at": "2026-10-12T08:00:00+00:00"
}
```

`role` is what the caller is for this task. `note` is `null` when there is no link **or the caller cannot
see the note** (for example an assignee whose share was revoked).

| Status | When |
|--------|------|
| 400 | Body is not a JSON object |
| 401 | Missing, invalid or expired JWT |
| 403 | Assignee tries to change or delete: `Only the owner of the task can change or delete it.` |
| 404 | Task not visible (`Task not found.`); linked note not visible (`Note not found.`); assignee is not a collaborator or does not exist (`Assignee not found.`) |
| 409 | Move not allowed; stale `version`; stale column on reorder |
| 422 | Field rules above; unknown fields; `status` or `position` sent to create or `PATCH`; assignee cannot see the linked note; bad filter values |

`q` matches title or description, case-insensitively, with `%` and `_` taken literally.

## Data model

`tasks`: `id`, `owner_id` (FK → `users`, cascade), `assignee_id` (FK, `SET NULL`), `note_id`
(FK → `notes`, `SET NULL`: deleting a note unlinks its tasks and keeps them), `title`, `description`,
`status`, `priority`, `due_at`, `position`, `version`, `created_at`, `updated_at`.
Indexes: `(owner_id, status, position)`, `assignee_id`, `note_id`.

Audit events, with the task ID as `subject_id`: `task.created`, `task.updated` (`changed`: field names),
`task.transitioned` (`from`, `to`), `task.reordered` (`status`, `count`), `task.deleted`.
A change that changes nothing is not audited.

### Migration and rollback

`migrations/Version20261013120000.php` adds the table.

```sh
make migrate
docker compose run --rm php-cli php bin/console doctrine:migrations:migrate prev   # roll back
```

Rolling back deletes all tasks. Notes and users are untouched.

## UI

**Tasks** (`/account/tasks`) shows the board.

- **Drag and drop**: drop a card on another column to change its status, or between cards of its column
  to reorder. What the server refuses is explained and the board is reloaded.
- **Keyboard**: every card has buttons for the same moves — `←` `→` to the neighbouring status and, for the
  owner, `↑` `↓` within the column — plus **Edit** and **Delete** (with a confirmation).
- **Filters**: status, priority, and assignee (anyone / assigned to me / unassigned).
- **Task editor**: title, description, priority, due time, linked note (found by searching), assignee.
  The assignee list contains only people who can see the linked note.
- Due times are entered and shown in the time zone from the account settings; overdue tasks are marked.

## Fixtures

`app:fixtures:sharing:seed <runId>` (manifest version 8) adds 20 tasks, listed under `tasks` with ID,
title, status, priority, position, owner, assignee, note and due time.

- Five tasks in each status.
- 16 belong to the owner: every 3rd is assigned to the editor, every 7th to the viewer.
  4 belong to the editor (`task_05`, `10`, `15`, `20`); `task_10` and `task_20` are assigned to the owner.
- Every 4th + 1 (`task_01`, `05`, `09`, …) is linked to `shared_1`.
- Due times are fixed, one day apart from 2026-11-02T09:00Z; every 6th task has none.

## Tests

`make test`; `tests/Application/Api/TaskTest.php`.

| Requirement | Tests |
|-------------|-------|
| 1 Title 1–150; 2 description max 5000; 4 priority | `testCreateValidation`, `testFieldBoundariesAndUnsafeText`, `testCreateWithDefaults` |
| 3 Statuses; 8 server-enforced transitions; illegal transition 409 | `testTransitionsAreEnforcedByTheServer`, `testMovedTaskGoesToTheEndOfTheNewColumn` |
| 5 Due time in UTC; invalid due date 422 | `testDueTimeIsStoredAndReturnedInUtc`, `testCreateValidation` |
| 6 Optional note link; 11 deleting a note unlinks | `testNoteLink` |
| 7 Assignee permission; assigned task visible to the assignee | `testAssigneeMustBeACollaboratorAndSeesTheTask`, `testNoteLink` |
| 9 Atomic reorder; stale reorder 409 | `testReorderIsAtomicAndRejectsAStaleColumn` |
| 10 Optimistic version checks | `testOnlyTheOwnerEditsAndDeletes`, `testTransitionsAreEnforcedByTheServer` |
| 12 Mutations audited | asserted in the create, update, transition, reorder and delete tests |
| Unauthorized access 403 / 404 | `testOnlyTheOwnerEditsAndDeletes`, `testAssigneeMustBeACollaboratorAndSeesTheTask` |
| Filters, search, pagination, board order | `testListFiltersAndBoardOrder` |
| Fixtures | `SharingFixturesTest` |
| OpenAPI | `testOpenApiDocumentsTaskRoutes` |

## Limitations

- One assignee per task; no comments, subtasks or labels.
- The board loads all of the user's tasks and filters them in the browser; the API filters are for other clients.
- An assignee cannot reorder: order belongs to the owner's board.
- Assignees are limited to collaborators on purpose, so assigning cannot be used to test which emails
  have accounts. Share a note with someone to be able to assign to them.
- No browser test suite in this repository; the UI flows (drag to reorder and to move, refused jump,
  keyboard moves, filters, editor with time zone, assignee's view, delete) were checked manually with a
  headless browser.
