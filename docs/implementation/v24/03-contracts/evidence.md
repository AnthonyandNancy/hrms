# T03 — Contract agreed terms, activation and append-only version evidence

- **Status:** COMPLETE
- **Date:** 2026-11-28
- **Plan:** `docs/superpowers/plans/2026-11-28-v2-4-implementation-master-plan.md`, T03
- **Full gate:** `docs/implementation/v24/03-contracts/gate-all.log`

## Implemented

1. Added first-class agreed terms to `employment_contract_versions`:
   `agreed_salary_minor`, `agreed_currency`, `terms_hash`, `status`, `activated_at`,
   `activated_by`.
2. Added model- and SQLite database-level append-only guards for contract versions.
3. Contract creation accepts the agreed salary/currency; the hash is computed from the
   complete immutable version snapshot.
4. Added `POST /v1/employment-contracts/{id}/activate` with Workflow-independent command
   idempotency, signed-start-date validation, period-overlap rejection and future
   `PENDING_EFFECTIVE` handling.
5. Added `GET /v1/employments/{employmentId}/contract-versions` for scoped selectors.
6. Contract detail now returns `agreed_salary_cents`, `source_version`, `state`, `version`
   and server-computed `allowed_actions`.
7. Contract activation is represented in the generated page-action manifest; both frontend
   and backend page catalogues now expose the detail action.
8. Overlapping effective contract periods for the same employment are rejected, while
   explicit renewals that begin after the prior end date remain valid.

## Verification

Focused contract/action/route suite:

```text
OK (34 tests, 4863 assertions)
```

Full authorititative matrix after T04 changes were also present:

```text
GATE:ALL PASS (22 gates, 247.7s)
```

## Known follow-up

- The real MySQL production trigger is deferred to the MySQL migration/recovery gate
  T19; SQLite test triggers and the Eloquent append-only guard are green now.
- Contract detail frontend consumption of `allowed_actions` is completed in T17.
- Contract→Payroll consumption starts in T04 and is completed in T09/T17.
