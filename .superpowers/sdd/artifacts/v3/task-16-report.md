# Volume 3 Task 16 — Dashboard, Reports and Notices Workspace Report

## Status

**Implemented and committed.** The Reporting/Engagement workspace components,
API boundary and 12-test vitest suite are green. Full frontend unit suite,
typecheck, lint, module-boundary audit, page-component audit and backend PHPUnit
are green.

One integration caveat is recorded below: the three pages are purpose-built
module pages but the owned-files restriction prevented editing
`frontend/src/router/registry.ts` / `frontend/src/router/pageComponents.ts`, so
the existing registry keys still resolve to the pre-existing platform wrappers.
They are **not yet reachable as replacements** through `/dashboard`, `/reports`
and `/notices` until a lead-owned router/manifest change points those keys at
this module. No alternate route was invented.

## Commit

`9225de081b6c81967dcec31e0f595d286601f34a` — `feat(reporting): build dashboard, reports and notices`

Staged paths only (explicit `git add -- <paths>`):

- `frontend/src/modules/reporting/api.ts` (new)
- `frontend/src/modules/reporting/pages/DashboardPage.vue` (new)
- `frontend/src/modules/reporting/pages/ReportsPage.vue` (new)
- `frontend/src/modules/reporting/pages/NoticesPage.vue` (new)
- `frontend/src/modules/reporting/components/MetricCard.vue` (new)
- `frontend/src/modules/reporting/components/ReportRunner.vue` (new)
- `frontend/src/modules/reporting/components/ExportPanel.vue` (new)
- `frontend/src/modules/reporting/components/NoticeComposer.vue` (new)
- `frontend/tests/unit/ReportingWorkspace.spec.ts` (new)

No backend, OpenAPI, router registry, other page, shared component or other
module file was edited. No fake rows or static placeholder metrics exist.

## Endpoint verification

`php artisan route:list` was used against this worktree. The following routes
exist and are wired exactly as used:

- `GET /v1/reporting/dashboard` — `reporting.dashboard.read`
- `GET /v1/reporting/dashboard/metrics/{metric}` — `reporting.dashboard.read`
- `GET /v1/reporting/definitions` — `reporting.report.read`
- `POST /v1/reporting/run` — `reporting.report.read`
- `GET /v1/reporting/exports` — `reporting.export.read` (used for status polling)
- `POST /v1/reporting/exports` — `reporting.export`, Idempotency-Key + step-up
- `GET /v1/reporting/exports/{jobId}/download` — `reporting.export`
- `GET /v1/reporting/subscriptions` / `POST /v1/reporting/subscriptions` — exist but are outside this task's five pages
- `GET /v1/engagement/notices` — `engagement.notice.read`
- `POST /v1/engagement/notices` — `engagement.notice.manage,COMPANY`
- `GET /v1/engagement/notices/{notice}` — `engagement.notice.read`
- `POST /v1/engagement/notices/{notice}/acknowledge` — `engagement.notice.read`
- `GET /v1/org-units/tree` — audience picker source; requires `org.tree.read` + `org.unit.read`
- `GET /v1/departments` — not used by this workspace

**No required endpoint is missing.** All named endpoints exist.

### Contract corrections applied

- Export submission uses the real `POST /v1/reporting/exports` contract:
  `reportCode`, `purpose`, `fields`, `asOf`, `filters` and an
  `Idempotency-Key`. It does **not** send `definition_code`.
- Report execution uses the real `POST /v1/reporting/run` contract:
  `definition_code`, `filters`, `as_of`.
- 422 field denial reads `data.reason` (`EXPORT_FIELD_NOT_ALLOWED` /
  `EXPORT_FIELD_HIDDEN`) and renders Chinese copy, never the raw code.
- Reporting list/run envelope fields consumed: `items`, `page`, `page_size`,
  `total`, `as_of`, `freshness`, `source_hash`, `partial`, `partial_reason`.
- The dashboard snapshot uses a UTC computed timestamp in `freshness` rather
  than a projection enum; the page presents it as a live snapshot at that
  timestamp and renders the as-of business date separately.

