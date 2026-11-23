# Engineering Rules

These rules are binding for all contributors and coding agents in this repository.

1. **TDD where practical.** Write a failing test for behavior, watch it fail for the right reason, implement the minimum, then green before refactoring. Do not claim a gate passes without running it.
2. **No authorization bypass.** Every `/v1` route is capability-guarded unless it is in the explicit public allowlist (`v1/health`, `v1/meta`). Never add hidden admin shortcuts, permanent superusers or client-trusted capability flags.
3. **One writer per table.** Each table has exactly one owning module. Other modules use `Contracts` or projections; direct cross-module writes and raw `DB::table` access are architecture violations.
4. **Forward-only migrations.** Never edit a migration that has been merged. Correct with a new migration. Migrations use anonymous classes.
5. **No money floats.** Monetary amounts are integer minor units; rates are decimal strings scaled to at least 1e6. Payroll code must not use PHP floats.
6. **Document ADRs.** Irreversible architectural decisions require an Accepted ADR with context, alternatives, consequences, validation and review triggers. Do not silently diverge from an approved decision.
7. **Contracts are law.** OpenAPI 3.1 is the API source of truth. Add or change the spec with the route, and keep `capability:audit` and `arch:test` green.
8. **No real personal data.** Seeds and tests use synthetic names and clearly fake identifiers. Never commit `.env`, credentials, vendor dependencies, SQLite databases or real employee data.
9. **Run the gates before commit.**

```bash
cd backend
php artisan test
vendor/bin/pint --test
vendor/bin/phpstan analyse
php artisan capability:audit
php artisan arch:test
cd ..
node deploy/scripts/openapi-lint.mjs
pnpm openapi:validate
```

10. **UTC storage, explicit business time.** Timestamps are stored in UTC. Business dates are computed from the legal entity's timezone and calendar through the shared clock/calendar contracts.
