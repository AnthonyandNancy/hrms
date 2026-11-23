# HRMS V1.0 回滚 Runbook

> 文档性质：本仓库是 HRMS V1.0 的参考实现（reference implementation），本文件是**可执行的演练与操作基线**，不是生产切换已经完成的证明。
> 适用版本：V1.0 参考实现（数据库默认 SQLite；生产目标为 MySQL 8.4 + PITR）。
> 组织签认：**GATE**。本文件不包含、也不得预填任何姓名、签字或生产演练结果。

## 0. 事实边界（必须先读）

| 项目 | 本仓库可证明 | 必须在真实环境完成（GATE） |
|---|---|---|
| SQLite 快照、恢复到临时路径、sha256 一致、`PRAGMA integrity_check`、表存在/计数 | `bash deploy/scripts/rollback_drill.sh` 可重复执行 | — |
| 回滚时长 | 脚本输出 elapsed 与 `--budget-seconds`（默认 3600 秒）比较；只能证明本地 SQLite 路径 | 真实生产回滚必须在冻结窗口内以生产数据、生产网络和生产依赖演练，结果由 DevOps/技术负责人签字 |
| MySQL 全量备份/PITR | 仓库只提供 Compose 服务定义；`deploy/compose/docker-compose.yml` 的 MySQL 服务**未配置 binlog**，不能据此声称 PITR 可用 | 托管 MySQL 或主从 + binlog + 异地备份；隔离实例恢复；DBA 签字 |
| 功能开关回滚 | 实现与测试位于 `backend/app/Modules/Platform/Features/`、`backend/tests/Feature/FeatureFlagTest.php`；命令 `php artisan feature:set` 可用 | 逐路由核对 `feature:*` 中间件是否覆盖业务路由；生产关闭 flag 后验证 404 与审计；GATE |
| 路由切换/旧系统只读 | 仓库没有生产负载均衡、DNS 或旧系统控制面 | 由网络/平台团队执行，记录切换时点、证据和用户验证；GATE |
| 工资封存后更正 | `CorrectionService`、`ReopenService`、`backend/tests/Feature/Payroll/CorrectionRunTest.php` 证明更正链路与不可变约束 | 财务/法务确认更正批次、税务/银行回执和员工沟通；GATE |

> **NOT_EXECUTED 规则**：任何依赖真实 MySQL、生产负载均衡、旧系统、生产 IdP、银行/税务回执或组织签字的步骤，在本仓库只能标记 `NOT_EXECUTED` 或 `GATE`，不能写成 PASS。`rollback_drill.sh` 输出末尾的 `NOT_EXECUTED` 行就是这一原则的机器可读体现。

## 1. 目标、时限与授权

### 1.1 目标

1. 在事故或切换失败时，将可回滚的应用域回到上一个已验证状态。
2. 不丢失不可逆业务事实；已封存工资、已发布工资条、已签合同、已发通知、已报税和外部回执只能更正或人工对账，不能通过删除历史“回滚”。
3. 用可核验证据证明恢复后的数据库可读、可对账、可继续服务。

### 1.2 时限（目标值，不是本地实测值）

| 指标 | 目标 | 来源 | 说明 |
|---|---|---|---|
| 域级回滚/切回旧系统 | ≤ 60 分钟 | 计划 4.10 E10、8.2/8.3、D-21 | 从宣布回滚到旧系统恢复可写或新系统恢复可服务；以演练计时为准 |
| 生产灾难恢复 RTO | ≤ 2 小时 | 计划 5.2 混沌门禁、9.1、D-25 | 数据库/依赖整体恢复；不是单一域回滚 |
| 生产 RPO | ≤ 15 分钟 | 计划 5.2、9.1、D-25 | 依赖 binlog/异地备份；本地 SQLite 脚本不证明该指标 |

`rollback_drill.sh` 的默认预算 3600 秒对应 60 分钟回滚预算；它是**本地快照/恢复路径**的预算检查，不等价于生产 RTO/RPO 演练。真实生产必须另外记录 MySQL 恢复、PITR 追日志、路由切换和业务验证的总时长。

### 1.3 决策权限（GATE：人员名单未在本仓库签认）

计划 8.4 要求回滚决策由**交付经理 + 技术负责人 + 业务 owner**共同作出；涉及工资时增加**财务/薪酬负责人**。本仓库不填写姓名，实际名单、备份人和授权范围必须在切换前由组织签认。

