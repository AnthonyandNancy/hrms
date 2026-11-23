# Task 7 — Payroll: gross-to-net, cumulative withholding, close, corrections

## Context

Platform, IAM, People/Org, Workflow, Lifecycle, and Time exist. Read `.superpowers/sdd/artifacts/interface-freeze.md`.

Authoritative sources: solution §9.3–9.8; plan §4.7 and §6.1; D-14–D-16, ADR-0014; UAT-06, UAT-11. This is the highest-risk module: a single arithmetic or rounding error is a Sev1.

## Non-negotiable rules

- Money is integer minor units (cents). No PHP float anywhere in payroll. Rates/bases are decimal strings with scale >= 6, converted to integer scaled values for arithmetic.
- Default statutory step rounding: half-up to cents; rounding mode and scale are versioned parameters per legal entity/tax type. Acceptable modes: HALF_UP, HALF_EVEN, DOWN, UP. Never store negative zero.
- Four times separated: earning period, posting period, pay date, cutoff_at.
- Cumulative withholding for resident individuals: cumulative taxable income minus cumulative deductions, cumulative tax, minus tax already withheld; negative result -> 0 (no refund in-year), carry forward.
- Statutory parameters (tax brackets, social insurance and housing fund rates/bases) live in versioned tables with legal_entity_id, effective_from/to, approval evidence, and are never hardcoded.
- Published payslips and closed runs are immutable. Corrections create a new correction run referencing the original; original + corrections = payable total.

## Data model

- `payroll_items`: id, tenant_id, legal_entity_id, code, name, type (`EARNING|DEDUCTION|EMPLOYER_COST|INFO`), taxable bool, insurable bool, calculation (`FIXED|ATTENDANCE_PRORATE|PERCENT|FORMULA_LIMITED`), formula_json nullable limited to whitelisted operations, sort_order, status.
- `payroll_schemes`: id, tenant_id, legal_entity_id, code, name, frequency (`MONTHLY`), currency, version, status, valid_from, valid_to; `payroll_scheme_items` map items with amount/rate parameters.
- `payroll_runs`: id, tenant_id, legal_entity_id, scheme_id, period (`YYYY-MM`), earning_period_start/end, posting_period, pay_date, cutoff_at, status (`DRAFT|INPUT_LOCKED|CALCULATED|REVIEWED|APPROVED|PUBLISHED|PAID|CLOSED|CANCELLED`), run_type (`REGULAR|CORRECTION|OFF_CYCLE|BONUS`), correction_of_run_id nullable, prepared_by, reviewed_by, approved_by, published_by, totals (gross_cents, deductions_cents, net_cents, employer_cost_cents), input_hash, rule_version, calculated_at, closed_at, created_at, updated_at.
- `payroll_run_employees`: id, run_id, employment_id, gross_cents, taxable_cents, deductions_cents, net_cents, employer_cost_cents, status (`PENDING|CALCULATED|EXCLUDED|FAILED`), failure_reason nullable.
- `payroll_input_snapshots`: id, run_id, employment_id, snapshot_type, payload_json (normalized numeric strings/ints only), source_hash, created_at. One snapshot per employment per run type. Snapshot must include attendance summary, leave consumption, terms version, scheme version, manual adjustments, YTD opening, tax parameters version.
- `payroll_adjustments`: id, tenant_id, run_id nullable, employment_id, item_code, amount_cents signed, reason, case_id nullable, approved_by, effective_period.
- `payslips`: id, run_id, employment_id, version, gross_cents, deductions_cents, net_cents, employer_cost_cents, published_at, pdf_file_id nullable, unique (run_id, employment_id).
- `payslip_lines`: id, payslip_id, item_code, category, quantity (scaled int), unit, rate_scaled, amount_cents, taxable bool, source_type, source_id, sort_order.
- `statutory_parameters`: id, tenant_id, legal_entity_id, parameter_set (`TAX|SOCIAL_INSURANCE|HOUSING_FUND`), code, value_decimal string, value_int nullable, effective_from, effective_to, version, approved_by, approved_at, evidence_ref.
- `tax_brackets`: id, parameter_set_id, lower_bound_cents, upper_bound_cents nullable, rate_bps (basis points or scaled decimal), quick_deduction_cents.
- `payroll_ytd`: id, tenant_id, employment_id, tax_year, taxable_income_cents, tax_withheld_cents, social_insurance_cents, housing_fund_cents, gross_cents, net_cents, last_run_id, updated_at. Unique (employment_id, tax_year).
- `payroll_receipts`: id, run_id, type (`BANK|TAX`), file_hash, received_at, reconciled_at, metadata_json.

