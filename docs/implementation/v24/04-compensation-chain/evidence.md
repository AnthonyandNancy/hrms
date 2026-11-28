# T04 — Execution compensation version linked to contract and scheme

- **Status:** COMPLETE
- **Date:** 2026-11-28
- **Plan:** `docs/superpowers/plans/2026-11-28-v2-4-implementation-master-plan.md`, T04
- **Full gate:** `docs/implementation/v24/04-compensation-chain/gate-all.log`

## Implemented

1. Extended `employee_compensations` with:
   - `contract_version_id`
   - `payroll_scheme_id` / server-resolved `payroll_scheme_version`
   - typed flat `parameter_overrides_json`
   - `effective_to`, `status`, `approved_case_id`
   - `source_type/source_id`, `content_hash`, `supersedes_id`
2. `CompensationService` validates:
   - contract version exists and belongs to the same employment;
   - compensation date is inside the contract version range;
   - contract/scheme currency matches compensation currency;
   - scheme is active and effective at the compensation date;
   - effective range ordering and supported status;
   - parameter overrides are a flat scalar map (no nested arrays/floats).
3. Client-provided scheme version is ignored; the server stores the published scheme version.
4. Compensation rows remain append-only. A previous effective version is recorded through
   `supersedes_id`; same-employment/same-effective-date remains a 409.
5. `content_hash` records the complete normalized execution compensation link.
6. API response and OpenAPI expose the contract/scheme/version/hash and P3-protected parameter
   override projection.
7. Added Integration test proving the chain:
   `employment → contract version → execution compensation → scheme version → parameters`.

## Verification

Focused integration + existing compensation regression:

```text
OK (18 tests, 84 assertions)
```

Broader focused/static suite including contract and action contracts:

```text
OK (34 tests, 4863 assertions)
```

Full gate:

```text
GATE:ALL PASS (22 gates, 247.7s)
```

## Known follow-up

- Payroll snapshot/calculation does not yet consume the new compensation row; this is the
  explicit T09 integration boundary.
- Existing historical compensation rows without a contract link remain readable and must be
  treated as unresolved by T09/T19 rather than guessed.
- The frontend compensation form does not yet expose the full contract/scheme/parameter
  selector; T17 owns the UI completion.