| 角色 | 回滚中的职责 | 不能单独决定的事项 |
|---|---|---|
| 事件指挥官（Incident Commander） | 宣布 Sev、召集决策人、控制时间线、冻结变更 | 不能绕过财务对工资更正的批准 |
| 技术负责人 | 判断技术回滚点、数据库恢复方式、代码版本 | 不能擅自删除已封存数据 |
| 业务 owner（HR/财务） | 确认业务影响、对账口径、用户沟通 | 不能代替安全做数据泄露判定 |
| 财务/薪酬负责人 | 工资/税务/银行影响、更正批次批准 | 不能用回滚覆盖已发工资或已报税 |
| 安全/合规 | Sev1 数据泄露、审计、法务保全 | 不能以“按时上线”为由关闭证据 |
| DevOps/DBA | 快照、恢复、PITR、路由切换执行 | 不能在未批准时执行破坏性 SQL |

**双人复核**：恢复生产数据库、执行 PITR、切换路由和关闭/开启高风险 flag 必须由两人复核并记录；具体第二人名单为 **GATE**。紧急情况下可以先口头批准后补记录，但补记录必须包含时间、人员和证据。

## 2. 触发条件

满足任一条即进入回滚评估；是否真正回滚由 1.3 的决策组决定，不因“已经投入很多”而跳过。

| 触发器 | 判定证据 | 首选手册动作 |
|---|---|---|
| Sev1：数据泄露、工资错发、账本错账、系统不可用 | 安全告警/事故单；工资差异报告；`ledger:reconcile` 非零；健康检查持续失败 | 立即止血，评估回滚；工资/账本同时启动财务对账 |
| Sev2：核心流程不可用、权限错误、迁移对账失败 | 业务无法完成入职/合同/假勤/工资；越权证据；`migrate:reconcile` 非零 | 停止切换，回退当前波次或关闭对应 flag/路由 |
| 工资平行差异超过阈值 | `payroll:parallel-check` 退出 1；差异 > 1 分或人头不一致 | 不发布、不付款；财务决定是否回滚工资域 |
| 工资平行文件缺失/格式错误 | `payroll:parallel-check` 退出 2 | 这是 **NOT_EXECUTED/输入无效**，不是“无差异”；补齐文件后重跑 |
| 假勤账本不解释差异 | `ledger:reconcile` 退出 1，且差异无法解释 | 停止假勤关账/工资输入；HR 与财务对账 |
| 数据库/依赖不可用 | `/v1/health/ready` 返回 503；DB/cache/storage 任一 false | 先恢复依赖；必要时按本文件恢复数据库 |
| 路由切到新系统后核心 smoke 失败 | 监控/人工 smoke、UAT 关键路径失败 | 路由切回旧系统；保留新系统数据供对账 |
| 变更后错误率/延迟持续超门禁 | P95 > 500ms、错误率 > 0.5% 等 | 回退本次变更；按变更单回滚点执行 |

**不是触发条件**：单条文案、单个非关键页面样式（Sev4），或可通过正常补丁修复且不涉及数据安全/工资/账本/权限的问题。Sev3/Sev4 的处理应走变更和缺陷流程，不应为了“演练”制造不必要回滚。

## 3. 回滚顺序（总览）

严格按“先停写，再备份，再恢复，再核对，再切流”的顺序；任何一步失败就停在原地并升级，不要用“再试一次删除”掩盖失败。

```text
宣布回滚 / 建事故单
  -> 停止写入与异步任务
  -> 固化当前状态（事故元数据、hash、日志）
  -> 验证候选快照
  -> 恢复 SQLite 或 MySQL/PITR
  -> 关闭高风险功能开关（如适用）
  -> 路由切回旧系统/稳定版本
  -> 健康检查与用户 smoke
  -> 人数/组织/合同/假勤/工资对账
  -> 不可逆事项转更正/冲正/人工对账
  -> 证据归档 / 复盘 / GATE 签认
```

### 3.1 第 1 步：停止写入（GATE：生产流量控制）

**原则：停止所有会写数据库的入口，包括 HTTP 写请求、队列 worker、scheduler、外部回调和个人脚本。** 只读查询可以保留，但必须在确认没有后台写任务后再宣称“已停写”。

本地 Compose 参考环境（从仓库根目录执行）：

```bash
docker compose -f deploy/compose/docker-compose.yml stop php-worker php-scheduler nginx
# php-app 仍提供服务时，先进入维护模式；确认命令可用后再停止 php-app
docker compose -f deploy/compose/docker-compose.yml exec -T php-app php artisan down --retry=60
docker compose -f deploy/compose/docker-compose.yml stop php-app
docker compose -f deploy/compose/docker-compose.yml ps
```

