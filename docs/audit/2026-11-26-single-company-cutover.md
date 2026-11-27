# Single-company schema cutover evidence - 2026-11-26

## Scope and decision

Volume 2 Task 2 converts the schema to the single-company shape described by
ADR-0037:

- `authority_bindings.anchor_type = LEGAL_ENTITY` becomes `COMPANY`, with
  `anchor_id = NULL` and `anchor_key = 0`;
- every `tenant_id` and `legal_entity_id` column is removed;
- every unique business key led by those columns is rebuilt without them;
- `legal_entities` is dropped;
- the system is not live, so old data may be deleted and the supported runtime
  cutover is `migrate:fresh --seed`. No merging, de-duplication, preservation,
  or conflict-abort logic is implemented.

Migrations are forward-only. Both `down()` methods are documented no-ops;
restoration is from the pre-cutover database snapshot.

## What ran and exit codes

| # | Environment | Command | Exit | Result |
|---:|---|---|---:|---|
| 1 | SQLite 3.45.1 / PHP 8.4.28 / Laravel 13.43.0 (test config) | `php artisan test --compact --filter=SingleCompanyMigrationTest` | 0 | 7 passed, 327 assertions |
| 2 | MySQL 8.4.13 / PHP 8.4.28 / Laravel 13.43.0 (isolated DB) | `php artisan test --compact --filter=SingleCompanyMigrationTest` | 0 | 7 passed, 949 assertions |
| 3 | SQLite (configured worktree DB) | `php artisan migrate:fresh --seed --force` | 0 | all 56 migrations ran; 155 tables, 0 legacy columns, company_profile=1; seed emitted the expected missing-password notice |
| 4 | MySQL 8.4.13 (isolated empty DB) | `php artisan migrate:fresh --seed --force` | 1 | schema cutover completed; seeder failed on the removed `legal_entities` table (carried to Task 3) |
| 5 | SQLite (configured worktree DB) | `php artisan test --compact --filter=NoTenantNoLegalEntityTest` | 1 | 1 failed, 3 passed, 6 assertions; **1927** repository scan hits (expected red; Task 3/4 own cleanup) |
| 6 | MySQL 8.4.13 (clone of live legacy `factoryhr`) | `php artisan migrate --force` | 0 | both cutover migrations completed; in-place legacy cutover proven |
| 7 | MySQL 8.4.13 (second clone) | `php artisan migrate --force` with a synthetic `LEGAL_ENTITY` binding | 0 | probe converted to `COMPANY/NULL/0` |
| 8 | MySQL 8.4.13 (same DB) | run migration `000300::up()` twice more via tinker | 0 | idempotent, still exactly 0 `LEGAL_ENTITY` bindings |
| 9 | MySQL 8.4.13 (third clone) | run `000320::up()` on a table with an existing replacement key | 0 | legacy columns removed; only one `code` unique key remained |

The source for rows 6-9 was a read-only `mysqldump` clone of the live
`factoryhr` database. The live database remained at 54 applied migrations and
171 legacy column instances after these drills; it was not migrated or modified.

### TDD red evidence

Before either migration existed, the initial four-test version of
`SingleCompanyMigrationTest` was run. Exit code was **1**; 2 tests failed and 2
passed (4 assertions):

- `assignments still has tenant_id` on the legacy-column scan;
- `users.username must be unique after cutover`.

The red failures were the expected schema defects, not setup errors. Both
migrations were then implemented and the suite turned green.

## Legacy-column counts before / after

MySQL 8.4 in-place cutover of the live-schema clone:

| Metric | Before | After |
|---|---:|---:|
| Tables | 156 | 155 |
| Tables with `tenant_id` or `legal_entity_id` | 145 | 0 |
| Legacy column instances | 171 | 0 |
| `legal_entities` | present, 1 row | absent |
| `company_profile` | 1 row | 1 row |
| `authority_bindings` rows with `LEGAL_ENTITY` | 1 (injected probe) | 0 |
| Applied migrations | 54 | 56 |

Fresh SQLite database after the required command:

| Metric | Value |
|---|---:|
| Tables | 155 |
| Tables with legacy columns | 0 |
| Legacy column instances | 0 |
| Unique indexes led by legacy columns | 0 |
| Non-primary indexes containing legacy columns | 0 |
| `legal_entities` present | no |
| `company_profile` rows | 1 |
| `authority_bindings` rows with `LEGAL_ENTITY` | 0 |

## Index totals before / after

MySQL 8.4 in-place cutover:

