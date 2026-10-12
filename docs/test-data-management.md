# Test Data Management and Run Isolation

Whoever runs the tests — a person or an orchestrating program, here called the **runner** — creates
isolated **runs** of deterministic test data, resets them, moves their clock and removes them. Each run
has its own users and data, so runs can be used in parallel without seeing each other.

This is infrastructure for the runner, not a feature of the application. It is separate from the
application's API and must be kept away from the agents under test.

## Privilege boundary

| | |
|---|---|
| Where | `/test-admin/…`, outside `/api`; not in the application's `/api/doc.json` |
| When it exists | only if `APP_ENV` is not `prod` **and** `TEST_ADMIN_TOKEN` is set. Otherwise every route, including the control page, answers 404 like any unknown address |
| Credentials | its own bearer tokens. A user's JWT is never accepted, not even an application administrator's |
| Administrator token | the value of `TEST_ADMIN_TOKEN`: may do everything |
| Run token | returned once when a run is created, stored only as a hash: may read, reset, advance and delete **that run** only |
| Wrong credentials | no or unknown token: 401. A run token used on another run or on an administrator route: 403 |

`TEST_ADMIN_TOKEN` is empty in `.env`. `.env.dev` sets `dev-test-admin-token` so that the local
environment works out of the box; change it in `.env.local` when the machine is reachable by others.

## Runs

- A run ID is 1–32 lowercase letters, digits or dashes (generated when not given).
- A run has six personas with the emails `<persona>.<run_id>@qa-fixtures.test` and one shared password
  (`Fixture-Passw0rd!`): `owner`, `viewer`, `editor`, `unrelated`, `unverified` (cannot sign in) and
  `admin` (application administrator).
- All identifiers are derived from the run ID: the same run ID always has the same user, note, share,
  tag, task… IDs, also after a reset.
- **Fixture** chooses what the run contains. Every fixture has the personas, the four base notes
  (`private_1`, `private_2`, `shared_1`, `shared_2`) with their shares, and the tags and categories.

| Fixture | Adds |
|---------|------|
| `basic-notes` | nothing more |
| `sharing-rbac` | revision history of `shared_1`, notifications, audit records |
| `search-edge` | 60 search notes with fixed timestamps |
| `bulk-mixed` | the search notes and 105 bulk notes |
| `attachments` | three files on `shared_1` |
| `concurrency` | revision history and 20 tasks |
| `async-faults` | tasks and notifications |
| `full` (default) | everything |

- **Seed** (1–1000000, default 1) varies the generated content — which title words and keywords the
  search notes get, which priorities the tasks. The same fixture, fixture version and seed always produce
  the same content.
- **Manifest**: the JSON description of everything in the run, with all identifiers. Its `manifest_hash`
  is computed without what differs between runs (the run ID, generated IDs, the password), so two runs with
  the same fixture, version and seed have the same hash although they share no data.
- At most 50 runs exist at a time.

### Reset

`POST /test-admin/runs/{id}/reset` brings the run back to exactly its initial state, with the same
identifiers:

- every row tests created, changed or deleted in the run: notes, shares, revisions, tags, categories,
  tasks, attachments and their files, notifications, tokens, audit records of its personas;
- rate-limit counters of its personas, realtime events and presence of its notes;
- sign-ins: tokens issued before the reset stop working;
- the run's clock goes back to real time;
- captured mail to its personas is deleted from MailHog (`MAILHOG_API_URL`; the answer has
  `mail_deleted: null` when MailHog is not configured or not reachable).

Resetting twice gives the same result as resetting once. Changes of one run are serialized: a second
change waits up to five seconds and is then refused with 409.

### Clock

`POST /test-admin/runs/{id}/advance-clock` moves a run's clock forward, for that run's personas only.

| Follows the run's clock | Does not |
|-------------------------|----------|
| expiry of password-reset and email-confirmation links | lifetime of sign-in tokens (JWT) |
| "task due" notifications | rate-limit windows, presence, retention periods |
| | timestamps written to records (`created_at`, `updated_at`) |

A clock only goes forward, at most ten years; a reset puts it back. A persona whose email was changed by
a test no longer follows the run's clock until the run is reset.

## API

Runner-only OpenAPI document: `GET /test-admin/openapi.json` (administrator token), also in
`docs/test-admin-openapi.json`. Errors are `{"error": "…"}`, validation errors add `violations`.

| Route | Token | Success |
|-------|-------|---------|
| `GET /test-admin/fixtures` | admin | 200, fixture names and parts |
| `GET /test-admin/runs` | admin | 200 `{"items": [run], "max": 50}` |
| `POST /test-admin/runs` `{fixture?, seed?, run_id?}` | admin | 201, run + `run_token` + `manifest` |
| `GET /test-admin/runs/{id}` | admin or run | 200, run |
| `GET /test-admin/runs/{id}/manifest` | admin or run | 200, run + `manifest` |
| `POST /test-admin/runs/{id}/reset` | admin or run | 200, run + `mail_deleted` + `manifest` |
| `POST /test-admin/runs/{id}/advance-clock` `{seconds}` | admin or run | 200, run |
| `DELETE /test-admin/runs/{id}` | admin or run | 204 |