如果本地不是 Compose 而是 `php artisan serve`，先停止该进程，再用只读方式核对数据库文件；不要在有写入时直接 `cp` 数据库文件。

生产环境必须由网络/平台团队执行，以下为 **GATE** 检查清单，不是本仓库可以自动完成的命令：

- [ ] 在负载均衡/网关将新系统流量摘除或切到维护页。
- [ ] 停止 `php-worker`、`php-scheduler` 及所有外部回调消费者；确认队列无新的 DB 写入。
- [ ] 如需保留数据库进程，由 DBA 将目标库/账号置为只读或撤销写权限；确认没有长事务后继续。
- [ ] 记录停写时间（UTC）、操作人、工单号和当前连接数。
- [ ] 通知接口调用方/外部集成暂停重试，避免恢复后重复写入。

### 3.2 第 2 步：固化状态并生成快照

#### 通用元数据

```bash
incident_id="INC-YYYYMMDD-NNN"              # 替换为真实事故号
evidence_dir="build/ops/rollback-${incident_id}"
install -d "$evidence_dir"
date -u +%Y-%m-%dT%H:%M:%SZ | tee "$evidence_dir/timeline.txt"
git rev-parse HEAD | tee -a "$evidence_dir/timeline.txt"
git status --short | tee -a "$evidence_dir/timeline.txt"
```

不要把 `.env`、密码、Cookie、员工个人信息或银行/税务文件复制进证据目录；证据目录只保存命令、哈希、退出码、脱敏日志和签字记录。

#### SQLite（本仓库默认）

先确认 `php-app` 已停止（3.1），再复制；**写流量存在时不得直接 `cp`**。

```bash
snapshot_dir="$evidence_dir/sqlite"
install -d "$snapshot_dir"
source_db="$PWD/backend/database/database.sqlite"
snapshot="$snapshot_dir/pre-restore-$(date -u +%Y%m%dT%H%M%SZ).sqlite"

# 仅在应用已停写时执行。
cp -- "$source_db" "$snapshot"
sha256sum -- "$source_db" "$snapshot" | tee "$snapshot_dir/sha256.txt"
```

若必须在应用运行期间取 SQLite 一致快照，使用 SQLite online backup API（源连接只读），例如：

```bash
.tools/php/php -r '
  $s = new SQLite3($argv[1], SQLITE3_OPEN_READONLY);
  $s->enableExceptions(true);
  $d = new SQLite3($argv[2], SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
  $d->enableExceptions(true);
  $s->backup($d);
  $d->close(); $s->close();
' "$source_db" "$snapshot"
sha256sum -- "$snapshot"
```

取快照后立即用 drill 验证候选快照（drill 只操作临时副本，不会改快照本身）：

```bash
bash deploy/scripts/rollback_drill.sh \
  --database="$snapshot" \
  --budget-seconds=3600 \
  | tee "$snapshot_dir/rollback-drill.txt"
echo "rollback_drill_exit=${PIPESTATUS[0]}" | tee -a "$snapshot_dir/rollback-drill.txt"
```

> `rollback_drill.sh` 证明快照可以恢复并通过完整性/表检查，但**不会把快照替换到 `backend/database/database.sqlite`**。实际替换是 3.3 的人工步骤。

#### MySQL 8.4（生产目标；本地 Compose 未配置 binlog）

Compose 的 `mysql:8.4` 服务可用于本地全量 dump 演练，但 `deploy/compose/docker-compose.yml` 没有 `--log-bin`，因此**不能把本地 Compose 当作 PITR 已经可用的证据**。生产应使用托管 MySQL（优先）或主从 + binlog + 异地备份，具体基线见 `docs/adr/0011-deployment-observability.md` 与 D-25。

本地/受控环境的全量 dump（在容器内使用 Compose 注入的环境变量，避免把密码写进 shell 历史）：

```bash
mkdir -p "$evidence_dir/mysql"
docker compose -f deploy/compose/docker-compose.yml exec -T mysql \
  sh -eu -c 'MYSQL_PWD="$MYSQL_PASSWORD" mysqldump -u"$MYSQL_USER" \
    --single-transaction --quick --routines --triggers "$MYSQL_DATABASE"' \
  > "$evidence_dir/mysql/full-$(date -u +%Y%m%dT%H%M%SZ).sql"
sha256sum "$evidence_dir/mysql/"*.sql | tee "$evidence_dir/mysql/sha256.txt"
```

生产快照必须额外记录：

