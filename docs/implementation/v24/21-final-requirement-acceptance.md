# FactoryHR V2.4 — Final Requirement Acceptance (NO-GO)

- **Date:** 2026-11-28
- **Branch:** `v2.4-implementation`
- **Current HEAD:** `b888693` (plus this acceptance record)
- **Requirement:** `docs/FactoryHR_V2.4_需求与技术设计说明书.md`
- **Plan:** `docs/superpowers/plans/2026-11-28-v2-4-implementation-master-plan.md`
- **Full gate:** `docs/implementation/v24/06-time-facts/gate-all-final.log`
- **Decision:** **NO-GO**

## 1. Conclusion

The current branch contains a stable, reviewed foundation (T00–T04, hardened T06) and complete MCP acceptance assets (T18). The authoritative 22-gate technical matrix passes.

It is **not** a completed V2.4 business delivery:

- T05a/T05b onboarding, contract draft and compensation draft are merged.
- T07 leave effects are complete and merged; T16 notification/report is merged with full-gate evidence.
- T20–T24 remain unmapped; 458-card authority is still pending; R03/R04/R05/R08 anchor construction is blocked.
- No Playwright MCP human acceptance has been executed; all MCP items remain `NOT RUN`.
- Real MySQL/Redis/object-storage migration, concurrency and restore verification is not complete.
- Therefore AC-01/AC-21/AC-22 can not pass, and no Requirement-wide completion claim is allowed.

## 2. Merged and verified work

| Task | State | Evidence | Limitation |
|---|---|---|---|
| T00 baseline + ADR | COMPLETE | `docs/implementation/v24/00-baseline.md`; gate log | Baseline only |
| T01 install/demo/readiness | COMPLETE | `docs/implementation/v24/01-install/evidence.md` | Unit gate only; no browser install flow |
| T02 generated action catalog and object actions | COMPLETE | `docs/implementation/v24/02-action-catalog/evidence.md` | Contract/Time detail wiring is in later tasks |
| T03 contract agreed versions | COMPLETE | `docs/implementation/v24/03-contracts/evidence.md` | MySQL trigger/UI waits for T19/T17 |
| T04 execution compensation link | COMPLETE | `docs/implementation/v24/04-compensation-chain/evidence.md` | Snapshot still reads terms until T09 |
| T06 monthly Time fact versions | COMPLETE / Approved-with-risk | `docs/implementation/v24/06-time-facts/implementation-report.md`; final gate | MySQL true concurrency deferred to T19; complete workday coverage deferred to T09 |
| T05 onboarding/contract/compensation | COMPLETE | `docs/implementation/v24/05-onboarding/implementation-report.md`; gates `gate-all.log`, `gate-all-t05b.log` | T05b included; payroll activation/approval is T09/T11 |
| T07 leave workflow effects | COMPLETE | `docs/implementation/v24/07-leave-effects/implementation-report.md`; gates `gate-all-t07b.log`, `gate-all-t07c.log` | Snapshot consumption remains T09 |
| T09 payroll snapshot v2 | COMPLETE | `docs/implementation/v24/09-payroll-snapshot/implementation-report.md`; gates `gate-all-t09ab.log`, `gate-all-t09c.log`, `gate-all-t09d2.log` | Benefit/performance adapters are empty/default until T14/T15 replace and rerun |
| T12 mid-month compensation | COMPLETE | `docs/implementation/v24/12-midmonth/implementation-report.md`; gates `gate-all-t12a.log`, `gate-all-t12b.log` | MCP end-to-end evidence remains T18 |
| T13 signed YTD/corrections | COMPLETE | `docs/implementation/v24/13-corrections/implementation-report.md`; gates `gate-all-t13ab.log`, `gate-all-t13c.log`, `gate-all-t13d.log` | Independent void/retry and action UI complete; MCP remains T18 |
| T14 benefits→Payroll | COMPLETE | `docs/implementation/v24/14-benefits/implementation-report.md`; gates `gate-all-t14.log`, `gate-all-t14-ui.log`, `gate-all-t14d.log` | Multi-plan/reversal/employer cost/snapshot/UI/P3 masking complete; T16 report leakage separate |
| T15 performance/Offer/training | COMPLETE | `docs/implementation/v24/15-performance-bonus/implementation-report.md`; gates `gate-all-t15a.log`–`gate-all-t15e.log` | MCP evidence remains T18 |
| T10 payroll rule engine | COMPLETE | `docs/implementation/v24/10-payroll-rules/implementation-report.md`; gates `gate-all-t10a.log`–`gate-all-t10f.log` | JSON-only editor and legacy fail-closed are documented residual risks; MCP T18 |
| T17 frontend precision/actions/self-service | COMPLETE | `docs/implementation/v24/17-frontend/implementation-report.md`; gates `gate-all-t17a.log`–`gate-all-t17d.log` | Money, self payslips, server actions and Chinese sweep complete; MCP remains T18 |
| T08 overtime/timeoff/travel | COMPLETE | `docs/implementation/v24/08-overtime-travel/implementation-report.md`; gates `gate-all-t08a.log`, `gate-all-t08b.log`, `gate-all-t08c.log` | Ledger, travel facts, Workflow approval, policy constraints and approval UI complete; MCP T18 |
| T11 payroll runs/membership | COMPLETE | `docs/implementation/v24/11-payroll-runs/implementation-report.md`; gates `gate-all-t11a.log`, `gate-all-t11b1.log`, `gate-all-t11b2.log` | Member form visual polish remains T17 |
| T18 MCP acceptance | IN PROGRESS | `docs/implementation/v24/18-mcp-acceptance/evidence/T19-MCP-001/`–`T19-MCP-004/` | Login/dashboard, role anchor UI, PAYROLL_RUN allow/deny and user role-assignment UI executed; remaining matrix NOT RUN |
| T19 real stack/recovery | IN PROGRESS | `docs/implementation/v24/19-real-stack/implementation-report.md`; `concurrency/`; `18-mcp-acceptance/evidence/T19-MCP-003/`, `T19-MCP-004/` | MySQL/Redis/MinIO, clean install, migrations, backup/restore, synthetic upgrade, concurrent idempotency, scoped access, role-assignment UI and overlap repair pass; final image/real previous-release upgrade/final GO pending |

