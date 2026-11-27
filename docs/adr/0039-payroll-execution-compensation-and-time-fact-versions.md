# ADR-0039: Payroll execution compensation, closed time facts and orthogonal settlement states

- **Status:** Accepted — 2026-11-28
- **Supersedes:** none
- **Amends:** ADR-0006, ADR-0007, ADR-0014 (only where this ADR is more specific)
- **Decision owner:** Product + Engineering
- **Source requirements:** `docs/FactoryHR_V2.4_需求与技术设计说明书.md`, Part I AC-04–AC-14 and AC-19–AC-22; Part II 5.1.2–5.1.8, 5.2 B–E, 5.3, 5.5–5.8
- **Implementation plan:** `docs/superpowers/plans/2026-11-28-v2-4-implementation-master-plan.md`

## Context

The approved V2.4 design separates four facts that the current code interleaves:

1. People owns the person, employment, assignment, contract and append-only contract terms/version.
2. Payroll exclusively owns the **actual execution compensation** used for calculation.
3. Time owns raw attendance, leave, overtime, travel and an immutable monthly time-fact version.
4. Workflow owns cases, decisions and effects; it does not own the resulting business tables.

The current implementation still reads `employment_terms.base_salary_minor` during payroll, allows one scheme for an entire run, reads live attendance instead of a closed monthly fact version, treats `PUBLISHED → PAID → CLOSED` as one chain, and clamps negative YTD movements. Those behaviours cannot satisfy AC-04, AC-06–AC-12, AC-14 or AC-20.

This ADR records the already-approved V2.4 direction so migration and service changes do not silently fall back to the legacy model.

## Decision

### 1. Contract agreement is not execution compensation

- `employment_contract_versions` (or the successor append-only contract-terms version record) is the authoritative evidence of the **agreed** contract terms and agreed salary summary.
- `employee_compensations` is the Payroll-owned, effective-dated **execution compensation version** used by payroll.
- A compensation version must reference the employment, the selected contract version, the published scheme/rule version and typed parameter overrides. It must pass same-employment, same-effective-range, currency, overlap and completeness checks.
- Contract agreement and execution compensation may legitimately differ; the UI must show both and their linkage rather than copy one over the other.
- `employment_terms` salary fields become historical read-only compatibility evidence. New payroll calculations must not use them as a live salary fallback.
- Historical migration is fail-closed: if the contract, scheme or unique effective range cannot be resolved, the row is marked `unresolved` and blocks the affected payroll instead of guessing the first/latest row.

### 2. Payroll uses an immutable, self-contained snapshot

- A payroll run resolves a scheme, rule version and parameters **per employee and effective segment**, not one batch-wide scheme.
- A locked snapshot records contract version, execution compensation version(s), scheme/rule/item versions, closed time-fact version/hash, benefit and performance inputs, statutory versions, completeness and blockers.
- Calculation consumes only the locked snapshot. After lock, it must not read current scheme, contract or attendance rows to reinterpret the result.
- Required missing data blocks the employee/run. It is never converted to a zero or a full-attendance assumption.
- A run with any employee-level blocker cannot enter review, approval, publication or disbursement.

### 3. Time closes into an immutable monthly fact version

- Time owns an append-only `time_payroll_fact_version` and per-day/interval fact rows generated from approved attendance, leave, overtime and travel facts.
- A closed period records a stable source hash and exception count. Payroll consumes only the closed version.
- Reopening preserves the old version and creates a new version on the next close; it never mutates the facts consumed by a historical payroll snapshot.
- Leave payroll treatment is a versioned policy attribute (`PAID/PARTIAL/UNPAID/UNRESOLVED` plus paid ratio), not an inference from an English leave-type code.
- Overtime PAY and TIMEOFF are mutually exclusive. PAY enters payroll only through an effective settlement; TIMEOFF enters a time-off ledger and never produces payroll overtime pay.
- Travel creates a counted-attendance fact through the same fact pipeline, not a fabricated punch and not a calendar-only link.

### 4. Corrective accounting is cumulative and auditable

- A correction recalculates the target for the original payroll period from the original facts and the knowledge cutoff.
- The current correction delta is `target − (original effective amount + all effective prior corrections)`. It is not a second full target and not a repeat of an earlier delta.
- the original payslip and each correction remain immutable.
- YTD is an append-only signed ledger; `payroll_ytd` is a rebuildable projection. Negative corrections and carry-forward are preserved, never clamped to zero.
- Duplicate publication, correction, disbursement or effect execution is idempotent.

### 5. Approval, payslip publication and disbursement are separate facts