- 全量备份位置、校验和、加密/KMS 密钥引用（不记录密钥本身）。
- binlog 起始位点/GTID、目标恢复时点（UTC）、binlog 是否已异地归档。
- 数据库版本、字符集/排序规则、时区（Compose 为 `+00:00`）、应用 commit SHA。
- 备份是否与对象存储/附件版本匹配（S3/MinIO 的版本或快照由平台负责；本仓库无生产对象存储控制面）。

### 3.3 第 3 步：恢复数据库

#### SQLite 恢复（本地参考实现）

```bash
# 应用/worker 必须已停止；下面的“旧文件”保留，绝不删除。
restore_dir="$evidence_dir/restore"
install -d "$restore_dir"
restore_candidate="$restore_dir/restored.sqlite"
cp -- "$snapshot" "$restore_candidate"
sha256sum -- "$snapshot" "$restore_candidate" | tee "$restore_dir/sha256.txt"

# 验证候选恢复副本（只读连接）：
.tools/php/php -r '
  $d = new SQLite3($argv[1], SQLITE3_OPEN_READONLY);
  $d->enableExceptions(true);
  $d->exec("PRAGMA query_only=ON");
  $result = $d->querySingle("PRAGMA integrity_check");
  if ($result !== "ok") { fwrite(STDERR, "integrity_check=$result\n"); exit(1); }
  echo "integrity_check=ok\n";
  $d->close();
' "$restore_candidate"

live_db="$PWD/backend/database/database.sqlite"
pre_restore="$restore_dir/live-before-restore-$(date -u +%Y%m%dT%H%M%SZ).sqlite"
mv -- "$live_db" "$pre_restore"
cp -- "$restore_candidate" "$live_db"
sha256sum -- "$live_db" "$restore_candidate" | tee -a "$restore_dir/sha256.txt"
```

恢复后检查迁移历史和可读性（只读命令优先）：

```bash
cd backend
../.tools/php/php artisan migrate:status --no-interaction
../.tools/php/php artisan people:as-of-check --date="$(date -u +%F)"
cd ..
```

如果数据库与代码版本不匹配，停止继续切流；按 3.8 的发布版本对齐处理。**不要**用 `migrate:fresh`、`db:wipe` 或手工删除表“修复”恢复失败。

#### MySQL/PITR 恢复（GATE：DBA 在隔离实例执行）

以下命令是操作模板，不是本仓库已经执行过的结果：

```bash
# 1) 在隔离实例创建恢复库，不要直接覆盖生产库。
#    mysql/mysqladmin 的连接参数、TLS、账号和权限由 DBA 按生产基线提供。
mysql --defaults-extra-file=/secure/client.cnf -h "$RESTORE_HOST" -u "$RESTORE_USER" \
  -e 'CREATE DATABASE factoryhr_restore CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;'

# 2) 恢复全量 dump 到隔离库。
mysql --defaults-extra-file=/secure/client.cnf -h "$RESTORE_HOST" -u "$RESTORE_USER" \
  factoryhr_restore < "$evidence_dir/mysql/full-YYYYMMDDTHHMMSSZ.sql"

# 3) 以 UTC 目标时点重放 binlog；起止位点/GTID 必须来自事故记录。
mysqlbinlog \
  --start-datetime='YYYY-MM-DD HH:MM:SS' \
  --stop-datetime='YYYY-MM-DD HH:MM:SS' \
  /secure/binlog/binlog.000001 /secure/binlog/binlog.000002 \
  | mysql --defaults-extra-file=/secure/client.cnf -h "$RESTORE_HOST" -u "$RESTORE_USER" factoryhr_restore

# 4) 先用应用只读连接检查，再决定是否切换连接串。
cd backend
DB_CONNECTION=mysql DB_HOST="$RESTORE_HOST" DB_PORT=3306 \
DB_DATABASE=factoryhr_restore DB_USERNAME="$RESTORE_USER" DB_PASSWORD="$RESTORE_PASSWORD" \
../.tools/php/php artisan migrate:status --no-interaction
cd ..
```

要求：

- [ ] 全量恢复和 binlog 重放均在隔离实例完成，不直接覆盖仍可回退的生产库。
- [ ] 记录目标恢复时点与备份时点，计算实际 RPO；RPO > 15 分钟即升级。
- [ ] 校验 `GTID`/binlog 连续性、表数量、关键表计数和 `CHECK TABLE`（由 DBA 执行）。
- [ ] 恢复库通过下方健康检查、对账和最小 smoke 后才切换应用连接。
- [ ] 数据库版本/字符集/时区与生产一致；Compose 使用 `--default-time-zone=+00:00`，业务日期仍按法人时区计算。
- [ ] 回滚到旧代码时，数据库结构必须与该代码的迁移状态兼容；采用**前向迁移 + 快照恢复**，不要依赖破坏性 down migration。

