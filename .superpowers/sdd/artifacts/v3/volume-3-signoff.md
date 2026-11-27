# Volume 3 — Final Signoff

Date: 2026-11-27 (Asia/Singapore)
Branch: `feat/v2.1-remediation`
Worktree: `D:/code/ai/factoryHR/.worktrees/v2.1-remediation`
Scope: last open item NEW-P2 plus whole-suite sanity only.

## Verdict

**REJECTED — runtime NEW-P2 leak is closed, but the contract is not.**

`e117422` now strips the four amount fields from both the calculation result
payload and the embedded run when the caller lacks a per-run P3 payslip grant.
However, `docs/openapi/openapi.yaml` still declares those same fields as
**required** on every `PayrollCalculationResult`, and the generated TypeScript
contract still marks them non-optional. A successful no-P3 calculate response
therefore violates the published contract. The OpenAPI lint/validation gates
are structural and do not compare a response payload against its schema, so
they remain green while this semantic mismatch exists.

## Fix commits inspected

```text
$ git show e117422 --stat
commit e117422b8c226d102de9a028874b0c93dae3c159
    fix(payroll): strip amounts from the calculation response without a per-run P3 grant
 .../Http/Controllers/PayrollRunController.php      | 30 ++++++++++++++++++++--
 .../tests/Feature/Payroll/PayrollWorkspaceTest.php |  5 +++-
 2 files changed, 32 insertions(+), 3 deletions(-)

$ git show 7cc1bd6 --stat
commit 7cc1bd68e548ae48c5df798f060095eb4ab5d891
    test(payroll): refresh the run service after registering membership
 backend/tests/Support/PayrollTestCase.php | 29 ++++++++++++++++++++---------
 1 file changed, 20 insertions(+), 9 deletions(-)
```

Neither commit changes `docs/openapi/openapi.yaml` or
`frontend/src/generated/api.ts`.

## Per-item results

| id | result | evidence |
| --- | --- | --- |
| NEW-P2 runtime / no-P3 | **PASS** | `PayrollRunController.php:221-237` resolves `$visible = amountsVisibleFor($principal, $refreshed)` and returns `...$this->omitRunAmounts($result, $visible)` with `'run' => $this->runs->runDetail($refreshed, $visible)`. `omitRunAmounts()` at `:127-138` removes `gross_cents`, `deductions_cents`, `net_cents`, and `employer_cost_cents`. A disposable HTTP probe (removed after evidence capture) produced no-P3 top-level keys `["calculated","failed","run"]`; the embedded run had no amount keys; embedded employee rows were only `["employment_id","status","failure_reason"]`. |
| NEW-P2 runtime / P3 present | **PASS** | The same probe with `AllowAllAuthorizer::fieldProfiles['payroll.payslip.read'] = P3_RESTRICTED` produced top-level amounts `["calculated","failed","gross_cents","deductions_cents","net_cents","employer_cost_cents","run"]`; the embedded run included all four amounts; embedded employees included all four amounts. Probe: `OK (2 tests, 31 assertions)`. |
| NEW-P2 `unit` gating | **PASS / no emitter** | No `unit` key exists in the calculate result, the embedded run, or the embedded employee rows in either branch. The probe's key lists for both P3 and no-P3 contain no `unit`, so this endpoint has no ungated unit field. `omitRunAmounts()` does not name `unit`; if a future calculation payload adds a unit-bearing money line, that denylist will need extending. |
| NEW-P2 contract | **FAIL** | `docs/openapi/openapi.yaml:4425-4451` still lists `gross_cents`, `deductions_cents`, `net_cents`, and `employer_cost_cents` under `PayrollCalculationResult.required`. `frontend/src/generated/api.ts:6718-6725` still generates them as required `number` properties. The no-P3 response now omits them, so the response and the "Contracts are law" source disagree. |
| Test: lifecycle no-P3 assertion | **PASS** | `backend/tests/Feature/Payroll/PayrollWorkspaceTest.php:50-56` asserts `data.run.status = CALCULATED` and asserts `gross_cents`/`net_cents` are absent from `data.run`. This replaces the old `assertIsInt($calculated->json('data.run.gross_cents'))` leaky assertion. (The committed test still checks only `run`, not the top-level calculation payload; the disposable probe covered both.) |
| Test: cross-run isolation | **PASS** | `test_p3_on_one_run_does_not_reveal_another_runs_amounts`: `OK (1 test, 6 assertions)`. |
| Gate 4: ungated `runDetail()` | **PASS** | `rg 'runDetail\(' backend/app/Modules/Payroll` finds the service definition at `PayrollRunService.php:211` plus 12 controller call sites (`PayrollRunController.php:96,210,218,235,245,254,263,280,288,297,315,333`). Every controller call passes explicit visibility (`$includeAmounts`, `amountsVisibleFor(...)`, or `$visible`); the ungated-call pattern search returned none. The service default `$includeAmounts = true` is not relied on by any controller response. |
| Whole-suite sanity | **PASS** | All requested backend, frontend, OpenAPI, actions and audit commands exited 0; see below. |

