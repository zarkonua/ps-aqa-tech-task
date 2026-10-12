# Advanced Search and Sorting

`GET /api/notes` combines text search, tag, category, scope and date filters with a stable sort and
pagination. The response body is still a plain JSON array of notes; totals are in response headers.

## Parameters

All are optional and combined by **AND**. Requires `Authorization: Bearer <JWT>`.

| Parameter | Value | Invalid value |
|-----------|-------|---------------|
| `q` | Text to find in title or content, max 200 characters. Case-insensitive; `%`, `_` and quotes are taken literally | 422 |
| `tagIds`, `tagMatch`, `categoryId` | See [Tags and Categories](tags-categories.md#notes-existing-routes-extended). `tagMatch=any` (default) or `all` | 422 |
| `scope` | `owned` (default), `shared`, `all` | 422 |
| `updatedFrom`, `updatedTo` | Inclusive bounds on `updated_at`. `2026-10-11` (the whole UTC day) or `2026-10-11T08:30:00Z` / `…+02:00` | malformed: **400**; from later than to: **422** |
| `sortBy` | `title`, `createdAt`, `updatedAt` (default `updatedAt`) | **400** |
| `order` | `asc`, `desc`. Default `desc` for dates, `asc` for title | **400** |
| `page` | Starts at 1 | 400 |
| `limit` | Page size 1–100, default 20 | 422 |

Notes with the same sort value are always ordered by ID, so paging through a result never repeats or
skips a note, even when many notes share a timestamp.

**Response headers**

| Header | Meaning |
|--------|---------|
| `X-Total-Count` | Number of notes matching the filters that the caller may see |
| `X-Page`, `X-Limit` | The page and page size that were applied |

Only notes the caller owns or has a share on are searched, counted or returned.

Errors raised by these parameters use API Platform's format, e.g.
`{"title": "An error occurred", "detail": "sortBy must be one of: title, createdAt, updatedAt.", "status": 400}`;
`q`, `scope`, `tagIds`, `tagMatch` and `categoryId` also list `violations`.

### Compatibility

Older parameters keep working: `title`, `content`, `sort[updatedAt]`, `sort[title]` and `itemsPerPage`
(`sortBy`/`order` and `limit` win when both forms are sent). Three defaults changed:

| | Before | Now |
|---|--------|-----|
| Page size when none is sent | 30 | 20 |
| Largest page size | 50 | 100 (`itemsPerPage` above it is capped, `limit` above it is a 422) |
| Order when no sort is sent | unspecified | `updatedAt` descending, then ID |

Changing a note's tags or category counts as an update and moves its `updated_at`.

## UI

On the Notes and Shared-with-me lists: search box with field choice, tag filter, **Updated from / to**
date inputs, sort dropdown (updated, created, title), page size, and a row of removable chips for the
active filters with **Reset all**.

- Filters, sort, page size and page are kept in the page URL
  (`/account/notes?q=alpha&sort=title_asc&tag=…&from=2026-02-02&page=2`), so a reload or a copied link shows
  the same list. Unknown values in the URL are ignored.
- An empty result offers **Reset filters**; an inverted date range is explained without sending a request.
- The total and page count come from `X-Total-Count`.

## Fixtures

`app:fixtures:sharing:seed <runId>` (manifest version 3) adds 60 `search_NN` notes, listed under
`search_notes` with their ID, title, owner, timestamps, tags, category and shares.

- 40 are owned by the owner (every 5th shared with the viewer, every 10th also with the editor) and 20 by
  the editor (every second one shared with the owner as viewer).
- `updated_at` is fixed: six notes share each timestamp, one day apart from 2026-02-01T12:00:00Z.
  `created_at` is one hour apart from 2026-01-01.
- Titles cycle through `Alpha`, `Ünïcödé`, `日本語メモ`, `Привет`, `emoji 🎉`, `100% done`, `under_score`,
  `O'Brien; DROP`, `Zeta`, `alpha`; contents through `apple`, `banana`, `cherry`.

## Tests

`make test`; `tests/Application/Api/NoteSearchTest.php`.

| Requirement | Tests |
|-------------|-------|
| FR-01 Full text, case-insensitive | `testFullTextIsCaseInsensitiveOverTitleAndContent` |
| FR-02 Query max 200 | `testQueryLength` |
| FR-03 Filters joined by AND; FR-04 tag any/all | `testFiltersAreJoinedByAnd`, `TaxonomyTest::testFilterNotesByTagAndCategory` |
| FR-05 Inclusive UTC date range | `testDateRangeIsInclusiveAndUtc` |
| FR-06 Inverted range, malformed date, unknown sort | `testInvalidParametersAreRejected` |
| FR-07 Sort fields and directions; FR-09 default | `testSorting`, `testDefaultSortIsUpdatedDescendingWithIdTieBreak` |
| FR-08 Tie-break by ID, stable pages | `testEqualTimestampsGiveStablePages` |
| FR-10 Page from 1, default 20, max 100 | `testPageSize`, `testInvalidParametersAreRejected` |
| FR-11 Total counts only authorized notes; private notes excluded | `testTotalCountsOnlyAuthorizedNotes` |
| SQL-like text is literal | `testSqlLikeTextIsTakenLiterally` |
| Fixtures | `SharingFixturesTest` |
| OpenAPI | `testOpenApiDocumentsSearchParameters` |

## Limitations

- Search is a substring match (`LIKE`), not a ranked full-text index.
- Only `updated_at` can be filtered by date.
- No rate limiting (429) and no browser test suite in this repository; the UI flows (combined filters,
  reload, chips, reset, deep links, bad URL values) were checked manually with a headless browser.