`php artisan migrate:rollback-plan --run=<id>` 只生成 JSON 计划，输出明确写 `Executed: NO`；它不是生产回滚执行器。参见 `backend/app/Modules/Platform/Migration/Commands/MigrateRollbackPlanCommand.php` 与 `docs/runbooks/rollback.md` 本文件。

### 3.4 第 4 步：关闭高风险功能开关（GATE：逐路由核对）

可用 flag 名单来自 `backend/app/Modules/Platform/Features/FeatureFlagService.php`：

`payroll_enabled`、`time_enabled`、`self_service_enabled`、`recruitment_enabled`、`export_enabled`。

示例（`--reason` 至少 8 个字符；会写审计事件）：

```bash
cd backend
../.tools/php/php artisan feature:set payroll_enabled off --tenant=1 \
  --reason="INC-YYYYMMDD-NNN rollback: stop payroll exposure"
../.tools/php/php artisan feature:set time_enabled off --tenant=1 \
  --reason="INC-YYYYMMDD-NNN rollback: stop time exposure"
../.tools/php/php artisan feature:set self_service_enabled off --tenant=1 \
  --reason="INC-YYYYMMDD-NNN rollback: stop self-service exposure"
../.tools/php/php artisan feature:set recruitment_enabled off --tenant=1 \
  --reason="INC-YYYYMMDD-NNN rollback: stop recruitment exposure"
../.tools/php/php artisan feature:set export_enabled off --tenant=1 \
  --reason="INC-YYYYMMDD-NNN rollback: stop export exposure"
cd ..
```

必须核对的不是命令退出码，而是**实际路由效果**：

- [ ] 检查 `backend/routes/api.php` 中对应业务路由是否挂载 `feature:<flag>`；当前参考实现只有 `FeatureFlagService`/`EnsureFeatureEnabled`/`FeatureFlagTest` 的基础，不能假定所有业务路由已被 flag 覆盖。
- [ ] 对已挂载路由，在关闭 flag 后请求应返回 `404 NOT_FOUND`，并写 `feature_flag.blocked` 审计；`FeatureFlagTest` 覆盖该行为。
- [ ] 对未挂载 flag 的路由，必须依赖路由/负载均衡切回旧系统，不能把“flag 已关闭”当作“业务已下线”。
- [ ] 记录每次 flag 变更的 actor、reason、时间、前后值和审计事件；未通过 `feature:set`/受权 API 的数据库直改不符合审计要求。

### 3.5 第 5 步：路由切回旧系统（GATE：仓库无生产控制面）

本仓库没有生产负载均衡、DNS、网关或旧系统控制面，因此**不提供看似可执行的虚构命令**。由网络/平台团队按已批准切换单执行，并记录：

- [ ] 旧系统仍保留只读窗口（D-21 要求 60–90 天，具体截止日 GATE）；旧系统可写前先确认数据边界和重复写入防护。
- [ ] 关闭新系统写流量；确认 3.1 的停写状态仍然有效。
- [ ] 在负载均衡/网关/DNS 将用户流量切回旧系统；记录切换前后 VIP/域名/目标组/版本。
- [ ] 旧系统健康检查与关键用户 smoke 通过：登录、员工查询、组织树、合同查看、假勤查询、工资查看（只读边界按波次决定）。
- [ ] 通知用户“当前在旧系统只读/可写”的实际状态，不宣称新系统已验证。
- [ ] 若新系统仍接受外部回调，暂停或重放到隔离队列；防止旧系统恢复后双写。
- [ ] 直接查数据库/日志核对没有新系统写入继续发生；确认后才算切流完成。

新系统健康检查（在可访问域名上；生产实际主机名由 GATE 提供）：

```bash
curl -fsS https://hrms.example.invalid/v1/health/live
curl -fsS https://hrms.example.invalid/v1/health/ready
curl -fsS https://hrms.example.invalid/v1/health
```

本地 Compose 可用：

```bash
curl -fsS http://127.0.0.1:8080/v1/health/live
curl -fsS http://127.0.0.1:8080/v1/health/ready
curl -fsS http://127.0.0.1:8080/v1/health
```

语义必须区分（见 `backend/app/Modules/Platform/Http/Controllers/HealthController.php`、`ReadinessController.php` 和 `backend/tests/Feature/HealthProbeTest.php`）：