```sh
curl -X POST http://localhost:4444/test-admin/runs \
     -H "Authorization: Bearer dev-test-admin-token" -H "Content-Type: application/json" \
     -d '{"fixture": "sharing-rbac", "seed": 7}'
```

```json
{
  "run_id": "r3f9a1c2b7d4", "fixture": "sharing-rbac", "fixture_version": 11, "seed": 7,
  "manifest_hash": "a2871f5a9039…", "resets": 0,
  "clock_offset_seconds": 0, "clock_now": "2026-10-12T21:30:00+00:00",
  "created_at": "2026-10-12T21:30:00+00:00", "updated_at": "2026-10-12T21:30:00+00:00",
  "run_token": "5c3d…", "manifest": { "users": { "owner": { "id": "…", "email": "owner.r3f9a1c2b7d4@qa-fixtures.test" } }, "…": "…" }
}
```

| Status | When |
|--------|------|
| 400 | Body is not a JSON object |
| 401 | No token, or an unknown one |
| 403 | A run token where it does not apply |
| 404 | No such run (with the administrator token); or the whole API is disabled |
| 409 | Run ID already exists; the run is being changed by another request; clock more than ten years ahead |
| 422 | Unknown fixture, seed out of range, malformed run ID, bad `seconds`, unknown fields |
| 429 | 50 runs exist already |

Creating, resetting, advancing and deleting are recorded in the audit log as `run.created`, `run.reset`,
`run.clock_advanced` and `run.deleted`, with the run ID as `subject_id`.

## Control page

`/test-admin` is a small page for the runner: after entering the administrator token (kept for the
browser tab only) it lists the runs and offers **Create run**, **Manifest**, **Reset**, **+1 hour**,
**+1 day** and **Delete** (reset and delete ask first). The application never links to it.

## Command line

The commands from earlier features still work, without registering a run:

```sh
docker compose run --rm php-cli php bin/console app:fixtures:sharing:seed my-run --fixture=search-edge --seed=5
docker compose run --rm php-cli php bin/console app:fixtures:sharing:purge my-run
```

## Data model

`test_runs`: `id`, `fixture`, `fixture_version`, `seed`, `manifest`, `manifest_hash`, `token_hash`,
`clock_offset_seconds`, `resets`, `created_at`, `updated_at`.
`migrations/Version20261013220000.php` adds it; rolling back drops it and leaves the runs' data, which
can then be removed with the purge command.

## Tests

`make test`; `tests/Application/TestAdmin/TestAdminTest.php`.

| Requirement | Tests |
|-------------|-------|
| RUN-01 Isolated data per run; RUN-08 concurrent runs cannot see each other; AC-02 | `testTheSameSeedGivesTheSameContentInDisjointRuns` |
| RUN-02 Fixture version and seed; RUN-03 same seed, same state, identifier mapping; RUN-09 hash; AC-01 | `testTheSameSeedGivesTheSameContentInDisjointRuns`, `testCreateRunAndSignInAsItsPersonas` |
| RUN-04 Reset removes rows, files, events, sign-ins; RUN-10 idempotent; AC-03 | `testResetClearsEverySideEffect` (MailHog removal checked against the running stack) |
| RUN-05 Persona presets | `testCreateRunAndSignInAsItsPersonas`; expired and used tokens: `SharingFixturesTest`, `AccountTest` |
| RUN-06 Time override per run | `testClockIsAdvancedForOneRunOnly` |
| RUN-07 Not mounted in production; NEG-03; AC-04 | `testItDoesNotExistWithoutATokenOrInProduction` |
| NEG-01 Other-run reset denied | `testARunTokenOnlyReachesItsOwnRun` |
| NEG-02 Wrong admin token denied | `testOnlyTestAdminTokensAreAccepted` |
| NEG-04 Reset during an active change is safe | `testASecondChangeOfTheSameRunWaitsThenGivesUp` |
| NEG-05 Seed replay stable; invalid input, conflicts, quota | `testResetClearsEverySideEffect`, `testCreateValidationConflictsAndQuota` |
| Separate from the application and its documentation | `testTheApiIsNotPartOfTheApplication` |

## Limitations

- Tokens do not expire and have no finer scopes than "everything" and "one run".
- Runs live in the application's database, isolated by their identifiers and addresses, not in separate
  databases or schemas.
- Only what is listed above follows a run's clock; there is no global clock abstraction.
- Realtime events of notes that a test both created and deleted stay until they expire (one hour).
- A reset replaces data while requests of the run's users may still be arriving; those requests then
  fail (401 or 404), which is the intended outcome but not a graceful one.
- The control page was checked manually with a headless browser against the running stack.
