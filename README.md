# Senior AQA Engineer Technical Task

This repository contains a practical technical task for a Senior AQA Engineer.

The main goal is to demonstrate how you approach test automation for a real product:
- cover the API with automated tests;
- add UI test coverage for the most important end-to-end user flows.

The expected deliverable is a link to a public GitHub repository with your solution.
Please include a `README.md` in your repository that clearly explains:
- how to set up and run the project locally;
- how to run the automated tests;
- and, if possible, evidence that tests run successfully (for example, CI run links or screenshots).

The idea behind this task is not only to check test writing itself, but also to see your engineering decisions: what scenarios you prioritize, how you structure tests, and how easy your solution is to run and review by another engineer.


## Product description

This is a small notes application with authentication. It is intentionally simple, but still includes enough business flow to design meaningful API and UI automated tests.

Key features:
- Sign-up form: user registration with email and password.
- Email confirmation flow (for Sign Up): account activation via confirmation email.
- Sign-in form: authentication for existing users.
- Profile page: basic account information page for an authenticated user.
- Notes list: list of user notes with search and pagination.
- Notes creation form: create a new note.
- Notes editing form: update an existing note.
- Notes deletion confirmation: confirm and remove a note.
- Notes sharing: share a note with other users as viewer or editor, and see notes shared with you. See [Notes Sharing and RBAC](docs/notes-sharing-rbac.md).
- Tags and categories: per-user tags and categories, up to 10 tags and one category per note, filter notes by tag. See [Tags and Categories](docs/tags-categories.md).
- Advanced search: combined filters, date range, stable sorting and totals for the notes list. See [Advanced Search and Sorting](docs/advanced-search-sorting.md).
- Password reset and account settings: reset by emailed link, change password, change email with confirmation, time zone. See [Password Reset and Account Settings](docs/password-reset-account-settings.md).
- Bulk operations: set category, add or remove tags, or delete up to 100 notes at once, with per-note results. See [Bulk Operations](docs/bulk-operations.md).
- File attachments: up to five PNG, JPG, PDF, TXT or CSV files per note. See [File Attachments](docs/file-attachments.md).
- Version history: every real save of a note is a revision that can be compared and restored; stale saves are refused. See [Notes Version History](docs/notes-version-history.md).
- Tasks: a kanban board of tasks with priorities, due times, assignees and optional links to notes. See [Tasks and Kanban](docs/tasks-kanban.md).

## Tech Stack

- Symfony: backend framework.
- MySQL: relational database for users, notes, and related data.
- MailHog: local email testing service used to capture outgoing emails (including sign-up confirmation emails).

## Setup

Run the following command from the project root:

```sh
make up && make install && make migrate
```

After setup is complete, open the URLs below and verify the app is available.

## URLs

- App UI: [http://localhost:4444](http://localhost:4444)
- API docs JSON: [http://localhost:4444/api/doc.json](http://localhost:4444/api/doc.json)
- API docs UI: [http://localhost:4444/api/doc](http://localhost:4444/api/doc)
- [MailHog](https://github.com/mailhog/mailhog): [http://localhost:8025](http://localhost:8025)
  - Sign-up confirmation emails should appear here.

Recommended order for first check:
1. Open App UI and make sure the main application is reachable.
2. Open API docs to inspect available endpoints and response formats.
3. Open MailHog and verify that test emails are being captured.

## Diagram

This diagram can help you understand the main entities and interactions before test design:
- [Service Diagram](docs/service-diagram.md)

## Fixtures

Seed five personas (owner, viewer, editor, unrelated, unverified) with private and shared notes, tags and categories, isolated by a run ID:

```sh
docker compose run --rm php-cli php bin/console app:fixtures:sharing:seed my-run
docker compose run --rm php-cli php bin/console app:fixtures:sharing:purge my-run
```

Details: [sharing fixtures](docs/notes-sharing-rbac.md#fixtures), [tags and categories fixtures](docs/tags-categories.md#fixtures), [search fixtures](docs/advanced-search-sorting.md#fixtures).

After pulling changes to entities, delete `var/cache/dev` and `var/cache/test`: `cache:clear` can leave stale API metadata behind.

## Good luck!