- `/v1/health/live`：只证明进程可响应，不访问 DB/cache/storage。
- `/v1/health/ready`：DB、cache、storage 全部可用才 200；任一失败为 503。
- `/v1/health`：DB 失败为 503；cache/storage 失败可为 200 `degraded`。不能用 legacy 200 代替 readiness 通过。

### 3.6 第 6 步：恢复后对账（在此之前不得宣布回滚完成）

先对恢复目标执行只读检查；`payroll:parallel-check` 没有旧系统导出文件时退出码 2，必须标 **NOT_EXECUTED**，不得写成通过。

```bash
cd backend
../.tools/php/php artisan people:as-of-check --date="$(date -u +%F)"
../.tools/php/php artisan ledger:reconcile --as-of="$(date -u +%F)" --tenant=1
../.tools/php/php artisan payroll:reconciliation --period=YYYY-MM
../.tools/php/php artisan payroll:parallel-check --period=YYYY-MM \
  --old-file=/secure/exports/old-payroll-YYYY-MM.csv
../.tools/php/php artisan migrate:reconcile --tenant=1
../.tools/php/php artisan retention:preview --all --tenant=1
cd ..
```

对账口径（全部要求差异为 0，或差异有书面解释并经业务 owner 签字）：

| 维度 | 最低核对内容 | 命令/证据 |
|---|---|---|
| 人数 | 按法人/组织/状态的在职人数；离职/未来生效边界 | SQL 只读计数 + `people:as-of-check` + HR 签字 |
| 组织 | 组织单元数、父子关系、closure、有效区间 | SQL/报表 + `migrate:reconcile`（有迁移 run 时） |
| 合同 | `employment_terms` 版本、有效期、到期数 | SQL 只读查询 + 合同 owner 抽样签字 |
| 假勤 | 期初 + 发生 = 期末；未解释差异 0 | `ledger:reconcile --as-of=... --tenant=1`，退出 0 |
| 工资总额 | 人头、应发、扣款、实发、雇主成本、更正链 | `payroll:reconciliation --period=...`；`payroll:parallel-check`（有旧导出时） |
| 工资条/封存 | 已发布工资条不可变；更正链可追溯 | `CorrectionRunTest`、`ReconciliationService`、财务签字 |
| 审计/保留 | 审计可查、法务保全优先、删除 dry-run | `retention:preview --all --tenant=1`；删除必须另行审批 |

SQL 只读抽查示例（在恢复目标库执行；把 `:as_of` 替换为真实业务日，使用只读账号）：

```sql
-- 在职人数（按法人）
SELECT legal_entity_id, COUNT(*) AS active_headcount
FROM employments
WHERE tenant_id = 1
  AND status IN ('ACTIVE', 'PROBATION')
  AND valid_from <= :as_of
  AND valid_to > :as_of
GROUP BY legal_entity_id;

-- 有效组织单元数
SELECT COUNT(*) AS active_org_units
FROM org_units
WHERE tenant_id = 1
  AND status = 'ACTIVE'
  AND valid_from <= :as_of
  AND valid_to > :as_of;

-- 有效合同版本数（按雇佣关系去重）
SELECT COUNT(DISTINCT employment_id) AS employees_with_active_terms
FROM employment_terms
WHERE tenant_id = 1
  AND effective_from <= :as_of
  AND effective_to > :as_of;

-- 工资批次原始合计（更正链以 payroll:reconciliation 为准）
SELECT period, COUNT(*) AS run_count,
       SUM(gross_cents) AS gross_cents,
       SUM(deductions_cents) AS deductions_cents,
       SUM(net_cents) AS net_cents,
       SUM(employer_cost_cents) AS employer_cost_cents
FROM payroll_runs
WHERE tenant_id = 1
  AND status IN ('PUBLISHED', 'PAID', 'CLOSED')
GROUP BY period
ORDER BY period;
```

**GATE**：SQL 数字只能发现差异，不能替代业务签字。人数/组织/合同由 HR 数据 owner 按 S-06 确认；工资总额、税务/银行回执由财务按 S-05 确认。姓名、签字、日期必须由组织在真实文档中填写。

### 3.7 第 7 步：不可逆事项与工资更正（强制规则）

**规则：已经 finalized/published 的工资批次和已发布工资条，永远不得通过回滚删除、覆盖或修改历史；只能创建更正批次（correction run），并保留原批次。** 代码约束见：

