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
- T07 leave effects are complete and merged; T16 notification/report remains incomplete.
- T08–T15, T17, and T19 have not been implemented.
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
| T11a payroll run members | PARTIAL | `docs/implementation/v24/11-payroll-runs/implementation-report.md`; gate `gate-all-t11a.log` | Membership source/preassignment done; orthogonal publish/disburse and HTTP actions remain T11b |
| T18 MCP acceptance assets | IN PROGRESS | `docs/implementation/v24/18-mcp-acceptance/` | Assets only; no MCP execution |

### Full gate result

```text
GATE:ALL PASS (22 gates, 344.3s)
```

This proves the merged technical foundation passes the existing automated gates. It does not prove unmerged work, remote/provider integration, MySQL concurrency, browser behavior, or V2.4 business acceptance.

## 3. Unmerged / blocked branches

These branches are intentionally **not merged** because independent review found Critical issues.


### T16 notification/report — BLOCKED

- Branch: `v24-t16-engagement-reporting`
- Commit: `79de276`
- What works: notification delivery ledger foundation, list/read revalidation, identifier-only payslip intent, report field resolver skeleton, extensive focused tests.
- Blocking findings:
  - Default log transport can fabricate success; `SENT` must require a real provider receipt.
  - Provider send occurs before durable PENDING reservation; concurrent replays can send twice.
  - Engagement FAILED delivery cannot be correctly retried through Platform payload/channel.
  - Export principal/authVersion and queued execution are not proven safe.
  - Report field tier is resolved as a global maximum across rows instead of per-row authority; mixed P1/P3 grants can over-expose fields.
  - Report row scope sometimes uses dashboard capability; unauthorized money fields can be reconstructed or shown as zero.
  - Notice audience snapshot fail-open, expiry on home, and governance response drift remain.
- T16a (delivery truth/retry), T16b (report row/field scoping), T16c (notice/queue/copy) must be completed separately.

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
- **T18 execution:** Playwright MCP human acceptance (assets only, all NOT RUN).
- **T19:** MySQL/Redis/object storage, upgrade, backup restore, migration reconciliation and final GO/NO-GO.

## 5. Requirement acceptance

| AC | Result | Reason |
|---|---|---|
| AC-01 traceability | ❌ NOT ACCEPTED | 155/458/T01–T19 assets exist, but T20–T24 unmapped and execution is NOT RUN |
| AC-02 clean install | ❌ NOT ACCEPTED | Installer tests pass; no real-stack/browser proof |
| AC-03 roles/login | ❌ NOT ACCEPTED | Existing IAM only; no five+ role MCP evidence |
| AC-04 employee/contract/compensation | ❌ PARTIAL | T03/T04/T05 merged; MCP/real-stack evidence still missing |
| AC-05 permission/field isolation | ❌ PARTIAL | T02 catalog/actions merged; T16 report leakage unresolved |
| AC-06 same-run multiple schemes | ❌ NOT MET | T09 not implemented |
| AC-07 payroll sample 9480/1400/8080/9980 | ❌ NOT MET | T09/T10 not implemented |
| AC-08 leave cancel/dynamic recalc | ❌ PARTIAL | T06/T07 merged; T09 Snapshot/MCP remain |
| AC-09 mid-month raise 8500 | ❌ NOT MET | T09/T12 not implemented |
| AC-10 overtime/timeoff/travel | ❌ NOT MET | T08 not implemented |
| AC-11 rule validation | ❌ NOT MET | T10 not implemented |
| AC-12 cumulative corrections | ❌ NOT MET | T13 not implemented |
| AC-13 approval/execution consistency | ❌ PARTIAL | T07 complete; T09 Snapshot/MCP remain |
| AC-14 benefits/performance payroll | ❌ NOT MET | T14/T15/T09 not implemented |
| AC-15 recruitment/training | ❌ NOT MET | T15 not implemented |
| AC-16 reports/exports | ❌ PARTIAL/BLOCKED | T16 field/export security issues unresolved |
| AC-17 notices/notifications | ❌ PARTIAL/BLOCKED | Delivery/retry/audience issues unresolved |
| AC-18 unified UI/Chinese | ❌ PARTIAL | Existing frontend gates pass; T17 not done |
| AC-19 errors/edges | ❌ PARTIAL | Several failed/partial branches still have half-success paths |
| AC-20 history/regression | ❌ NOT PROVEN | No real migration/replay/restore acceptance |
| AC-21 full MCP acceptance | ❌ NOT RUN | 0 MCP items executed |
| AC-22 release GO | ❌ NO-GO | P0/P1 blockers, unmerged tasks, no MCP evidence |

## 6. Resume protocol

1. Keep this branch/HEAD as the stable foundation; T05 and T07 are merged, while the blocked T16 branch must not be merged wholesale.
2. Continue T16 or the Payroll chain on top of merged T05/T07.
3. Execute T11b orthogonal publication/disbursement and member actions; then T10/T12/T13 and T14/T15.
4. Execute T16a (delivery reservation/default-fail-closed/retry), T16b (per-row field scope/no-zero), T16c (notice expiry/queue/copy).
5. Continue T08–T17 in dependency order; T09 must not be skipped.
6. Run `pnpm gate:all` after each merge.
7. Only then execute the merged T18 matrix with Playwright MCP and complete T19 real-stack/migration/restore verification.
8. Re-issue GO only when every AC has evidence; this document remains NO-GO until then.

## 7. Final statement

The merged technical foundation is green and useful, but V2.4 is **not complete**. Any claim of Requirement satisfaction at this HEAD would be false. The correct status is **NO-GO**, with T11b/T16 blocked and T08/T10/T12–T19 outstanding.
