# ADR-0003: Four timelines, half-open validity and legal-entity business dates

> **Superseded in part by ADR-0034 (2026-11-25):** four-timeline semantics and UTC storage remain; the business-date timezone is now `company_profile.timezone`, not a legal-entity timezone.

- **Status:** Accepted — 2026-11-24
- **Decision IDs:** D-03 (valid time, transaction time, business date, payroll period), D-17 (business calendar, statutory annual leave floor)
- **Supersedes:** the working "lifecycle effect" ADR that previously occupied number 0003; its content now lives in ADR-0005
- **Review trigger:** a legal entity needs a non-Gregorian calendar, a jurisdiction requires a business date that is not derived from the employer's IANA timezone, or an as-of query must combine valid time and transaction time in a way the two helpers cannot express

## Context and trigger

Every HR fact has more than one "when". A salary change has a validity period (when it applies), a recording instant (when the system learned it), a business date (when the employer considers it effective) and a payroll period (which run settles it). Mixing them produces the classic failure modes: a back-dated raise silently rewrites a finalized payslip, an attendance punch at 01:00 maps to the wrong day, a DST shift is paid twice, and a query "as of last month" returns today's data.

The approved solution fixes the semantics in D-03 and the statutory annual-leave floor and calendar rules in D-17. Because every module already depends on these predicates, changing them later would touch all read models — hence the W4 freeze.

## Decision

1. **Four timelines, named.** Validity (`valid_from`/`valid_to`), transaction time (`recorded_at`/`superseded_at`), business date (`business_date` columns, computed) and payroll period (`period` on payroll runs). They are never collapsed into one timestamp.
2. **Half-open intervals only.** A range is `[valid_from, valid_to)`; the open end is the literal `9999-12-31` (`BusinessDate::OPEN_END`). A row valid from 2026-01-01 to 2026-02-01 is *not* valid on 2026-02-01. Enforced in one place: `App\Shared\Time\AsOfQuery::whereValidOn()`, and by database checks/triggers for MySQL (`valid_from < valid_to`) and SQLite triggers in `create_people_tables` / `create_organization_tables`.
3. **Two as-of predicates, one helper each.** `AsOfQuery::whereValidOn()` filters validity; `AsOfQuery::whereKnownAt()` filters transaction time with the same half-open rule. Business modules must not hand-roll `whereDate()` comparisons.
4. **Timestamps are UTC; business dates are local.** All timestamps are stored in UTC. A business date is derived from the legal entity's IANA timezone and calendar through `TenantBusinessDate` (which reads the timezone via the Organization contract) and `BusinessDate::today()`. The process timezone is UTC (`APP_TIMEZONE=UTC`) and must never be used to infer a business date.
5. **Night shifts and DST are computed from real elapsed time.** `ShiftWindow`/`ShiftService` resolve wall-clock boundaries through the legal entity timezone, so a spring-forward night shift counts the real elapsed minutes once and a fall-back shift counts the repeated hour once. A morning punch after midnight maps to the shift's start business date.
6. **Statutory annual leave floor.** `StatutoryAnnualLeaveFloor` implements D-17: cumulative service ≥1 year → 5 days, ≥10 → 10, ≥20 → 15. A statutory annual leave type cannot be configured below the applicable floor; company leave may be. The floor is computed from `seniority_start_date` on the employment, not from hire date at one employer.
7. **Money and time do not share rounding.** Business dates are calendar dates; durations inside Time are integer minutes. Rates and money follow ADR-0014.

## Alternatives considered

| Alternative | Why rejected |
| --- | --- |
| Store only "current" values plus an audit log | The audit log is not queryable as a read model; every as-of view would need replay, and payroll reconciliation would be unreliable. |
| Closed intervals `[from, to]` | Adjacent periods either overlap on the boundary day or leave a gap; every consumer must remember an off-by-one rule. Half-open is the only composable choice. |
| Use the server timezone for business dates | A UTC server turns 00:30 Shanghai into the previous day; attendance, leave and payroll cut-offs shift silently. |
| Store business date as a UTC midnight timestamp | Invites accidental timezone conversion and makes "same business date" comparisons fragile. A `date` column is unambiguous. |
| Compute night-shift minutes as wall-clock difference | Overcounts or undercounts on DST transitions; real elapsed time is what payroll and working-time rules require. |
| Let each module implement its own as-of filter | Guarantees the list/detail/tree/dashboard divergence the audit is designed to catch. |

## Consequences

Positive:

- one predicate set governs every effective-dated read;
- future-dated changes are visible only from their effective date;
- historical and transaction-time queries are possible without replaying events;
- attendance and leave are correct across midnight and DST.

Negative / costs:

- every current-state query must remember the as-of predicate; a missing predicate returns overlapping rows;
- `9999-12-31` must be treated as open-ended, not as a real far-future date;
- two clocks exist (valid time and transaction time) and tests must pin both;
- DST rules need IANA timezone data kept current in the runtime image.

## Validation

- `tests/Unit/Time/AsOfQueryTest.php` — half-open validity and transaction-time predicates.
- `tests/Unit/Time/DateRangeTest.php` — start contained, end excluded, open-ended range, overlap boundaries.
- `tests/Unit/Time/BusinessDateTest.php` — real calendar dates, impossible dates rejected, `OPEN_END`.
- `tests/Unit/Time/BusinessCalendarTest.php` — workweek/holiday/makeup overrides, unknown timezone rejected, local date not UTC date.
- `tests/Unit/Time/ShiftWindowTest.php` and `tests/Feature/Time/OvernightShiftTest.php` — cross-midnight, spring-forward and fall-back counted once, Shanghai has no DST shift.
- `tests/Unit/Time/StatutoryFloorTest.php` — 5/10/15-day floors, one day short of ten years, below-floor rejection, company leave exempt.
- `tests/Integration/AsOfConsistencyTest.php` — as-of agreement across list, detail, org tree and directory.
- `php artisan test`, `vendor/bin/phpstan analyse`.

## Security and privacy

Business dates participate in authorization: capabilities are evaluated on a business date, so a caller cannot widen access by supplying a date outside the binding's validity. `TenantBusinessDate::resolve()` validates the date format strictly and rejects impossible calendar dates with a 422 envelope rather than coercing them. Timezone is a tenant/legal-entity attribute, never client-supplied.

## Review triggers and rollback

Revisit if a jurisdiction needs a non-Gregorian business calendar, if a payroll period must span legal entities with different timezones, or if a read model needs a combined "valid at T and known at K" predicate that the two helpers cannot compose. Rollback would require rewriting stored dates, so the ADR is treated as irreversible after real data exists; before that, the change is confined to `AsOfQuery`, `BusinessDate`, `TenantBusinessDate` and `ShiftWindow`.