## What was built

### 1. Module API boundary (`api.ts`)

- Typed transport for dashboard snapshot, metric drill-down, report definitions,
  report run, export history/create, notices list/detail/acknowledge/publish and
  org-unit tree.
- Normalisation rejects rows without valid identity; malformed payloads become
  explicit empty states rather than fabricated records.
- Projection metadata (`as_of`, `source_hash`, `total`, `partial`,
  `partial_reason`, `freshness`) is preserved from the wire.
- Metric values are displayed as integers with locale grouping only; `COUNT`
  units render as `人`/`项`, `MINUTES` as `分钟`, `MINOR_UNITS`/`CENTS` as
  `最小货币单位`, `RATIO` as `%`. **No decimal scale is invented locally.**
- Export field-policy denials map to readable Chinese:
  - `EXPORT_FIELD_NOT_ALLOWED` → 所选字段不在该报表的可导出字段白名单内…
  - `EXPORT_FIELD_HIDDEN` → 所选字段为受限字段，按字段策略不可导出。
  - Row cap, invalid purpose, not-ready, expired and download-limit reasons also
    have Chinese copy.
- Status labels are mapped to Chinese; unknown codes degrade to `其他状态`,
  never raw enums.

### 2. Dashboard (`DashboardPage.vue`, `MetricCard.vue`)

- Real `GET /v1/reporting/dashboard?as_of=` snapshot with a business-date
  picker and refresh.
- Metric cards show label, integer value, API unit, as-of date, freshness and a
  warning when unavailable/partial.
- Per-card click calls `GET /v1/reporting/dashboard/metrics/{metric}?as_of=` and
  renders the drill-down table under the same as-of.
- Unavailable metric reasons are translated to Chinese operational copy instead
  of leaking the backend's English explanation.
- Five states: loading, empty, error with request id + retry, forbidden
  (`reporting.dashboard.read` absent, no request made), partial/unavailable.

### 3. Reports (`ReportsPage.vue`, `ReportRunner.vue`, `ExportPanel.vue`)

- Definition catalog from `GET /v1/reporting/definitions`.
- `ReportRunner` handles definition selection, as-of, server-declared filters
  (`ORG` filters use `GET /v1/org-units/tree`) and run.
- Result table shows as-of, source hash, total, freshness, and partial banner.
  Empty results render an explicit empty state with no invented rows.
- Status cells are translated (`ACTIVE` → 在职, etc.).
- Definition load is resilient: an unavailable org tree leaves the report
  runnable with an honest partial note rather than blocking the catalog.
- `ExportPanel` is independently guarded by `reporting.export` and:
  - selects from API-returned exportable fields only (P3 hidden fields excluded);
  - submits `reportCode`/`purpose`/`fields`/`asOf`/`filters` with an
    `Idempotency-Key`;
  - maps 422 field denials to Chinese;
  - shows the job status and a short-lived download link when `COMPLETED`;
  - polls `GET /v1/reporting/exports` for terminal job states, stopping on
    failure or when the component unmounts.

### 4. Notices (`NoticesPage.vue`, `NoticeComposer.vue`)

- List from `GET /v1/engagement/notices` with `all` / `unread` / `managed`
  filters; `managed` appears only with `engagement.notice.manage`.
- Detail from `GET /v1/engagement/notices/{id}`; a 404 from audience scoping
  renders Chinese copy.
- Acknowledge calls `POST /v1/engagement/notices/{id}/acknowledge` with an
  idempotency key and visibly marks the notice 已确认 in both list and detail.
- Composer collects title/body/audience (`ALL` or `ORG`, with org units from
  the real tree) and requires-ack, then shows an explicit confirmation panel
  naming the audience before `POST /v1/engagement/notices`.
- Acknowledged notices are visibly marked with a Chinese chip and counts.
- Five states: loading, empty, error with request id + retry, forbidden
  (`engagement.notice.read` absent, no requests), partial.

### 5. Capability guards

