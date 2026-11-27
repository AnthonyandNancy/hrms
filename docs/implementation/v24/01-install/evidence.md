# T01 — Clean installation, demo guard, catalogue preservation and readiness

- **Status:** COMPLETE
- **Date:** 2026-11-28
- **Branch:** `v2.4-implementation`
- **Plan:** `docs/superpowers/plans/2026-11-28-v2-4-implementation-master-plan.md`, T01
- **ADR:** `docs/adr/0039-payroll-execution-compensation-and-time-fact-versions.md`

## 1. Implemented behaviour

1. Added a single Platform-owned installation state (`platform_installation_states`, `instance_key=primary`).
2. `hrms:install` now:
   - accepts no annual-days/legal-basis values and leaves the annual entitlement unresolved rather than inventing a policy;
   - creates or resumes one pending installation for one administrator;
   - refuses a second administrator after installation;
   - completes only after all reference steps and the superadmin check;
   - records catalog hash and completion evidence.
3. `db:seed` is a no-op and never seeds demo identities or business rows.
4. `iam:seed-demo` is refused in production.
5. Capability sync preserves custom role pages and capabilities, including the wildcard exclusion; orphaned codes in role grants now block removal.
6. `/v1/health/ready` checks migrations, completed installation state and exactly one active privileged administrator in addition to db/cache/storage.
7. OpenAPI `HealthData.checks` documents the three new optional readiness checks.

## 2. RED evidence

Command:

```bash
cd backend
../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpunit \
  --filter 'InstallAndDemoGuardTest|SyncCapabilitiesPreservesCustomGrantsTest|InstallCommandTest|HealthProbeTest' \
  --colors=never
```

Observed before implementation:

```text
16 tests, 8 failures, 3 errors
- platform_installation_states table missing
- default seeder created 5 demo users
- legacy demo seeder was not rejected in production
- readiness did not call migration/installation/administrator checks
- sync deleted custom role page grants
- optional leave parameters still blocked install
```

Those failures were the expected RED for the new behaviour, not production-code errors.

## 3. GREEN evidence

Focused tests:

```text
OK (16 tests, 127 assertions)
```

Adjacent filter:

```text
phpunit --filter 'Install|Health|SyncCapabilities|DemoSeed'
OK (28 tests, 1874 assertions)
```

Static/contract checks:

```text
Pint --test: PASS (1338 files)
PHPStan: [OK] No errors
arch:test: Module boundaries OK
OpenAPI validate: valid (2 pre-existing operation-4xx warnings)
actions:check: OK
openapi:check: generated types are current
```

## 4. Full gate

Command:

```bash
pnpm gate:all
```

Result:

```text
GATE:ALL PASS (22 gates, 222.5s)
```

Full output: `docs/implementation/v24/01-install/gate-all.log`.

## 5. Untested / follow-up

- The full gate uses SQLite tests, not the final MySQL/Redis/object-storage acceptance stack; T19 owns that evidence.
- This task proves installer semantics but does not yet execute a browser install/login flow; T18/T19 own the Playwright MCP proof.
- Custom role preservation is tested at the database/projection level; an end-to-end custom-role edit/upgrade remains part of the later IAM/authorization task T02.
