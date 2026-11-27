# 单公司迁移预检（FactoryHR V2.1 / Volume 2 Task 1）

- 日期：2026-11-26
- Worktree：`D:/code/ai/factoryHR/.worktrees/v2.1-remediation`
- 命令：`php artisan company:preflight --json`
- 数据源：主检出的旧 SQLite 库 `D:/code/ai/factoryHR/backend/database/database.sqlite`（以只读方式通过 `DB_DATABASE` 绝对路径指向；未对工作树新库做旧数据假设）
- 输出：`docs/audit/2026-11-26-single-company-preflight.json`
- 读取方式：实现只调用 `Schema::getTables()`、`Schema::hasColumn()` 和 `DB::table(...)->count()`，不执行 `SHOW TABLES`、写入、迁移或 DDL。

## 决策与处置

系统**未上线**。用户已决定：旧数据库直接删除，随后以 `migrate:fresh --seed` 全量重建。因此本预检是**只读数据清单**，不是冲突裁定门禁：

- 不因重复业务键中止，不提出合并、去重或保留旧数据。
- `data_disposition` 固定为 `REBUILD`，下游读者应把本报告中的旧行数理解为“即将丢弃的数据量”。
- 本报告只记录重建前现状，供迁移演练与审计留痕；不把任何旧行作为必须保留的输入。

只读性证据：命令执行前后旧库 SHA-256 均为
`2b848886b84055c70d21a0fabd9cea35ed036a9061828c7ef1b368afcfdd0d4c`。

## 结果摘要

| 项目 | 值 |
|---|---|
| connection / driver | `sqlite` |
| data_disposition | `REBUILD` |
| company_profile 行数 | `0`（旧库没有 `company_profile` 表；按要求缺失时返回 0） |
| 表总数 | 107 |
| 含旧列的表 | 98（`tenant_id` + `legal_entity_id`：12；仅 `tenant_id`：86；仅 `legal_entity_id`：0；无旧列：9） |
| 全部表行数合计 | 345（含迁移/缓存账本） |
| LEGAL_ENTITY 授权绑定 | 1 |

## 保留公司

旧库不存在 `company_profile`，因此当前没有可保留的公司行：

- `company_profile.id`：无（表缺失，`company_profile_rows = 0`）
- `name/timezone/currency`：无旧值

重建后由 `2026_11_26_000050_create_company_profile` 播种单例行（`id=1`、`Synthetic Company`、`CN`、`Asia/Shanghai`、`CNY`）；这是目标 seed 行为，不是对旧数据的保留。

## 旧列清单（逐表）

下表为本次真实运行的全部 107 张表。`—` 表示该表没有两个旧列。