- `backend/app/Modules/Payroll/Services/CorrectionService.php`：更正只能从 `PUBLISHED`/`PAID`/`CLOSED` 原批次创建；差额对原批次及此前已发布更正计算。
- `backend/app/Modules/Payroll/Services/ReopenService.php`：只有计算/复核/待批准阶段可按控制重开；已发布/已付款/已关账必须更正，`reopen` 会被拒绝。
- `backend/app/Modules/Payroll/Models/PayrollRun.php`、`Payslip.php`：封存财务事实和已发布工资条不可变。
- `backend/tests/Feature/Payroll/CorrectionRunTest.php`：覆盖差额、链式更正、已发布原批次约束。

受权更正流程（HTTP 路由来自 `backend/routes/api.php`；实际域名/会话/MFA 由 GATE 提供）：

```bash
# 1) 从已发布/已付款/已关账原批次创建更正批次；reason >= 10 字符。
curl -fsS -X POST "https://hrms.example.invalid/v1/payroll/runs/<original-run-id>/corrections" \
  -H "Cookie: hrms_session=<operator-session>" \
  -H "X-CSRF-TOKEN: <csrf-token>" \
  -H "Content-Type: application/json" \
  -d '{"period":"YYYY-MM","reason":"INC-YYYYMMDD-NNN correction after rollback reconciliation"}'

# 2) 按状态机完成：lock-inputs -> calculate -> review -> approve -> publish。
#    每次受权操作都需要相应 capability、MFA step-up、幂等键和审计记录。
curl -fsS -X POST "https://hrms.example.invalid/v1/payroll/runs/<correction-run-id>/lock-inputs" \
  -H "Cookie: hrms_session=<operator-session>" -H "X-CSRF-TOKEN: <csrf-token>"
curl -fsS -X POST "https://hrms.example.invalid/v1/payroll/runs/<correction-run-id>/calculate" \
  -H "Cookie: hrms_session=<operator-session>" -H "X-CSRF-TOKEN: <csrf-token>"
```

复核、批准、发布必须由不同责任人按职责分离完成；发布后执行：

```bash
cd backend
../.tools/php/php artisan payroll:reconciliation --period=YYYY-MM
../.tools/php/php artisan payroll:parallel-check --period=YYYY-MM \
  --old-file=/secure/exports/old-payroll-YYYY-MM.csv
cd ..
```

其他不可逆事项同样走更正/人工流程，不得删除历史：

| 事项 | 回滚后的处理 |
|---|---|
| 已发工资/银行付款 | 创建更正批次；银行侧按银行流程冲正/补发；财务签字 |
| 已报税/税务回执 | 税务更正/补充申报由财务/税务责任人执行；不删除工资记录 |
| 已签合同 | 保留合同版本；通过新版本/补充协议纠正，法务确认 |
| 已发通知/Offer/绩效确认 | 保留发送记录；补发更正通知或撤回流程，业务 owner 确认 |
| 已确认假期/已结算 | 保留账本分录；用冲正/调整分录修正，账本只追加 |
| 外部系统回执 | 保留回执；以对账和人工工单闭环，禁止伪造/删除回执 |

### 3.8 第 8 步：发布版本与数据库结构对齐

- 恢复的数据库必须对应明确的代码 commit；记录代码 SHA、迁移状态、配置版本。
- 本仓库迁移是**forward-only**：不要为回滚修改已合并迁移，也不要假设 `migrate:rollback` 能还原生产结构。
- 如果回滚到旧代码而数据库已包含新迁移，先由技术负责人评估兼容性；必要时使用恢复快照而不是 down migration。
- `php artisan migrate:rollback-plan --run=<id>` 只输出计划；执行前必须有快照、停写、双人批准和 DBA 复核。
- 发布包、Compose 镜像 tag、`APP_VERSION` 与 OpenAPI 契约必须一起记录；不要只回滚镜像而保留不兼容的新结构。

### 3.9 第 9 步：退出条件与宣布

只有以下全部满足才可宣布“回滚完成”：

- [ ] 停写期间没有新的业务写入；旧系统/恢复目标没有双写。
- [ ] 数据库快照 hash、恢复 hash、完整性检查和预期表检查通过（SQLite 以 `rollback_drill.sh` 输出为准）。
- [ ] MySQL 恢复/PITR 在隔离实例完成并由 DBA 签字（生产必需，GATE）。
- [ ] `/v1/health/live`、`/v1/health/ready` 和核心 smoke 通过；`/v1/health` 的 degraded 没有被误报为 ready。
- [ ] 人数/组织/合同/假勤/工资对账 0 未解释差异，或差异已有书面解释和 owner 签字。
- [ ] 已封存工资/工资条/合同/通知/回执没有被删除；更正批次已建立并有状态机记录。
- [ ] 用户、客服、财务、HR、外部接口方收到准确通知（当前系统、只读/可写、预计恢复）。
- [ ] 证据已归档，事故单和复盘已建立。

