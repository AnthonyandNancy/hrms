# Task 7A Report — Payroll Calculation Core

## Status

COMPLETE. Pure payroll domain plus shared money primitives; no HTTP, database,
migrations, lifecycle, or fixture writes were added.

Worktree: `D:\code\ai\factoryHR\.worktrees\task-7a`
Branch: `feat/task-7a-payroll-calculator`
Baseline: `b869706`

## Commits

| Commit | Slice |
|---|---|
| `f5f14e7` | `feat(money)`: exact Decimal, RoundingMode, Percentage, and integer-minor-unit Money with tests |
| `fbd3e2e` | `feat(payroll)`: payroll value objects, tax/statutory rules, PayrollCalculator, and full unit/property suite |
| `194d463` | `docs(payroll)`: `docs/07-business-rules.md` Payroll section |
| `870dde3` | `feat(payroll)`: reconciling correction delta value object and carry-forward regression coverage |
| `0ffdaf0` | `fix(payroll)`: checked intermediate aggregation and result invariants |
| `31319b3` | `test(payroll)`: percentage-ratio integration and proration rounding boundaries |
| `72af4e5` | `docs(payroll)`: interim report verification update |
| `d5c055c` | `docs(payroll)`: initial Task 7A report |
| `eb1a953` | `docs(payroll)`: final report verification/commit table |

All commits are on `feat/task-7a-payroll-calculator` and based on `b869706`.
No work was performed outside the worktree.

## Files

### Shared money primitives

- `backend/app/Shared/Money/Decimal.php`
- `backend/app/Shared/Money/RoundingMode.php`
- `backend/app/Shared/Money/Percentage.php`
- `backend/app/Shared/Money/Money.php`

### Payroll domain

- `backend/app/Modules/Payroll/Domain/AttendanceSummary.php`
- `backend/app/Modules/Payroll/Domain/CumulativeTaxDetail.php`
- `backend/app/Modules/Payroll/Domain/DeductionLine.php`
- `backend/app/Modules/Payroll/Domain/EarningLine.php`
- `backend/app/Modules/Payroll/Domain/EmployerCostLine.php`
- `backend/app/Modules/Payroll/Domain/HousingFundRule.php`
- `backend/app/Modules/Payroll/Domain/ManualAdjustment.php`
- `backend/app/Modules/Payroll/Domain/PayrollCalculationInput.php`
- `backend/app/Modules/Payroll/Domain/PayrollCalculationResult.php`
- `backend/app/Modules/Payroll/Domain/PayrollCalculator.php`
- `backend/app/Modules/Payroll/Domain/PayrollContext.php`
- `backend/app/Modules/Payroll/Domain/PayrollCorrectionDelta.php`
- `backend/app/Modules/Payroll/Domain/PayrollLineSource.php`
- `backend/app/Modules/Payroll/Domain/PayrollPeriod.php`
- `backend/app/Modules/Payroll/Domain/PayslipLineCategory.php`
- `backend/app/Modules/Payroll/Domain/PayslipLineDraft.php`
- `backend/app/Modules/Payroll/Domain/ProrationDivisor.php`
- `backend/app/Modules/Payroll/Domain/RoundingConfig.php`
- `backend/app/Modules/Payroll/Domain/SocialInsuranceRule.php`
- `backend/app/Modules/Payroll/Domain/TaxBracket.php`
- `backend/app/Modules/Payroll/Domain/TaxTable.php`
- `backend/app/Modules/Payroll/Domain/YtdTaxSnapshot.php`

### Tests

