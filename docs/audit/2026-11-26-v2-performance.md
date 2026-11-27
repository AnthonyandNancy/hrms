# V2.1 performance, concurrency and failure-recovery evidence

Date: 2026-11-27
Branch: `feat/v2.1-remediation`, commit `20ce996` (gate matrix), drills at `cb8b630`

## Verdict

**PARTIAL.** The reproducible local baselines below were measured on this branch.
The HTTP load probe, concurrency-under-load and failure-recovery drills were
**NOT EXECUTED against this branch's build**, because no Compose stack was
deployed from this worktree. Every unexecuted item is listed with the exact
command needed to run it, so this is a scheduling gap, not an unknown.

## 1. Measured on this branch (real output)

From `pnpm gate:all` (22 gates, 230.8 s total) at commit `9e9de01`:

| Gate | Runtime | What it exercises |
| --- | --- | --- |
| backend:phpunit | 109.7 s | 1278 tests / 58,488 assertions across SQLite |
| backend:phpstan | 9.1 s | static analysis, 1196 files |
| backend:pint | 2.0 s | code style, 1196 files |
| frontend:unit | 43.1 s | 71 files / 599 tests |
| frontend:build | 26.8 s | production bundle build |
| frontend:typecheck | 15.9 s | full TS project typecheck |
| product audits (7) | 1.8 s combined | page model, primitives, copy, components, coverage, modules, single-company |
| contract gates (5) | 8.3 s combined | OpenAPI lint/validate/generate/actions/manifest |

These are reproducible: `pnpm gate:all` prints per-gate timings on every run.

## 2. Data-volume baseline (real, MySQL)

`deploy/scripts/mysql_backup_restore.sh` against MySQL 8.4:

```
dump_sha256=bb8f705599a0ebbaecad419c8a888906266d9908a1c91aba02d8f4dec8f0c116 bytes=453750
tables_checked=156 mismatches=0
```

A 443 KB dump across 156 tables round-tripped with zero mismatches. This is a
correctness baseline for data volume, not a latency measurement.

## 3. Previously recorded HTTP baseline (V1.1 image, NOT this branch)

The most recent real HTTP probe on this machine is
`evidence/load-probe-2026-11-25T07-01-35-079Z.json`:

```
requests=60 concurrency=6 elapsed_ms=834 throughput_rps=71.94
latency_ms: p50=53.97 p95=251.45 p99=277.84
status_counts: {200: 60}  failures=0
```

**This must not be presented as V2.1 performance.** It was taken against the V1.1
Compose image from a different worktree. It is included only as the last known
reference point.

## 4. NOT EXECUTED (with the exact command to run)

### 4.1 HTTP load probe on this branch's build

```bash
node deploy/scripts/load_probe.mjs \
  --base-url http://127.0.0.1:<port> \
  --requests 240 --concurrency 24 \
  --cookie "hrms_session=<session-from-login>"
```

Blocked because: no stack from this worktree was deployed (Volume 4 Task 9 did
not complete). Needs a seeded stack plus a real session cookie.

### 4.2 Concurrency correctness under load

Target scenarios, each needing the live stack:

- two concurrent `POST /v1/payroll/runs/{id}/calculate` → exactly one succeeds, the
  other gets 409, no double-counted payslips;
- two concurrent `POST /v1/employees/onboard` with the same `Idempotency-Key` →
  one row created, the second replays;
- two concurrent `POST /v1/recruitment/candidates/{id}/convert` → one employee
  created, the second replays or 409s on the duplicate employee_no.

All three are already covered *sequentially* by the backend suite
(`PayrollWorkspaceTest`, `CreateEmployeeCommandTest`, `RecruitmentWorkspaceTest`);
what is missing is the **parallel** proof.

### 4.3 Failure recovery

- kill the PHP worker mid-request and confirm the request fails cleanly (no
  partial payroll run, no orphaned ledger row);
- stop Redis and confirm the API degrades to 503 with a Chinese message rather
  than 500;
- stop MySQL and confirm readiness reports 503 and the UI shows the maintenance
  state.

Each is a `docker compose stop <service>` + probe sequence, requiring the stack.

## 5. What this means for the release decision

The functional gate matrix is green and reproducible. Performance and
failure-recovery under a live stack are **unproven on this branch**. That is
enough to withhold a "production-ready" claim while still allowing a
"functionally complete, pending load/DR validation" verdict. See
`docs/audit/2026-11-26-v2-final-acceptance.md`.
