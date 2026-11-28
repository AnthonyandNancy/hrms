# T10 Payroll Rule Engine

## T10a — restricted AST compiler and Decimal engine

- Added `PayrollRuleCompiler` with a strict node whitelist:
  const/var, + - * /, neg/abs, round, min/max/clamp, if/compare/and/or/not,
  progressive tier.
- Compiler rejects:
  - unknown node types and properties;
  - non-decimal constants/rates;
  - unknown variables;
  - malformed children and invalid arity;
  - round scale/mode errors;
  - non-increasing or unbounded non-final tier bounds;
  - >500 nodes or >32 depth.
- Added `CompiledPayrollRule` (canonical hash, node/depth/variable metadata),
  `CompiledRuleEvaluation` (value + trace) and `PayrollRuleEngine`.
- Engine uses `App\Shared\Money\Decimal`/bcmath only, short-circuits
  boolean nodes, evaluates progressive tier slices exactly, and rejects
  division by zero/clamp inversion/tier negative base.
- Added unit tests for IF arithmetic, tier 0–1000/1000+ rates, unknown
  variable/property rejection, division by zero and tier-bound ordering.
- Verification: 5 unit tests / 8 assertions; Pint and PHPStan green.

Remaining T10: rule-version persistence/publish/retire API, SchemeItem
FIXED/PERCENT/ATTENDANCE_PRORATE integration, dependency graph, snapshot
rule hashes and frontend restricted editor.

## T10b — SchemeItem execution

- Added `payroll_scheme_items.formula_json`; scheme item creation, snapshot v2
  and model casts carry the AST unchanged.
- CalculationService now executes each item by `calculation`:
  - FIXED → `amount_cents`;
  - PERCENT → base salary cents × `rate_scaled` / 1e6, rounded once;
  - ATTENDANCE_PRORATE → amount × paid minutes / scheduled minutes;
  - FORMULA_LIMITED → restricted AST compiled/evaluated with base salary,
    attendance variables and integer-cent variables.
- Missing rate/Formula and unknown variables fail the employee rather than
  defaulting the item to zero; failed run becomes `CALCULATED_WITH_BLOCKERS`.
- Added integration coverage for all four calculations and malformed formula
  rejection.
- Verification: payroll/integration/unit 205 tests / 30,858 assertions; Pint,
  PHPStan and arch green.

Remaining T10: rule version persistence/publish/retire, dependencies, snapshot
rule hashes, rule editor and API.

## T10c — rule version persistence and published snapshot

- Added `payroll_rule_versions` with scheme item/version, immutable AST,
  dependencies, stage, rounding, engine version, canonical hash, DRAFT/
  PUBLISHED/RETIRED status and publish/retire evidence.
- Added `PayrollRuleVersionService`:
  - draft creation compiles and hashes the restricted AST;
  - publish recompiles and rejects hash mismatch/non-draft;
  - retire is explicit and audited.
- Snapshot v2 resolves the newest PUBLISHED rule per scheme item and freezes
  `rule_version`, `rule_hash`, `rule_engine_version` and `rule_ast`.
- Calculation verifies the frozen AST hash before evaluation, preventing a
  tampered snapshot or stale rule from being used.
- Added regression: draft is not used; published AST overrides legacy formula;
  retired rule returns to legacy fallback; snapshot freezes published hash.
- Verification: payroll/integration/unit 206 tests / 30,867 assertions; Pint,
  PHPStan and arch green.

Remaining T10: rule version API/UI, dependency validation/graph and cascade
publish/retire controls.