- `backend/tests/Unit/Shared/Money/DecimalTest.php`
- `backend/tests/Unit/Shared/Money/DecimalScaleTest.php`
- `backend/tests/Unit/Shared/Money/RoundingTest.php`
- `backend/tests/Unit/Shared/Money/MoneyTest.php`
- `backend/tests/Unit/Shared/Money/PercentageTest.php`
- `backend/tests/Unit/Modules/Payroll/Domain/CumulativeWithholdingTest.php`
- `backend/tests/Unit/Modules/Payroll/Domain/NegativeZeroTest.php`
- `backend/tests/Unit/Modules/Payroll/Domain/NetPayTest.php`
- `backend/tests/Unit/Modules/Payroll/Domain/NoFloatTest.php`
- `backend/tests/Unit/Modules/Payroll/Domain/PayrollCalculationInputTest.php`
- `backend/tests/Unit/Modules/Payroll/Domain/PayrollCalculationPropertyTest.php`
- `backend/tests/Unit/Modules/Payroll/Domain/PayrollCalculationResultTest.php`
- `backend/tests/Unit/Modules/Payroll/Domain/PayrollCalculationResultInvariantTest.php`
- `backend/tests/Unit/Modules/Payroll/Domain/PayrollCorrectionDeltaTest.php`
- `backend/tests/Unit/Modules/Payroll/Domain/PayrollOverflowTest.php`
- `backend/tests/Unit/Modules/Payroll/Domain/PayrollIntermediateOverflowTest.php`
- `backend/tests/Unit/Modules/Payroll/Domain/PercentageIntegrationTest.php`
- `backend/tests/Unit/Modules/Payroll/Domain/ProrationRoundingTest.php`
- `backend/tests/Unit/Modules/Payroll/Domain/PayrollTestFixtures.php`
- `backend/tests/Unit/Modules/Payroll/Domain/PayrollValueObjectTest.php`
- `backend/tests/Unit/Modules/Payroll/Domain/PayslipLineSourceTest.php`
- `backend/tests/Unit/Modules/Payroll/Domain/ProrationTest.php`
- `backend/tests/Unit/Modules/Payroll/Domain/SocialInsuranceBaseTest.php`
- `backend/tests/Unit/Modules/Payroll/Domain/TaxTableTest.php`
- `backend/tests/Unit/Modules/Payroll/Domain/UserProvidedMetadataTest.php`

### Documentation

- `docs/07-business-rules.md` (new; clear `## Payroll` heading, ready for a
  later Time section)

## Exact verification results

Commands run from `backend/` with `/d/code/ai/factoryHR/.tools/php` prepended
to `PATH`.

```text
php artisan test
Tests: 164 passed (30426 assertions)
Duration: 3.38s
```

```text
vendor/bin/pint --test
PASS 113 files
```

```text
vendor/bin/phpstan analyse --no-progress --memory-limit=1G
[OK] No errors
```

`phpstan` must be invoked with `--memory-limit=1G` in this environment; the
default 128M limit crashes the parallel workers before analysis. This is a
tooling constraint, not a code failure. `--debug` was also used during
verification when the shared result-cache file was briefly locked.

## Key decisions

1. **Exact decimal storage.** `Decimal` stores a sign, an unscaled digit
   string, and an explicit scale. It validates plain decimal strings, rejects
   more than 18 integer digits, and routes all arithmetic through integer or
   bcmath operations. Inputs such as scientific notation, whitespace, bare
   signs, and multiple decimal points are rejected.
2. **Rounding is exact and mode-parameterised.** `HALF_UP` rounds ties away
   from zero, `HALF_EVEN` to the even neighbour, `DOWN` toward zero, and `UP`
   away from zero whenever a remainder exists. Signed zero is normalized at
   construction, so no operation can emit `-0`.
3. **Money is always integer minor units.** `Money` uses integer cents,
   validates ISO-shaped currency codes, checked-overflow arithmetic,
   deterministic largest-remainder allocation, ratio multiplication rounded
   once at the cents boundary, and the required JSON shape
   `{amount, currency, minor}`.
4. **Percentages are exact scaled ratios.** `Percentage` stores the ratio at
   scale 1e6. Thus `100%` is `1_000_000`, `50%` is `500_000`, and an input
   percent string divides by 100 before scaling.
5. **Statutory parameters are injected.** No tax thresholds, brackets,
   quick deductions, SI rates, HF rates, or base bounds are hardcoded in the
   calculator. Tests and documentation label every numeric sample `DEMO`.
6. **Tax-table validation is structural.** Brackets must start at zero,
   be contiguous and non-overlapping, have positive width, and end in exactly
   one open top bracket. Insertion boundaries are tested at the cent.
7. **Cumulative withholding matches the brief.** The calculator applies the
   full tax table to cumulative taxable income, subtracts already-withheld
   tax, floors current withholding at zero, and records the negative
   difference as `carryForwardCents`; it does not issue an in-year refund.
   A YTD opening snapshot for another tax year is rejected.
8. **Carry-forward is not double counted.** Regression coverage verifies that
   the following month subtracts only the recorded YTD withheld amount; the
   informational carry is not applied a second time to cash withholding.
