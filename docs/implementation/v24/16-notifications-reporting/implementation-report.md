# T16 — Notification delivery truth and report field projection

- **Status:** COMPLETE
- **Date:** 2026-11-28
- **Baseline:** `a5c90a199cb2b4d067495d4560dc517ec6684295`
- **Branch:** `v24-t16-engagement-reporting`
- **Plan:** `docs/superpowers/plans/2026-11-28-v2-4-implementation-master-plan.md`, T16
- **Design:** `docs/FactoryHR_V2.4_需求与技术设计说明书.md` 5.1.7, 5.4, 5.5, 5.6; AC-16/17/18

## Scope

T16 makes two previously cosmetic boundaries real:

1. An in-app notification delivery is `SENT` only when the external channel
   returns a successful provider receipt. A failed attempt remains `FAILED` and
   retryable on the same delivery row. Replaying the same business event never
   creates a second notification or provider send.
2. Notification list/read re-authorize the caller against the referenced notice
   or published payslip on every request. Retracted, expired, out-of-scope and
   audience-changed links keep their inbox identity but lose their payload and
   cannot be marked read.
3. Reporting resolves the caller's live field tier. `columns`, row attributes,
   chart data, totals and export fields are filtered. Unauthorized money fields
   are absent, never null/zero-derived.

## Changed files

### Schema (forward-only, anonymous classes)

- `backend/database/migrations/2026_11_28_000700_extend_notification_delivery_and_notice_expiry.php`
  - extends the existing Platform-owned `notification_deliveries` ledger with
    `notification_id`, `provider_message_id`, index and unique
    `(notification_id, recipient_user_id)`; widens the existing channel column.
  - adds nullable indexed `notices.expires_at`.
  - **Deliberate deviation:** the plan named
    `2026_11_28_001400_create_notification_deliveries.php`; the user explicitly
    overrode that to `2026_11_28_000700_*` and the table already existed. Creating
    a second delivery table would fork the single-writer ledger.
- `backend/database/migrations/2026_11_28_000710_add_notification_event_key.php`
  - adds nullable `notifications.event_key` plus unique `(event_key, user_id)`.

### Platform delivery ownership

- `backend/app/Modules/Platform/Contracts/NotificationDeliveryLedger.php`
  - the only cross-module write/read seam for delivery evidence.
- `backend/app/Modules/Platform/Services/EloquentNotificationDeliveryLedger.php`
  - sole writer of `notification_deliveries`.
  - creates a `PENDING` row, then writes `SENT` only for a successful receipt
    and `FAILED` with `sent_at = null` otherwise; retries update the same row and
    increment attempts.
- `backend/app/Modules/Platform/Contracts/NotificationTransport.php`
  - receipt-returning transport for the existing template/outbox dispatcher.
- `backend/app/Modules/Platform/Services/LogNotificationTransport.php`
  - reference transport returning a synthetic provider acknowledgement.
- `backend/app/Modules/Platform/Http/Controllers/NotificationAdminController.php`
  - dispatch and retry now call the transport and persist its receipt. Neither
    path writes `SENT` without a successful receipt or emits a synthetic
    `platform.notification.delivery.sent` event on failure.
- `backend/app/Shared/Notifications/`
  - `NotificationReceipt`, `NotificationDeliveryStatus`,
    `NotificationDeliveryRecord`; receipt/reason/status data with no business
    coupling.

### Engagement notifications and notices

- `backend/app/Modules/Engagement/Contracts/ExternalNotificationChannel.php`
  - now `channel()` + `send(type, userId, payload): NotificationReceipt`.
- `backend/app/Modules/Engagement/Services/LogExternalNotificationChannel.php`
  - returns a successful reference receipt and logs identifiers/type only.
- `backend/app/Modules/Engagement/Services/NotificationService.php`
  - assigns deterministic `event_key` values, makes fan-out idempotent, records
    outbox and ledger evidence, and contains provider exceptions as `FAILED`.
  - revalidates notice/payslip targets for every list/read.
  - strips payload and marks `valid=false` with Chinese `invalidReason` for
    stale rows; read attempts return 404.
- `backend/app/Modules/Engagement/Services/NoticeAccessPolicy.php`
  - checks retraction, `expires_at`, frozen audience snapshot membership and the
    current live target. An audience move invalidates old links.
- `backend/app/Modules/Engagement/Services/NoticeService.php`,
  `NoticeGovernanceService.php`, `Http/Controllers/NoticeController.php`
  - accept/persist/emit `expiresAt`; publication and reminder events use
    deterministic keys.