| Index class | Before | After |
|---|---:|---:|
| Total indexes | 731 | 448 |
| Unique indexes | 265 | 262 |
| Non-unique indexes | 466 | 186 |
| Unique indexes whose first column was `tenant_id`/`legal_entity_id` | 74 | 0 |
| Non-primary indexes containing a legacy column | 354 | 0 |

The migration drops every dependent non-primary index, then recreates only the
derived **unique** business keys. Obsolete tenant-scoped non-unique lookup
indexes are intentionally not rebuilt. 72 of the 74 legacy-led unique indexes
had a business remainder and were recreated; 1 disappeared with
`legal_entities`, and 1 (`legal_entity_calendars.calendar_entity_unique`, whose
columns were only `tenant_id, legal_entity_id`) had no remainder and was
correctly skipped. Three replacement names exceed MySQL's 64-character
identifier limit and use deterministic `uniq_<sha256-prefix>` names:

| Table | Replacement columns | Replacement index |
|---|---|---|
| `leave_ledger_entries` | `employment_id, leave_type_id, source_type, source_id, account` | `uniq_2f3de277683b3a68f27ac8d66a88a306` |
| `reporting_projection_rows` | `source_projection, business_date, entity_type, entity_id` | `uniq_fb899aa82556629b0254ee1c86a774b9` |
| `statutory_parameters` | `parameter_set, code, version, effective_from` | `uniq_2a2cd77ba6bbeedf7e1b00da22ad546e` |

Required keys verified on both SQLite and MySQL:

| Table.column | Actual replacement unique index |
|---|---|
| `users.username` | `users_username_unique` |
| `org_units.code` | `org_units_code_unique` |
| `employments.employee_no` | `employments_employee_no_unique` |

## Complete legacy-led unique index map (74 indexes)

Each replacement key was derived at migration time from `Schema::getIndexes()`:
the leading legacy column(s) were removed and the remaining columns became the
new unique key. 72 keys were recreated. One (`legal_entities`) disappeared with
its table; one (`legal_entity_calendars.calendar_entity_unique`) had no business
remainder and was deliberately skipped.

