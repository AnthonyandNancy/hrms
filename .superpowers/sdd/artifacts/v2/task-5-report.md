# Volume 2 Task 5 Report — Action Manifest Completion and Service Parity

- **Status:** COMPLETE
- **Date:** 2026-11-26
- **Worktree:** `D:/code/ai/factoryHR/.worktrees/v2.1-remediation`
- **Branch:** `feat/v2.1-remediation`
- **Commit:** `feat(contract): complete permission workbench actions and service parity`

## Scope and concurrency

Only the seven files assigned to this task were edited. The concurrent Task 6/7/8/Task-9 Vue, controller, service and OpenAPI files were never edited or staged. Their registry/catalog write declarations were **consumed** after they landed, which is why all six permission-workbench pages are now fully checked with zero skips.

## Delivered

| File | Change |
|---|---|
| `scripts/generate_action_manifest.mjs` | Added optional `services: string[]` per action; new `buildServiceIndex()` parses every `backend/app/**/*.php` class into short-name + FQN method maps; `buildActionManifest`/`validateActionManifest` reject unknown class, unknown method, malformed/duplicate reference, and require `services` on the three newly added governance pages. Added `validateCapabilityGaps()` and `validateWorkbenchCoverage()` plus frozen `WORKBENCH_PAGES` / `SERVICE_MANDATORY_PAGES`. Per-action API uniqueness is now **per page** instead of global, so one real write API can be represented on two different pages (role tab + dedicated workbench) but never twice on the same page. Exports and output ordering preserved. |
| `scripts/audit_action_manifest.mjs` | CLI now passes `capabilityGaps` + `serviceIndex` into the pure audit, runs capability-gap and workbench validation, prints owned-gap count and explicit skips, and keeps the byte-for-byte freshness check. |
| `scripts/tests/generate_action_manifest.test.mjs` | 6 → 28 tests covering service pass/fail (class, method, duplicate, raw index parsing), capability-gap ownership/reason/catalog/page-declaration/stale-gap/duplicate/unknown-page, workbench writable/read-only/skip/service-mandatory modes, cross-page API reuse and same-page duplicate rejection, and gap-cannot-exempt-a-declared-write. |
| `docs/generated/action-input.json` | +5 permission-workbench actions, each with `services`: `system.menus.create/update/delete` → `MenuOverrideService::{create,update,delete}`, `system.data.scopes.save` → `DataScopePolicyService::upsert`, `system.field.policies.save` → `FieldPolicyService::update`. Existing 15 actions untouched (services stay optional). |
| `docs/generated/page-actions.json` | Regenerated: 9 pages, 20 actions. |
| `frontend/src/generated/pageActions.ts` | Regenerated; `GeneratedPageAction` now has optional `services?: string[]`. |
| `docs/audit/2026-11-26-v2-action-gaps.json` | Fixed the stale calendar gap to `POST /v1/system/calendars/publish`; removed the 3 menu-override write gaps now covered by real actions (158 → 155); added a one-line `reason` and measurement fields to all 15 `capabilityGaps`. |
| `docs/audit/2026-11-26-v2-action-gaps.md` | Re-measured; documents enforced gate rules, per-owner counts (155 entries), and all 15 owned capability gaps with reason. |
| `backend/tests/Contract/ActionManifestParityTest.php` | Rewritten to 3 tests: full four-way parity for **every** manifest action, frozen workbench mode coverage, and owned capability-gap reality check. |

## 15 page capability gaps — before/after

**Before: 15. After: 15 retained, 0 legitimately resolvable, 15 with explicit owner + reason.**

No gap was closable. Every one of the 15 capabilities exists in `CapabilityCatalog`, but **no OpenAPI operation carries that exact `x-capability`** (verified by parsing all 323 operations, not substring matching):

- `people.employee.export` — Volume 3 Task 4
- `people.contract.export` — Volume 3 Task 8
- `org.unit.export` — Volume 3 Task 5
- `org.position.export` — Volume 3 Task 6
- `recruitment.requisition.export` — Volume 3 Task 14
- `payroll.payslip.read` — Volume 3 Task 12
- `time.leave.admin` — Volume 3 Task 10
- `time.overtime.manage` — Volume 3 Task 11
- `time.travel.manage` — Volume 3 Task 11
- `workflow.template.manage` — Volume 3 Task 17
- `notification.manage` — Volume 3 Task 16
- `iam.permission.read` — Volume 3 Task 17
- `system.settings.manage` — Volume 3 Task 17
- `notification.read` — Volume 3 Task 16
- `reporting.report.create` — Volume 3 Task 16

Near-misses were deliberately **not** treated as resolutions (same resource, different capability code): `payroll.payslip.read.self`, `engagement.notification.read`, `system.dictionary.manage`/`system.parameter.manage`, `workflow.case.admin`, `reporting.definition.manage`. Inventing or re-labelling a capability/operation would have violated requirement 1.

The 3 write gaps closed (`158 → 155`) are real, already-existing operations newly declared by permission-workbench pages: `POST /v1/system/menus`, `PATCH /v1/system/menus/{page_code}`, `DELETE /v1/system/menus/{page_code}`.

## Permission workbench