### Full gate result

```text
GATE:ALL PASS (22 gates, 344.3s)
```

This proves the merged technical foundation passes the existing automated gates. It does not prove unmerged work, remote/provider integration, MySQL concurrency, browser behavior, or V2.4 business acceptance.

## 3. Merged branch evidence

### T16 notification/report — MERGED / COMPLETE (code)

- Branch: `v24-t16-engagement-reporting`
- Merge commit: `d8a7f9a`
- Full gate: `GATE:ALL PASS (22 gates, 290.2s)`, log
  `docs/implementation/v24/16-notifications-reporting/gate-all-t16-merge.log`.
- Critical findings addressed by merged implementation/tests:
  - provider receipt required before `SENT`; missing receipt is `FAILED` and
    retryable on the same row;
  - deterministic event keys prevent duplicate notification/send on replay;
  - notice retraction/expiry/audience changes invalidate old links and reads;
  - payslip notifications carry identifiers only and recheck publication/self scope;
  - report columns, rows, totals, charts and exports use per-row field authority
    and omit unauthorized money rather than zero-filling;
  - export creation returns an honest `422 EXPORT_FIELD_FORBIDDEN`.
- Residual risks: the reference transport is not a real vendor integration;
  SQLite cannot prove true concurrent delivery locking; browser/MCP proof remains
  T18 and real-stack verification remains T19.

## 4. Not implemented

- **T05b:** contract and execution-compensation drafts created by approved onboarding.
- **T08:** overtime PAY/TIMEOFF ledger and travel time-fact closure.
- **T09:** Snapshot v2 consuming contract + execution compensation + closed Time facts + employee-level schemes; no-zero/blocker integration.
- **T10:** restricted payroll AST rule engine and published rule versions.
- **T11:** payroll run members, blockers, independent publication/disbursement.
- **T12:** mid-month compensation segmentation and period-level statutory calculation.
- **T13:** cumulative corrections and signed YTD ledger.
- **T14:** benefit payroll inputs, multi-plan and reversal.
- **T15:** performance bonus mapping, Offer→compensation, training exam attempts.
- **T17:** exact money display, complete object actions, self-service payslip and all detail/list state/Chinese copy.
- **T18 execution:** first MCP pack executed; remaining 20 modules, 155 entries, 458 cards and role matrix NOT RUN/BLOCKED.
- **T19:** app-level concurrency, real previous-release upgrade, deployment traffic restore and final GO/NO-GO remain.

