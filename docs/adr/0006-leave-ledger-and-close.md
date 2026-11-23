# ADR-0006: Append-only leave ledger in signed integer minutes

- **Status:** Accepted — 2026-11-24
- **Decision IDs:** D-06 (leave ledger and close), D-17 (statutory leave and calendar interaction)
- **Supersedes:** none
- **Review trigger:** a leave rule needs a unit that is not a whole minute, a close process must delete or rewrite ledger rows, or an accrual must depend on a rate that cannot be expressed as a dated integer quantity

## Context and trigger

Leave balances are money-like: they are earned over time, consumed, expire, get encashed at offboarding, and are often regulated. Implementing them as a mutable `balance` column loses the reason for every change, cannot be reversed idempotently, and makes concurrent requests race. Implementing them in decimal days introduces rounding drift (0.5 + 0.5 + 0.5 day requests on an 8-hour day) and makes "how did this employee end with 3.00 days" unanswerable.

D-06 fixes an append-only ledger. Because closing a period and reversing an already-consumed leave is not reversible once payroll has settled it, the representation must be decided before Time serves real traffic — hence this ADR pre-dates R2 acceptance.

## Decision

1. **Signed integer minutes, append-only.** `leave_ledger_entries` records `quantity_minutes` as a signed integer. There is no float and no decimal days in storage. A day is converted at the legal entity's `standard_day_minutes` only at the display boundary (`balance_days`), never in the ledger.
2. **Named accounts, one equation.** The ledger uses exactly seven accounts — `GRANT`, `RESERVE`, `CONSUME`, `RELEASE`, `EXPIRE`, `ADJUST` and `REVERSE` — enumerated in `LeaveEquation::ALL`. `LeaveEquation::summarize()` derives every projection from the entries, and `available` excludes reserved time while `balance` is the total. `tests/Unit/Time/LeaveEquationTest.php` asserts the equation accounts for every account and that negative quantities are rejected.
3. **Reserve then consume, never double count.** A submitted leave request posts a negative `RESERVE` (a hold). Approval posts `RELEASE` for the hold plus `CONSUME`, so approved time is counted exactly once. Cancellation posts a `RELEASE` that restores availability once and is idempotent on repeat.
4. **Reversal, not rollback.** Any correction writes a `REVERSE` entry whose direction is the opposite of the target entry (`ReversalTest`). Ledger rows are never updated or deleted; the model rejects mutation (`UPDATED_AT = null`, append-only guard). This is what allows a settled period to be corrected without rewriting history.
5. **Idempotency by source.** Entries carry `source_type`, `source_id` and optionally `case_id`, with a unique constraint that prevents the same source from reserving twice. A repeated approval or retried job is a no-op rather than a double deduction (`LeaveConcurrencyTest`).
6. **Concurrency safety.** Every balance check and append runs inside one transaction under a row lock on the leave account (`LeaveLedgerService`), so two requests for the last day produce one success and no negative balance unless the leave type explicitly allows a negative balance.
7. **Projections are rebuildable, never authoritative.** `leave_balances` is a projection. `php artisan ledger:reconcile` compares it against the ledger, reports differences, and `--rebuild` repairs it; a corrupted projection exits non-zero without changing the ledger. Operators may only rebuild, never "fix" the ledger to match a projection.
8. **Period close does not rewrite.** Closing a period blocks new entries with an effective date inside it; later corrections use a reversal dated after the close. Expiry is an explicit `EXPIRE` entry, not a decrement of the accrual row.
9. **Statutory floors interact with the ledger, not the balance column.** `StatutoryAnnualLeaveFloor` (ADR-0003) constrains the entitlement that generates ACCRUAL; it never edits a balance directly.

## Alternatives considered

| Alternative | Why rejected |
| --- | --- |
| Mutable `balance_days` decimal column | No reason code per change, not idempotent, and concurrent requests race; rounding drift makes the balance unexplainable. |
| Store decimal days as the ledger unit | Half-day and hourly leave produce repeating decimals; converting to minutes only at read time keeps arithmetic exact. |
| Floating-point minutes/hours | Violates the no-float rule and accumulates error across many entries. |
| Delete or update the original entry on correction | Destroys the audit trail and makes an already-settled payroll period irreconcilable. |
| Enforce uniqueness only in application code | Two concurrent requests both pass the check; a database constraint is required. |
| Treat the projection as source of truth | A corrupted projection would become the balance; the ledger must be able to rebuild it deterministically. |

## Consequences

Positive:

- every minute of leave has a reason, a source and an author;
- corrections are auditable and idempotent;
- concurrency cannot overspend without explicit permission;
- projections can be rebuilt and reconciled at any time.

Negative / costs:

- more rows than a balance column, and reads must aggregate;
- day-level UX requires a standard-day conversion that differs by legal entity;
- period close needs an explicit rule for post-close corrections;
- the ledger equation must be re-verified whenever a new account is added.

## Validation

- `tests/Unit/Time/LeaveEquationTest.php` — equation covers every account, available excludes reserve, negative quantity rejected, exact-balance assertion.
- `tests/Feature/Time/LeaveLedgerBalanceTest.php` — all accounts reflected, reserve/release, no negative unless allowed, day display uses legal-entity standard day, duplicate source append idempotent.
- `tests/Feature/Time/ReversalTest.php` — consume then reverse restores once; reverse direction is opposite of target.
- `tests/Feature/Time/LeaveConcurrencyTest.php` — two requests for the last day yield one success and no negative balance; unique source prevents double reserve.
- `tests/Feature/Time/Console/LedgerReconcileCommandTest.php` — clean projection exits 0, corrupted projection exits 1 without touching the ledger, `--rebuild` repairs then rechecks clean, tenant filter honoured.
- `tests/Feature/Time/Http/TimeEndpointsTest.php` — leave request reserves and cancel releases exactly once through HTTP.
- `php artisan ledger:reconcile` on the demo dataset (0 differences).

Evidence: `evidence/logs/ledger-reconcile.txt`, `evidence/logs/backend-test-current.txt`.

## Security and privacy

Leave reasons and leave types can reveal health or family information, so they are treated as at least P1/P2 fields and masked by field profile; requests are capability-guarded (`time.leave.request`, `time.leave.read`, `time.leave.admin`, `time.leave.ledger.read`) and row-scoped to the caller's anchors. Ledger rows carry the acting user and case id, so a disputed deduction is traceable. The audit trail is append-only; no route exposes a ledger delete.

## Review triggers and rollback

Revisit if a jurisdiction requires accrual in hours to a precision finer than a minute, if a close must retroactively change settled history (it must not — use reversals), or if a second writer ever needs to post to the ledger. Rollback is not feasible once balances are used in payroll: it would require a new ledger, a reconciliation of every account and an audited opening balance, which is why the append-only design is treated as irreversible.
