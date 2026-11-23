# ADR-0014: Payroll arithmetic, rounding and versioned statutory parameters

- **Status:** Accepted — 2026-11-24
- **Decision IDs:** D-14, D-15, D-16
- **Supersedes:** none
- **Review trigger:** a statutory jurisdiction needs per-locality or multi-code SI/HF rules, a calculation step needs more than one rounding boundary, a rate cannot be represented at scale 1e6, the DEMO tax table is proposed for production, or finance/legal has not signed the parameter set

## Context and trigger

Payroll errors are financial and often irreversible. D-14 selects an in-house
gross-to-net calculator with cumulative withholding and versioned statutory
parameters while leaving tax filing and bank clearing to external adapters.
D-15 requires integer minor units, exact decimal rates, explicit rounding, no
PHP floats, and no negative zero. D-16 requires the four payroll time axes and
traceable calculation evidence.

The reference implementation must demonstrate these rules with executable
arithmetic, not prose. It must also be explicit that the shipped tax/SI/HF
numbers are synthetic DEMO values and that the current model has one SI rule and
one HF rule per legal entity, not a complete locality matrix. This ADR records
the code-level contract and its limits so a later statutory expansion does not
silently change historical payslips.

## Decision

1. **Integer minor units only.** `App\Shared\Money\Money` stores an integer
   number of cents and a three-letter currency. Addition, subtraction, and
   allocation use bcmath/string arithmetic and reject native-integer overflow.
   `PayrollRun`, `PayrollRunEmployee`, `Payslip`, `PayslipLine`, and the
   `*_cents` database columns store integers. Payroll and money source code is
   scanned for float literals, float types, float casts, `floatval`, native
   `round()`, and native `abs()`; the scanner is itself tested.
2. **Exact decimals.** `App\Shared\Money\Decimal` is an immutable, arbitrary-
   precision signed decimal with an explicit scale, implemented with bcmath and
   no binary floating point. `Percentage` stores a rate at scale `1e6`
   (for example `8.500000%` becomes a scaled ratio), and `fromPercent()` is the
   only percentage parser used by SI/HF parameters.
3. **Versioned rounding boundary.** `RoundingConfig` fixes monetary scale at two
   decimals and rejects any other scale. `RoundingMode` supports `HALF_UP`,
   `HALF_EVEN`, `DOWN`, and `UP`. The payroll scheme carries the selected mode;
   `PayrollCalculator` applies it only at documented boundaries: prorated salary,
   overtime earnings, employee/employer SI, employee/employer HF, tax from the
   table, and derived payslip lines. Intermediate work is carried at scale 6 (or
   greater) and is not rounded to cents prematurely.
4. **Statutory parameters are injected and versioned.** `TaxParameterResolver`
   resolves `TAX`, `SOCIAL_INSURANCE`, and `HOUSING_FUND` rows by tenant, legal
   entity, effective date, and exact version. `StatutoryParameter` rows and tax
   brackets are append-only. `SnapshotService` records the effective TAX, SI, and
   HF versions in each locked input snapshot, so a closed run keeps the versions
   it used. A missing or mixed effective version fails closed; no default
   statutory rate is hardcoded in the calculator.
5. **Cumulative resident withholding.** `PayrollCalculator` computes current
   taxable income from gross less employee SI, employee HF, approved special
   deductions, and the current-period threshold, floored at zero. It then adds
   the immutable YTD opening `cumulativeTaxableCents`, applies the versioned
   `TaxTable` to cumulative taxable income, subtracts `ytdTaxWithheldCents`, and
   withholds `max(0, difference)`. A negative difference is retained as a
   non-positive `carryForwardCents` and is not refunded in-year.
   `CumulativeTaxDetail` stores the table version, tax year, opening/current/
   cumulative taxable amounts, threshold, special deductions, withholding, tax,
   and carry-forward for explainability.
6. **Independent SI and HF caps.** `SocialInsuranceRule` and `HousingFundRule`
   each own a version, minimum base, maximum base, and separate employee/employer
   rates. The calculator clamps gross to each base independently and applies the
   rate once at the cents boundary. HF is never inferred from the SI base.