## Calculator

`PayrollCalculator` pure domain service:
- Input: `PayrollCalculationInput` DTO with employment id, period, gross earnings lines, attendance/leave proration inputs, terms (salary), scheme items, statutory parameter set, YTD, manual adjustments, rounding config.
- Output: `PayrollCalculationResult` with lines, gross, taxable, employee deductions, employer cost, net, cumulative tax detail, warnings.
- Compute in this order (document exactly): base salary -> attendance/leave proration -> allowances -> overtime -> gross -> social insurance base (clamped) -> employee SI -> housing fund base -> employee HF -> taxable income (gross - SI - HF - special deductions - threshold) -> cumulative tax -> other deductions -> net. Employer costs separate.
- Proration: daily rate = monthly salary * 12 / 365 or configured divisor; use exact integer arithmetic with scaled decimals; document and version the divisor.
- Cumulative withholding: maintain YTD from payroll_ytd; correction runs adjust YTD by delta; negative tax delta carries until positive.
- Rounding applied at documented steps only; intermediate values keep scale 6; final cents half-up by default.
- Zero/negative handling: net can be negative only if configured; never emit -0.
- Every payslip line stores source_type/source_id for traceability.

## Lifecycle and controls

- State transitions enforced by a state machine; illegal transitions 409.
- `INPUT_LOCKED` creates snapshots and hash; changes after lock require reopening (only if not published/paid, dual approval, audited) or a correction run.
- `CALCULATED`: idempotent recalculation allowed only in DRAFT by authorized preparer; after lock, recalculation forbidden.
- Review, approve, publish, mark-paid require distinct users (SOD) unless an audited override by payroll admin + reason; approver cannot be preparer.
- Publish makes payslips visible to employees (self-only).
- Mark-paid requires reconciliation file/receipt reference or explicit manual confirmation with reason.
- Close seals run; no updates to run/employees/payslips/lines. Enforce at model level for CLOSED and for payslips after PUBLISHED.
- Correction run: copies original snapshot references, computes deltas vs original, produces payslips that represent only the difference (or full new with delta lines — choose delta-only and document); original + correction totals reconcile exactly.
- Reopen: allowed only DRAFT..APPROVED and before publish/paid, requires two approvers and reason; fully audited.
- Export: bank file CSV and tax return CSV adapters with stable formats; generated file hash stored; no real bank/tax integration.

## Endpoints

- `GET/POST /v1/payroll/items`, `GET/POST /v1/payroll/schemes`
- `POST /v1/payroll/runs` (create DRAFT)
- `POST /v1/payroll/runs/{id}/lock-inputs`
- `POST /v1/payroll/runs/{id}/calculate`
- `POST /v1/payroll/runs/{id}/review`, `/approve`, `/publish`, `/mark-paid`, `/close`
- `POST /v1/payroll/runs/{id}/reopen`
- `POST /v1/payroll/runs/{id}/corrections`
- `GET /v1/payroll/runs`, `GET /v1/payroll/runs/{id}`, `GET /v1/payroll/runs/{id}/employees`, `GET /v1/payroll/runs/{id}/reconciliation`
- `GET /v1/payroll/payslips/my?period=`, `GET /v1/payroll/payslips/{id}`
- `GET /v1/payroll/runs/{id}/exports/bank`, `/exports/tax`
- `GET/POST /v1/payroll/statutory-parameters` (versioned; approval required)
All guarded with payroll capabilities; `payroll.payslip.read.self` for own payslip; high-risk actions require step-up MFA.

