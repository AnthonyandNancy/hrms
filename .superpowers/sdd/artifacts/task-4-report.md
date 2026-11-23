# Task 4 Report — Workflow / Case–Decision–Effect

## Status

**DONE_WITH_CONCERNS**

Worktree: `D:\code\ai\factoryHR\.worktrees\task-4`
Branch: `feat/task-4-workflow`
Baseline: `b869706`
Implementation HEAD: `9239014`

Task 4 implementation is complete and green on the three required gates.
Repository-wide `capability:audit` and `arch:test` commands do not exist on this
branch baseline; that is a Task 1B integration concern, not a Task 4 failure.

## Commits

| Commit | Slice |
|---|---|
| `9239014` | Task 4 workflow, case/decision/effect runtime, tests, and static-analysis fixes |

## Verification (final run)

| Gate | Command | Result |
|---|---|---|
| Test suite | `php artisan test` | **98 passed, 585 assertions** |
| Style | `vendor/bin/pint --test` | **PASS — 151 files** |
| Static analysis | `phpstan analyse --no-progress` (level 6, 1G) | **PASS — 0 errors** |

PHPStan was reduced from 127 findings to 0. No baseline, no level reduction and
no `@phpstan-ignore` entries were introduced.

## Delivered

- Workflow declarative template validation and snapshotting.
- Case submission, list/detail/timeline read model with separate application and
  effect status fields.
- Immutable decision evidence, state machine, row-locked approval runtime,
  required-count multi-approver support, RETURN/resubmit cycles, delegation and
  admin override with audit.
- Effect registry, local savepoint execution, external outbox dispatch,
  scheduled due-runner, retry with max attempts and escalation.
- Reassignment command and workflow invariant check command.
- Seeded seven reference templates idempotently.
- Delegation CRUD with date, amount and scope enforcement.
- Migrations, Workflow service provider, routes and capability middleware.

## Static-analysis fixes (behavior-neutral)

- `ConditionEvaluator`: removed the redundant `default` arm. The operator is
  already validated against the closed `OPERATORS` list, so every runtime string
  is still handled and invalid operators continue to throw
  `INVALID_CONDITION_OPERATOR` before the match.
- `EffectService::aggregateId`: added an explicit `default` arm that throws
  `UnhandledMatchError`. `validateDeclarations()` rejects unknown
  `aggregate_id_field` values before materialisation, so previously the match
  would also have thrown `UnhandledMatchError`; only the message is more
  specific.
- `EffectService::refreshCaseEffectStatus`: replaced repeated `array_filter`
  count predicates with one closure evaluated against the same statuses. Truth
  table and resulting `CaseEffectStatus` values are unchanged.
- `EffectExecutor`: removed a redundant second null check while preserving the
  enclosing `scheduled_for !== null` guard.
- `WorkflowDelegation`: corrected date cast property docblocks to
  `CarbonImmutable` and documented `revoked_at`; controller access now uses
  non-nullsafe `->` for the two non-null date columns.
- `CaseQueryService`: removed unused `Clock` dependency (it had no clock-based
  read behavior) and typed `visibleQuery()` as `Builder<CaseModel>`.
- `DecisionService`/`DelegationService`: precise node array shapes plus
  null-coalescing access that PHP evaluates identically on a null grant.
- Tests: added list/array-shape and `TestResponse<JsonResponse>` generics. In
  tests, `fresh()?->` chains were replaced with `refresh()` on already-persisted
  models, preserving the same asserted values. `collect()` calls received a
  typed `(array)` cast to resolve template inference. Architecture mutation
  checks use `ReflectionClass::hasMethod()` instead of the PHPStan-redundant
  `method_exists()`.

No observable behavior change was identified or made.

## Known concerns / gaps

- `php artisan capability:audit` and `php artisan arch:test` are referenced by
  repository rules but are not defined at this branch baseline
  (`b869706`). They cannot be run here.
- OpenAPI contract specs and `docs/06-workflows.md` are not present as tracked
  files in this branch baseline; Task 4 routes and behavior are covered by the
  feature tests and architecture test instead.
- The implementation remains a reference slice, not a production rollout:
  IAM authorizer, organization and people modules are represented by injected
  contracts and fakes rather than full cross-module integration.
- Multi-approver concurrency is serialized by row locks and tested via replay;
  true parallel database sessions are not exercised on SQLite.
