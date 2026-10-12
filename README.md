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

## Sharing fixtures

Seed four personas (owner, viewer, editor, unrelated) with two private and two shared notes, isolated by a run ID:

```sh
docker compose run --rm php-cli php bin/console app:fixtures:sharing:seed my-run
docker compose run --rm php-cli php bin/console app:fixtures:sharing:purge my-run
```

Details: [Notes Sharing and RBAC](docs/notes-sharing-rbac.md#fixtures).

## Good luck!