## Real gate outputs

### Backend

```text
cd backend && ../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpunit --colors=never --filter='Payroll'
  OK (177 tests, 30938 assertions)                       exit 0  (11.092s, 102 MB)

cd backend && ../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpunit --colors=never
  OK (1248 tests, 58395 assertions)                      exit 0  (2:18.116, 368 MB)

cd backend && ../.tools/php/php.exe -d memory_limit=1G vendor/bin/pint --test
  PASS  1191 files                                       exit 0

cd backend && ../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpstan analyse --no-progress
  [OK] No errors                                         exit 0
```

### Focused NEW-P2 evidence

```text
Disposable probe (removed after evidence capture)
  NO_P3_KEYS=["calculated","failed","run"]
  NO_P3_RUN_KEYS=[...,"input_hash","snapshot_version","reopen_count","employees"]
  NO_P3_EMPLOYEE_KEYS=["employment_id","status","failure_reason"]
  P3_KEYS=["calculated","failed","gross_cents","deductions_cents","net_cents","employer_cost_cents","run"]
  P3_RUN_KEYS=[...,"gross_cents","deductions_cents","net_cents","employer_cost_cents",...]
  P3_EMPLOYEE_KEYS=["employment_id","status","failure_reason","gross_cents","taxable_cents","deductions_cents","net_cents","employer_cost_cents"]
  OK (2 tests, 31 assertions)                            exit 0

test_real_lifecycle_actions_advance_a_draft_run_to_published
test_p3_on_one_run_does_not_reveal_another_runs_amounts
  OK (2 tests, 25 assertions)                            exit 0

test_p3_on_one_run_does_not_reveal_another_runs_amounts (alone)
  OK (1 test, 6 assertions)                              exit 0
```

### Frontend

```text
cd frontend && pnpm typecheck
  exit 0

cd frontend && pnpm lint
  exit 0 (eslint --max-warnings=0)

cd frontend && pnpm test:unit
  66 files / 546 tests passed                            exit 0
```

### OpenAPI, actions and audits

```text
node deploy/scripts/openapi-lint.mjs
  openapi-lint: OK (259 paths, 334 operations, 3.1.0)    exit 0

pnpm openapi:validate
  valid; 2 warnings (health/live and health/ready no 4XX) exit 0

pnpm actions:check
  OK (155 pages, 16 write pages, 15 pages with actions,
      34 actions, 149 explicit write gaps, 14 owned
      capability gaps, 1 public write exception)          exit 0

node scripts/audit_page_model.mjs
  {"ok": true, "failures": []}                            exit 0

node scripts/audit_ui_primitives.mjs
  {"ok": true, "failures": []}                            exit 0

node scripts/audit_user_copy.mjs
  {"ok": true, "failures": []}                            exit 0
```

## Residual evidence

```text
docs/openapi/openapi.yaml:4425-4451
  PayrollCalculationResult:
    required:
      - calculated
      - failed
      - gross_cents
      - deductions_cents
      - net_cents
      - employer_cost_cents
      - run

frontend/src/generated/api.ts:6718-6725
  PayrollCalculationResult: {
    calculated: number;
    failed: number;
    gross_cents: number;
    deductions_cents: number;
    net_cents: number;
    employer_cost_cents: number;
    run: components["schemas"]["PayrollRunDetail"];
  };
```

## Conclusion

The security behaviour requested for NEW-P2 is fixed and the full suite is
green. Volume 3 cannot be signed off while the published OpenAPI contract still
requires amounts that the no-P3 response correctly omits; update the contract
(and generated client type) to make the amount fields conditional/optional for
the authorized no-P3 case, then re-run the OpenAPI and focused payroll evidence.

SIGNOFF: VOLUME_3_REJECTED — NEW-P2 runtime gating is fixed, but `PayrollCalculationResult` still requires gross_cents, deductions_cents, net_cents and employer_cost_cents that a no-P3 calculate response now correctly omits.
