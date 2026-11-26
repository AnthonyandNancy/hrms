# Volume 3 Task 4 — Employee Sub-profiles, Attachments and Change Requests Report

## Status

**Implemented, tested, verified and committed.**

The employee detail workspace now exposes four real sub-profile sections
(教育经历, 工作经历, 家庭/紧急联系人, 证照与资格), a file workspace using the
real signed-download API, and an approval-bound change-request workspace with
draft → submit → read-only behaviour. Every mutation is capability-guarded.
No client-side download URL, fake row, inferred person value or fabricated
decision is rendered.

## Commit

`a8bc7ed` — `feat(people): add employee sub-profiles, files and change requests`

Explicitly staged paths only (see the staging command in the terminal log):

- `backend/app/Modules/People/Http/Controllers/EmployeeProfileController.php`
- `backend/tests/Feature/People/EmployeeProfileApiTest.php`
- `frontend/src/modules/people/employees/components/EmployeeProfileSection.vue`
- `frontend/src/modules/people/employees/components/EmployeeFileList.vue`
- `frontend/src/modules/people/employees/components/EmployeeChangeList.vue`
- `frontend/src/modules/people/employees/pages/EmployeeDetailPage.vue` (tab wiring only)
- `frontend/tests/unit/EmployeeProfileSection.spec.ts`
- `frontend/tests/e2e/real/employee-profile.spec.ts`

No router/registry, backend routes, OpenAPI, generated client, other module or
other page/test was changed.

## Backend

`EmployeeProfileController` already exposed the six subresource routes
(`GET/POST/PATCH` for education, experiences, contacts, certificates,
change_requests, imports and `POST .../change_requests/{id}/submit`). The
missing correctness seams were added in that controller only:

1. **Idempotent writes.** `POST` and `PATCH` on every profile subresource and
   `submit` now replay the original response when the caller supplies an
   `Idempotency-Key` (and remain unguarded by that header when it is omitted).
   Replays set `Idempotency-Replayed: true`. The operation body includes the
   state guard so a successful submit can be replayed without turning into a
   409, while a genuinely second submit still conflicts.
2. **Submitted change requests are read-only.** `PATCH` on a non-`DRAFT`
   change request and `submit` on a non-`DRAFT` request return `409` with
   `data.reason = CHANGE_REQUEST_NOT_DRAFT`. Rows belonging to another
   employment remain `404`. This uses the existing generic profile writer; no
   new route was added.
3. **Decision projection.** The change-request list now returns the
   server-stored `decided_by` and `decided_at` alongside the existing payload.
   Missing decisions remain `null`; nothing is inferred.
4. **Capability and scope.** All methods keep the existing route capability
   guards and the controller's per-employment authorizer re-scope. No
   authorization bypass, raw controller SQL, model write or cross-module write
   was introduced.

### Backend TDD

`backend/tests/Feature/People/EmployeeProfileApiTest.php` was extended first.
The initial run failed for the right reasons:

- 2 failures showed `PATCH` after submit returned `200` instead of `409`;
- 1 failure showed the list omitted `decided_by`;
- 2 idempotency tests showed duplicate rows/replayed writes were not handled.

After the minimal controller changes:

- `phpunit --filter='EmployeeProfileApiTest'` → **OK (12 tests, 61 assertions)**.
- `phpunit --filter='EmployeeProfileApiTest|DocumentTest|EmployeeDocument'`
  → **OK (17 tests, 87 assertions)**.

Coverage includes create/list, PATCH versioning, read-only capability denial,
invalid effective dates (422 and no write), draft edit/submit, read-only after
submit, decision/approver projection, idempotent create, idempotent submit,
archived-employee document reads, masked P2 phone output and out-of-scope 404.

## Frontend

### `EmployeeProfileSection.vue`

- Reused by the 教育经历 / 工作经历 / 联系方式 tabs and the credentials tab for
  证照与资格; all data comes from the real profile endpoints.
