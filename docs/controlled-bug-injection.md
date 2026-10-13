# Controlled Bug Injection

The runner can switch on known defects for one test run, let agents test that run, and then check
what was really there to find. Ten defects are catalogued. With none switched on — the state of every
new or reset run — the application behaves correctly.

This is part of the runner's test-admin API ([Test Data Management](test-data-management.md)): same
tokens, same rules, not available in production. **This document, the catalog and the oracle are ground
truth. Keep them away from the agents under test.**

## How it works

- A defect is switched on **per run**. It affects requests made by that run's personas
  (`<persona>.<run_id>@qa-fixtures.test`) and nobody else: not other runs, not real users.
- Each defect has a stable `bug_id` and an opaque `challenge_id` (such as `ch-9b2e60`). Both are accepted
  when switching on. The challenge ID says nothing about the defect, so it can be logged or shown.
- The application gives no sign of what is switched on: no header, field, badge, documentation entry or
  audit record. Switching is deliberately **not** written to the audit log, which a run's own
  `admin` persona can read.
- In production nothing is ever switched on, whatever the database says.

## Catalog

`GET /test-admin/bugs` returns it with preconditions, expected and actual behaviour and remediation.

| Bug | Challenge | Severity | Surface | Defect when switched on |
|-----|-----------|----------|---------|-------------------------|
| BUG-001 | ch-4f7a1c | major | api | `GET /api/notes`: pages after the first start one note late |
| BUG-002 | ch-9b2e60 | critical | auth | A viewer can edit a shared note |
| BUG-003 | ch-c31d08 | major | filter | `tagMatch=any` and `tagMatch=all` are exchanged |
| BUG-004 | ch-7e5512 | major | state | `GET /api/notes/{id}` returns the title and content of the previous revision |
| BUG-005 | ch-a06f39 | minor | sort | `sortBy=title` sorts in the opposite direction |
| BUG-006 | ch-2d8b47 | minor | api | `POST /api/tasks` answers 200 instead of 201 |
| BUG-007 | ch-e1930a | minor | async | Sharing a note notifies the recipient twice |
| BUG-008 | ch-58c7d2 | major | api | Task descriptions over 5000 characters are accepted |
| BUG-009 | ch-b47e9f | minor | ui | `X-Total-Count`, and the count shown above the notes list, is one too high |
| BUG-010 | ch-06a2e5 | major | state | A task can be moved to any status |

Combinations are allowed except where one defect would make another impossible to tell apart:
BUG-001 with BUG-005, and BUG-001 with BUG-009. Such a selection is refused with 422.

## API

Schemas and examples: `GET /test-admin/openapi.json` (version 1.1), also `docs/test-admin-openapi.json`.

| Route | Token | Success |
|-------|-------|---------|
| `GET /test-admin/bugs` | admin | 200 `{"bugs": [...]}` |
| `GET /test-admin/runs/{id}/bugs` | admin or run | 200 `{"run_id", "active_bugs": [{"bug_id", "challenge_id"}]}` |
| `POST /test-admin/runs/{id}/bugs` `{"bug_ids": [...]}` | admin or run | 200, the new selection |
| `DELETE /test-admin/runs/{id}/bugs` | admin or run | 204 |
| `GET /test-admin/runs/{id}/oracle-results` | admin or run | 200, one result per defect |

- `POST` **replaces** the selection, so repeating it changes nothing. An empty list is refused; use
  `DELETE`.
- `DELETE` switches everything off and keeps the run's data. `POST /test-admin/runs/{id}/reset` switches
  everything off **and** restores the data; use it after agents have worked in the run.
- A run's summary has `active_bug_count`.

| Status | When |
|--------|------|
| 400 | Body is not a JSON object |
| 401 | No token, an unknown one, or a user's JWT |
| 403 | A run token on another run, or on the catalog |
| 404 | No such run; or the whole API is disabled |
| 409 | The run is being changed by another request |
| 422 | Empty or malformed list, unknown ID, unknown field, defects that exclude each other |