9. **Correction arithmetic has a first-class value object.** 
   `PayrollCorrectionDelta::between()` computes signed revised-minus-original
   deltas and enforces the net and employer-cost identities. Recovery deltas
   are valid and exact; zero correction is representable.
10. **Traceability is mandatory.** Every line has a non-empty source type and
    id, plus a unique increasing sort order. `PayslipLineDraft` denormalizes
    `sourceType`/`sourceId` for persistence. `PayrollCalculationResult`
    rejects line totals that do not reconcile.
11. **Negative net is explicit policy.** The default context rejects a
    negative net. A context may explicitly allow it; the exact negative amount
    is then returned and flagged `NET_BELOW_ZERO`. It is never clamped or
    silently repaired.
12. **Native-integer overflow is treated as an error.** Money arithmetic,
    payroll aggregation, taxable-income subtraction, cumulative-taxable
    addition, cumulative-tax difference, other-deduction summation, and result
    reconciliation use checked bcmath comparisons against
    `PHP_INT_MIN`/`PHP_INT_MAX`; overflow cannot become a float or a truncated
    integer.
13. **No-float guard is token-based.** The guard analyzes PHP tokens in
    `app/Modules/Payroll` and `app/Shared/Money`, catches float literals,
    declarations, casts, `floatval`/`doubleval`/`is_float`/`is_double`, and
    native `round()` calls, while permitting Decimal's method named `round()`
    and comments that mention floating point. Self-tests prove each prohibited
    pattern is detected.
14. **Proration is unworked-time based.** Full attendance pays the full monthly
    salary, including leap-year February. Unpaid absence is deducted at the
    configured daily rate (`ANNUAL_DAYS_365` or `FIXED_21_75`) using 480
    minutes per standard day. Overtime uses exact minutes/60, the same divisor,
    and the parameterised multiplier.
15. **Four time axes are separate.** `PayrollCalculationInput` requires an
    earning period, posting period, pay date, and cutoff datetime as distinct
    fields; tests pin them independently.

## Gaps and boundaries

- **Task 7B responsibility.** This slice does not implement database models,
  snapshots, run state transitions, approval/SoD, publication, close,
  receipt reconciliation, seeders, commands, endpoints, or OpenAPI changes.
- **Single aggregate SI/HF rule.** V1 models social insurance as one injected
  rule with employee/employer rates and clamp bounds. Splitting into pension,
  medical, unemployment, injury, and maternity components requires a later
  statutory-parameter expansion. The documented gross-to-net order and cent
  invariants should remain stable.
- **Carry forward is reported, not persisted.** `carryForwardCents` is present
  in the result for Task 7B to persist/consult; this pure slice has no YTD
  store. Subsequent calculations receive the authoritative YTD withheld amount
  explicitly.
- **Correction delta has no line-level decomposition.** The value object
  reconciles monetary totals. Task 7B must decide whether to emit per-line
  deltas; documentation requires delta-only payslip representation.
- **Proration uses a standard 8-hour day.** The brief specifies exact
  minutes/60 and the two divisors; it does not define shift-specific or
  calendar-holiday proration. Any shift-calendar variant belongs in a future
  Time integration.
- **Currency support is integer-cent based.** `Money` assumes two decimal
  places for all currencies; currencies with different minor-unit exponents
  (for example JPY=0, KWD=3) are not supported in V1 and must be added with an
  explicit currency-scale table before use.
- **PHPStan cache lock.** One run reported an access-denied error writing the
  shared result cache. Re-running with `--debug` and then `--no-progress`
  completed with `[OK] No errors`. CI should use `--memory-limit=1G` and a
  writable temp directory.
- **Untracked baseline test directories remain.** `backend/tests/Architecture/`,
  `Contract/`, and `Integration/` contain baseline `.gitkeep` files that were
  already untracked at baseline. They were deliberately not swept into this
  task's commits. `.superpowers/sdd/artifacts/task-7a-brief.md` was likewise
  pre-existing/untracked and left untouched.

## Explicit DEMO warning

Every numeric tax, SI, and HF value in this task's tests and documentation is
synthetic DEMO data. None is an authoritative statutory value for any
jurisdiction. Production parameters require versioning, effective dates,
legal-entity/locality scope, and finance/legal approval evidence.
