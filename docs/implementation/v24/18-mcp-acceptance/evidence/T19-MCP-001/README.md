# T19-MCP-001 — real-stack login / workspace / dashboard

- Date: 2026-11-28 (Asia/Shanghai)
- Frontend: real `VITE_API_MODE=http` SPA served by nginx at `http://127.0.0.1:5190`
- API: real Laravel `/v1` in isolated `factoryhr-t19` Compose project
- Build: `53f588f`
- Browser: Playwright MCP Chromium

## Executed steps

1. Navigated to `/`; app redirected to `/login?redirect=/workspace`.
2. Logged in as synthetic `installer` account created by `hrms:install`.
3. Observed redirect to `/workspace`; page showed:
   - company `Acme China Synthetic`;
   - current date `2026年11月28日`;
   - expected no-employment state because clean install has zero employments;
   - zero assigned tasks and an empty-state message.
4. Navigated to `/dashboard`; page showed:
   - header `人力仪表盘`;
   - `可用指标 5 / 7`;
   - real zero counts for active/probation/contracts/tasks/leave liability;
   - explicit Chinese unavailable reasons for payroll cost and recruitment funnel;
   - no fabricated or zero-filled metrics for the unavailable indicators.
5. Revisited `/workspace` for a stable second screenshot.

## Evidence

- `workspace.png` / `workspace.yml`
- `dashboard.png` / `dashboard.yml`

The snapshot YAML retains only synthetic UI text. No password, cookie, CSRF
token, TOTP secret or real personal data is stored here.

## Result scope

- `PASS`: M01 login redirect + authenticated workspace baseline.
- `PASS`: Dashboard clean-install/empty-data rendering and unavailable-metric copy.
- Console: one expected pre-login `401 /v1/me` request; no post-login console error
  was observed in the captured dashboard state.
- `NOT RUN`: all other matrix entries, R02–R09 roles, 155 historical entries,
  458 cards and the remaining 20 modules.
- `BLOCKED`: R03/R04/R05/R08 anchor construction and pending 458-card authority,
  as recorded by the MCP runbook.

This is the first real MCP execution, not a T18 completion claim.
