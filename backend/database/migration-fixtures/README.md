# Migration fixtures

Synthetic only. No real personal data. `DEMO-*` identifiers are deliberately
fake and must never be replaced by production extracts in this repository.

## Sets

| Set | Purpose |
|---|---|
| `known-good/` | Coherent 12-employee tenant used by tests, rehearsal scripts and demo import. |
| `broken/` | One deliberately corrupt case per validator: duplicate employee, orphan org, cycle, overlapping primary assignment, bad date, unknown leave type, negative leave, payroll totals mismatch. |
| `mapping/demo.csv` | Crosswalk of old org codes to new org ids and employee numbers to new employment ids. Target ids are synthetic. |

## Apply order

`organizations -> people -> assignments -> terms -> leave_openings -> payroll_ytd`

Validation is always run for the whole file before any target row is written.
Any ERROR-severity issue blocks apply; WARNING issues are retained in the run
report and do not block.

## Known-good counts

| Domain | Rows | Reconciled totals |
|---|---:|---|
| organizations | 8 | count |
| people | 12 | count |
| assignments | 12 | count |
| terms | 12 | count |
| leave_openings | 12 | quantity_minutes = 57,600 |
| payroll_ytd | 12 | gross/net/taxable/withheld/SI/HF cents |
