# M04 Employee Records — Test & Acceptance Evidence

Date: 2026-11-27
Branch: `feat/v2.3-module-closure`
Environment: local Windows + Docker Compose; app `http://localhost:5174`; MySQL 8.4 container.

## Backend focused tests

Command:

```bash
cd backend
../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpunit \
  --filter 'EmployeeProfileRetireTest|EmployeeChangeRequestWorkflowTest'
```

Result: **PASS** — 8 tests / 46 assertions.

Covered:
- append-only sub-profile retire, 404 cross-employment scope, idempotent replay header;
- change-request submit opens a real Workflow case;
- approved effect applies only whitelisted fields and replays once;
- HR_ADMIN assignee resolution uses real IAM `user_roles`.

## Implementation defects found and fixed

1. `EmployeeChangeApprovalPort` had no container binding → every People profile API returned 500.
   - Bound `EmployeeChangeApprovalPort` to `EmployeeChangeWorkflowService`.
2. Circular container dependency:
   `EmployeeProfileService → ApprovalPort → Workflow → EffectRegistry → effect handler → EmployeeProfileService`.
   - Extracted `EmployeeProfileChangeApplier` + `EmployeeProfileVersionWriter`;
   - effect handlers are now lazy factories with a declared type;
   - `EffectRegistry` resolves handlers only at execution time.
3. Demo COMPANY authority bindings were seeded with `anchor_id=1`, but the authorizer correctly requires COMPANY to be instance-wide (`anchor_id=null`) → all demo COMPANY permissions were denied.
   - `iam:seed-demo` now repairs historical COMPANY rows; local data repaired.
4. Employee list sent `include_archived=false`; Laravel `boolean` rejects the string `"false"` → 422.
   - client now sends `1/0`.
5. `EmployeeProfileSection` received a parent `data-testid` that overrode the resource-level id.
   - removed the override; tests now use `profile-education`.
6. Detail routes matched non-numeric ids, so deleted FOLD paths rendered “employee not found” instead of 404.
   - detail route parameters are constrained to numeric ids.
7. Change-request form only collected `change_type`, creating drafts that could never be submitted.
   - form now collects target sub-resource, record id, field and new value; submit payload carries a real `diff`.

## Frontend focused tests

Command:

```bash
cd frontend
pnpm vitest run \
  tests/unit/employeeChangeWorkflow.spec.ts \
  tests/unit/employeeProfileRetire.spec.ts \
  tests/unit/employeeTabsContract.spec.ts \
  tests/unit/employeeImportExportActions.spec.ts \
  tests/unit/EmployeeProfileSection.spec.ts \
  tests/unit/EmployeeDetailPage.spec.ts
```

Result: **PASS** — 41 tests.

## Real-stack E2E

Command:

```bash
cd frontend
HRMS_DEMO_PASSWORD=*** HRMS_DEMO_HR=demo.hr \
HRMS_FRONTEND_URL=http://localhost:5174 HRMS_BACKEND_URL=http://localhost:5174 \
pnpm playwright test --config=playwright.real.config.ts tests/e2e/real/employee-records.spec.ts
```

Result: **PASS** — 1 test, real MySQL-backed API.

Covered:
- employee directory + server search by `DEMO-0002`;
- detail workspace and same-object tab switching;
- education create persisted and re-read;
- change request draft creation and Workflow submission;
- document list exposes no fabricated download URL;
- `/hr/employees/imports` and seven other FOLD routes land on `/not-found`.

## Deferred / not claimed

- Full PHPUnit suite, full Vitest suite and `pnpm gate:all`: **NOT RUN in this module step** (executed later globally).
- UAT sign-off: **NOT RUN**.