- `backend/app/Modules/Engagement/Models/Notice.php`,
  `Notification.php`, `Http/Controllers/NotificationController.php`
  - expiry cast, event key, principal-bound list/read.

### Payslip publication

- `backend/app/Modules/Payroll/Contracts/PublishedPayslipRef.php`,
  `PayslipNotificationDirectory.php`
  - identifier-only read contract for published payslips.
- `backend/app/Modules/Payroll/Services/EloquentPayslipNotificationDirectory.php`
  - selects only id/employment/run; never selects money columns.
- `backend/app/Modules/Payroll/Contracts/PayslipPublicationNotifier.php`,
  `Services/NullPayslipPublicationNotifier.php`
  - outbound Payroll seam with a safe default.
- `backend/app/Modules/Payroll/Services/PayrollPublicationEventBridge.php`
- `backend/app/Modules/Payroll/Providers/PayrollServiceProvider.php`
  - observes the committed `PayrollRun` transition to `PUBLISHED` and invokes
    the notifier via `DB::afterCommit`, containing transport failures. Payroll
    core services (`ApprovalService`, calculation, state machine) were **not**
    modified in the final implementation.
- `backend/app/Modules/Engagement/Adapters/PayslipPublicationNotifierAdapter.php`,
  `Services/PayslipNotificationService.php`
  - fan out `PAYSLIP_PUBLISHED` notifications with only
    `{payslip_id, period}`; resolve each employment's own linked user.
- `EngagementServiceProvider`, `PayrollServiceProvider`
  - wire the ledger, transport, directory, adapter and notifier.

### Reporting field projection

- `backend/app/Modules/Reporting/Services/FieldTierResolver.php`
  - live maximum field profile for report read, dashboard read and export.
- `ReportService.php`
  - filters catalog fields by tier before building columns/rows; export
    creation rejects an unauthorized field with `422 EXPORT_FIELD_FORBIDDEN`;
    totals/charts use only surviving fields.
- `MetricQueryBuilder.php`
  - without P2/P3, `payroll_cost_by_entity` is `FORBIDDEN` with empty
    columns/items and total 0; money attributes are removed from projection rows
    when not visible.
- `MetricDefinition.php`, `ReportDefinitionCatalog.php`
  - Chinese forbidden reason and explicit non-money payroll fields
    (`employee`, `currency_code`).
- `ExportService.php`
  - creation-time field denial is an honest `422`; download re-authorization
    retains established `403` semantics.
- `ReportingServiceProvider.php`
  - wiring for the resolver.

### Contract and frontend consumption

- `docs/openapi/openapi.yaml`
  - `Notification.valid`, `invalidReason`, optional `delivery` receipt; required
    `NotificationDelivery.provider_message_id`; `Notice.expiresAt`;
    dashboard `FORBIDDEN` status.
- `frontend/src/generated/api.ts` regenerated.
- `frontend/src/modules/platform/api/notifications.ts`,
  `api/index.ts`, `labels.ts`, `pages/NotificationsPage.vue`
  - normalise live validity, hide payload/links/mark-read for invalid rows,
    exclude invalid rows from unread/bulk-read, show Chinese backend reason and
    `PAYSLIP_PUBLISHED`.
- `frontend/src/modules/reporting/components/noticeApi.ts`,
  `NoticeDetailDrawer.vue`
  - carry/display notice expiry.
- Frontend tests: invalid notification state, projected report columns (no
  fabricated money), forbidden dashboard money metric.

## Verification

All commands were run in this worktree. Per instruction, `pnpm gate:all` was
**not** run.

```text
backend: phpunit --testsuite Feature       OK (1111 tests, 10816 assertions)
backend: phpunit --testsuite Unit          OK (318 tests, 35011 assertions)
backend: phpunit --testsuite Contract      OK (65 tests, 13400 assertions)
backend: phpunit --testsuite Integration   OK (24 tests, 109 assertions)
backend: phpunit --testsuite Architecture  OK (31 tests, 77 assertions)
backend: focused T16 + adjacent            OK (67 tests, 848 assertions)
backend: focused T16 combined               OK (19 tests, 144 assertions)
backend: vendor/bin/pint --test            PASS (1378 files)
backend: vendor/bin/phpstan analyse        [OK] No errors
backend: php artisan arch:test             Module boundaries OK
backend: php artisan capability:audit      Capability audit OK
openapi: node deploy/scripts/openapi-lint.mjs
         OK (293 paths, 376 operations, 3.1.0)
openapi: pnpm openapi:validate
         valid (2 pre-existing health-operation warnings)
openapi: pnpm openapi:generate / openapi:check
         generated types current
frontend: pnpm typecheck                  PASS
frontend: pnpm lint                       PASS
frontend: pnpm test:unit                  OK (112 files, 655 tests)
git diff --check                          PASS
```

