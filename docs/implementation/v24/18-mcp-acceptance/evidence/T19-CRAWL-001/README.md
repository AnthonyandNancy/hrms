# T19-CRAWL-001 — real admin SPA page crawl

- Date: 2026-11-28
- Frontend build: `aeccadc`
- Real stack: `factoryhr-t19`, `http://127.0.0.1:5190`
- Session: synthetic installer (SYSTEM_ADMIN)
- Method: Playwright MCP sequential navigation of 28 current static routes,
  recording URL, title, HTTP status, body summary and console errors.

## Result

- All 28 navigated routes returned **HTTP 200** and rendered their expected
  Chinese page titles.
- `37 个页面` is visible in the real `/system/pages` directory; screenshot and
  accessibility snapshot are included.
- No raw enum/error token was observed in the page summaries.

## PARTIAL / findings

Four self-service time pages emitted console failures because the installer has
no employment binding:

- `/attendance`: one 422;
- `/leave`: one 409;
- `/overtime`: five 409s;
- `/travel`: one 409.

The pages still rendered Chinese unavailable/empty states, so these are recorded
as `PARTIAL_NO_EMPLOYMENT`, not full PASS. This is logged as GAP-21; the
underlying self-scoped request gating needs a fix before T18 can mark those
modules fully passing.

## Not run

- Dynamic detail routes: employee, movement, contract, performance cycle,
  candidate and workflow case.
- Infrastructure pages: login/forbidden/not-found.
- R02–R09 role matrix, 155 historical entries and 458 cards.
- Any destructive workflow beyond the separately recorded role/run scenarios.

This is scripted MCP navigation evidence, not a human 458-card acceptance and
not a T18 completion claim.