7. **Negative values and zero are explicit.** Amounts may be negative for
   recoveries; `PayrollCorrectionDelta` requires original plus delta to equal the
   revised result. Negative net is rejected unless the legal entity's scheme
   explicitly permits it, in which case it is returned exactly with a
   `NET_BELOW_ZERO` warning. Any rounded zero is normalized to `0` / `0.00`;
   negative zero is forbidden in storage, DTOs, and JSON.
8. **Four time axes remain separate.** Earning period, posting period, pay date,
   and cutoff timestamp are distinct DTO fields and snapshot keys. Tax year comes
   from the earning period; the tax table version is resolved from the run cutoff
   and must match the locked parameter version.
9. **The calculator is pure and testable.** `PayrollCalculator` has no database,
   HTTP, global clock, or float dependency. `CalculationService` resolves
   persisted inputs and the pure calculator remains the single arithmetic engine.

### Known limitations of this implementation

- The shipped `payroll:seed-parameters` values are **synthetic DEMO** data and
  not legal advice. They must not be used for real payroll. Real production
  parameters require finance/legal approval evidence and a signed parameter
  version (GATE).
- There is **one SI rule and one HF rule per legal entity**, not separate
  pension/medical/unemployment/injury/maternity rules or a prefecture-level
  matrix. The `statutory_parameters` schema has `legal_entity_id` but no
  insurance-locality dimension. Splitting insurance codes is a future statutory
  expansion and must not change the gross-to-net order or the cent invariants.
- The tax table HTTP/seed path accepts bracket rates as integer `rate_bps`
  (basis points, effectively four decimal places) and converts them to
  `Percentage` at scale 1e6. SI/HF rates are accepted as decimal strings. Thus
  stored/calculated rates are scale 1e6, but the tax-bracket input representation
  does not itself carry six decimal places. Moving tax-bracket input to decimal
  strings is a review trigger, not a claim already satisfied.
- Rounding mode is a payroll-scheme parameter, not a per-locality statutory
  rounding matrix. A jurisdiction requiring region-specific or step-specific
  rounding needs a new versioned parameter dimension.
- This is not a tax filing engine and not a bank clearing system. The tax table
  and quick-deduction model are sufficient for the reference calculation but do
  not replace the statutory filing process or external reconciliation.

## Alternatives considered

| Alternative | Why rejected |
| --- | --- |
| Store money as PHP floats or database `DECIMAL` and convert at the edge | Binary floating point or implicit database rounding makes cent-level errors unreproducible; the no-float rule exists precisely to prevent this. |
| Round every intermediate result to cents | Accumulates avoidable differences and makes a payslip unexplainable against the documented calculation order; rounding belongs at the defined line boundaries. |
| Use a flat monthly tax rate or annualize only the current month | Does not satisfy cumulative withholding, changing deductions, or prior withholding and cannot explain a correction or year-end position. |
| Hardcode statutory rates in the calculator | Freezes jurisdiction rules in code, prevents effective-dated parameter approval, and makes a legal change a code deployment. |
| Use one base clamp for both SI and HF | Produces wrong contributions where the two bases differ and hides the independent statutory rule. |
| Let each legal entity run a separate payroll engine | Creates divergent arithmetic, audit evidence, and reconciliation; one pure calculator with injected parameters is easier to verify. |
| Treat `-0.00` or a negative net as a harmless display issue | Negative zero is an accounting/audit defect and a negative net can mean an unlawful deduction; both require explicit handling. |

## Consequences

Positive:

- payroll results are reproducible from a snapshot, rule version, and exact
  decimal inputs;
- cent-level arithmetic is testable without floating-point tolerance;
- statutory changes are effective-dated and require evidence;
- cumulative withholding, SI/HF caps, corrections, and YTD all use one calculator;
- the limits of the DEMO and one-rule-per-entity model are explicit.

Negative / costs:

- integer/decimal arithmetic and source scanning add implementation overhead;
- parameter administration and finance/legal approval are mandatory operational
  work, not a one-time seed;
