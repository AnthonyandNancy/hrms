# ADR-0003: Case-owned lifecycle effects and talent thin slices

- **Status:** Accepted — 2026-11-23
- **Decision IDs:** D-05 (Case/Decision/Effect), D-07 (contracts), D-16 (effective dating); supports D-08/D-25
- **Supersedes:** none
- **Review trigger:** an effect needs to mutate another module's tables directly, a lifecycle command cannot be expressed as an idempotent Case effect, or a talent approval bypasses Workflow

## Context and trigger

Task 5 introduces employee lifecycle changes (onboarding, probation, transfer,
terms, offboarding, contract renewal, rehire) and talent slices (recruitment,
performance, training). The architecture already requires Case/Decision/Effect
for approvals and forbids a controller from writing cross-module facts. The
implementation needed a stable seam between Workflow's internal effect
registry and domain modules without exposing Workflow models.

## Decision

1. **Domain effect contract.** Workflow publishes `DomainEffectHandler`,
   `DomainEffectCommand` and `DomainEffectResult` in `Contracts`. Domain
   modules implement `supports/apply/reverse`; `DomainEffectHandlerAdapter`
   adapts them to Workflow's internal handler interface. Domain modules do not
   import `EffectRegistry` or Workflow models.
2. **Lifecycle owns orchestration.** Lifecycle case types map to closed,
   code-owned templates and effect types. The approved effect calls People,
   IAM, Time and Reporting through their Contracts, inside one caller-owned
   transaction. It records an EFFECTIVE `lifecycle_intent` as the idempotency
   guard and an outbox event for downstream consumers.
3. **People remains the employment fact writer.** People publishes
   `EmploymentLifecycleDirectory` and `PeopleEmploymentWriter` contracts. The
   writer appends assignments and terms versions, never rewrites ended rows.
   Lifecycle stores only its projection/evidence tables.
4. **Effective dates are explicit.** Business effects write half-open
   effective ranges; application status and effect status stay independent.
   Future effects are visible only through as-of queries on/after the
   effective date.
5. **Offboarding is atomic.** Checklist verification, assignment/employment
   termination, IAM session revocation/auth_version increment, Workflow
   delegation revocation, leave-balance capture and EFFECTIVE intent share one
   database transaction.
6. **Talent approvals use Workflow.** Requisition and offer approvals are
   Cases with talent effect handlers. Offer acceptance is an explicit state
   transition that creates at most one onboarding Case draft via a
   Lifecycle-owned contract. Performance appeals create new Cases; the
   submitted review is immutable. Training capacity uses a row lock and a
   unique enrollment key.
7. **Contracts are the API boundary.** Every route is in OpenAPI 3.1, has a
   capability manifest entry, and mutating commands require an
   `Idempotency-Key`.

## Alternatives considered

| Alternative | Why rejected |
| --- | --- |
| Controllers call People services directly for each vertical | Approval and effect state diverge; no replay-safe effect record; cross-module writes escape the Case audit trail. |
| Lifecycle imports Workflow `EffectRegistry`/models | Violates the one-writer and contracts-only rules; makes domain modules depend on Workflow internals. |
| One generic "employee update" effect with arbitrary payload | Widens authority, makes templates non-closed, and loses the per-vertical validation and audit semantics. |
| Store current employment values only | Loses as-of history and makes future-dated effects and payroll preparation impossible to reconcile. |
| Talent owns onboarding employment creation | Duplicates People fact ownership and bypasses lifecycle validation/checklist rules. |
| Rely on application-level capacity checks | Race can overbook a session; a lock plus unique constraint is required. |

## Consequences

Positive:

- every employee lifecycle change is traceable from Case to decision to
  idempotent effect and audit/outbox evidence;
- future-dated and historical views remain consistent;
- module boundaries stay enforceable by `arch:test`;
- talent slices reuse the same approval and evidence machinery.

Negative / costs:

- more contracts and DTO mapping than direct service calls;
- Workflow templates and IAM role membership must be seeded before a case can
  be submitted;
- cross-module integrity diagnostics need read-only contracts instead of
  joining tables freely;
- the Reporting export-cancellation port is initially a no-op.

Security and privacy: audit payloads omit PII; candidate contact values are
encrypted and hashed; authorization is capability-guarded, with no
client-trusted capability flags. Offboarding revocation is transactional and
does not depend on a later job.

## Validation

- `php artisan test` — lifecycle, talent and integration suites, including
  idempotency, future-dated transfer, offboarding revocation, offer-to-onboarding
  conversion, performance appeal immutability and training capacity.
- `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`.
- `php artisan capability:audit`, `php artisan arch:test`.
- `node deploy/scripts/openapi-lint.mjs`, `pnpm openapi:validate`.

Evidence lives in `.superpowers/sdd/artifacts/task-5-report.md`.

## Rollback and review

Lifecycle effect types and routes are additive; removing one requires a new
API version. A blocked effect can be retried through Workflow's existing effect
retry path. If Reporting gains a real export-cancellation contract, replace
`NullExportTaskCanceller` behind the existing port. Re-review before allowing
any effect to write another module's tables, before introducing asynchronous
effect execution, or before payroll consumes lifecycle outcomes as immutable
snapshots.
