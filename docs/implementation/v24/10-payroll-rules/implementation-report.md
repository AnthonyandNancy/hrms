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
