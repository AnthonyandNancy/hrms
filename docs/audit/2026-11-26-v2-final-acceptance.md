# FactoryHR V2.1 — final acceptance and GO/NO-GO

Date: 2026-11-27
Branch: `feat/v2.1-remediation`
Decision owner: lead (with independent review evidence recorded per volume)

## Decision

# NO-GO for production release.
# GO for internal / staging use.

Rationale in one paragraph: the four-volume remediation has produced a
functionally complete, gate-clean system — 1278 backend tests and 599 frontend
tests pass, all 22 gates pass fail-fast and reproducibly, three of four volumes
carried an independent review that found and forced fixes to real defects, and
the backup/restore and rollback drills pass with real evidence. What is *not*
proven is the last mile: a Compose stack deployed from this branch, five-role UAT
against it, latency under load, and disaster recovery beyond dump/restore.
Separately, 123 of 155 pages still render through a shared shell, and the largest
of those is the exact anti-pattern this remediation set out to remove. Shipping
to production without those would mean shipping on assertions rather than
evidence, which is precisely what the four-volume plan existed to prevent.

## What is genuinely done

### Volume 1 — decisions, baseline, contracts ✅
All contract surfaces frozen and enforced by gates: page model, action manifest,
permission/resource catalog, OpenAPI 3.1, display contracts. Reviewed and closed.

### Volume 2 — single company, permissions, login ✅
Tenant and legal-entity columns removed (156 → 155 tables, 171 → 0 legacy
columns, MySQL rehearsal evidence under `evidence/single-company/`), permission
closure across role → page → capability → anchor → field profile, password login
as the only required path, MFA optional and off by default without short-circuiting
capability checks. Reviewed, five blockers found and fixed, then approved.

### Volume 3 — employee domains and every business area ✅
17 implementation tasks: atomic employee onboarding, list/detail/sub-profiles,
departments, positions, movements, contracts, attendance, leave, overtime/travel,
payroll, performance, recruitment, training, dashboard/reports/notices, and the
platform workspaces. Signed off as `SIGNOFF: VOLUME_3_APPROVED` (commit `279a494`)
after two adversarial review rounds that produced **six + three** blockers. Every
blocker was a real defect, not a style note:

- payroll amounts were gated once per request, so a P3 grant on run A revealed
  run B's money — then, after the first fix, still revealed money to a P2 grant
  and still leaked from `calculate()`;
- the contract row and its creation version committed in separate transactions,
  so a ledger failure could orphan a contract;
- contract creation was missing the CSRF gate its own matrix claimed;
- four recruitment endpoints had controller methods but no routes;
- the candidate workspace existed only in tests, not on the production routes;
- the candidate-stage payload documented `stage` while the controller required
  `status`;
- archive/restore actions had no reachable control, and archived notices vanished
  from every list so restore was impossible after a reload.

All nine are fixed with regression tests that fail without the fix.

### Volume 4 — platform convergence and release ⚠️
Landed: platform module disposition + role scenario support, account security and
sessions with the deployment's MFA posture served by the API, workflow center
consistency (found and fixed a missing "my decided" view plus stale case-type
codes and a wrongly-advertised decision contract), the self-service portal,
benefits + calendar/settings workspaces, notification + audit workspaces, and the
authoritative 22-gate matrix with a fail-fast `pnpm gate:all`.
Not landed: shell retirement, real-stack UAT, live performance/failure drills.

## Evidence index

| Area | Artefact |
| --- | --- |
| Volume 1 acceptance | `docs/audit/2026-11-26-v2-volume-1-acceptance.md` |
| Volume 2 acceptance | `docs/audit/2026-11-26-v2-volume-2-acceptance.md` |
| Volume 3 acceptance | `docs/audit/2026-11-27-v2-volume-3-acceptance.md` |
| Volume 3 adversarial review | `.superpowers/sdd/artifacts/v3/volume-3-review.md` |
| Volume 3 blocker rechecks | `.superpowers/sdd/artifacts/v3/volume-3-blocker-recheck.md`, `volume-3-final-verification.md` |
| Volume 3 sign-off | `.superpowers/sdd/artifacts/v3/volume-3-signoff.md` |
| 22-gate matrix | `docs/audit/2026-11-26-v2-full-gates.md` |
| Backup / restore / rollback | `evidence/v21-backup/README.md` |
| Performance posture | `docs/audit/2026-11-26-v2-performance.md` |
| Release checklist | `docs/runbooks/2026-11-26-v2-release-checklist.md` |
| Single-company migration | `evidence/single-company/README.md` |
| Volume 3 known gaps | `.superpowers/sdd/artifacts/v3/backlog.md` |

## The four conditions that would flip this to GO

1. **Deploy from this branch and run five-role UAT.** `docker_up.sh` with a
   distinct project name and `DEMO=1`, then `verify_real_stack.sh`, capturing real
   status codes per role. The scripts already exist; only execution is missing.
2. **Execute the 52 collected real-stack Playwright specs** against that stack and
   record pass/fail. Currently collected only — no claim of execution is made.
3. **Retire `PlatformWorkspace.vue` (4583 lines, 32 `mode` branches)** by giving
   its 21 pages dedicated implementations. This is the one place where the
   architecture still contradicts the plan.
4. **Measure disaster recovery properly**: MySQL PITR with binlogs on an isolated
   instance, reporting RPO/RTO. The dump/restore round-trip already passes; that
   is necessary but not sufficient.

## Honest statement of limitation

Two of the twelve Volume 4 tasks (7 and 9) were attempted repeatedly and did not
complete: the shell-retirement conversions failed three times in parallel and the
stack deployment failed once, each exiting without producing work. Rather than
manufacture shallow pages or claim an unexecuted UAT as passing, this acceptance
records those as open blockers. Nothing in this document claims a gate passed
without observed output, and no real-stack execution is claimed anywhere.
