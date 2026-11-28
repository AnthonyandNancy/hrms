# T15 Performance Bonus Payroll Link

## T15a — published performance snapshot to approved payroll bonus

- Added `score_scaled`, `grade` and `source_revision` to
  `performance_result_snapshots`; publication now records grade/final rating and
  a stable source revision hash.
- Added `performance_bonus_mappings` with snapshot/cycle/employment/item,
  period, amount, mapping version, status, approver and source revision.
- DRAFT mappings never enter Payroll; approval re-checks that the performance
  snapshot is published and writes audit evidence.
- Added `PerformanceBonusDirectoryService` and bound it to the Payroll-owned
  `PerformanceBonusDirectory` port. It returns only APPROVED mappings for the
  exact employee and payroll period whose snapshot is published.
- Added `PerformanceBonusPayrollTest`:
  - approved 2026-01 mapping freezes and enters gross;
  - DRAFT mapping is excluded;
  - approved future-period mapping is excluded;
  - Snapshot v2 contains one performance bonus with mapping provenance.
- Verification: 31 focused Talent/Payroll tests / 243 assertions; Pint,
  PHPStan and arch green.

Remaining T15: mapping management API/UI, Offer salary draft and training
attempt source chain; MCP evidence remains T18.

## T15b — Offer salary to Payroll compensation draft

- Added `offers.salary_scheme_code` and exposed it in offer create/list
  projections and OpenAPI.
- Offer acceptance now passes `base_salary_minor`, `salary_currency` and
  `salary_scheme_code` through the Lifecycle onboarding draft.
- `TalentOnboardingDraftService` preserves Offer salary terms instead of
  overwriting them with only location/schedule.
- Added offer→onboarding→`EmployeeCompensation` regression:
  - one compensation draft per employment;
  - exact salary 2,000,000 minor units;
  - currency/scheme preserved;
  - DRAFT + ONBOARDING source + approved case id.
- Verification: focused Offer/Onboarding/Talent/Payroll tests 10 tests /
  124 assertions; Pint, PHPStan and arch green.

Remaining T15: bonus mapping API/UI and training attempt source chain.

## T15c — mapping API and exam-attempt source

- Added authenticated bonus mapping API:
  - `GET /v1/performance/bonus-mappings` (`performance.report.read`);
  - `POST /v1/performance/bonus-mappings` (`performance.result.publish`);
  - `POST /v1/performance/bonus-mappings/{id}/approve`.
- Writes use CommandRunner/idempotency, company anchor and audit; OpenAPI,
  action manifest, page registry and route ledger updated.
- Added `training_exam_attempts` and `TrainingExamAttemptService`:
  - attempt requires published exam, active enrollment, matching
    session/course and check-in;
  - score/pass is stored on the attempt with a source hash;
  - retries append attempt numbers.
- `TrainingService::complete()` now derives PASS/FAIL and score from the
  latest attempt whenever a published exam exists; client-supplied `score`
  cannot overwrite the attempt evidence. Completion without an attempt returns
  `TRAINING_EXAM_ATTEMPT_REQUIRED`.
- Added HTTP mapping create/list/approve regression and attempt-source
  regressions (valid attempt, missing check-in, completion without attempt).
- Verification: focused Talent/Payroll 33 tests / 271 assertions plus
  TrainingAttemptSource/Lifecycle 11 tests / 110 assertions; Pint, PHPStan
  and arch green.

Remaining T15: mapping UI and training attempt HTTP surface/UI; MCP remains T18.

- Added `POST /v1/training/exams/{id}/attempts` with CommandRunner,
  idempotency and company exam-management capability; response includes
  attempt number, score, pass flag and 64-char source hash.
- Added HTTP regression for attempt submission after enrollment + check-in.

## T15e — mapping and attempt UI

- Added a permission-gated `绩效奖金映射` dialog on the performance workspace:
  - loads server mappings on demand;
  - creates integer-cent DRAFT mappings with snapshot/period/item/version;
  - approves DRAFT mappings through the real API;
  - shows approved/draft states without deriving payroll eligibility.
- Added exam attempt submission UI in the training exam panel:
  - published exams expose `提交成绩`;
  - enrollment id and integer score are validated before the API call;
  - success displays attempt number, pass/fail and source hash prefix.
- Added unit regressions for both dialogs/actions and their exact API payloads.
- Verification: frontend typecheck/lint and focused UI tests pass.