| Page | Mode | Actions | Write APIs declared |
|---|---|---:|---|
| `system-users` | writable | 3 | 3/3 covered |
| `system-roles` | writable | 5 | 5/5 covered |
| `system-menus` | writable | 3 | 3/3 covered (services required) |
| `system-permissions` | **read-only** | 0 | 0 (no save API invented) |
| `system-data-scopes` | writable | 1 | 1/1 covered (services required) |
| `system-field-policies` | writable | 1 | 1/1 covered (services required) |

Workbench result: `writableChecked: 5, readOnlyChecked: 1, skipped: []`.

## Gap-validator strength preserved

- Every capability-guarded write is either exactly one page action or an explicitly owned `gaps[]` entry — unchanged.
- A `gaps[]` entry can never exempt a write that a page now declares (`gap … is declared by page …; it must have an action`).
- A stale gap (API no longer in OpenAPI) still fails (`gap … does not match an OpenAPI operation`).
- New: `capabilityGaps[]` entries are validated in both directions — must exist in `CapabilityCatalog`, be declared by each listed page in `PermissionResourceCatalog`, have a one-line reason and a `Volume 2|3 Task N` owner, and **must not exist in OpenAPI** (a stale capability gap is a failure).

## Verification (real output)

### Node tests

```text
$ node --test scripts/tests/generate_action_manifest.test.mjs scripts/tests/audit_action_manifest.test.mjs
ℹ tests 35
ℹ pass 35
ℹ fail 0
```

### Determinism (generator run twice)

```text
$ node scripts/generate_action_manifest.mjs
action manifest OK (9 pages, 20 actions, 155 explicit write gaps, 15 owned capability gaps)
$ sha256sum docs/generated/page-actions.json frontend/src/generated/pageActions.ts
241e31b77463b4016e1ed97a763ff82e9fc81f8f51660263bcdd8bdfcad03b4d *docs/generated/page-actions.json
2c29e3c42f03927ff37ed5430cc1a773548459993cb82aece2a863634d36f762 *frontend/src/generated/pageActions.ts
$ node scripts/generate_action_manifest.mjs
action manifest OK (9 pages, 20 actions, 155 explicit write gaps, 15 owned capability gaps)
$ sha256sum docs/generated/page-actions.json frontend/src/generated/pageActions.ts
241e31b77463b4016e1ed97a763ff82e9fc81f8f51660263bcdd8bdfcad03b4d *docs/generated/page-actions.json
2c29e3c42f03927ff37ed5430cc1a773548459993cb82aece2a863634d36f762 *frontend/src/generated/pageActions.ts
IDENTICAL
```

### Audit gate

```text
$ node scripts/audit_action_manifest.mjs
actions:check OK (160 pages, 10 write pages, 9 pages with actions, 20 actions, 155 explicit write gaps, 15 owned capability gaps, 1 public write exceptions)
EXIT=0
```

### Backend contract tests (required filter command)

```text
$ cd backend && ../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpunit --colors=never     --filter='ActionManifestParityTest|PagePermissionCoverageTest|OpenApiActionLabelTest'
..................                                                18 / 18 (100%)
OK (18 tests, 8506 assertions)
EXIT=0
```

`ActionManifestParityTest` alone: `OK (3 tests, 5148 assertions)`.

Note on ordering: an earlier run of this filter was blocked by Task 9's then-unparseable
`backend/tests/Feature/Authorization/AuthorizationConsistencyTest.php`. The same command was
re-run after Task 9's file became parseable and passed unchanged. Explicit-file invocation of the
same three test files (`... phpunit tests/Contract/ActionManifestParityTest.php
tests/Contract/PagePermissionCoverageTest.php tests/Contract/OpenApiActionLabelTest.php`) produced
the identical result.

## TDD evidence

- Wrote generator tests first; initial run failed with `SyntaxError: … does not provide an export named 'validateCapabilityGaps'` — the right reason. Implemented, then iterated through genuine assertion failures (service index shape, page/API uniqueness, skip reason shape) until 28/28 green.
- Wrote the stricter parity test before the generator/audit were fully wired; it failed with `account-mfa/account.mfa.confirm: services are required on the permission workbench`, proving services are optional for legacy actions and required only for the new workbench actions; scoped the assertion accordingly and reached 3/3 green.
- Added cross-page API reuse tests before changing the uniqueness rule; they failed with `POST /v1/users: has 2 actions; exactly one is required`, then passed after moving uniqueness to `(page, api)`.

## Concerns / follow-ups

1. **Generated artifacts depend on Task 8's declarations**: this commit contains the governance actions and the regenerated manifest, while `frontend/src/router/registry.ts` and `PermissionResourceCatalog.php` (Task 8's write declarations) were still uncommitted at commit time. The gate is green against the current working tree and the artifacts are byte-identical to HEAD, but the two commits must land together on the branch; checked out alone, this commit would report those three pages as explicit workbench skips.
2. **15 capability gaps remain open by design** and need Volume 3 operations before they can become actions. `ActionManifestParityTest` fails the moment any of those operations appears in OpenAPI without the gap being removed and the action added — so they cannot rot silently.
3. **`services` is optional for the existing 15 actions** (per requirement 2). Making it mandatory for every action requires declaring owning services for the auth/MFA/employee/role actions, which belongs to their owning tasks.
4. **Alias capabilities are still tracked, not merged**: `payroll.payslip.read` vs `.read.self`, `notification.read` vs `engagement.notification.read`. A capability-equivalence decision is a Volume 3 contract task, not an action-manifest edit.
