# T07 Leave Workflow Effects and Net Facts

## Scope

Closed the leave approval execution gap identified by the T07 plan and AC-08 /
AC-13:

- Leave request approval is a real Workflow domain effect
  (`time.leave.apply`), not `workflow.noop`.
- Revocation executes a declared reverse effect (`time.leave.reverse`) and
  appends exactly one REVERSE ledger fact for the original CONSUME.
- Leave payroll treatment is a first-class, versioned policy property.
- Payroll input uses signed ledger net rather than leave-type code guessing.
- Closed-month revocation stays blocked by the existing freeze guard.

Snapshot, Calculation, YTD, Onboarding, Notification and Report code were not
changed.

## Data model

New forward-only migration:

`backend/database/migrations/2026_11_28_000600_add_payroll_treatment_to_leave_policies.php`

Adds to `leave_policy_versions`:

- `payroll_treatment` nullable string: `PAID`, `PARTIAL`, `UNPAID` or
  `UNRESOLVED`.
- `paid_ratio_scaled` nullable unsigned integer, validated to `1..999999` for
  `PARTIAL`.

Nullable is intentional: legacy rows with neither column read as `UNRESOLVED`
instead of being guessed from `leave_types.code`.

`LeavePolicyService` now accepts the first-class fields, validates them and
stores them in the new columns. The old nested
`accrual.payroll_treatment` input remains accepted for one compatibility
window, but is not written back into `accrual_json`.
`LeavePolicyService::row()`, `TimeFactVersionService::leaveTreatment()` and
`PayrollInputDirectoryService` read the first-class fields first and use the
legacy `accrual_json` shape only as a compatibility fallback.

## Workflow effect wiring

New:

`backend/app/Modules/Time/EffectHandlers/LeaveApprovalEffectHandler.php`

- Implements `DomainEffectHandler`.
- `time.leave.apply` verifies that the Workflow case is `LEAVE_REQUEST`,
  `APPROVED`, and that its frozen payload still matches the Time projection.
- `time.leave.reverse` delegates to Time's revocation service.
- Reuses `LeaveApprovalService`; tests never call it directly to prove approval.

Registration:

- `backend/config/workflow.php`
- `backend/app/Modules/Time/Providers/TimeServiceProvider.php`
  (module-owned effect handler list and effect types)
- `backend/app/Modules/Workflow/Providers/WorkflowServiceProvider.php`

Template seed:

- `workflow:seed-templates` now publishes `leave.request` with
  `time.leave.apply`, `reverse_effect_type: time.leave.reverse`.
- All other seeded templates remain `workflow.noop`.
- Template create stays `firstOrCreate` on `(code, version)`, so re-running the
  seed command is still idempotent and does not rewrite existing versions.

Workflow support added for declared reversals:

- `EffectService` validates that a declared reverse effect type is registered
  before a Decision is committed and freezes the declaration into the effect
  payload.
- `EffectExecutor::reverse()` appends a separate reversal effect linked by
  `reversal_of_id`, runs the reverse handler, and marks the original effect
  `REVERSED`. Replays return the existing reversal and never invoke the handler
  twice.
- `CaseCommandService::reverseEffect()` is the Workflow-owned command used by
  the Time revocation path.

## Ledger semantics and idempotency

Approval:

- Time uses the request's actual minutes and frozen policy version.
- `LeaveLedgerService::consume()` releases any submit-time RESERVE and appends
  one signed CONSUME.
- The existing ledger identity
  `(employment_id, leave_type_id, source_type, source_id, account)` and unique
  index make CONSUME replay-safe. Workflow's effect row and decision idempotency
  are additional guards.

Revocation:

- The Time revocation path asks Workflow to execute the reverse effect.
- The reverse handler calls `LeaveApprovalService::reverseApprovedCase()`,
  which appends one REVERSE with `source_type=LEAVE_REVOKE` and
  `targetAccount=CONSUME`.
- A replayed revoke returns the terminal projection and does not add a second
  REVERSE. The ledger source key would additionally collapse a concurrent
  duplicate.
- If a case has no applied effect (legacy projection), the existing fallback
  releases only an outstanding hold.

Payroll input:

- `PayrollInputDirectoryService::attendanceForPeriod()` groups
  CONSUME/REVERSE/RELEASE ledger entries by case, sums signed quantities, and
  uses only the remaining net magnitude.
- CONSUME is negative, REVERSE positive; RELEASE is a hold event and does not
  create payroll leave.
- Treatment is taken from the frozen policy version:
  - `PAID`: net minutes into paid leave.
  - `UNPAID`: net minutes into unpaid leave.
  - `PARTIAL`: exact integer split via `paid_ratio_scaled`.
  - `UNRESOLVED`/missing: throws
    `LEAVE_PAYROLL_TREATMENT_UNRESOLVED` (fail closed).
- No `leave_types.code` substring is used for paid/unpaid classification.
- Legacy case-less ledger entries keep a narrow published-policy lookup so the
  existing migration/manual-import path still has a policy source; real request
  entries require their frozen policy version.

Closed periods:

- The revocation path calls `PayrollFreezeGuard::assertNotFrozen()` over every
  month spanned by the request before appending anything.
- A closed `attendance_period_closures` row yields stable
  `PERIOD_CLOSED` conflict with the period in details; no REVERSE is written
  and the projection stays `APPROVED`.

