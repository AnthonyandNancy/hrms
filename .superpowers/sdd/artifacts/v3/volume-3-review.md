# Volume 3 Remediation — Independent Review

Date: 2026-11-27 (Asia/Singapore)
Branch: `feat/v2.1-remediation`
Worktree: `D:/code/ai/factoryHR/.worktrees/v2.1-remediation`
Target: `docs/audit/2026-11-27-v2-volume-3-acceptance.md` (implementer claims, unverified)
Requirements: `docs/superpowers/plans/2026-11-26-v2-1-volume-3-employee-domains.md`

## Verdict

**APPROVED_WITH_BLOCKERS**

The quality gates genuinely pass, but six accepted contracts are not satisfied:
payroll run-list/detail money is scoped by "has P3 anywhere" rather than per run;
the contract row and its creation version are not atomic; the contract create
route is missing the CSRF gate its matrix claims; the recruitment candidate
workspace is implemented in tests but not wired to production routes; the
documented candidate-stage payload disagrees with the controller; and the
registered notice archive/restore actions lack a reachable control on their
`/notices` host workspace (restore exists nowhere in the frontend).

## BLOCKERS

| id | file:line | wrong | why it matters | evidence |
| --- | --- | --- | --- | --- |
| V3-B1 | `backend/app/Modules/Payroll/Http/Controllers/PayrollRunController.php:44,82` (also `:59-71`; `PayrollRunService.php:501-505`) | Amount visibility is decided once per request with `canSeeAmounts()`, which calls `permits('payroll.payslip.read')` at no anchor and therefore returns true for a P3 grant on any run (or at any allowed anchor). The list's `else` branch only resolves field sets for audit; it never removes amounts for runs where the caller lacks P3. `show()` uses the same global boolean. | A user who holds P3 on run A but is merely a lifecycle actor on run B gets run B's gross/deductions/net/employer-cost amounts from both `/runs` and `/runs/{id}`. This is a cross-run P3 disclosure in the exact area the acceptance says was fixed. | `EloquentPayrollRunMembershipDirectory::runIdsForUser()` selects every lifecycle-actor run; `runSummary()` adds all four `*_cents` keys whenever `$includeAmounts` is true, and `runDetail()` likewise emits every employee's amounts. The regression test only exercises `/runs/{id}/employees`, not list/detail with heterogeneous P3 grants. |
| V3-B2 | `backend/app/Modules/People/Http/Controllers/EmploymentContractController.php:72-95` and `backend/app/Modules/People/Services/EmploymentContractService.php:46-87` | Contract row commit and creation-version append are separate transactions. The controller calls `contracts->open()` (transaction commits), then `versions->append()` (new transaction). The acceptance's claimed `afterOpen` callback does not exist (`rg afterOpen backend` returns nothing). The controller still orchestrates the cross-module ledger write. | A ledger/audit failure after `open()` leaves a contract with no `CREATION` version. The acceptance explicitly claims they "still commit together", so the stated atomicity and controller-orchestration fix are false. No failure-injection test covers this path. | `open()` returns at service line 86-87 and its transaction closes there; controller line 75 starts the append only after that return. |
| V3-B3 | `frontend/src/modules/talent/pages/CandidateListPage.vue:2-10`, `CandidatePipelinePage.vue:2-10`, `CandidateDetailPage.vue:2-10`, `backend/tests/.../RecruitmentWorkspaceTest.php:40-51` | The new `modules/talent/recruiting/pages/CandidateWorkspacePage.vue` and `CandidateDetailPage.vue` are imported only by their unit test. Production registry keys `recruitment/candidates/index`, `.../pipeline`, `.../by-id` still load the generic `TalentWorkspacePage`; only `RequisitionListPage.vue` was rewired to the new workspace. | The six-stage pipeline, explicit stage move, candidate detail and conversion UI are not reachable from the production routes. The page action `recruitment.candidates.move.stage` is registered against `recruitment-candidates`, which renders the old generic page, so the action has no real host control. This contradicts "every new workspace reachable from the registry". | `pages/index.ts:18-21` maps the candidate keys to `CandidateListPage.vue` / `CandidatePipelinePage.vue` / `CandidateDetailPage.vue`; each imports `../components/TalentWorkspacePage.vue`. `rg` finds no production import of `recruiting/pages/CandidateWorkspacePage`. |
| V3-B4 | `docs/openapi/openapi.yaml:15929-15934`, `backend/app/Modules/Talent/Http/Controllers/RecruitmentController.php:203-205` (also `:35-42`) | `moveCandidateStage` documents a required `stage` field with enum `NEW,SCREENING,INTERVIEW,OFFER,HIRED,REJECTED`; the controller requires `status`, accepts `OFFER_ACCEPTED`, and rejects `HIRED`. | Any client generated from OpenAPI (or a contract test) sends `stage` and gets 422 because `status` is missing; clients following the documented enum hit the invalid-stage path. The hand-written frontend happens to send `status`, which hides the break. `updateRequisition` similarly documents a `status` property that the controller neither validates nor applies. | OpenAPI `required: [stage]`; controller `['status' => ['required', ...]]`; `MOVABLE_CANDIDATE_STAGES` contains `OFFER_ACCEPTED`, not `HIRED`. |
| V3-B5 | `frontend/src/modules/reporting/pages/NoticesPage.vue:149-151`; `docs/generated/page-actions.json` (`notices` archive/restore); `frontend/src/modules/platform/components/PlatformWorkspace.vue:2589-2596` | The `/notices` route (pageCode `notices`, which hosts the registered archive/restore actions) renders `NoticesPage.vue`, which offers only the composer and acknowledgements. The only archive control is in `PlatformWorkspace`, used by the separate `/notices/approval|audience|reads|templates` governance pages; no restore control exists anywhere in the frontend. | The deleted `NoticeArchivePage` was not replaced on its own workspace as the action manifest claims. The manifest gate therefore records two actions that have no host control on page `notices`, and users cannot restore an archived notice at all. | `NoticesPage.vue` imports only `acknowledgeNotice/getNotice/listNotices`; it has no archive/restore API call or button. `pageActions.ts` still lists both actions for page `notices`, while `PlatformWorkspace.vue` implements only `archiveNotice()` and no `/restore` call exists in `frontend/src`. |
| V3-B6 | `backend/routes/api.php:263-271`; `backend/tests/Authorization/endpoint_matrix.php:1446-1451` | The contract-create route is in an `auth.session`-only group; its middleware list is `auth.session, mfa.enrolled, capability:people.contract.manage` with no `csrf.header`. The endpoint matrix marks this high-risk write with `csrf_missing`, and `VerifyCsrfHeader` is an opt-in alias, not global middleware. | A session-authenticated attacker can submit contract creation/renewal without an `X-CSRF-Token`, bypassing the repo's cross-site request-forgery control on a state-changing employment record. The frontend does send the header via its HTTP client, so tests and normal UI use do not expose the gap. | `route:list` reports `POST /v1/employments/{employmentId}/contracts` middleware as `['api','auth.session','mfa.enrolled','capability:people.contract.manage']`. The matrix's `csrf_missing` label is not backed by middleware; `GapCompletionCapabilityMatrixTest` only checks that the label string is present, and `CsrfTest` exercises `/v1/auth/logout-all`, not this route. |