## 5. Requirement acceptance

| AC | Result | Reason |
|---|---|---|
| AC-01 traceability | ❌ NOT ACCEPTED | 155/458/T01–T19 assets exist, but T20–T24 unmapped and execution is NOT RUN |
| AC-02 clean install | ❌ NOT ACCEPTED | Installer tests pass; no real-stack/browser proof |
| AC-03 roles/login | ❌ NOT ACCEPTED | Existing IAM only; no five+ role MCP evidence |
| AC-04 employee/contract/compensation | ❌ PARTIAL | T03/T04/T05 merged; MCP/real-stack evidence still missing |
| AC-05 permission/field isolation | ✅ MET (code/UI) | T02 action catalog + T14 benefit masking + T16 per-row report/export field projection pass; MCP pending |
| AC-06 same-run multiple schemes | ❌ NOT MET | T09 not implemented |
| AC-07 payroll sample 9480/1400/8080/9980 | ✅ MET (code) | `PayrollAcceptanceSampleTest` exact gross/deduction/net/employer cost + stored run/payslip evidence |
| AC-08 leave cancel/dynamic recalc | ❌ PARTIAL | T06/T07 merged; T09 Snapshot/MCP remain |
| AC-09 mid-month raise 8500 | ✅ MET (code) | T12 golden 10×8000 + 10×9000 = 8500 regression passes |
| AC-10 overtime/timeoff/travel | ✅ MET (code/UI) | Overtime case/approve + policy method constraint, append-only TIMEOFF ledger, travel conflicts/facts/payroll and travel change regressions pass; MCP pending |
| AC-11 rule validation | ✅ MET (code/UI) | Restricted AST compiler/engine, published hash snapshot, dependency graph and editor regressions pass |
| AC-12 cumulative corrections | ✅ MET (code/UI) | Exact 8080→8300→8350 cumulative regression, signed YTD, unpaid void/retry and action UI pass |
| AC-13 approval/execution consistency | ❌ PARTIAL | T07 complete; T09 Snapshot/MCP remain |
| AC-14 benefits/performance payroll | ✅ MET (code/UI) | Multi-plan/reversal/employer cost, P3 field masking and bonus mapping regressions pass; T16 report security separate |
| AC-15 recruitment/training | ✅ MET (code/UI) | Offer→compensation, bonus mapping UI, training attempt/source UI regressions pass; MCP pending |
| AC-16 reports/exports | ✅ MET (code/UI) | T16 report/export field crop, no-zero leakage and export reauthorization regressions pass; MCP pending |
| AC-17 notices/notifications | ✅ MET (code/UI) | T16 receipt-based delivery/retry, revocation/expiry/audience checks and payslip ID-only notifications pass; real provider/MCP pending |
| AC-18 unified UI/Chinese | ✅ MET (code/UI) | BigInt money, self payslips, allowed_actions-only actions and Chinese enum/copy audit pass; MCP pending |
| AC-19 errors/edges | ❌ PARTIAL | Several failed/partial branches still have half-success paths |
| AC-20 history/regression | ❌ PARTIAL | Real install/backup/restore, synthetic upgrade, concurrent idempotency and scoped authorization pass; previous-release upgrade and recovery under deployment traffic remain |
| AC-21 full MCP acceptance | ❌ PARTIAL | T19-MCP-001/002/003 executed login/dashboard, role anchor UI and PAYROLL_RUN allow/deny; remaining 20 modules/155 entries/458 cards and role-assignment UI NOT RUN |
| AC-22 release GO | ❌ NO-GO | P0/P1 blockers, unmerged tasks, no MCP evidence |

## 6. Resume protocol

1. Keep this branch/HEAD as the stable foundation; T05/T07/T16 are merged with full-gate evidence.
2. Continue the T18 MCP matrix and resolve role-anchor/card-authority blockers.
3. Run `pnpm gate:all` after each merge.
4. Continue T18 MCP execution and T19 app-level concurrency/real-upgrade verification.
5. Re-issue GO only when every AC has evidence; this document remains NO-GO until then.

## 7. Final statement

The merged technical foundation is green and useful, but V2.4 is **not complete**. Any claim of Requirement satisfaction at this HEAD would be false. The correct status is **NO-GO**, with most MCP matrix entries NOT RUN and T19 concurrency/real-upgrade/final GO still pending.
