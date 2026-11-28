# T19-CRAWL-004 — 155 historical entry URL dispositions

- Date: 2026-11-28
- Real stack: `factoryhr-t19`
- Browser: standalone Playwright Chromium, real admin session (`installer`)
- Source list: `docs/v2.3-remediation/2026-11-27-155-entry-disposition-matrix.md`
- Rows: 155 unique, no gaps/duplicates.

## Result

```text
rows:                 155
KEEP:                  35
FOLD:                 120
disposition matches:  155 / 155
HTTP document status: 200 / 155
console errors:         0
```

Rules applied:

- KEEP expected the current target path (dynamic `:id` replaced with a
  synthetic id; `/login` accepted `/workspace` because the crawl session was
  already authenticated).
- FOLD accepted the documented catch-all `/not-found` or the current target
  page.
- Every old URL resolved without a crash or console error.

## Scope boundary

This closes the **URL-disposition** portion of the 155-entry ledger only. It does
not claim that the 458 functional cards, per-entry permissions, write actions or
visual business outcomes have been accepted. Those remain `NOT RUN` and are
tracked separately.

## Artifacts

- `legacy-entry-routes.json`
- `legacy-entry-crawl-results.json`
- `legacy-entry-crawl-results.md` (row-by-row actual path)
- `legacy-entry-crawl.log`