| 表 | 行数 | 旧列 |
|---|---:|---|
| `assignments` | 0 | tenant_id |
| `attendance_corrections` | 0 | tenant_id |
| `attendance_days` | 0 | tenant_id |
| `attendance_rule_versions` | 0 | tenant_id |
| `audit_events` | 1 | tenant_id |
| `auth_events` | 0 | tenant_id |
| `authority_bindings` | 1 | tenant_id |
| `break_glass_grants` | 0 | tenant_id |
| `business_trip_records` | 0 | tenant_id |
| `cache` | 1 | — |
| `cache_locks` | 0 | — |
| `candidates` | 0 | tenant_id |
| `capabilities` | 76 | — |
| `case_events` | 0 | tenant_id |
| `case_members` | 0 | tenant_id |
| `cases` | 0 | tenant_id |
| `dashboard_snapshots` | 0 | tenant_id |
| `decisions` | 0 | tenant_id |
| `demo_datasets` | 0 | tenant_id |
| `documents` | 0 | tenant_id |
| `effects` | 0 | tenant_id |
| `employment_contract_versions` | 0 | tenant_id |
| `employment_terms` | 0 | tenant_id |
| `employment_user_links` | 0 | tenant_id |
| `employments` | 0 | tenant_id / legal_entity_id |
| `export_jobs` | 0 | tenant_id |
| `failed_jobs` | 0 | — |
| `feature_flags` | 0 | tenant_id |
| `file_objects` | 0 | tenant_id |
| `holidays` | 0 | tenant_id / legal_entity_id |
| `idempotency_keys` | 0 | tenant_id |
| `interviews` | 0 | tenant_id |
| `job_batches` | 0 | — |
| `jobs` | 0 | — |
| `leave_accounts` | 0 | tenant_id |
| `leave_balances` | 0 | tenant_id |
| `leave_ledger_entries` | 0 | tenant_id |
| `leave_request_projections` | 0 | tenant_id |
| `leave_types` | 3 | tenant_id |
| `legal_entities` | 1 | tenant_id |
| `legal_entity_calendars` | 1 | tenant_id / legal_entity_id |
| `legal_holds` | 0 | tenant_id |
| `lifecycle_intents` | 0 | tenant_id |
| `lifecycle_tasks` | 0 | tenant_id |
| `login_attempts` | 0 | tenant_id |
| `mfa_factors` | 0 | tenant_id |
| `migration_issues` | 0 | — |
| `migration_runs` | 4 | tenant_id |
| `migration_shadow_rows` | 0 | tenant_id |
| `migrations` | 27 | — |
| `notice_acknowledgements` | 0 | tenant_id |
| `notices` | 0 | tenant_id |
| `notifications` | 0 | tenant_id |
| `offers` | 0 | tenant_id |
| `onboarding_checklist_templates` | 5 | tenant_id |
| `org_unit_closure` | 1 | tenant_id |
| `org_units` | 1 | tenant_id / legal_entity_id |
| `outbox_events` | 0 | tenant_id |
| `overtime_records` | 0 | tenant_id |
| `password_reset_tokens` | 0 | — |
| `payroll_adjustments` | 0 | tenant_id |
| `payroll_input_snapshots` | 0 | tenant_id |
| `payroll_items` | 0 | tenant_id / legal_entity_id |
| `payroll_receipts` | 0 | tenant_id |
| `payroll_run_employees` | 0 | tenant_id |
| `payroll_runs` | 0 | tenant_id / legal_entity_id |
| `payroll_scheme_items` | 0 | tenant_id |
| `payroll_schemes` | 0 | tenant_id / legal_entity_id |
| `payroll_ytd` | 0 | tenant_id |
| `payslip_lines` | 0 | tenant_id |
| `payslips` | 0 | tenant_id |
| `performance_appeals` | 0 | tenant_id |
| `performance_cycles` | 0 | tenant_id |
| `performance_reviews` | 0 | tenant_id |
| `performance_templates` | 0 | tenant_id |
| `persons` | 0 | tenant_id |
| `positions` | 0 | tenant_id / legal_entity_id |
| `punch_records` | 0 | tenant_id |
| `recovery_codes` | 0 | tenant_id |
| `recruitment_requisitions` | 0 | tenant_id / legal_entity_id |
| `report_definitions` | 0 | tenant_id |
| `report_runs` | 0 | tenant_id |
| `reporting_projection_rows` | 0 | tenant_id / legal_entity_id |
| `retention_purge_runs` | 0 | tenant_id |
| `role_capabilities` | 151 | tenant_id |
| `roles` | 10 | tenant_id |
| `sessions` | 0 | tenant_id |
| `shift_assignments` | 0 | tenant_id |
| `shift_templates` | 1 | tenant_id / legal_entity_id |
| `statutory_parameters` | 0 | tenant_id / legal_entity_id |
| `stg_assignments` | 0 | tenant_id |
| `stg_leave_openings` | 12 | tenant_id |
| `stg_organizations` | 8 | tenant_id |
| `stg_payroll_ytd` | 12 | tenant_id |
| `stg_people` | 12 | tenant_id |
| `stg_terms` | 0 | tenant_id |
| `tax_brackets` | 0 | tenant_id |
| `training_enrollments` | 0 | tenant_id |
| `training_programs` | 0 | tenant_id |
| `training_sessions` | 0 | tenant_id |
| `user_roles` | 1 | tenant_id |
| `users` | 1 | tenant_id |
| `workflow_delegations` | 0 | tenant_id |
| `workflow_instances` | 0 | tenant_id |
| `workflow_tasks` | 0 | tenant_id |
| `workflow_template_nodes` | 0 | tenant_id |
| `workflow_templates` | 15 | tenant_id |

## 将被丢弃的数据量与来源

不进行冲突裁定。以下为旧库非空表的实际行数，全部属于将被 `migrate:fresh --seed` 丢弃的旧数据：