| # | Table | Legacy unique index | Columns before | Replacement columns | Replacement unique index |
|---:|---|---|---|---|---|
| 1 | `attendance_corrections` | `correction_case_unique` | `tenant_id, case_id` | case_id | `attendance_corrections_case_id_unique` |
| 2 | `attendance_days` | `attendance_day_unique` | `tenant_id, employment_id, business_date` | employment_id, business_date | `attendance_days_employment_id_business_date_unique` |
| 3 | `attendance_devices` | `attendance_devices_entity_code_unique` | `tenant_id, legal_entity_id, code` | code | `attendance_devices_code_unique` |
| 4 | `attendance_period_closures` | `attendance_closure_period_unique` | `tenant_id, legal_entity_id, month` | month | `attendance_period_closures_month_unique` |
| 5 | `attendance_rule_versions` | `attendance_rule_version_unique` | `tenant_id, version` | version | `attendance_rule_versions_version_unique` |
| 6 | `benefit_enrollments` | `benefit_enrollments_version_unique` | `tenant_id, employment_id, plan_id, effective_from` | employment_id, plan_id, effective_from | `benefit_enrollments_employment_id_plan_id_effective_from_unique` |
| 7 | `benefit_plans` | `benefit_plans_tenant_code_version_unique` | `tenant_id, code, version` | code, version | `benefit_plans_code_version_unique` |
| 8 | `benefit_transactions` | `benefit_transactions_payroll_unique` | `tenant_id, payroll_run_id` | payroll_run_id | `benefit_transactions_payroll_run_id_unique` |
| 9 | `benefit_transactions` | `benefit_transactions_period_unique` | `tenant_id, enrollment_id, period` | enrollment_id, period | `benefit_transactions_enrollment_id_period_unique` |
| 10 | `candidate_conversions` | `candidate_conversion_candidate_unique` | `tenant_id, candidate_id` | candidate_id | `candidate_conversions_candidate_id_unique` |
| 11 | `candidates` | `candidate_email_hash_unique` | `tenant_id, email_hash` | email_hash | `candidates_email_hash_unique` |
| 12 | `candidates` | `candidate_mobile_hash_unique` | `tenant_id, mobile_hash` | mobile_hash | `candidates_mobile_hash_unique` |
| 13 | `cases` | `cases_tenant_id_case_no_unique` | `tenant_id, case_no` | case_no | `cases_case_no_unique` |
| 14 | `contract_templates` | `contract_templates_tenant_code_version_unique` | `tenant_id, code, version` | code, version | `contract_templates_code_version_unique` |
| 15 | `dashboard_snapshots` | `dashboard_snapshot_scope_unique` | `tenant_id, metric_code, as_of, scope_hash` | metric_code, as_of, scope_hash | `dashboard_snapshots_metric_code_as_of_scope_hash_unique` |
| 16 | `demo_datasets` | `demo_datasets_tenant_dataset_unique` | `tenant_id, dataset` | dataset | `demo_datasets_dataset_unique` |
| 17 | `employment_contracts` | `employment_contracts_tenant_no_unique` | `tenant_id, contract_no` | contract_no | `employment_contracts_contract_no_unique` |
| 18 | `employment_user_links` | `employment_user_links_unique` | `tenant_id, user_id, employment_id, valid_from` | user_id, employment_id, valid_from | `employment_user_links_user_id_employment_id_valid_from_unique` |
| 19 | `employments` | `employments_employee_no_unique` | `tenant_id, legal_entity_id, employee_no` | employee_no | `employments_employee_no_unique` |
| 20 | `feature_flags` | `feature_flags_tenant_flag_unique` | `tenant_id, flag` | flag | `feature_flags_flag_unique` |
| 21 | `holidays` | `holiday_entity_date_unique` | `tenant_id, legal_entity_id, date` | date | `holidays_date_unique` |
| 22 | `iam_data_scope_policies` | `iam_scope_template_unique` | `tenant_id, template_code` | template_code | `iam_data_scope_policies_template_code_unique` |
| 23 | `iam_field_policies` | `iam_field_policy_key_unique` | `tenant_id, field_key` | field_key | `iam_field_policies_field_key_unique` |
| 24 | `idempotency_keys` | `idempotency_scope_key_unique` | `tenant_id, principal_id, scope, key` | principal_id, scope, key | `idempotency_keys_principal_id_scope_key_unique` |
| 25 | `leave_accounts` | `leave_account_open_unique` | `tenant_id, employment_id, leave_type_id, opened_on` | employment_id, leave_type_id, opened_on | `leave_accounts_employment_id_leave_type_id_opened_on_unique` |
| 26 | `leave_balances` | `leave_balance_projection_unique` | `tenant_id, employment_id, leave_type_id` | employment_id, leave_type_id | `leave_balances_employment_id_leave_type_id_unique` |
| 27 | `leave_ledger_entries` | `leave_ledger_source_unique` | `tenant_id, employment_id, leave_type_id, source_type, source_id, account` | employment_id, leave_type_id, source_type, source_id, account | `uniq_2f3de277683b3a68f27ac8d66a88a306` |
| 28 | `leave_request_projections` | `leave_case_projection_unique` | `tenant_id, case_id` | case_id | `leave_request_projections_case_id_unique` |
| 29 | `leave_types` | `leave_type_code_unique` | `tenant_id, code` | code | `leave_types_code_unique` |
| 30 | `legal_entities` | `legal_entities_tenant_code_unique` | `tenant_id, code` | code | dropped with `legal_entities` (expected) |
| 31 | `legal_entity_calendars` | `calendar_entity_unique` | `tenant_id, legal_entity_id` | (none) | skipped: no business remainder |
| 32 | `menu_overrides` | `menu_override_page_unique` | `tenant_id, page_code` | page_code | `menu_overrides_page_code_unique` |
| 33 | `migration_shadow_rows` | `migration_shadow_natural_key_unique` | `tenant_id, domain, natural_key` | domain, natural_key | `migration_shadow_rows_domain_natural_key_unique` |
| 34 | `notice_templates` | `notice_templates_tenant_code_version_unique` | `tenant_id, code, version` | code, version | `notice_templates_code_version_unique` |
| 35 | `notification_outbox` | `notification_outbox_event_unique` | `tenant_id, event_key` | event_key | `notification_outbox_event_key_unique` |
| 36 | `notification_templates` | `notification_template_code_unique` | `tenant_id, code` | code | `notification_templates_code_unique` |
| 37 | `offers` | `offer_candidate_version_unique` | `tenant_id, candidate_id, version` | candidate_id, version | `offers_candidate_id_version_unique` |
| 38 | `onboarding_checklist_templates` | `onboarding_template_code_unique` | `tenant_id, task_code` | task_code | `onboarding_checklist_templates_task_code_unique` |
| 39 | `org_units` | `org_units_tenant_code_unique` | `tenant_id, code` | code | `org_units_code_unique` |
| 40 | `overtime_requests` | `overtime_request_day_unique` | `tenant_id, employment_id, business_date` | employment_id, business_date | `overtime_requests_employment_id_business_date_unique` |
| 41 | `payroll_items` | `payroll_item_code_unique` | `tenant_id, legal_entity_id, code` | code | `payroll_items_code_unique` |
| 42 | `payroll_schemes` | `payroll_scheme_version_unique` | `tenant_id, legal_entity_id, code, version` | code, version | `payroll_schemes_code_version_unique` |
| 43 | `payroll_ytd` | `payroll_ytd_unique` | `tenant_id, employment_id, tax_year` | employment_id, tax_year | `payroll_ytd_employment_id_tax_year_unique` |
| 44 | `performance_appeals` | `appeal_review_unique` | `tenant_id, review_id` | review_id | `performance_appeals_review_id_unique` |
| 45 | `performance_cycles` | `performance_cycle_name_unique` | `tenant_id, name` | name | `performance_cycles_name_unique` |
| 46 | `performance_indicators` | `performance_indicator_code_unique` | `tenant_id, code` | code | `performance_indicators_code_unique` |
| 47 | `performance_reviews` | `review_cycle_employment_unique` | `tenant_id, cycle_id, employment_id` | cycle_id, employment_id | `performance_reviews_cycle_id_employment_id_unique` |
| 48 | `performance_templates` | `performance_template_version_unique` | `tenant_id, code, version` | code, version | `performance_templates_code_version_unique` |
| 49 | `persons` | `persons_tenant_identity_unique` | `tenant_id, id_type, id_number_hash` | id_type, id_number_hash | `persons_id_type_id_number_hash_unique` |
| 50 | `position_grades` | `position_grades_tenant_entity_code_unique` | `tenant_id, legal_entity_id, code` | code | `position_grades_code_unique` |
| 51 | `position_qualifications` | `position_qualifications_tenant_position_version_unique` | `tenant_id, position_id, version` | position_id, version | `position_qualifications_position_id_version_unique` |
| 52 | `positions` | `positions_tenant_legal_entity_code_unique` | `tenant_id, legal_entity_id, code` | code | `positions_code_unique` |
| 53 | `punch_records` | `punch_hash_unique` | `tenant_id, employment_id, raw_payload_hash` | employment_id, raw_payload_hash | `punch_records_employment_id_raw_payload_hash_unique` |
| 54 | `recruitment_requisitions` | `requisition_no_unique` | `tenant_id, requisition_no` | requisition_no | `recruitment_requisitions_requisition_no_unique` |
| 55 | `report_definitions` | `report_definitions_tenant_code_unique` | `tenant_id, code` | code | `report_definitions_code_unique` |
| 56 | `reporting_projection_rows` | `reporting_projection_identity_unique` | `tenant_id, source_projection, business_date, entity_type, entity_id` | source_projection, business_date, entity_type, entity_id | `uniq_fb899aa82556629b0254ee1c86a774b9` |
| 57 | `requisition_openings` | `requisition_opening_channel_unique` | `tenant_id, requisition_id, channel` | requisition_id, channel | `requisition_openings_requisition_id_channel_unique` |
| 58 | `salary_adjustments` | `salary_adjustments_case_unique` | `tenant_id, case_id` | case_id | `salary_adjustments_case_id_unique` |
| 59 | `shift_schedules` | `shift_schedules_employment_date_unique` | `tenant_id, employment_id, business_date` | employment_id, business_date | `shift_schedules_employment_id_business_date_unique` |
| 60 | `shift_templates` | `shift_version_unique` | `tenant_id, legal_entity_id, code, version, valid_from` | code, version, valid_from | `shift_templates_code_version_valid_from_unique` |
| 61 | `statutory_parameters` | `statutory_parameter_version_unique` | `tenant_id, legal_entity_id, parameter_set, code, version, effective_from` | parameter_set, code, version, effective_from | `uniq_2a2cd77ba6bbeedf7e1b00da22ad546e` |
| 62 | `sys_dictionaries` | `sys_dictionaries_tenant_id_type_code_unique` | `tenant_id, type, code` | type, code | `sys_dictionaries_type_code_unique` |
| 63 | `sys_parameters` | `sys_parameters_tenant_id_key_scope_unique` | `tenant_id, key, scope` | key, scope | `sys_parameters_key_scope_unique` |
| 64 | `talent_pool_tags` | `talent_pool_candidate_tag_unique` | `tenant_id, candidate_id, tag` | candidate_id, tag | `talent_pool_tags_candidate_id_tag_unique` |
| 65 | `training_checkins` | `training_checkins_session_employment_unique` | `tenant_id, session_id, employment_id` | session_id, employment_id | `training_checkins_session_id_employment_id_unique` |
| 66 | `training_courses` | `training_courses_tenant_code_unique` | `tenant_id, code` | code | `training_courses_code_unique` |
| 67 | `training_enrollments` | `enrollment_session_employment_unique` | `tenant_id, session_id, employment_id` | session_id, employment_id | `training_enrollments_session_id_employment_id_unique` |
| 68 | `training_exams` | `training_exams_tenant_code_version_unique` | `tenant_id, code, version` | code, version | `training_exams_code_version_unique` |
| 69 | `training_plans` | `training_plans_tenant_code_unique` | `tenant_id, code` | code | `training_plans_code_unique` |
| 70 | `training_programs` | `training_program_code_unique` | `tenant_id, code` | code | `training_programs_code_unique` |
| 71 | `travel_policies` | `travel_policies_effective_unique` | `tenant_id, legal_entity_id, city_tier, effective_from` | city_tier, effective_from | `travel_policies_city_tier_effective_from_unique` |
| 72 | `travel_policies` | `travel_policies_version_unique` | `tenant_id, legal_entity_id, city_tier, version` | city_tier, version | `travel_policies_city_tier_version_unique` |
| 73 | `users` | `users_tenant_username_unique` | `tenant_id, username` | username | `users_username_unique` |
| 74 | `workflow_templates` | `workflow_templates_tenant_id_code_version_unique` | `tenant_id, code, version` | code, version | `workflow_templates_code_version_unique` |