- the current locality model is incomplete for jurisdictions with split
  insurance codes or regional rounding;
- tax-bracket input at basis-point precision may need a migration to scale-1e6
  decimal strings before production;
- real tax and bank reconciliation remain external adapters (GATE).

Data/API/permission/migration/operations/training impact: payroll tables remain
Payroll-owned and forward-only; parameter creation is capability-guarded and
audited; snapshots store the version identifiers and integer/decimal payloads.
Finance and legal must own the real statutory matrix and sign each version;
operators must not mistake DEMO values for production values. A later split of
SI codes or addition of localities requires a new parameter dimension, migration,
and regression fixtures.

## Validation

- `php artisan test --filter=NoFloatTest` — token scanner rejects float literals,
  types, casts, `floatval`, native `round()` and native `abs()` in Payroll and
  Shared/Money; includes a source-tree scan.
- `php artisan test --filter=CumulativeWithholdingTest` — January progression,
  February cumulative-minus-withheld, changing deductions, negative carry,
  zero-taxable no-refund, year rollover, prior-year rejection, and correction
  delta identity.
- `php artisan test --filter=SocialInsuranceBaseTest` — SI floor/ceiling clamps,
  independent HF clamp, parameter-driven rate changes, and rounding-mode effect
  at the cent boundary.
- `php artisan test --filter=TaxTableTest` — contiguous brackets, quick
  deduction, requested rounding mode, overlap/gap/top-bracket validation, and
  negative quick-deduction rejection.
- `php artisan test --filter=PayrollCalculationPropertyTest` — 500 seeded cases
  preserving net, employer-cost, deduction-component, line-sum, source, and
  no-negative-zero invariants.
- `php artisan test --filter=PercentageIntegrationTest` — fractional percentage
  rates are applied as ratios, not raw percentages.
- `php artisan test --filter=ProrationRoundingTest` — one rounding boundary for
  salary proration and HALF_UP/DOWN/UP behavior.
- `php artisan test --filter=NegativeZeroTest` — no negative zero in Money,
  rounded decimals, DTOs, or JSON.
- `php artisan test --filter=DecimalTest` — exact add/multiply/divide, rounding,
  scale, range, and signed-zero handling.
- `php artisan test --filter=PayrollReopenTest` — effective-dated parameter
  versions, evidence requirement, and closed runs retaining their snapshot
  version.
- `php artisan test --filter=PayrollValueObjectTest` — invalid period, scale,
  timezone, currency, SI/HF bounds, and tax-bracket data are rejected.
- `php artisan payroll:seed-parameters --legal-entity=<id> --year=<yyyy>
  --approved-by=<user-id>` — writes DEMO-labelled synthetic parameters and
  requires an approver. This is not a production parameter approval.
- `php artisan test --filter=PayrollCommandTest` — seed command requirements and
  reconciliation behavior.

## Security and privacy

The calculator receives only the payroll context, normalized snapshots, and
versioned parameter rows; it does not read people tables or PII. Audit and
snapshot payloads carry IDs, codes, integer cents, decimal strings, and version
labels, not identity or bank plaintext. Real statutory values, tax filing,
banking, and legal certification are outside the reference implementation and
remain organizational/legal GATEs. Any production deployment must replace DEMO
parameters, obtain finance/legal sign-off, and test the jurisdiction's actual
rounding and insurance rules.

## Review triggers and rollback

Re-review if a jurisdiction needs multiple SI/HF codes, locality-specific
rounding, a tax-bracket rate input finer than `rate_bps`, a different
withholding formula, a different rounding scale, or a refund/carry-forward rule.
Also re-review when moving from DEMO to production parameters or when a
correction changes the calculation order.

Rollback path: the pure calculator and versioned parameter model can be kept
while disabling a new parameter dimension or reverting to a previously approved
version; historical snapshots must remain readable and must not be recalculated
with new rates. Changing the gross-to-net order, cent scale, or no-float rule
requires a new ADR, regression fixtures, and UAT-11 review. Owner: payroll
technical owner with finance/legal and audit.