## NON-BLOCKING

| id | file:line | observation |
| --- | --- | --- |
| V3-N1 | `backend/routes/api.php:905-919`; `backend/tests/Authorization/endpoint_matrix.php:474-496` | The three high-risk recruitment writes are declared `mfa_required` in the matrix but carry no `mfa.enrolled`/`mfa.step-up` middleware. The matrix assertion is therefore not executable; either add the middleware or stop claiming MFA. The same mismatch exists on `POST /v1/employments/{employmentId}/contracts` (`api.php:268-271`; `endpoint_matrix.php:1446-1451`), which the matrix marks `csrf_missing` but the route group only applies `auth.session` + `mfa.enrolled` + capability, with no `csrf.header`. |
| V3-N2 | `backend/routes/api.php:917-919`; `RecruitmentController.php:219-222`; `endpoint_matrix.php:492-496` | The interviews route is gated by `recruitment.interview.manage`, but the controller additionally requires `recruitment.candidate.read`. A principal holding only the declared capability gets 403; matrix/OpenAPI omit that second requirement. |
| V3-N3 | `backend/app/Modules/Payroll/Http/Controllers/PayrollRunController.php:77-83`; `PayrollWorkspaceTest.php:196-200` | A non-member `GET /runs/{id}` is denied by the PAYROLL_RUN route anchor with 403 `CAPABILITY_DENIED`, not the 404 `OUT_OF_SCOPE` suggested by the matrix's `out_of_scope_id` label. It does not disclose existence (nonexistent and out-of-scope ids look the same), and `docs/02-permissions.md` permits 403 or 404 here, but the matrix label is imprecise. |
| V3-N4 | `backend/routes/api.php:1126-1129`; `PayrollRunController.php:80` | The route anchor does enforce membership for `GET /runs/{id}` (a non-member without a PAYROLL_RUN binding is rejected), but `findRun()` itself has no membership predicate. Safety depends entirely on every caller route retaining the PAYROLL_RUN anchor. This is defence-in-depth risk, not a current bypass. |
| V3-N5 | repository tree | Acceptance says "all gates are green on a clean tree"; `git status --short` shows 71 untracked `.superpowers/sdd/artifacts/**` files. No tracked changes, and no `.env`, SQLite database, vendor directory or log is committed, so this is a claim/cleanliness discrepancy rather than a product blocker. |

## Contract checks that passed