## Binding conversion proof

Second live-schema clone, synthetic probe row
`purpose_code = TASK2_PROBE`:

| Stage | `anchor_type` | `anchor_id` | `anchor_key` | LEGAL_ENTITY rows |
|---|---|---|---:|---:|
| Before migration | `LEGAL_ENTITY` | 42 | 42 | 1 |
| After `000300` | `COMPANY` | `NULL` | 0 | 0 |
| After two additional `up()` calls | `COMPANY` | `NULL` | 0 | 0 |

`000300` returns immediately when `authority_bindings` is absent and its update
is naturally idempotent once no `LEGAL_ENTITY` rows remain.

## Replacement-key guard proof

A synthetic table was created with:

- `UNIQUE(tenant_id, code)` named `probe_legacy_key`;
- `UNIQUE(code)` named `probe_existing_key`.

After `000320::up()`, `tenant_id` and `legal_entity_id` were gone and the table
had exactly one unique key on `code`: `probe_existing_key`. The migration did
not create a duplicate replacement key.

## Fresh MySQL seeding failure (carried to Volume 2 Task 3)

The required MySQL `migrate:fresh --seed --force` rehearsal completed all schema
migrations including `000300` and `000320`, then exited **1** during seeding:

```text
SQLSTATE[42S02]: Base table or view not found: 1146 Table
'factoryhr_v2_task2.legal_entities' doesn't exist

SQL: select * from `legal_entities` where (`tenant_id` = 1 and `code` = 'DEMO-CN') limit 1
```

