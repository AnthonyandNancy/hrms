# Volume 3 — Final Signoff

Date: 2026-11-27 (Asia/Singapore)
Branch: `feat/v2.1-remediation`
Worktree: `D:/code/ai/factoryHR/.worktrees/v2.1-remediation`
Scope: re-verification of the single residual NEW-P2 contract item, plus whole-suite confirmation.

## Verdict

**APPROVED**

NEW-P2 is closed at both layers. The calculate endpoint omits all four run
amounts unless the caller holds a per-run P3 payslip grant, and commit `7e9fcf1`
now makes the published OpenAPI contract and generated TypeScript client agree
with that runtime shape. All requested gates are green.

## Residual item — schema vs. runtime agreement

### Runtime (unchanged by `7e9fcf1`)

`PayrollRunController.php:221-237` resolves `$visible = amountsVisibleFor($principal, $refreshed)`
for the refreshed run, then returns:

```php
return ApiResponse::ok([
    ...$this->omitRunAmounts($result, $visible),
    'run' => $this->runs->runDetail($refreshed, $visible),
]);
```

`omitRunAmounts()` at `:127-138` removes `gross_cents`, `deductions_cents`,
`net_cents`, and `employer_cost_cents`. The disposable HTTP probe from the prior
pass — removed after evidence capture — observed:

```text
no P3:  data keys = ["calculated","failed","run"]
        run employee keys = ["employment_id","status","failure_reason"]
P3:     data keys = ["calculated","failed","gross_cents","deductions_cents",
                     "net_cents","employer_cost_cents","run"]
        run and employee rows include all four amounts
        no `unit` key in either branch
probe result: OK (2 tests, 31 assertions)
```

### Contract (`7e9fcf1`)

`docs/openapi/openapi.yaml:4425-4448` now requires only:

```yaml
required:
  - calculated
  - failed
  - run
```

Each amount property remains declared but carries
`description: Present only when the caller may see amounts on this run.`, and
the schema-level description states the fields are omitted entirely for a
caller without a per-run `payroll.payslip.read` grant at a money-revealing (P3)
profile, never returned as null or zero.

`frontend/src/generated/api.ts:6718-6731` mirrors this: only `calculated`,
`failed`, and `run` are mandatory; all four amounts are optional `number`
properties with the same conditional description.

Direct parser check of the schema:

```text
{"required":["calculated","failed","run"],
 "properties":["calculated","failed","gross_cents","deductions_cents",
               "net_cents","employer_cost_cents","run"]}
```

`7e9fcf1` changes only `docs/openapi/openapi.yaml` and
`frontend/src/generated/api.ts`; `git diff 4cbe13a..HEAD -- backend/app frontend/src ':!frontend/src/generated/api.ts'`
is empty. No runtime behaviour changed in the fix.

## Per-item results

| id | result | evidence |
| --- | --- | --- |
| NEW-P2 no-P3 runtime | **PASS** | Calculate result, embedded run, and embedded employee rows omit all four amount keys; probe key lists above. |
| NEW-P2 P3-present runtime | **PASS** | All four amounts present at the result, run, and employee levels under a P3 field profile. |
| NEW-P2 `unit` | **PASS** | Calculate result/run/employee payloads carry no `unit` key in either branch, so nothing is left ungated. |
| NEW-P2 schema agreement | **PASS** | `PayrollCalculationResult.required` is `calculated`, `failed`, `run`; amounts optional and documented conditional. Generated TS matches. |
| Lifecycle test | **PASS** | `test_real_lifecycle_actions_advance_a_draft_run_to_published` asserts `gross_cents`/`net_cents` absent from `data.run` for the no-P3 calculator call. |
| Cross-run test | **PASS** | `test_p3_on_one_run_does_not_reveal_another_runs_amounts`; focused pair `OK (2 tests, 25 assertions)`. |
| Ungated `runDetail()` | **PASS** | 12 controller call sites, each passing explicit visibility; ungated-pattern search returned none. |
| Whole-suite sanity | **PASS** | Independent re-run below; all exit 0. |

## Real gate outputs

### Backend (independent re-run)

```text
phpunit --colors=never
  OK (1248 tests, 58395 assertions)                       exit 0  (2:07.519, 370 MB)

phpunit --colors=never --filter='OpenApi'
  OK (20 tests, 11631 assertions)                         exit 0  (0:06.430, 82 MB)
  OpenApiSchemaContractTest: 0 success-schema violations across 334 operations
  OpenApiSchemaContractTest: 0 error-set violations across 334 operations
  OpenApiSchemaContractTest: 0 capability violations across 334 operations

phpunit --filter='test_real_lifecycle_actions_advance_a_draft_run_to_published|test_p3_on_one_run_does_not_reveal_another_runs_amounts'
  OK (2 tests, 25 assertions)                             exit 0

pint --test
  PASS  1190 files                                        exit 0

phpstan analyse --no-progress
  [OK] No errors                                          exit 0
```

### Frontend and repository

```text
cd frontend && pnpm test:unit
  66 files / 546 tests passed                             exit 0
  openapiGeneration.spec.ts 3/3 passed

node deploy/scripts/openapi-lint.mjs
  openapi-lint: OK (259 paths, 334 operations, 3.1.0)     exit 0

pnpm openapi:validate
  valid; 2 warnings (health/live and health/ready no 4XX) exit 0

pnpm actions:check
  OK (155 pages, 16 write pages, 15 pages with actions,
      34 actions, 149 explicit write gaps, 14 owned
      capability gaps, 1 public write exception)          exit 0
```

Prior pass in this gate, unchanged by the contract-only fix and not re-run:

```text
pnpm typecheck                                            exit 0
pnpm lint                                                 exit 0
node scripts/audit_page_model.mjs                         exit 0
node scripts/audit_ui_primitives.mjs                      exit 0
node scripts/audit_user_copy.mjs                          exit 0
```

## Conclusion

The one remaining Volume 3 failure — NEW-P2's calculate response returning run
amounts without a per-run P3 grant — is fixed at the runtime, test, and contract
layers. No other embedded-run response is ungated, and the full suite is green.

SIGNOFF: VOLUME_3_APPROVED
