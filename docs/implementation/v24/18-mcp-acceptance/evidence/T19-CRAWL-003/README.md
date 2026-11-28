# T19-CRAWL-003 — dynamic detail pages

- Date: 2026-11-28
- Frontend image: `factoryhr/frontend:v24-t19e`
- Backend image: `factoryhr/php-app:v24-t19e`
- Real stack: `factoryhr-t19`, `http://127.0.0.1:5190`
- Session: synthetic installer (SYSTEM_ADMIN)
- Fixtures: synthetic demo employments DEMO-0001..0004; contract #1;
  draft transfer case #2; performance cycle #1; candidate #1.

## Results

| Page | URL | Result |
|---|---|---|
| Employee detail | `/hr/employees/1` | HTTP 200; all 8 tabs selected without non-2xx or console errors |
| Contract detail | `/hr/contracts/1?employment_id=1` | HTTP 200; T19-CONTRACT-001 / DEMO-0001 shown |
| Movement detail | `/hr/movements/2` | HTTP 200; ASSIGNMENT_CHANGE case #2 shown |
| Performance cycle | `/performance/cycles/1` | HTTP 200; T19 Performance Cycle shown |
| Candidate detail | `/recruitment/candidates/1` | HTTP 200; T19 Synthetic Candidate shown |
| Workflow case | `/workflow/cases/2` | HTTP 200; assignment-change context shown |

All six pages had zero non-2xx `/v1` responses and zero console errors.

The contract detail is intentionally employment-scoped. A bare
`/hr/contracts/1` shows the documented empty state; the ledger deep link carries
`employment_id=1`, and that URL was the one verified.

## Evidence

- `employee-detail.png` / `.yml`
- `contract.png`, `movement.png`, `performance-cycle.png`, `candidate.png`,
  `workflow-case.png`

## Scope

This covers page navigation and detail rendering with synthetic data. It does
not claim every create/edit/approve/reject interaction, the R01–R09 role matrix,
155 historical entries or 458 cards.