- Dashboard page and requests require `reporting.dashboard.read`.
- Reports catalog/run require `reporting.report.read`.
- Export panel requires `reporting.export` and is absent otherwise.
- Notices require `engagement.notice.read`; the composer requires
  `engagement.notice.manage`.

## TDD evidence

The vitest suite was written first and observed **RED**:

```
Error: Failed to resolve import "@/modules/reporting/components/MetricCard.vue"
```

after which the module components/API/pages were implemented. A second
intentional RED was observed for the freshness/status refinements before the
mapping was added. Final targeted result: **12 / 12 passing**.

Coverage (12 tests):

1. Metric card exact value/unit/as-of/freshness/partial reason + no invented
   decimal scale.
2. Dashboard snapshot load + business-date query + partial banner + click
   drill-down to the real metric endpoint.
3. Dashboard forbidden state with no request.
4. Report run with as-of + filter and projection metadata (as_of, source_hash,
   total), Chinese status rendering.
5. Honest report partial banner + empty result; unavailable org tree does not
   block definition load.
6. Reports forbidden state with no request.
7. `EXPORT_FIELD_NOT_ALLOWED` → readable Chinese, raw code absent.
8. `EXPORT_FIELD_HIDDEN` → readable Chinese, raw code absent.
9. Export success with exact `reportCode`/`purpose`/`asOf` payload, idempotency
   key, completed job and download link.
10. Notice list/detail/acknowledge with real endpoint + visible 已确认 marker.
11. Composer confirmation naming audience; no POST before confirm; correct
    payload after confirm.
12. Notices forbidden state with no fake rows and no request.

## Verification (real output)

- `cd frontend && pnpm exec vitest run tests/unit/ReportingWorkspace.spec.ts`
  → **12 passed / 12**.
- `cd frontend && pnpm typecheck` → **PASS**.
- `cd frontend && pnpm lint` → **PASS** (`--max-warnings=0`).
- `cd frontend && pnpm test:unit`
  → **58 files / 436 tests passed**.
- `cd backend && ../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpunit
  --colors=never`
  → **OK (1201 tests, 57841 assertions)** (sanity check; no backend edits).
- `node scripts/audit_frontend_modules.mjs`
  → `{"ok":true,"scanned":237,"failures":[]}`.
- `node scripts/audit_page_components.mjs`
  → `{"ok":true,"total":160,"mappedIncludingBase":160,"failures":[]}`.

## Integration caveat (router entry)

The owned-files list excludes `frontend/src/router/registry.ts` and
`frontend/src/router/pageComponents.ts`. The registry currently resolves
`dashboard/index`, `reports/index` and `notices/index` through
`frontend/src/modules/platform/pages/index.ts` to the legacy
`PlatformWorkspace` wrappers. The new module pages are complete and tested, but
a lead-owned change is required to make them the route targets, for example:

- add a `frontend/src/modules/reporting/pages/index.ts` manifest (and thin
  compatibility pages only if a registry key needs a distinct single target); or
- update the platform manifest loaders for `dashboard/index`, `reports/index`
  and `notices/index` to import the new module pages.

This was not done here to avoid editing files outside the task's ownership. The
pages deliberately do **not** import the legacy `@/views/*` shells, and the
module-boundary gate confirms that.

## Notes / gaps

- `GET /v1/engagement/notices` returns a bare array (not a projection
  envelope); the API boundary accepts both shapes and marks `partial=false`
  when the projection fields are absent.
- No separate `GET` endpoint exists for an export job status. The page polls the
  owner-scoped `GET /v1/reporting/exports` list, which is the real read seam.
  It does not invent a job-status endpoint.
- Subscription management (`GET/POST /v1/reporting/subscriptions`) exists but is
  not one of the five required pages; it remains on the platform workspace.
- Export download is rendered as the backend-signed/token URL rather than
  fetched as a blob, preserving expiry/download-cap semantics and avoiding a
  client-side counter mutation.
- Dashboard `freshness` is the backend's computed-at UTC timestamp; it is shown
  as 数据更新于 plus 实时快照 rather than mislabelled as a projection enum.