The failing runtime is `DemoOrganizationSeederService` (invoked by
`iam:seed-demo` through `DatabaseSeeder`), which still models and writes the
legacy `LegalEntity`/`tenant_id` shape. This is a **carried item owned by
Volume 2 Task 3**; it is not hidden by weakening the migration or the test.
The cutover schema itself was verified after this failed seed: 155 tables, 0
legacy columns, 0 `legal_entities`.

The configured SQLite `migrate:fresh --seed --force` command exited 0 because
`DEMO_SEED_PASSWORD` is not set in the test environment, so `DatabaseSeeder`
skips `iam:seed-demo` and emits its configured warning rather than exercising
the legacy seeder. On MySQL with `DEMO_SEED_PASSWORD` set, the same fresh run
exits 1 after the schema is fully cut over; the post-failure schema check is 155
tables, 0 legacy columns, 0 `legal_entities`, and 1 `company_profile` row.

## Intentionally red contract gate

`NoTenantNoLegalEntityTest` remains red at this stage, as expected:

- exit code: 1;
- tests: 1 failed, 3 passed, 6 assertions;
- repository scan violation count: **1927**;
- the schema assertions are green; the failures are runtime/frontend/OpenAPI
  identifiers owned by Volume 2 Tasks 3 and 4.

The scanner's runtime roots contain **373 files / 1,633 matching lines** after
the two exact preflight marker exemptions. The complete file list is committed
at `.superpowers/sdd/artifacts/v2/task-2-runtime-sweep-files.txt` and grouped
per module in `.superpowers/sdd/artifacts/v2/task-2-runtime-sweep-manifest.md`.
Every listed file must be updated or deleted by Task 3; the list is a work
manifest, not an allowlist.

## Tool versions

- PHP 8.4.28 (NTS)
- Laravel Framework 13.43.0
- MySQL 8.4.13 (official `mysql:8.4` container)
- SQLite 3.45.1