- **Payroll list membership**: `PayrollRunService::listRuns()` applies `whereIn('id', runIdsForUser(...))`; a non-member cannot receive another specialist's run in the list. The index route uses a feature-level SELF check and the row query supplies the PAYROLL_RUN scope.
- **Payroll show membership**: `GET /runs/{id}` has `capability:payroll.run.read,PAYROLL_RUN`; `RequireCapability` resolves `id` and the production authorizer requires both the per-run binding and `EloquentPayrollRunMembershipDirectory::isMember()`. A non-member cannot read the run.
- **Deleted form replacements (6 of 7 checked)**: employee create uses `EmployeeCreateDrawer` from `EmployeeListPage`; department/position/contract use drawers in their workspaces; requisition create uses `RequisitionFormDrawer`; notice create uses `NoticeComposer`. The notice archive replacement is only on the governance pages, and restore has no replacement (V3-B5).
- **Generated actions map to routes/OpenAPI**: `actions:check` passes, and a direct comparison of all 34 manifest actions against the live Laravel route table (`route:list --json`) found **0 missing routes**. All inspected action paths (`POST /v1/employments`, `/departments`, `/positions`, `/recruitment/requisitions`, `/close`, `/stage`, notice archive/restore) exist in both the route table and spec. This proves route existence, not UI reachability (see V3-B3/B5).
- **Money**: inspected payroll/recruitment/contract writes use integer `*_cents` / `*_minor` and integer validation; no new PHP float money found in the touched paths. The only float seen is a training rating percentage, not money.
- **404 vs 403**: employment-contract out-of-scope reads are 404 (`EmploymentContractApiTest::test_out_of_scope_contract_read_is_404`); payroll out-of-scope uses 403 as noted in V3-N3.
- **Artefacts**: no stray `.env`, SQLite, vendor, `dist` or log file is tracked (`git ls-files` check empty).

## Claims I could NOT verify

1. **Real-stack Playwright execution**: not run, as the acceptance itself records. It says collection only; the requested review command set did not include `pnpm build` or Playwright `--list`, so I did not re-run them.
2. **Actual MySQL transaction/rollback semantics for contract creation**: I proved from code that the contract transaction commits before the ledger append; I did not run a MySQL failure-injection test.
3. **Every UI control for all 155 pages**: I sampled the four requested areas and the seven deleted forms. The product gates (`page_model`, `ui_primitives`, `user_copy`) pass, but they do not prove action reachability (V3-B3/B5).
4. **External OpenAPI-generated client behaviour**: inferred from the spec/controller mismatch; I did not generate or execute a client.

## Gate output actually seen

All commands were run once from the stated directories; logs were kept outside the repository.

Backend:

```
phpunit --colors=never
  OK (1246 tests, 58376 assertions)   exit 0  (2:03.585, 370 MB)
pint --test
  PASS 1190 files                     exit 0
phpstan analyse --no-progress
  [OK] No errors                      exit 0
artisan capability:audit
  Capability audit OK. Public routes (allowlist): 6   exit 0
artisan arch:test
  Module boundaries OK. Warnings (non-blocking): 4     exit 0
```

Frontend:

```
pnpm typecheck    exit 0
pnpm lint         exit 0 (eslint --max-warnings=0)
pnpm test:unit    66 files / 545 tests passed   exit 0
```

Root:

```
node deploy/scripts/openapi-lint.mjs
  openapi-lint: OK (259 paths, 334 operations, 3.1.0)   exit 0
pnpm openapi:validate
  valid; 2 warnings (health/live and health/ready have no 4XX response)   exit 0
pnpm actions:check
  OK (155 pages, 16 write pages, 15 pages with actions, 34 actions,
      149 explicit write gaps, 14 owned capability gaps, 1 public write exception)   exit 0
node scripts/audit_page_model.mjs    ok true   exit 0
node scripts/audit_ui_primitives.mjs ok true   exit 0
node scripts/audit_user_copy.mjs     ok true   exit 0
```

### Discrepancies with the acceptance document

- The numeric gate results match the acceptance document (1246/58376, 1190, 66/545, 259/334, 155/16/15/34/149/14/1).
- `pnpm openapi:validate` emitted **2 warnings** (health endpoints missing 4XX responses). The acceptance records only `exit 0` and does not mention them; non-blocking.
- The acceptance claims a **clean tree**; the worktree had 71 untracked files under `.superpowers/sdd/artifacts/` before this review, all review/implementation artefacts. No tracked product changes.
- The acceptance claims contract creation and version commit **together via an `afterOpen` callback**; no such callback exists in the code (V3-B2).
- The acceptance claims **all new workspaces are reachable**; the candidate workspace is not wired (V3-B3) and notice archive/restore has no reachable control (V3-B5).

## Review scope

Four concrete areas requested: payroll run scope/show guard; employment-contract creation atomicity; four recruitment routes versus matrix/OpenAPI/controller; seven deleted form pages and action manifest reachability. Classic failure modes checked: 403-vs-404, float money, English enums, weakened gates, stray artefacts.