Focused T16 tests:

```text
NotificationDeliveryAndRevocationTest   OK (6 tests, 59 assertions)
ReportFieldProjectionTest               OK (4 tests, 32 assertions)
PayslipPublicationNotificationTest      OK (3 tests, 5 assertions)
NotificationDeliveryTest (Platform)     OK (6 tests, 48 assertions)
```

Coverage maps to the required scenarios:

| Required scenario | Test |
|---|---|
| No provider receipt means not `SENT` | `test_without_a_provider_receipt_a_delivery_is_not_sent_and_can_be_retried_once`; Platform `test_dispatch_without_a_transport_receipt_is_failed_and_retry_requires_a_new_receipt` |
| Retry does not duplicate notification/send | same Platform test + `test_repeating_the_same_delivery_event_does_not_create_or_send_a_second_notification` |
| Retracted old link invalid | `test_retracted_notice_notification_is_invalid_and_can_no_longer_be_opened_or_marked_read` |
| Expired / audience-changed old links invalid | `test_expired_notice_notification_is_invalid_and_old_notice_link_returns_not_found`; `test_audience_change_makes_the_old_notification_link_invalid` |
| Payslip notification has no money | `test_payslip_notification_contains_only_ids_and_is_rechecked_against_publication_and_self_scope` |
| Publication survives notification failure | `test_a_failing_notifier_never_rolls_back_the_publication` |
| Report money fields absent without permission | `test_report_columns_and_attributes_are_filtered_by_live_field_profile` |
| Aggregates do not reconstruct missing money | `test_aggregates_do_not_reconstruct_missing_money` |
| Export re-authorization / field crop | `test_export_reauthorizes_and_omits_uncovered_money_columns`; existing `ExportReauthorizationTest`, `ExportScopeTest` |
| Aggregate/detail consistency | `test_report_and_detail_share_exactly_the_same_surviving_cells` |
| Failure never renders fake empty table | `frontend/tests/unit/reportErrorState.spec.ts` |

### Test environment caveat

`backend/vendor` and `backend/node_modules` are symlinks into the main
workspace. Composer's PSR-4 base path therefore resolves `App\`/`Tests\` to the
main checkout. Backend PHPUnit was run with a temporary, **untracked**
`backend/.t16-bootstrap.php` that prepends a worktree PSR-4 autoloader, plus
`APP_BASE_PATH` pointing at this worktree. This file is intentionally excluded
from the commit. A normal standalone checkout with its own `composer install`
does not need it. The main workspace was never modified.

## Incomplete / follow-up boundaries

- T17 owns exact client-side money formatting, broader self-service payslip UI
  and full allowed-action wiring; T16 only adds identifier-only payslip
  notifications and revalidation behind the existing self payslip route.
- T18/T19 own real-browser MCP acceptance and MySQL concurrency verification.
  SQLite serializes writers, so delivery row locking is verified structurally
  here but not under true concurrent load.
- The in-app notification is created before the external send. A failed send
  keeps a legitimate inbox row plus a `FAILED` delivery; retry is explicit via
  the existing Platform retry command. Automatic background retry/backoff is
  outside T16.
- Notification payloads intentionally contain only identifiers. The client
  loads notice/payslip detail from its canonical service after revalidation.

## Risks

- Legacy rows with `event_key = null` are not deduplicated by the new unique
  index. New producers always supply a deterministic key; a replay of an event
  that predates T16 may therefore create one additional row. No historical row
  is rewritten or deleted.
- The delivery ledger reuses one synthetic `notification_outbox` row per
  notification event because the existing `notification_outbox_id` is unique.
  This keeps one ledger table and one Platform writer; it also means the legacy
  outbox dispatcher and Engagement share the same physical evidence table.
- The repository-reference transport does not call a real vendor. A production
  email/WeChat adapter must replace `NotificationTransport` and
  `ExternalNotificationChannel`; without a real successful receipt, production
  deliveries remain `FAILED` by design.
- `docs/openapi` still emits two pre-existing health-operation 4xx warnings
  unrelated to T16.

## Post-merge verification (main)

- Merge commit: `d8a7f9a`.
- `pnpm gate:all`: **PASS**, 22 gates, 290.2s.
- Log: `docs/implementation/v24/16-notifications-reporting/gate-all-t16-merge.log`.
- Contract generation (`openapi:generate`, `actions:generate`) produced no drift
  after the merge.
- This verifies the branch against the current combined V2.4 mainline; production
  transport, true MySQL concurrency and browser MCP remain T19/T18.