- Uses `DataTable` + `FormDialog`, capability-guarded create/edit via
  `ActionGate`, PATCH versioned edits, server field errors shown inline and a
  Chinese error summary.
- Education/experience/证照 have effective or issue/expiry dates with
  client-side range validation. `FormDialog` has no delete action; no delete
  API exists for these versioned subresources, so no destructive control is
  invented (see “Deviations”).
- Omitted or masked fields render `无权查看`; explicitly empty fields render
  `未登记`. Objects (masked P2 phone) are never printed as JSON.
- Each section owns loading, empty, error (with request id/retry) and forbidden
  state; one failure cannot blank the employee summary or other sections.

### `EmployeeFileList.vue`

- Lists real document rows: filename, human-readable size, uploader
  (`uploaded_by_name`, else explicit `用户 #id`, else `无权查看`) and
  `uploaded_at`.
- Client-side rejection with Chinese copy for unsupported extensions/MIME types
  and files over 20 MB; the server remains authoritative.
- Multipart upload uses the real `POST /v1/employments/{id}/document-files`
  route with a real XHR upload-progress bar, idempotency key and CSRF header.
- Download first calls `GET /v1/documents/{id}/signed-download` and only uses
  the returned `url`. If the API returns no URL, the page reports that no
  download started; it never constructs `/download` or a storage path.
- Delete asks for confirmation naming the file and then calls
  `DELETE /v1/documents/{id}`; legal-hold conflicts are shown from the server
  response. Upload/download/delete controls are wrapped in their exact
  `ActionGate` capabilities.

### `EmployeeChangeList.vue`

- Lists real change requests with Chinese change type and status; no raw enum is
  rendered.
- Detail panel shows a status timeline, submit time, approval decision,
  approver (`用户 #id`) and decision time only when those fields were returned.
  If the API omits them, the page renders `无权查看`.
- Create draft → edit draft → submit; submitted requests expose no edit/submit
  control and the server also rejects them with a 409.
- Approval entry links to the existing `/cases/{caseId}` route only when
  `case_id` is present, guarded by `workflow.case.read`.

### Detail-page wiring

`EmployeeDetailPage.vue` was extended only by importing the three components
and rendering them under the existing tabs (`credentials`, `education`,
`experience`, `contacts`, `changes`). The summary, archive/restore actions,
tab component and route behaviour are unchanged.

## TDD / tests

Frontend focused unit test written first at
`frontend/tests/unit/EmployeeProfileSection.spec.ts`; initial RED was an
unresolved component import. Final result:

- `pnpm exec vitest run tests/unit/EmployeeProfileSection.spec.ts`
  → **13 tests passed / 13**.
- Combined with the existing detail workspace:
  → **25 tests passed / 25** across 2 files.

The unit suite covers sub-profile list/add/edit/field-422/forbidden,
file list metadata/oversized/unsupported-type/signed-URL/delete confirmation/
read-only gating, and change status timeline/draft→submit/omitted decision/
forbidden states.

`frontend/tests/e2e/real/employee-profile.spec.ts` contains **3 real-stack
tests**:

1. HR adds education with effective dates and sees the real row;
2. HR creates a change-request draft, submits it and loses the submit control;
3. the file workspace never renders a fake download anchor and only exposes
   upload controls behind the required capability.

Playwright list output: **Total: 3 tests in 1 file**. The real suite was not
executed because no demo worker/seed password is available in this session; the
spec uses the existing real config and honours both
`HRMS_DEMO_PASSWORD` / `DEMO_SEED_PASSWORD`.

## Verification (real output)

- `cd backend && ../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpunit --colors=never --filter='EmployeeProfileApiTest'`
  → **OK (12 tests, 61 assertions)**.
- `cd backend && ../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpunit --colors=never`
  → **1222 tests / 58046 assertions; 2 failures, both in
  `ArchitectureBoundaryTest` / `ModuleOwnershipTest` because the concurrent
  Task 8 edit left `DB::` in `EmploymentContractController.php`; not this task**.
