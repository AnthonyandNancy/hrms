# Time / Travel (M12)

Owns the single `/travel` six-tab workspace: mine, requests, policies, approval,
reports and expenses. It consumes the Time travel REST surface only and never
imports `timepay` pages.

## Required main-agent wiring

- `frontend/src/modules/time/workspaces/index.ts`: point `travel/index` to
  `../travel/pages/TravelWorkspacePage.vue`.
- `frontend/src/router/registry.ts` and `pageComponents.ts`: keep only `/travel`
  and remove the six folded `/travel/*` entries.
- Remove the six legacy `frontend/src/modules/timepay/pages/Travel*Page.vue`
  files, their barrel keys and definitions. No redirects.
- Add `POST /v1/travel/requests/{id}/change` and
  `GET /v1/travel/reports/summary` routes; register
  `time.travel.report.read` if that capability is retained.

Backend behavior and integration metadata live under
`docs/implementation/M12/integration.json`.

No component fabricates a travel row, policy limit, attendance link or expense amount; empty,
unconfigured and error states render only what the server returned.