```sh
curl -X POST http://localhost:4444/test-admin/runs/r3f9a1c2b7d4/bugs \
     -H "Authorization: Bearer dev-test-admin-token" -H "Content-Type: application/json" \
     -d '{"bug_ids": ["ch-9b2e60", "BUG-006"]}'
```

## Oracle

`GET /test-admin/runs/{id}/oracle-results` does not report what is switched on; it **checks**. For every
catalogued defect it sends, as the run's own personas and through the whole application, the requests
that reveal it, and compares the answer with that of a correct application.

```json
{
  "run_id": "r3f9a1c2b7d4", "examined_at": "2026-10-14T08:00:00+00:00", "consistent": true,
  "results": [
    {"bug_id": "BUG-002", "challenge_id": "ch-9b2e60", "active": true,
     "expected": 403, "actual": 200, "manifested": true, "consistent": true, "error": null}
  ]
}
```

- `manifested`: expected and actual differ, so the defect can be reproduced right now.
- `consistent`: the defect shows exactly when it is switched on. The top-level `consistent` is true when
  that holds for all ten.

Use it to confirm a run before agents start (everything switched on shows, nothing else does), to confirm
a clean baseline, and as the reference when judging a bug report: a reported defect counts when it
matches a result with `manifested: true` and comes with evidence that reproduces it.

The probes leave traces in the run — `private_2` gets new content, the `unrelated` persona gets
notifications — so reset the run after checking and before agents start.

## Control page

On `/test-admin`, **Challenges** on a run opens a panel with one checkbox per challenge ID (IDs only, no
descriptions). **Apply selection** and **Check run** ask for confirmation; **Check run** shows expected
and actual per challenge.

## Data model and rollback

`test_runs.active_bugs` (JSON, null when none), added by `migrations/Version20261014080000.php`. Rolling
back drops the column: every run behaves correctly again.

## Tests

`make test`; `tests/Application/TestAdmin/BugInjectionTest.php`.

| Requirement | Tests |
|-------------|-------|
| BUG-01 Stable ID, severity, feature, preconditions, oracle, remediation | `testCatalogDescribesEveryDefect` |
| BUG-02 Per run only; NEG-04 concurrent runs isolated | `testEachDefectShowsOnlyWhenSwitchedOn` (a second run is checked each time) |
| BUG-03 Clean baseline; AC-04 | `testBaselineHasNoDefects`; the whole existing suite runs with nothing switched on |
| BUG-04 Catalog, state and oracle hidden; NEG-01; AC-03; UI-02 | `testNothingTellsAnAgentWhatIsSwitchedOn` |
| BUG-05 The required defect types; FIX-01 ten seeds; AC-01 | `testEachDefectShowsOnlyWhenSwitchedOn` (once per defect) |
| BUG-06 Validated combinations; NEG-02 | `testInvalidAndConflictingSelectionsAreRefused` |
| BUG-07 Oracle expected/actual; AC-02 | `testEachDefectShowsOnlyWhenSwitchedOn`, `testBaselineHasNoDefects` |
| BUG-09 Not in production | `TestAdminTest::testItDoesNotExistWithoutATokenOrInProduction` covers the route prefix; `BugSwitch` returns false in production |
| BUG-10 Disable and restore; NEG-03 | `testEachDefectShowsOnlyWhenSwitchedOn`, `testADefectIsVisibleToATesterOfThatRun` |
| UI-01 Select by challenge ID | `testEachDefectShowsOnlyWhenSwitchedOn` switches on by challenge ID; panel checked manually |

## Limitations

- BUG-08 (a defect counts only with reproduced evidence) is a scoring rule. The oracle supplies the
  reference; taking in and judging bug reports belongs to the benchmark feature (17) and is not here.
- The defects live in the application's code behind a per-run switch. Someone who can read the source can
  read them; the agents under test must not have the repository.
- A persona whose email a test changed no longer belongs to its run for this purpose, until reset.
- The oracle's probes are fixed; a defect is only known to it through its one probe.
- BUG-007 doubles the in-app notification; the second email depends on the recipient's email settings.
- BUG-009 is the only defect aimed at the UI. The others show in the UI as a consequence of the API.
- The control page panel was checked manually with a headless browser, not by a committed browser test.