## Tests

New test file:

`backend/tests/Feature/Time/LeaveWorkflowEffectTest.php`

Seven feature tests:

1. Real Workflow approve -> exactly one CONSUME, exactly one RESERVE RELEASE,
   applied `time.leave.apply`.
2. Decision/effect replay -> no duplicate CONSUME.
3. Revoke -> one REVERSE; payroll unpaid net drops to zero; revoke replay adds
   no second REVERSE.
4. PARTIAL/UNPAID policies -> correct payroll paid/unpaid totals from the new
   columns.
5. UNRESOLVED policy -> payroll input fails closed.
6. Closed-month revoke -> `PERIOD_CLOSED`, no REVERSE, projection unchanged.
7. Cross-month approval -> one CONSUME, DEC/JAN closed fact rows each contain
   their own 960 minutes, no double count.

The four T06 fact tests and a Payroll integration fixture needed treatment
columns because T06 now correctly blocks unresolved treatment; those fixtures
were updated to publish explicit policies. No production behavior outside T07
was relaxed.

## Verification evidence

Focused + adjacent PHPUnit:

```text
OK (107 tests, 587 assertions)
```

Command:

```text
vendor/bin/phpunit --filter='LeaveWorkflowEffectTest|TimeFactVersionTest|LeaveRevokeFreezeTest|LeaveLedgerBalanceTest|ReversalTest|TimeWorkflowIntegrationTest|LeavePolicyAndOnBehalfTest|TimeToPayrollIntegrationTest|CommandsTest|EffectRegistryTest|EffectIdempotencyTest|StatusSeparationTest|ApprovedNotEffectiveTest|TemplateVersionTest|TimeEndpointsTest|TimeCatalogsTest|LeaveOpeningMigrationTest'
```

Contract-adjacent suite:

```text
OK (140 tests, 14120 assertions)
```

Pint:

```text
PASS (1360 files)
```

PHPStan:

```text
[OK] No errors
```

Architecture / capability:

```text
arch:test      Module boundaries OK (4 pre-existing non-blocking warnings)
capability:audit  Capability audit OK (35 pre-existing negative-manifest warnings)
```

Not run: `gate:all` (out of the requested boundary).

## Risks / follow-up

- Existing leave policies created before T07 are `UNRESOLVED` by design. A
  payroll run or monthly close that encounters such a policy now fails closed
  until an administrator publishes a version with an explicit treatment.
- `REVERSED` replaces the original effect status; the original payload and
  decision remain immutable and the reversal row is linked by
  `reversal_of_id`. Consumers that only aggregate `effects.status` see the
  two rows as the reversal evidence.
- The legacy case-less ledger compatibility lookup should be removed after
  historical imports are migrated to explicit policies.
- OpenAPI already declared `LeavePolicyCatalog.payroll_treatment` and
  `paid_ratio_scaled`; no contract change was required.

## T07a update — reversal atomicity and lock preservation

- Rebased the T07 branch onto the hardened T06 main (`b888693`, containing
  `58fbe80` month locks and signed-net allocation).
- `LeaveApprovalService` now locks every month in the request range using
  `PayrollFreezeGuard::periodsForRange()` before writing CONSUME.
- Added a regression test proving a throwing reverse handler cannot mark the
  original Workflow effect or Time projection as successful. The original
  effect remains `APPLIED`, the projection remains `APPROVED`, no REVERSE is
  written, and a later retry with a working handler succeeds.
- `EffectExecutor::reverse()` flips the original effect to REVERSED only after
  the reverse handler returns REVERSED; FAILED reversal rows are retryable.
- `CaseCommandService::reverseEffect()` runs inside one transaction with the
  case lock and throws `EFFECT_REVERSAL_FAILED` instead of reporting a failed
  reversal as successful.
- `WorkflowCasePort::reverseEffect()` and its adapter now return the Workflow
  outcome so Time commits `REVOKED` only after both Workflow and ledger
  reversal succeed.
- This is **T07a only**. Reject/return/withdraw release, real admin-role
  authorization, existing template upgrade, reversal audit/outbox, first-class
  UNRESOLVED submission boundary, company-timezone dates, real HTTP revoke
  coverage and Payroll Snapshot consumption remain T07b/T07c.

## T07b1 — reject/return/withdraw release pending leave holds

- Workflow templates may now declare one `outcome_effect_type` on an effect
  specification. The leave template declares
  `time.leave.pending.release`.
- DecisionService materializes and executes the outcome effect for REJECT and
  RETURN; CaseService does the same for WITHDRAW, inside the same transaction.
- `time.leave.pending.release` maps the Workflow outcome to Time:
  - REJECTED → Time projection REJECTED;
  - WITHDRAWN → Time projection WITHDRAWN;
  - RETURNED → Time projection RETURNED.
- It releases exactly one outstanding RESERVE via the append-only ledger,
  settles the projection, and is replay-safe through the ledger source key.
- RETURNED is excluded from active Time facts and may be approved again.
- Added HTTP-level tests for reject, return and withdraw asserting one RELEASE,
  no CONSUME and the expected Time state.
- Verification: workflow/leave adjacent suite 90 tests / 541 assertions;
  PHPStan and arch clean. Full gate follows the remaining T07 work.