- `cd backend && ../.tools/php/php.exe -d memory_limit=1G vendor/bin/pint --test`
  → **FAIL only on concurrent `PayrollRunController.php`** at final run. Our two
  PHP files pass an explicit `pint --test` run.
- `cd backend && ../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpstan analyse --no-progress`
  → **2 errors in concurrent `PayrollWorkspaceTest.php`; no error in our files**.
- `cd frontend && pnpm typecheck` → **PASS** (`vue-tsc -b`, exit 0).
- `cd frontend && pnpm lint` → **PASS** (`eslint . --max-warnings=0`, exit 0).
- `cd frontend && pnpm test:unit` → **61 test files passed / 61;
  467 tests passed / 467**.
- `cd frontend && pnpm exec playwright test --config=playwright.real.config.ts --list tests/e2e/real/employee-profile.spec.ts`
  → **Total: 3 tests in 1 file**.

## Missing routes / contract requests

1. **`GET /v1/employees/export` is absent.** `php artisan route:list --path=export`
   shows only engagement, payroll, reporting and training exports; no operation
   carries `people.employee.export`, and the capability audit itself records
   this gap. This task does not own `backend/routes/api.php` or OpenAPI, so the
   route was **reported, not added**. Required contract:
   `GET /v1/employees/export`, `x-capability: people.employee.export`,
   server-side data-scope constraint and field whitelist/trimming; 403 without
   the capability. The frontend therefore has no export button (no fake UI).
2. **`GET/POST /v1/employees/{employmentId}/documents` does not exist.** The
   real document surface is `GET|POST /v1/employments/{employment_id}/document-files`,
   `GET /v1/documents/{document_id}/signed-download`,
   `GET /v1/documents/{document_id}/content` and
   `DELETE /v1/documents/{document_id}`. The frontend uses that real surface
   and the E2E asserts no fabricated URL.
3. **`uploaded_by_name` / `uploaded_at` are not returned by the current
   document projection.** The controller/service payload returns `created_at`
   and (hidden) `file_object_id`; uploader identity is only stored on
   `file_objects.owner_user_id`. The UI renders `无权查看` for omitted uploader
   and upload time rather than inferring them from `created_at` or a session
   user. Contract request: add `uploaded_by`, `uploaded_by_name` and
   `uploaded_at` to the document list projection (and keep `created_at`
   distinct).
4. **No delete/archive endpoint for education, experiences, contacts or
   certificates.** These subresources are versioned by `superseded_at`; only
   create/PATCH exist. The components expose no delete control and no fake
   delete command. If deletion is required, the contract must add an
   effective-dated end/`DELETE` command.
5. **Change-request approval is not wired to a case row.** The API stores
   `case_id`, but no People-side endpoint creates/attaches the workflow case on
   submit; the approval link is rendered only if a case id already exists.
   Required integration: submit should create/attach a workflow case or the
   list projection should return the resulting case id.
6. **Contacts have no effective-date fields.** The contact schema only has
   `recorded_at`/`superseded_at`; the component explicitly labels the
   interface gap instead of inventing an effective date. If 生效日期 must be
   user-editable for contacts, the contract needs `valid_from`/`valid_to` (or
   effective-dated contact versions).

## Deviations / notes

- `EmployeeProfileController` received no new public route or method name; the
  required additions are private correctness helpers plus idempotency wrapping
  of existing public actions. This respects “add missing methods only” and
  avoids touching `backend/routes/api.php`.
- The detail page shows all sub-profile write controls only under
  `people.employee.manage`; file upload is under `people.document.read`, file
  download under `people.document.download`, delete under
  `people.employee.manage` — matching the existing route middleware exactly.
- No export UI is present because the endpoint is genuinely absent; adding a
  button would violate the no-fake-feature rule.
