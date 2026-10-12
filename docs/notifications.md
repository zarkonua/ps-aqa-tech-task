# Notifications

Users are told, in the app and optionally by email, when something happens that concerns them.

## Events

| Type | Who is notified | When |
|------|-----------------|------|
| `share_granted` | the user the note is shared with | a share is created |
| `share_revoked` | the user who lost access | a share is revoked |
| `task_assigned` | the new assignee | a task is created with, or changed to, an assignee other than the person doing it |
| `task_completed` | the other person on the task (owner or assignee) | a task is moved to `done` |
| `task_due` | the assignee, or the owner of an unassigned task | an unfinished task is due within 24 hours or overdue |

Nobody is notified about their own action.

## Rules

- **One event, one notification.** Each event has a key (for example the share ID); delivering the same
  event again stores nothing and mails nothing. A `task_due` reminder is sent once per due time; changing
  the due time allows a new one.
- **Stored per notification:** ID, type, who caused it, what it is about (a note or a task, by ID), when,
  and when it was read. Titles are **not** stored.
- **Redaction.** The title of the note or task is looked up when notifications are read and included only
  while the recipient can still see it. After a revocation, an unassignment or a deletion the notification
  stays but shows no title and no link. A `share_revoked` notification never names the note.
- **Unread count** is always the number of the user's notifications without a read time; the list, the
  count route and the bell use the same number. Marking as read is idempotent and keeps the first read time.
- **Retention:** notifications older than 90 days are removed.
- **Scope:** a user can only list and mark their own notifications; another user's ID is a 404.
- **Email** is optional and per type. Opting out affects only notification emails: sign-up, password
  reset and email-change messages are always sent, and the in-app notification is always created.
  Emails say what happened but do not contain titles.

`task_due` notifications are created when the user's notifications are read (list or count) and by
`app:notifications:run`, which also applies the retention to all users. Schedule it (cron) if due emails
should arrive without the user opening the app:

```sh
docker compose run --rm php-cli php bin/console app:notifications:run
```

## API

`Authorization: Bearer <JWT>`; documented in [`/api/doc.json`](http://localhost:4444/api/doc.json) (tag *Notifications*).

| Route | Success |
|-------|---------|
| `GET /api/notifications?unread=true&page=1&limit=20` | 200 `{"items", "total", "unread", "page", "items_per_page"}`, newest first; `limit` 1–100 |
| `GET /api/notifications/unread-count` | 200 `{"unread": 2}` |
| `POST /api/notifications/{id}/read` | 200, the notification |
| `POST /api/notifications/read-all` | 200 `{"updated": 2, "unread": 0}` |
| `GET /api/account/notification-preferences` | 200 `{"email": {"share_granted": true, …}}` |
| `PATCH /api/account/notification-preferences` | 200, same shape; send only the types to change |

```json
{
  "id": "8a7b6c5d-4e3f-4a2b-9c1d-0e9f8a7b6c5d",
  "type": "share_granted",
  "message": "owner@example.com shared a note with you: \"Release plan\".",
  "actor": { "id": "97ee…", "email": "owner@example.com" },
  "resource": { "type": "note", "id": "fef4…", "title": "Release plan", "available": true },
  "link": "/account/shared",
  "created_at": "2026-10-11T09:30:00+00:00",
  "read_at": null
}
```

`total` counts what the filter matches; `unread` always counts all unread. `actor` is `null` for
`task_due`. When the resource is out of reach: `"title": null, "available": false, "link": null`.
`message` is plain text; display it as text.

| Status | When |
|--------|------|
| 400 | Preferences body is not a JSON object |
| 401 | Missing, invalid or expired JWT |
| 404 | `Notification not found.` — unknown, malformed or another user's ID |
| 422 | Bad `page`, `limit` or `unread`; unknown notification type or a non-boolean value in preferences; unknown fields |

## Data model

| Table / column | Notes |
|----------------|-------|
| `notifications` | `id`, `user_id` (FK, cascade), `type`, `event_id` (SHA-256 of the event key), `actor_id`, `resource_type`, `resource_id`, `created_at`, `read_at`; `UNIQUE (user_id, event_id)` |
| `users.email_opt_outs` | JSON map of types the user does not want emails for; `NULL` = emails for all |

The unique index is what makes delivery idempotent, also for simultaneous deliveries.

### Migration and rollback

`migrations/Version20261013140000.php`. Existing users get emails for all types.

```sh
make migrate
docker compose run --rm php-cli php bin/console doctrine:migrations:migrate prev   # roll back
```

Rolling back deletes all notifications and email choices.

## UI

- **Notifications** in the header shows a badge with the unread count (refreshed on navigation and every
  minute). It opens the list: unread ones are marked, each can be marked as read, **Mark all as read**
  does the rest, and **Open tasks / Open shared notes** goes to the page where the item is.
- **Profile → Notification emails**: one checkbox per type.

## Fixtures

`app:fixtures:sharing:seed <runId>` (manifest version 9) adds seven notifications, listed per persona
under `notifications` with ID, type, resource and read state: the viewer has two unread `share_granted`
and one read `task_assigned`; the editor one read and one unread; the owner one unread `task_assigned`
and one read `task_completed`. No email is sent for them; emails appear in MailHog when the events are
triggered through the API.

## Tests

`make test`; `tests/Application/Api/NotificationTest.php`.

| Requirement | Tests |
|-------------|-------|
| FR-01 Event types | `testShareTriggersANoticeAndCountDecreasesOnRead`, `testRevokedContentIsRedacted`, `testTaskNotifications`, `testDueTasksNotifyOnceAndOnlyTheirPerson` |
| FR-02 Stored fields | `testShareTriggersANoticeAndCountDecreasesOnRead` |
| FR-03 Idempotent; FR-04 dedupe | `testTheSameEventIsStoredAndMailedOnce`, `testDueTasksNotifyOnceAndOnlyTheirPerson` |
| FR-05 Unread count; replayed mark-read | `testShareTriggersANoticeAndCountDecreasesOnRead` |
| FR-06 Opt-out covers only optional mail; FR-08 mail | `testEmailOptOutIsHonoredAndOnlyCoversNotificationMail` |
| FR-07 Revoked data redacted; no leak | `testRevokedContentIsRedacted`, `testTaskNotifications` |
| FR-09 90-day retention | `testPaginationAndRetention` |
| FR-10 User scope; foreign read 404 | `testNotificationsAreScopedToTheirUser` |
| Fixtures | `SharingFixturesTest` |
| OpenAPI | `testOpenApiDocumentsNotificationRoutes` |

## Limitations

- No push or live updates: the badge is polled.
- Deleting a shared note does not notify the people it was shared with.
- Emails are sent while the triggering request is handled (no queue); a mail failure is logged and does
  not fail the request.
- Links go to a page, not to the individual note or task.
- No browser test suite in this repository; the UI flows (badge, list, mark read, deep link, redaction
  after revoke, mark all, email choices with MailHog) were checked manually with a headless browser.