| 表 | 行数 | 来源/含义 |
|---|---:|---|
| `role_capabilities` | 151 | 角色能力种子投影 |
| `capabilities` | 76 | 能力目录 |
| `migrations` | 27 | 迁移账本 |
| `workflow_templates` | 15 | 工作流模板种子 |
| `stg_leave_openings` | 12 | 迁移 staging 数据 |
| `stg_payroll_ytd` | 12 | 迁移 staging 数据 |
| `stg_people` | 12 | 迁移 staging 数据 |
| `roles` | 10 | 角色模板（9 个模板角色 + 运行记录） |
| `stg_organizations` | 8 | 迁移 staging 数据 |
| `onboarding_checklist_templates` | 5 | 入职清单模板 |
| `migration_runs` | 4 | 迁移运行账本 |
| `leave_types` | 3 | 假期类型种子 |
| `audit_events` | 1 | 审计事件 |
| `authority_bindings` | 1 | 授权绑定 |
| `cache` | 1 | Laravel 缓存 |
| `legal_entities` | 1 | 旧法人主体 `ACME-CN` |
| `legal_entity_calendars` | 1 | 旧法人日历 |
| `org_unit_closure` | 1 | 组织闭包 |
| `org_units` | 1 | 组织单元 |
| `shift_templates` | 1 | 班次模板 |
| `user_roles` | 1 | 用户角色分配 |
| `users` | 1 | 用户 `installer` |

## 授权绑定

- LEGAL_ENTITY 数量：**1**
- 绑定内容：`principal_id=1`、`capability_code=*`、`anchor_type=LEGAL_ENTITY`、`anchor_id=1`、`field_profile=P3_RESTRICTED`、`purpose_code=CLI_SYSTEM_ADMIN`
- 目标 COMPANY 映射（ADR-0037）：`anchor_type=COMPANY`、`anchor_id=null`、`anchor_key=0`
- 受影响 user/role：用户 `installer`（id 1）与 `SYSTEM_ADMIN` 的 `user_roles` 分配

说明：由于旧库整体重建，上述映射仅记录旧锚点形态和 ADR 决策；实际 `COMPANY` 绑定将由重建后的 seed 生成，不代表迁移时保留该旧绑定。

## 门禁基线（必须由 Volume 2 Tasks 3/4 清零）

本任务按计划**只建立门禁、不弱化门禁**。真实仓库当前仍有大量历史引用：

```text
node scripts/audit_single_company.mjs
exit code: 1
failures: 1927
```

按扫描根拆分：

| 扫描面 | 命中行数 |
|---|---:|
| `backend/app` | 1629 |
| `backend/routes` | 4 |
| `frontend/src` | 189 |
| `docs/openapi/openapi.yaml` | 105 |
| `backend/database/seeders`、`docs/generated` | 0（`docs/generated` 当前不存在） |

`NoTenantNoLegalEntityTest` 的结构性用例通过（标记行、文件级/目录级反例、迁移目录边界），但目标代码扫描用例同样失败，命中数与本门禁一致。原因相同：存量引用尚未由 Volume 2 Tasks 3/4 清理。**不得**通过扩大 allowlist、文件级豁免或目录级豁免让它变绿。

门禁 fixture 测试：`node --test scripts/tests/audit_single_company.test.mjs` → 4 passed。预检 feature 测试：3 passed / 964 assertions。`node deploy/scripts/openapi-lint.mjs` → exit 0，`openapi-lint: OK (251 paths, 323 operations, 3.1.0)`。

## 阻断项

- 本预检自身无阻断项；它是只读清单，不要求任何数据保留决定。
- cutover 执行时的破坏性前提是已确认旧库可删除并允许 `migrate:fresh --seed`；这已由用户裁定。
- MySQL 8.4 真实演练属于 Task 2/Volume 2 后续任务，本任务 **NOT RUN**；不得据此声称 MySQL 演练已通过。

## 精确运行时例外

唯一运行时例外是 `backend/app/Modules/Organization/Commands/CompanyPreflightCommand.php::buildReport()` 内的两行字面量：

- `->where('anchor_type', 'LEGAL_ENTITY')`（计数旧授权绑定）
- `['tenant_id', 'legal_entity_id']`（探查旧列是否存在）

两行各带 `// single-company:preflight-only` 行标记。扫描器只忽略“该文件 + 该标记行”；同一文件的未标记行、同目录其他文件、整目录均不获豁免。迁移目录继续整体排除，因为 migration 的职责就是命名并删除旧列。