## 4. 证据清单（E-13 / E-15 可引用）

| 证据 | 生成方式 | 责任人/状态 |
|---|---|---|
| 回滚脚本输出 + exit code + elapsed | `bash deploy/scripts/rollback_drill.sh`，保存完整 stdout/stderr | DevOps；本地证据可重跑 |
| 小预算 FAIL 证据（证明预算门禁有效） | `bash deploy/scripts/rollback_drill.sh --budget-seconds=1`，预期 exit 1 | DevOps；记录为负向测试 |
| 源/快照/恢复 sha256、integrity/表计数 | 脚本输出保存到 `build/ops/rollback-<incident>/` | DevOps |
| MySQL 全量/PITR 恢复日志、目标时点、RTO/RPO | 隔离实例执行；不能由本地脚本代替 | **GATE**：DBA/DevOps |
| 功能开关变更与审计 | `php artisan feature:set ...` 输出、`/v1/platform/features` 或审计查询 | **GATE**：平台 owner |
| 路由切换记录与用户 smoke | 负载均衡/网关/DNS 控制台截图/变更单；curl 结果 | **GATE**：网络/发布经理 |
| 人数/组织/合同/假勤/工资对账 | 命令输出 + SQL 只读导出 + owner 签字 | **GATE**：HR/财务/数据 owner |
| 工资更正链 | 原批次、更正批次 id、状态机审计、`payroll:reconciliation` | **GATE**：财务/薪酬 |
| 安全审计样本（登录/撤权/导出/break-glass） | E-15 证据包；测试与审计查询 | **GATE**：安全 |

建议保存：

```bash
bash deploy/scripts/rollback_drill.sh 2>&1 | tee "$evidence_dir/rollback-drill.txt"
printf 'exit=%s\n' "${PIPESTATUS[0]}" | tee -a "$evidence_dir/rollback-drill.txt"
cd backend
../.tools/php/php artisan migrate:status --no-interaction \
  | tee "$evidence_dir/migrate-status.txt"
../.tools/php/php artisan ledger:reconcile --as-of="$(date -u +%F)" --tenant=1 \
  | tee "$evidence_dir/ledger-reconcile.txt"
cd ..
```

E-13 的“脚本输出 + 时长”只能证明脚本覆盖的范围；生产验收还必须附 MySQL/PITR、路由切换、功能开关和对账证据。E-15 的登录、撤权、导出、工资封存、break-glass 样本不能由回滚脚本代替，需按 `docs/10-joint-audit-acceptance.md` 单独取证。

## 5. 复盘与关闭

- 事故复盘必须在回滚后 5 个工作日内完成；Sev1/Sev2 需技术负责人、业务 owner、安全/财务按影响参加（具体组织时限 GATE）。
- 复盘至少回答：触发信号是否及时、停写是否彻底、RTO/RPO 实际值、是否发生双写、不可逆事实如何处理、哪些证据缺失。
- 修复项必须有 owner、截止日期和验证方式；涉及工资/账本/权限的修复不得以“下一次上线再说”关闭。
- 若回滚暴露出备份不可用、PITR 不可用、路由切换不可控，必须在 Hypercare 退出前补齐演练；见 `docs/runbooks/hypercare.md`。

## 6. 快速检查卡（打印版）

```text
[ ] Sev 已分级，IC/技术/业务/财务（工资）已召集；双人复核已指定
[ ] 停写：HTTP、worker、scheduler、外部回调、批处理全部停止
[ ] 快照：源 hash、快照 hash、时间、commit、binlog/备份位置已记录
[ ] 候选恢复已验证；SQLite drill 或 MySQL 隔离恢复通过
[ ] 高风险 flag 关闭；逐路由确认实际 404/切流，不只看命令返回
[ ] 路由切回旧系统/稳定版本；旧系统只读边界明确
[ ] live/ready 健康检查通过；没有把 degraded 当 ready
[ ] 人数/组织/合同/假勤/工资对账 0 未解释差异（或书面解释）
[ ] 已 finalized 工资/工资条/合同/通知/回执未被删除；更正流程已启动
[ ] 证据归档、通知完成、复盘排期；GATE 签字待组织完成
```
