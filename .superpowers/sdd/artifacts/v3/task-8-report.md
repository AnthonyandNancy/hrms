# Volume 3 Task 8 — Employment contract workspace report

## Status

**DONE_WITH_CONCERNS**

- Worktree: `D:/code/ai/factoryHR/.worktrees/v2.1-remediation`
- Branch: `feat/v2.1-remediation`
- Commit subject: `feat(people): build the employment contract workspace`
- Scope: new `frontend/src/modules/people/contracts/**`, the four requested workspace tests, and additive backend changes in `EmploymentContractController` only. No router, registry, route, OpenAPI or other-module file was changed by this task.

## Delivered

| File | Behaviour |
|---|---|
| `backend/app/Modules/People/Http/Controllers/EmploymentContractController.php` | Adds date/probation validation (422 field errors), renewal target + required reason, and appends a version through the Lifecycle-owned `EmploymentContractVersionLedger` port for creation and renewal. Existing capability guards and employment scoping retained. |
| `backend/tests/Feature/People/ContractWorkspaceTest.php` | 5 feature tests / 61 assertions: draft creation + version, end date/probation 422s, renewal requires reason and preserves history, termination keeps versions and sets end date, capability guards. |
| `frontend/src/modules/people/contracts/api.ts` | Typed wire adapter over the real ledger, scoped show, amendments/version history, document-file attachments, signed download, create, renewal and terminate commands. No fake rows. |
| `frontend/src/modules/people/contracts/pages/ContractWorkspacePage.vue` | Ledger with employee, contract no, Chinese type, start/end, Chinese status, renewal count, 60/30/7/expired filters, create/renew drawer, five states and capability guards. |
| `frontend/src/modules/people/contracts/pages/ContractDetailPage.vue` | Contract facts, version timeline, contract attachments, amend drawer and irreversible termination confirmation naming the employee and effective date. |
| `frontend/src/modules/people/contracts/components/ContractFormDrawer.vue` | Create draft / renew / amend flows; end-after-start, probation ≤ 6 months and inside contract; renewal shows previous version; 422 field errors mapped inline. |
| `frontend/src/modules/people/contracts/components/ContractVersionList.vue` | Version timeline with effective dates, signer, version, status, reason and snapshot facts. |
| `frontend/tests/unit/ContractWorkspace.spec.ts` | 8 unit tests: Chinese ledger/status/filter, local date/probation validation, 422 inline mapping, renewal reason + previous-version comparison, timeline/attachments/termination confirmation, forbidden/error/empty/partial/loading, manual employee fallback and read-only capability guards. |
| `frontend/tests/e2e/real/contract-workspace.spec.ts` | 5 Playwright tests listed against the real config; skipped without a demo password. |

## Backend contract notes

Verified live routes with `php artisan route:list --path=contracts`:

- `GET /v1/employments/contracts` (ledger projection)
- `GET /v1/employments/contracts/expiring`
- `GET|POST /v1/employments/{employmentId}/contracts`
- `GET /v1/employments/{employmentId}/contracts/{id}`
- `GET|POST /v1/employment-contracts/{id}/amendments`
- `POST /v1/employment-contracts/{id}/terminate`
- `DELETE /v1/employment-contracts/{id}`, `POST /v1/employment-contracts/{id}/restore`
- `GET|POST /v1/contract-templates`, `POST /v1/contract-templates/{id}/publish`

There is **no** `GET/PATCH /v1/contracts` or `/v1/contracts/{id}`. Detail therefore reads the scoped
`GET /v1/employments/{employmentId}/contracts/{id}` (with a ledger fallback carrying `employment_id`
in the deep link), and edits append through `POST /v1/employment-contracts/{id}/amendments`.

### Missing routes / API gaps (reported, not added)

1. `GET /v1/contracts/{id}` and `PATCH /v1/contracts/{id}` from the brief do not exist; no routes were invented.
2. There is no direct contract-context attachment route; the workspace filters
   `GET /v1/employments/{employment_id}/document-files` to `category=CONTRACT`.
3. The contract list row has no `employee_name` in the per-employment ledger, and no probation/work-location
   columns on `employment_contracts`; the ledger projection supplies names for the workspace, while probation
   and work location are persisted in the append-only version snapshot.
4. No contract-template menu/route was registered (out of scope and expressly forbidden); template governance
   remains on its existing page.
5. The employee dropdown depends on `people.employee.read`; without it the drawer falls back to manual
   employment-id entry and shows an explicit note.

## Verification evidence

| Command | Result |
|---|---|
| `phpunit --filter='ContractWorkspaceTest'` | **OK (5 tests, 61 assertions)**, exit 0 |
| `phpunit` (full backend) | **OK (1222 tests, 58046 assertions)**, exit 0 |
| `pint --test` | FAIL on concurrent `app/Modules/Payroll/Http/Controllers/PayrollRunController.php` only; owned files pass. |
| `phpstan analyse --no-progress` | 2 errors in concurrent `tests/Feature/Payroll/PayrollWorkspaceTest.php`; no contract errors. |
| `vitest run tests/unit/ContractWorkspace.spec.ts` | **8/8 passed**, exit 0 |
| `pnpm test:unit` | 466 passed / 3 failed, all unrelated to contracts (`pageComponentUniqueness`, `timePayModulePages`, `timePayrollPages`) caused by concurrent payroll router changes. |
| `pnpm typecheck` | Fails only in concurrent `src/modules/payroll/**`; no contract-file errors. |
| `pnpm lint` (owned paths) | exit 0 for `src/modules/people/contracts`, unit and e2e spec. Full lint fails only on concurrent payroll files. |
| `playwright ... --list tests/e2e/real/contract-workspace.spec.ts` | **5 tests in 1 file** |
| UI/copy gates | no failures on `contracts/**`; gates remain red on concurrent files (baseline). |

## TDD evidence

1. Backend test written first; focused run failed for the right reasons:
   `4 failures` — missing creation version (`expected 1, got 0`), missing 422s (`expected 422, received 201`),
   termination version count (`expected 2, got 1`).
2. Controller implementation made the focused run green: `OK (5 tests, 61 assertions)`.
3. Frontend unit test written first; it failed with
   `Failed to resolve import @/modules/people/contracts/pages/ContractWorkspacePage.vue`.
4. Implementation made it green: `8/8`.
5. One architecture correction restored the repository's "no `DB::` in People controllers" rule without
   weakening tests; full backend then passed.

## Concerns

1. Full frontend gates are currently red because a concurrent payroll task is mid-flight; contract-scoped
   typecheck/lint/unit are clean, and the full suite was green before the concurrent payroll/router files changed.
2. This task could not wire the new workspace into `router/pageComponents.ts` or `registry.ts` (forbidden and
   concurrently modified). The components are ready; the lead must map `hr/contracts/index` and
   `hr/contracts/by-id` to them.
3. Creation/renewal history is appended after the contract transaction. The ledger is append-only; a crash
   between the two writes leaves a contract without a first version. Closing that window requires a People
   service hook, which is outside the allowed file list.
4. The UI uses a 20-row server page and a client expiry filter because the ledger route does not accept an
   expiry-window filter; the separate `/expiring` route exists but does not include employee names.

## Commit

```text
feat(people): build the employment contract workspace
```