- Payroll run workflow state, payslip publication state and disbursement registration state are independent.
- Approval does not automatically mean the payslip is published or that money has been disbursed.
- An approved run is corrected by a new correction run; it cannot be edited or reopened in place.
- Closing a run requires the configured business prerequisites, including the independent publication and disbursement states where the design requires both.

### 6. Authorization is object-aware and server-owned

- Page/action/capability/anchor/field definitions have one code-owned catalog.
- Detail responses expose server-computed `allowed_actions` plus state/version/source version. Clients may hide unavailable actions but cannot derive authority.
- Payroll run membership is explicit and can assign preparer, reviewer, approver, publisher and disburser before the corresponding action; capability alone does not grant access to an object.
- After a role/permission change, `auth_version`/session invalidation makes the next request use the new authority.

## Alternatives considered

1. **Keep `employment_terms` as the payroll source and treat `employee_compensations` as a read-only profile.** Rejected: it recreates the dual source of truth and makes salary adjustments display without affecting pay.
2. **Keep one run-level scheme and add a UI scheme selector per employee.** Rejected: the run-level column and calculator interface cannot represent different employee schemes or rule versions; this is not a UI-only change.
3. **Read live attendance at calculation time and only show a warning for missing data.** Rejected: a later edit would change a locked result and missing facts would still be silently paid as full attendance.
4. **Reuse the mutable `payroll_ytd` row and clamp negative corrections.** Rejected: it loses signed correction history and cannot rebuild or audit cumulative tax.
5. **Keep `PUBLISHED → PAID → CLOSED` as the only state and infer publication/disbursement flags from status.** Rejected: the business needs either operation to be independently authorized and evidenced.
6. **Let clients compute allowed actions from capability strings and status.** Rejected: hidden buttons are not an authorization boundary and object membership cannot be inferred from a global capability.
7. **Automatically resolve ambiguous historical terms by choosing the latest amount.** Rejected: it may pay the wrong amount. Ambiguity must be explicitly unresolved and adjudicated.

## Consequences

### Positive

- One explainable payroll source of truth and an auditable chain from contract → execution compensation → snapshot → payslip → correction/YTD.
- Historical payroll can be replayed from immutable snapshots and closed time facts.
- Missing data, wrong anchors and unauthorized amounts fail closed.
- Publication and disbursement can be independently scheduled, authorized and reconciled.
- Role and capability changes take effect immediately and have object-level visibility.

### Costs and risks

- Forward migrations and backfills are larger; ambiguous history will remain blocked until reviewed.
- Payroll calculation becomes employee/segment-oriented and touched code paths include report/export/freeze/receipt projections.
- More tables and state fields must be kept consistent, so transaction and invariant tests are mandatory.
- Legacy `employment_terms` writers must be redirected or made read-only, which can break compatibility shims if they are not tested.
- Real-stack and MCP verification take longer than SQLite/mock tests.

### Non-goals

- No multi-tenant or multi-company accounting.
- No arbitrary PHP/JavaScript/SQL payroll formulas; only the approved restricted AST.
- No bank payment, statutory filing or e-signature integration.
- No change to UTC storage, company business-date semantics or integer minor-unit money.

## Validation

The decision is accepted only while these gates remain green and evidence-backed:

- Contract/compensation chain tests for same employment, date, overlap, contract version, scheme version and unresolved history.
- Employee-level multi-scheme tests, mid-month segment tests (8000/9000 → 8500), missing-input blocker tests and no-current-input-after-lock tests.
- Time fact close/reopen/hash tests, leave net-ledger and paid-treatment tests, overtime PAY/TIMEOFF exclusivity and travel fact tests.
- Correction tests for 8080 → 8300 (+220) → 8350 (+50), signed YTD ledger and carry-forward.
- Publish/disbursement/close independence, duplicate action idempotency and approved-run immutability.
- `allowed_actions` server tests, role/auth_version replay tests and field/amount negative tests.
- `pnpm gate:all`, real MySQL/Redis/object-storage migration and restore checks, and Playwright MCP acceptance evidence.

## Review triggers

Review this ADR before implementation of any of the following changes:

- Changing the payroll execution source, snapshot schema version or closed Time fact contract.
- Relaxing the no-zero fallback/blocker rule.
- Combining publication and disbursement back into one state transition.
- Adding a second payroll calculation engine or allowing arbitrary executable rules.
- Changing the correction baseline or YTD sign semantics.
- Changing object-action semantics or allowing client-derived authorization.
- Any return to multi-company/tenant or real external payment integration.