## Commands

- `php artisan payroll:seed-parameters --legal-entity= --year=` — synthetic demo parameters clearly labeled DEMO, never real statutory values presented as authoritative.
- `php artisan payroll:parallel-check --period=YYYY-MM --old-file=` — compare new net vs old system export; report headcount, gross, net, per-employee diffs; exit non-zero if unexplained diff > 0.01 or headcount mismat## Tests (must pass)

Unit (pure calculator, no DB):
- `RoundingTest`: half-up default; half-even/down/up modes; scale; no negative zero; step ordering.
- `CumulativeWithholdingTest`: multi-month progression, deductions change, correction delta, negative cumulative tax floors at zero, year rollover.
- `SocialInsuranceBaseTest`: min/max clamp; rate changes mid-year; housing fund separate base.
- `ProrationTest`: mid-month join/leave, unpaid leave, overtime, divisor versioning.
- `PayslipLineSourceTest`: every line has source; totals equal sum of lines.
Feature:
- `PayrollRunLifecycleTest`: every legal/illegal transition; immutability after publish/close.
- `BatchImmutabilityTest`: cannot edit snapshot/payslip after lock/publish; DB-level attempt fails.
- `SodTest`: preparer cannot approve; approver cannot publish unless policy allows and audited; override requires two-person + reason.
- `CorrectionRunTest`: original immutable; delta computed; totals reconcile; YTD adjusted; multiple corrections chain correctly.
- `ReopenTest`: reopen only pre-publish; dual approval; audit; recalculation after reopen changes hash.
- `InputSnapshotTest`: hash stable; changing attendance after lock does not change calculated run; new run picks up change.
- `PayslipAuthorizationTest`: employee sees own only, 404 for others; manager cannot see payslip by default; payroll specialist sees scope; P3 fields hidden in list.
- `ExportTest`: bank/tax CSV generated with hash; no hidden fields; export audited; row scope respected.
- `ParallelReconciliationTest`: identical data => zero diff pass; injected 0.02 diff => fail; missing file => exit 2.
- `StatutoryParameterVersionTest`: parameters effective-dated; approval evidence required; changing parameters does not alter closed runs; as-of recalculation uses version.
- `PayrollReconciliationCommandTest`: clean => pass, corruption => non-zero.
Integration:
- `TimeToPayrollIntegrationTest`: attendance/leave summary -> snapshot -> calculation; unpaid leave reduces gross; overtime increases.
- `PeopleToPayrollIntegrationTest`: terms as-of period used; future raise excluded; termination mid-month prorated.
- `WorkflowPayrollApprovalTest`: run approval is a Case/Decision/Effect; approved-but-effect-failed visible.
- `UatCorrectionChainTest`: Jan closed, Feb correction -> original + correction = payable; YTD correct; payslips corrected.

## Constraints

- No PHP float; a grep-based test must fail if `float`/`(float)` appears in `app/Modules/Payroll`.
- No hardcoded tax rates in code; only versioned parameters.
- No tax filing or bank clearing; CSV/adapters only, clearly labeled.
- Statutory demo values marked DEMO and not presented as authoritative for any jurisdiction.
- Payslip PDFs optional; if not generated, return structured data and document the gap.
- Retention metadata: payment records >=2 years, accounting 30 years; legal hold blocks deletion.
- Update OpenAPI and the payroll section of `docs/07-business-rules.md`.
- Run full backend suite, pint, phpstan, capability:audit, arch:test.

## Report

Write `.superpowers/sdd/artifacts/task-7-report.md` with files, exact command results, decisions, gaps, commits. Commit in logical slices. Return status, hashes, one-line test summary, concerns.
