# FactoryHR V2.4 T18｜Playwright MCP 人工验收 Runbook

- **状态：** RUNBOOK READY；MCP 尚未执行
- **日期：** 2026-11-28
- **适用范围：** T18 全后台 21 模块、37 当前页面、155 历史入口、458 张源包功能卡、12 个跨模块闭环；源包存在但**权威性待确认**
- **禁止：** 用 mock、SQLite、自动 Playwright test、`--list`、OpenAPI lint、API 调用记录或代码审阅替代人工 MCP 证据
- **配套文件：** `21-module-mcp-acceptance-matrix.md`、`458-card-index.md`、`evidence-template.md`、`evidence-index.md`、`gap-log.md`

---

## 1. 前提与硬性边界

### 1.1 允许开始的硬条件

以下任何一项不满足，T18 不得开始；先记 `BLOCKED`，不要用临时替代骗取 PASS：

1. 用户已确认要进入 T18 的人工验收；本轮资产工作不得自动执行验收。
2. 真实前端以 `VITE_API_MODE=http` 启动，且页面加载的是真实 Laravel `/v1`，不是 mock。
3. 隔离 MySQL、Redis、S3/MinIO 对象存储已启动，并使用**本 worktree/本批次专用**的 Compose project 与数据库；不得复用其他 worktree 或主工作区栈。
4. 干净安装完成：空库执行 `hrms:install` 后，只有一个启用管理员，零员工、零工资批次、零流程案例。
5. 已准备 R01–R09 共 9 个验收角色：**R01–R07 为 7 个核心角色，R08–R09 为 2 个补充角色**。其中 R03/R04/R05/R08 的锚点构造当前 `BLOCKED`（见 §3.1），T18 不得用替代命令绕过。
6. 角色构造：R01/R06/R07/R09 可完整构造；R02 仅 COMPANY 部分可构造、CASE 审批 `BLOCKED`；R03/R04/R05/R08 因无法写 `ORG_UNIT`/`PAYROLL_RUN`/`CASE` anchor_id 而 `BLOCKED`，见 §3.1/§3.4。
7. 测试数据全部合成，明显虚构；禁止真实姓名、证件、手机号、工资、公司信息。
8. 操作人明确知道每一步对应矩阵的证据 ID，并能在同一浏览器会话记录 URL、角色、前置数据、动作、网络响应、截图/snapshot。

### 1.2 明确禁止

- 禁止以 `VITE_API_MODE=mock` 执行任何 T18 步骤。
- 禁止用 SQLite 作为 T18 验收库。
- 禁止把自动 Playwright specs 的结果复制成人工证据；自动测试只能作为前置门禁。
- 禁止通过 `curl`/API 调用、SQL、日志或代码审阅证明“UI 已工作”。
- 禁止在证据未生成时写 PASS；禁止用“应该”“理论上”“已实现”代替实际观察。
- 禁止跨 worktree 复用正在运行的其他分支容器、数据库、Redis 或对象存储桶。
- 禁止执行会修改主工作区 `D:\code\ai\factoryHR` 的脚本或写测试。
- 禁止把密码、session cookie、CSRF token、TOTP secret、恢复码、真实个人数据写入文档或截图。

### 1.3 当前已知前置阻塞（开始时先核对）

| 阻塞 | 影响 | 处理 |
|---|---|---|
| 458 卡源包存在但权威性待确认 | AC-01/AC-21 的逐卡验收不能把草稿当批准需求 | 458 个唯一卡 ID/标题已提取到 `458-card-index.md`；验收执行时逐卡结论仍为 `NOT RUN`，并注明源包“待审批/待确认” |
| T05–T17 在 master plan 仍为 PENDING | T18 的依赖不成立 | MCP 业务执行必须 `BLOCKED`；不得以静态审计或自动测试代替 |
| T06 计划表 PENDING 与 `06-time-facts/implementation-report.md` COMPLETE 冲突 | M09–M12 前置结论不唯一 | 先核实并统一状态；未统一前相关执行项 `BLOCKED` |
| T20–T24 无任务卡 | AC-01/AC-21 的 T01–T24 唯一覆盖不成立 | 保留目标口径；标 `UNMAPPED`；等待权威任务卡或修订 |
| R02 CASE 审批 + R03/R04/R05/R08 无法构造 | HR_ADMIN 的 CASE 审批、考勤专员、薪酬专员、薪酬复核人、招聘专员无法用现有 UI/CLI 写入 `CASE`/`ORG_UNIT`/`PAYROLL_RUN` anchor_id | 公司级 R02 可执行；M20 CASE 审批与 R03/R04/R05/R08 明确 `BLOCKED`；依赖 T02/T19 增加 anchor binding UI 或命令；不得用 `iam:bind-role`/`UserRoleDrawer` 假构造 |
| M21 无备份/恢复/升级 UI | 该 6 卡中的安装/升级/备份恢复不能做 MCP | 转 T19 运维证据；T18 标 `BLOCKED/UNMAPPED`，不得用 API 代替 |
| M17 员工档案无培训记录 Tab | E2E-09/M17 卡片不可执行 | 标 `UNMAPPED/BLOCKED`；不得新造入口 |
| M09 无本人打卡写端点 | “打卡”入口可能不可执行 | 导航确认；无入口则标 `UNMAPPED`，不伪造 API |
| M08 manifest 声明 activate 但详情页无按钮 | 合同激活 UI 项不可执行 | 标 `UNMAPPED/BLOCKED`；不得以 API 代替 UI |

---

## 2. 环境准备

### 2.1 隔离命名

为本批次选择唯一前缀，建议：

```text
COMPOSE_PROJECT_NAME=factoryhr-t18
DB_DATABASE=factoryhr_t18
AWS_BUCKET=factoryhr-t18-private
HTTP_PORT=5184
T18_FRONTEND_PORT=5185
```

要求：

- 不得使用默认 `factoryhr` Compose project；如现有 compose 文件未参数化 project，使用 `COMPOSE_PROJECT_NAME=factoryhr-t18` 或 `docker compose -p factoryhr-t18`。
- MySQL、Redis、MinIO 数据卷必须是本批次新卷；不得挂载其他 worktree 的卷。
- `.env` 必须被 `.gitignore` 排除（当前仓库已忽略 `backend/.env`、`deploy/compose/.env`）；禁止提交。
- 记录 `docker compose -p factoryhr-t18 ps`、卷名和端口占用。

### 2.2 代码与构建

只允许在 `D:\code\ai\factoryHR-worktrees\t18-acceptance-matrix` 操作：

```bash
git rev-parse HEAD
git status --porcelain
cd frontend
pnpm install --frozen-lockfile
VITE_API_MODE=http pnpm build
```

记录：

- HEAD；
- 是否有未提交变更；
- 构建输出的 `SPA_BUILD_SHA` / `GIT_SHA`（如果容器构建注入）；
- 构建日志路径；
- **不得**运行 `pnpm gate:all` 作为本任务动作；T18 只要求资产与 MCP 执行，不替代 T19 门禁。

### 2.3 真实依赖、php-app 与代理启动

以下命令使用本批次 Compose project `factoryhr-t18`。**`php-app` 是 FPM，不能直接给浏览器访问；必须同时启动 nginx（Compose 读 `${HTTP_PORT:-5174}`，本批次显式 `export HTTP_PORT=5184`）或按 §2.3.4 改用宿主机 `php artisan serve --port=8000`（仅备选，主路径仍是 nginx 5184）。** 所有命令都在本 worktree 根目录执行。

#### 2.3.1 启动 MySQL 8.4 / Redis / MinIO

```bash
export COMPOSE_PROJECT_NAME=factoryhr-t18
export HTTP_PORT=5184
export T18_FRONTEND_PORT=5185
export DB_DATABASE=factoryhr_t18
export DB_USERNAME=factoryhr_t18
export DB_PASSWORD='<synthetic-db-password>'
export MYSQL_ROOT_PASSWORD='<synthetic-root-password>'
export MINIO_ROOT_USER='factoryhr-t18'
export MINIO_ROOT_PASSWORD='<synthetic-minio-password>'
export AWS_BUCKET='factoryhr-t18-private'

docker compose -p "$COMPOSE_PROJECT_NAME" -f deploy/compose/docker-compose.yml config --quiet

docker compose -p "$COMPOSE_PROJECT_NAME" -f deploy/compose/docker-compose.yml   up -d mysql redis minio

docker compose -p "$COMPOSE_PROJECT_NAME" -f deploy/compose/docker-compose.yml   ps mysql redis minio
```

等待三个服务 `healthy`（`docker compose ps` 的 Health 列），再执行**一次性** `minio-init`：

```bash
# 一次性任务：创建私有 bucket，运行后容器退出；不要对已退出容器使用 exec。
docker compose -p "$COMPOSE_PROJECT_NAME" -f deploy/compose/docker-compose.yml   up -d minio-init

docker compose -p "$COMPOSE_PROJECT_NAME" -f deploy/compose/docker-compose.yml   logs --no-color minio-init | tee /tmp/t18-minio-init.log

# 必须看到 bucket ready: factoryhr-t18-private
docker compose -p "$COMPOSE_PROJECT_NAME" -f deploy/compose/docker-compose.yml   ps -a minio-init
```

如果 `minio-init` 已退出且日志没有 `bucket ready`，重新执行一次 `up -d minio-init`（它是幂等的），不要 `exec` 退出的容器。

验证存储与依赖：

```bash
# MySQL 8.4
docker compose -p "$COMPOSE_PROJECT_NAME" -f deploy/compose/docker-compose.yml   exec -T mysql mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e 'SELECT VERSION();'

# Redis
docker compose -p "$COMPOSE_PROJECT_NAME" -f deploy/compose/docker-compose.yml   exec -T redis redis-cli PING

# MinIO 内的 bucket 必须存在且 private
docker compose -p "$COMPOSE_PROJECT_NAME" -f deploy/compose/docker-compose.yml   exec -T minio sh -lc 'mc alias set local http://127.0.0.1:9000 "$MINIO_ROOT_USER" "$MINIO_ROOT_PASSWORD" >/dev/null && mc ls local'
```

#### 2.3.2 启动 php-app 和 nginx

```bash
docker compose -p "$COMPOSE_PROJECT_NAME" -f deploy/compose/docker-compose.yml   up -d php-app php-worker nginx

docker compose -p "$COMPOSE_PROJECT_NAME" -f deploy/compose/docker-compose.yml   ps php-app php-worker nginx
```

`php-app` 必须为 `healthy`，nginx 才能代理 `/v1`。浏览器和 MCP 访问：

```text
SPA/API 同源入口：http://127.0.0.1:5184
API 健康：http://127.0.0.1:5184/v1/health/live
API 就绪：http://127.0.0.1:5184/v1/health/ready
```

本批次 `php-app` 的环境必须满足：

- `DB_CONNECTION=mysql`，`DB_HOST=mysql`，`DB_DATABASE=factoryhr_t18`；
- `CACHE_STORE=redis`、`QUEUE_CONNECTION=redis`、`SESSION_DRIVER=redis`；
- `FILESYSTEM_DISK=s3`，`AWS_ENDPOINT=http://minio:9000`，`AWS_BUCKET=factoryhr-t18-private`；
- `APP_TIMEZONE=UTC`，公司时区在安装时显式输入；
- `IAM_MFA_ENABLED=false` 是默认验收配置；只有单独验证 MFA 可选路径时才临时改 true，并单独记录，不得混入主流程；
- 若用 Vite dev server（§2.3.3），`HRMS_CORS_ALLOWED_ORIGINS` 必须包含 `http://127.0.0.1:5185,http://localhost:5185`。

#### 2.3.3 前端 dev server、CORS 与 `VITE_DEV_API_PROXY_TARGET`

如果前端用 Vite dev server，浏览器打开 `http://127.0.0.1:5185`，代理必须指向本批次 nginx：

```bash
cd frontend
VITE_API_MODE=http VITE_API_BASE_URL=/v1 VITE_DEV_API_PROXY_TARGET=http://127.0.0.1:5184 pnpm dev --host 127.0.0.1 --port 5185
```

固定规则：

- Vite 代理变量名是 **`VITE_DEV_API_PROXY_TARGET`**（`frontend/vite.config.ts`）；
- `frontend/.env.example` 里的 `VITE_PROXY_TARGET` 是过期/不一致名称，不能使用（GAP-14）；
- 后端 `HRMS_CORS_ALLOWED_ORIGINS` 必须包含 `http://127.0.0.1:5185` 与 `http://localhost:5185`；
- 不允许用 `*`；cookie 是 credentialed（`supports_credentials=true`），CORS 必须精确白名单；
- 不得把代理指向其他 worktree 的 8000/5174。

#### 2.3.4 备选：宿主机 `php artisan serve --port=8000`

只有需要 Laravel 内置服务器/调试时才使用。此时必须给 Compose 的 MySQL/Redis/MinIO 发布宿主机端口，并用独立的 override 文件，不得改默认 compose：

```bash
# deploy/compose/docker-compose.t18-serve.yml（一次性、不提交；示例）
services:
  mysql:
    ports: ["13306:3306"]
  redis:
    ports: ["16379:6379"]
  minio:
    ports: ["19000:9000", "19001:9001"]
```

```bash
docker compose -p "$COMPOSE_PROJECT_NAME"   -f deploy/compose/docker-compose.yml   -f deploy/compose/docker-compose.t18-serve.yml   up -d mysql redis minio

# backend/.env 使用宿主机地址（示例，不得提交）
# DB_HOST=127.0.0.1 DB_PORT=13306 REDIS_HOST=127.0.0.1 REDIS_PORT=16379
# AWS_ENDPOINT=http://127.0.0.1:19000

cd backend
php artisan migrate --force
php artisan serve --host=127.0.0.1 --port=8000
```

此时前端：

```bash
cd frontend
VITE_API_MODE=http VITE_API_BASE_URL=/v1 VITE_DEV_API_PROXY_TARGET=http://127.0.0.1:5184 pnpm dev --host 127.0.0.1 --port 5185
```

后端 CORS 仍必须包含 `http://127.0.0.1:5185`，`APP_URL=http://127.0.0.1:8000`（或实际对外地址）。如果无法发布依赖端口或无法保持隔离，**不要使用本备选**，回到 §2.3.2 的 php-app+nginx 路径。

#### 2.3.5 端口变量一致性核对（执行前逐条检查）

Compose 实际读取的是 **`HTTP_PORT`**，不是 `T18_HTTP_PORT`。必须执行：

```bash
export COMPOSE_PROJECT_NAME=factoryhr-t18
export HTTP_PORT=5184
export T18_FRONTEND_PORT=5185
export VITE_DEV_API_PROXY_TARGET=http://127.0.0.1:5184

printf 'HTTP_PORT=%s\n' "$HTTP_PORT"
printf 'proxy=%s\n' "$VITE_DEV_API_PROXY_TARGET"
docker compose -p "$COMPOSE_PROJECT_NAME" -f deploy/compose/docker-compose.yml config   | grep -E 'published: "?5184|HTTP_PORT' || true
```

逐条核对：

| 项 | 必须是 | 不允许 |
|---|---|---|
| Compose nginx 发布端口 | `${HTTP_PORT:-5174}` 被设为 `5184` | 使用 `T18_HTTP_PORT` 而不导出 `HTTP_PORT` |
| SPA 同源入口 | `http://127.0.0.1:5184` | `http://127.0.0.1:8000`（除非走 §2.3.4 备选且已改全部命令） |
| `/v1/health/live`、`/v1/health/ready`、`/v1/meta` | `http://127.0.0.1:5184/...` | 混用 8000/5174/5184 |
| Vite dev server | `http://127.0.0.1:5185` | 5173/5174 |
| Vite proxy | `VITE_DEV_API_PROXY_TARGET=http://127.0.0.1:5184` | 8000/其他 worktree |
| CORS | `HRMS_CORS_ALLOWED_ORIGINS` 含 `http://127.0.0.1:5185,http://localhost:5185` | `*` 或漏掉 5185 |

#### 2.3.6 健康检查顺序

必须按顺序记录，前一步失败不得跳下一步：

1. `docker compose ps`：mysql/redis/minio/php-app/nginx 均为 `healthy`，`minio-init` 已成功退出；
2. liveness：`curl -fsS http://127.0.0.1:5184/v1/health/live`，期望 `status=ok`；
3. 依赖直连：MySQL `SELECT VERSION()`、Redis `PING`、MinIO bucket 存在且 private；
4. 迁移：`php artisan migrate --force` 退出码 0；
5. 安装：`php artisan hrms:install ...` 退出码 0；
6. readiness：`curl -fsS http://127.0.0.1:5184/v1/health/ready`，期望 HTTP 200 且 `db/cache/storage/migrations/installation/administrator` 全为 `true`；
7. `/v1/meta`：commit、build_time、spa_build_sha 与本次构建记录一致；
8. 前端 `/login` 可打开且网络请求真实打到本批次 `/v1`（不是 mock）。

```bash
curl -fsS http://127.0.0.1:5184/v1/health/live
curl -fsS http://127.0.0.1:5184/v1/health/ready
curl -fsS http://127.0.0.1:5184/v1/meta
```

### 2.4 干净安装

先确认目标数据库为空（或明确执行 reset，但必须记录这是**本批次隔离库**）：

```bash
# 示例：删除并重建本批次数据库；路径/库名必须已核对
docker compose -p factoryhr-t18 exec -T mysql mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "
  DROP DATABASE IF EXISTS \`factoryhr_t18\`;
  CREATE DATABASE \`factoryhr_t18\` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
"
```

安装：

```bash
docker compose -p factoryhr-t18 exec -T php-app php backend/artisan migrate --force

docker compose -p factoryhr-t18 exec -T php-app php backend/artisan hrms:install \
  --company-code=T18-CN \
  --company-name="T18 Synthetic Company" \
  --country=CN \
  --timezone=Asia/Shanghai \
  --currency=CNY \
  --admin-username=t18.installer \
  --admin-email=t18.installer@example.test \
  --admin-password="$T18_ADMIN_PASSWORD" \
  --enable=payroll,time,self_service,recruitment,export
```

记录：

- 安装退出码；
- 管理员数=1；
- 员工数=0；
- 工资批次数=0；
- 流程案例数=0；
- 重复执行安装的幂等结果；
- 生产拒绝 demo/reset 的实测（若本地环境可切换 `APP_ENV=production`，则只记录该测试，不得污染验收库；不能切换则写 `NOT RUN`）。

**不得**在干净安装验收完成前运行 demo seed；demo seed 属另一条可重复合成前置路径，必须明确区分。

---

## 3. 账号与测试数据合成

### 3.1 角色账号（R01–R09：7 核心 + 2 补充）

| 代号 | 角色 | 需要锚点 | 计划账号 | 构造状态与依赖 |
|---|---|---|---|---|
| R01 | `SYSTEM_ADMIN` | COMPANY | `t18.admin` | 可由 `hrms:install` 管理员承担；COMPANY 不需要 anchor_id |
| R02 | `HR_ADMIN` | COMPANY + CASE（`workflow.case.decide`） | `t18.hr` | **部分可构造**：COMPANY 授权可由 `iam:bind-role` 绑定；CASE 审批无法写 anchor_id，M20 CASE 审批 `BLOCKED` |
| R03 | `ATTENDANCE_SPECIALIST` | ORG_UNIT + COMPANY | `t18.attendance` | **BLOCKED**：需要 ORG_UNIT anchor_id；现有命令/UI 都不能写 |
| R04 | `PAYROLL_SPECIALIST` | PAYROLL_RUN + COMPANY | `t18.payroll` | **BLOCKED**：需要 PAYROLL_RUN anchor_id；现有命令/UI 都不能写 |
| R05 | `PAYROLL_APPROVER` | PAYROLL_RUN + COMPANY | `t18.approver` | **BLOCKED**：需要 PAYROLL_RUN anchor_id；现有命令/UI 都不能写 |
| R06 | `LINE_MANAGER` | MANAGED | `t18.manager` | 可创建用户后由 `iam:bind-role` 绑定；MANAGED 不需要 anchor_id；需 A/B 直接下属 |
| R07 | `EMPLOYEE` | SELF | `t18.employee` | 可创建用户后由 `iam:bind-role` 绑定；SELF 不需要 anchor_id；需绑定 A 的 employment |
| R08 | `RECRUITER` | ORG_UNIT + CASE | `t18.recruiter` | **BLOCKED**：需要 ORG_UNIT/CASE anchor_id；现有命令/UI 都不能写 |
| R09 | `AUDITOR` | COMPANY | `t18.auditor` | 可创建用户后由 `iam:bind-role` 绑定；仅 COMPANY 锚点，可执行 |

**R02 例外：** `HR_ADMIN` 不是“仅 COMPANY”。它包含 `workflow.case.decide` 的 CASE 锚点；`iam:bind-role` 不写 anchor_id、`UserRoleDrawer` 不传 `binding_anchors`，所以 R02 的公司级能力可构造，但 M20 的 HR CASE 审批不可构造，必须 `BLOCKED`，依赖 T02/T19 的 anchor binding UI/命令；不得用 SYSTEM_ADMIN 或其他角色冒充。

**口径：** R01–R07 是 7 个核心角色，R08–R09 是 2 个补充角色；`t18.unlinked-admin` 是 R01 的**变体**（COMPANY、无 employment link），不是 R10。`HR_SPECIALIST`、`TRAINING_ADMIN`、`BENEFITS_ADMIN`、`REPORT_VIEWER` 只作为模块可选代表角色，不计入 R01–R09 的 9 个验收角色；若临时使用，必须单独记录账号/锚点/证据，不能改变核心口径。

**不可执行替代步骤（明确禁止）：**

- `iam:bind-role` 的签名只有 `{--user=} {--role=} {--days=}`；其实现把每个 grant 的 `anchor_id` 写成 `null`，只适用于 COMPANY/MANAGED/SELF。**它不能写 `ORG_UNIT`/`PAYROLL_RUN`/`CASE` 的 anchor_id。**
- `UserRoleDrawer` 保存只发送 `{role_code, reason}`，**不发送 `binding_anchors`**；`POST /v1/users/{id}/roles` 对 `ORG_UNIT/PAYROLL_RUN/CASE` 会返回 `ROLE_ANCHOR_REQUIRED`。
- 因此 R03/R04/R05/R08 的账号与权限前置**无法在当前代码构造**，必须标 `BLOCKED`，等待 **T02/T19 增加 anchor binding UI 或命令**。
- 不得用“先绑角色再改库”“手写 authority_bindings”“复用 demo.payroll 的 run 锚点”“调用未授权 API”等步骤绕过；这些不是 MCP 验收，也不能产生可信证据。

**不可用账号说明：** `iam:seed-demo` 产生 `demo.admin/demo.hr/demo.manager/demo.employee/demo.payroll`，但 seed 只在已有 payroll run 时才给 PAYROLL_RUN 锚点，且不会解决 ORG_UNIT/CASE；demo 账号不能替代 R03/R04/R05/R08 的正式验收角色。

### 3.2 用户与绑定

对可构造范围（R01/R06/R07/R09 完整构造；R02 仅 COMPANY 部分构造）：

- 优先用 `/system/users` UI 创建用户（`username`、`email`、密码、可选 employment）；
- R01 必须由 `hrms:install` 创建；`t18.unlinked-admin` 作为 R01 变体单独创建，确保无 employment link；
- R06 需要 A/B 直接下属关系；R07 绑定 A 的 employment；R09 只读审计；
- 角色绑定使用 `iam:bind-role --user=<id> --role=<ROLE>`（仅 COMPANY/MANAGED/SELF 可执行），并记录 user id、role、auth_version、命令退出码；
- 初始密码不落盘、不写入证据；
- 创建后先用新账号登录，确认 `/v1/me` 的 capabilities 与页面菜单符合角色；
- 每次角色变更后刷新/重登，旧标签页下一次写请求必须 403。

### 3.3 业务前置数据顺序

严格按依赖构造；每一步都记录其 ID：

```text
A. 部门/组织树
B. 岗位/职级/任职资格
C. 员工 A/B/C + 任职 + 合同 + 执行薪酬
D. 用户绑定 + 角色锚点
E. 工作日历 + 假别 + 规则版本
F. 班次 + 排班 + 月度打卡/异常
G. 请假申请/批准/取消
H. 加班申请/确认/结算方法
I. 出差申请/审批/费用
J. 培训计划/课程/场次/报名/签到/考试
K. 绩效周期/参与者/评分/发布
L. 招聘需求/候选人/Offer/接受/转入职
M. 工资项目/方案/法定参数
N. 工资批次 + 输入 + 计算 + 复核 + 审批 + 发布 + 发放
O. 公告/通知/回执
P. 审计事件
```

只允许使用合成业务数据；禁止导入真实 CSV/旧库。需要旧入口/历史数据时，只引用 V2.3 迁移夹具的 `known-good` 合成数据，并明确标注来源。

### 3.4 前置数据缺陷的处理

- R02 的 CASE 审批、R03/R04/R05/R08：唯一处理是标 `BLOCKED`，写明依赖 T02/T19 的 anchor binding UI/命令；不提供 CLI/DB/API 替代步骤。R02 的 COMPANY 能力仍可执行。
- 无法通过 UI 或已明确可执行的受控命令构造的其他前置数据：标 `BLOCKED`，写缺什么、为什么、影响哪些证据 ID。
- 页面没有功能入口但 API 存在：标 `UNMAPPED`；不能用 API 结果判 PASS。
- 不得为了继续执行而修改角色模板、权限、租户或数据库来“绕过”能力；任何前置修复必须有独立提交/记录，不能混入验收证据。
- 不得把 demo seed、自动测试、fixture、SQL 或 curl 结果当作 MCP 证据。

---

## 4. MCP 执行方式

### 4.1 连接真实前端

1. 启动本批次前端，确保 `VITE_API_MODE=http`：

```bash
cd frontend
VITE_API_MODE=http \
VITE_API_BASE_URL=/v1 \
VITE_DEV_API_PROXY_TARGET=http://127.0.0.1:5184 \
pnpm dev --host 127.0.0.1 --port 5185
```

> `vite.config.ts` 的代理变量是 `VITE_DEV_API_PROXY_TARGET`；`frontend/.env.example` 中的 `VITE_PROXY_TARGET` 名称不一致，见 gap-log。若使用同源 compose 的 nginx 端口，则直接走 `/v1`，不经过 Vite 代理。

2. 在浏览器 MCP 中打开 `http://127.0.0.1:5185` 或本批次 nginx 端口。
3. 打开开发者网络面板或使用 MCP 的网络记录工具；每个写操作必须确认实际请求 URL、方法、状态。
4. 每次登录前，先记录账号、角色、锚点、当前 `auth_version`（如可见/由 `/v1/me` 返回）。

### 4.2 串行原则

- **串行人工操作**：一次只执行一个证据项，不并行点击多个角色/多个写动作。
- 一项证据失败，先保存现场和网络；不得跳过后面的同类验证并写“整体通过”。
- 一项 `FAIL` 必须触发回补（最小测试/实现）并重新执行同一路径；回归不得只跑自动测试。
- 所有模块的证据必须按矩阵顺序完成；跨模块闭环放在单模块通过后执行。
- 每个模块结束都更新 evidence index 的状态，不等到全部完成再补。

### 4.3 每个动作的固定观察

每个动作至少记录：

1. 角色、账号、锚点、前置数据 ID；
2. 页面路径、Tab、按钮/表单；
3. 操作前状态（列表/详情/版本/金额/状态）；
4. 实际动作（输入值、确认/取消、重复提交）；
5. 网络请求：method + path + status + request_id（不记录密码/cookie/token）；
6. 页面实际中文结果（成功/失败/空/部分/冲突）；
7. 刷新后结果；
8. 退出并重新登录后结果；
9. 撤权/换角色后的结果（适用时）；
10. 截图 + 页面 snapshot；
11. 结论和后续动作。

### 4.4 状态观测清单

每个模块至少观察并记录：

| 状态 | 如何触发 | 证据要求 |
|---|---|---|
| loading | 慢网络/首次加载 | 截图 + 网络 pending 或可见骨架/加载文案 |
| empty | 新库或筛选无结果 | 截图 + “无数据”中文文案，不得显示 0/伪造行 |
| error | 断开依赖/服务端 5xx/超时 | 中文错误 + request_id + 重试入口 |
| forbidden | 无页面/动作能力 | forbidden 页面/403 中文，不泄露对象 |
| partial | 一个投影/来源失败 | 可见 partial 提示，成功部分仍可读，不伪装全成功 |
| conflict | 重复提交/版本冲突/冻结数据 | 409/明确中文冲突 + 保留输入/刷新引导 |
| success | 正常写入 | 刷新后持久化 + 审计/版本/金额正确 |

### 4.5 刷新、重登、撤权

- **刷新：** 每个详情/列表至少刷新一次，确认 URL 深链、筛选/分页/选中/Tab 保留或按设计恢复。
- **重登：** 每个模块至少退出并重新登录一次；确认会话、权限、数据仍正确。
- **撤权：**
  - 用一个有写权限的账号打开页面并完成一次草稿/输入；
  - 由 R01 在另一浏览器/会话撤销其角色或动作权限；
  - 回到旧标签页，执行下一次写请求，必须 403/401；
  - 记录“旧标签页是否仍显示敏感数据、是否还能请求、是否被重定向”。
- **停用/锁定：**
  - 对非最后管理员账号执行停用；
  - 确认会话撤销、旧标签页下一次请求失败；
  - 不把最后管理员删到 0。

### 4.6 网络与安全记录

允许记录：

- method、path（去掉敏感 query）、status、`request_id`；
- 响应字段名与中文可见值；
- 是否包含金额/证件/手机号等敏感字段，是否被裁剪；
- Set-Cookie 的 HttpOnly/SameSite/Secure 标志（不记录 cookie 值）。

禁止记录：

- password、TOTP secret、恢复码、session id/token、CSRF token 原值；
- 真实个人数据、真实工资、真实公司信息；
- 完整 Authorization/Cookie header。

---

## 5. 逐模块执行顺序与检查点

> 模块详细映射、动作和 API 见 `21-module-mcp-acceptance-matrix.md` §4。本 runbook 只给执行顺序、关键路径和阻断条件。

### 5.1 阶段 0：环境与身份

| 证据 ID | 操作 | 预期 | 阻断条件 |
|---|---|---|---|
| `MCP-ENV-001` | 记录 HEAD、分支、工作树、build SHA、`/v1/meta` | 三者一致；无未授权变更 | HEAD 不一致 |
| `MCP-ENV-002` | `/v1/health/ready` | 200，db/cache/storage/migrations/installation/administrator 全 true | 任一 false |
| `MCP-ENV-003` | 空库安装/重复安装 | 唯一管理员、零业务行；重复安装不改密码 | 重复管理员或 demo 行 |
| `MCP-ENV-004` | 生产拒绝 demo/reset | 非零退出且无数据写入 | 能执行则 FAIL |
| `MCP-ENV-005` | 合成数据清单与 458 卡索引 | 所有 T18 数据存在；155 页/458 卡索引可追溯 | 数据/索引缺失则该模块 BLOCKED |

### 5.2 阶段 1：M01/M19/M21 基础权限与设置

先做这三个模块，因为其他模块依赖身份、权限和配置。

- **M01**：登录、退出、修改密码、会话列表/撤销、退出全部、工作台本人投影、无任职管理员边界、MFA 可选路径。
- **M19**：用户 CRUD、角色页面/动作/范围/字段授权、页面目录、菜单名称/排序/可见性、权限预览、撤权旧标签页。
- **M21**：公司资料、日历、字典、参数、文件、审计查询/导出、健康面板；安装/升级/备份恢复只做可见性确认，不能伪造。

**关键检查：**

- 不允许任何操作绕过服务端能力；
- 角色授权保存后刷新仍生效；
- 撤权后旧标签页写请求立即失败；
- 删除最后管理员/最后角色被拒绝；
- 审计字段导出不含密码/token；
- 备份/恢复不可用必须写 `BLOCKED/UNMAPPED`，不能写 PASS。

### 5.3 阶段 2：M04/M05/M06 主数据

- **M04**：员工列表筛选/分页、同名/近似名、建档、任职、合同/薪酬入口、详情 8 Tab、子档案新增/编辑/归档、变更申请、导入/导出、归档/恢复、旧 FOLD 链接 404。
- **M05**：组织树、部门新建/编辑、负责人、成员添加/移除、编制、移动影响预览、组织环/在岗人员删除拒绝、as-of 历史归属。
- **M06**：岗位目录、筛选/分页、岗位新建/编辑、职级、任职资格版本、在岗人员、在用岗位停用拒绝、建议薪酬不覆盖个人工资。

**关键检查：**

- 员工创建后 Person/Employment/Assignment/Terms 同源；
- 同名员工不误绑；
- 跨组织对象返回 404 且不泄露存在性；
- 组织移动/岗位变更后历史 as-of 不漂移；
- 每个列表刷新保留筛选/分页。

### 5.4 阶段 3：M07/M08/M20 生命周期与审批

- **M07**：异动台账/类型/日历/详情、入职/转正/调岗/调薪/借调/离职草稿、提交、审批、撤回、Effect 失败重试；未来生效不提前。
- **M08**：合同台账/到期/模板、创建、续签、变更、终止、附件/模板；激活按钮必须实际存在，不能以 API 证明 UI。
- **M20**：我的待办/我发起/我审批的/抄送我的/模板；决定、退回、拒绝、撤回、效果重试；非 assignee 拒绝。

**关键检查：**

- 审批决定与领域执行分开可见；
- 重复决定/重试不产生第二份业务；
- 未来生效不提前；
- 旧链接/撤权后不泄露敏感内容；
- 合同终止不可逆、版本不可原地改。

### 5.5 阶段 4：M09–M12 Time

- **M09**：10 Tab；我的打卡/明细/日历/异常/补卡/规则/班次/排班/月结/设备；补卡、规则发布、班次、排班、关账/重开、重算、原始打卡导出。
- **M10**：7 Tab；本人/代申请、预览、提交、取消/撤销、类型/规则版本、余额/账本、日历、汇总；跨月拆分。
- **M11**：6 Tab；本人加班、规则、确认、PAY/TIMEOFF 互斥、结算、调休余额。
- **M12**：6 Tab；本人出差、台账、政策、审批、统计、费用；与请假/打卡/加班不重叠。

**关键检查：**

- 时间事实唯一；闭账冻结；已计薪只走更正；
- 加班确认/结算幂等；不重复计薪/额度；
- 出差/请假/打卡/加班不重叠；
- “打卡”无写入口时标 `UNMAPPED`；
- 工资冻结时正确阻断；
- 跨月按天拆分正确。

### 5.6 阶段 5：M13/M14 薪酬与福利

- **M14**：福利方案、参保、缴费台账、法定参数；费率显示、个人/单位金额、冲销、历史快照。
- **M13**：批次、配置（项目/方案/员工薪酬）、工资条、报表；详情 5 Tab；lock/calculate/review/approve/publish/mark-paid/close、更正、重开、取消。

**关键检查：**

- 同批 A 月薪/B 计时分别使用自身方案；
- 缺时间事实/基础薪酬/社保参数时阻断，不显示 0；
- 月薪样例 8000/380/600/500/9480/400/1400/8080/9980 逐项来源/公式；
- 月中 10 天 8000 + 10 天 9000 = 8500；
- 更正 8080→8300 (+220) →8350 (+50)，不是 270；原工资条不变；
- 发布/发放正交；重复发布/发放不重复；
- 工资条 SELF 只能看本人已发布；字段权限遮挡；
- 金额 UI 两位小数、无 float 痕迹。

### 5.7 阶段 6：M15/M16/M17 人才

- **M15**：周期、模板、指标、目标、自评/主管评分、360、校准、结果发布、申诉、报表；发布后冻结。
- **M16**：需求、审批、开放、看板、预算、候选人、导入、面试、评价、Offer、沟通、人才库、转入职；一次 Offer 只转一员工。
- **M17**：计划、课程、场次、报名、签到、考试、反馈、档案、报表；未报名不能签到/考试；无培训津贴不加工资。

**关键检查：**

- 绩效未批准/未映射/草稿不发钱；
- 一 Offer 一员工；
- 培训来源闭环；
- 员工档案培训记录缺失时标 `UNMAPPED`；
- 角色 R08 锚点未就绪时标 `BLOCKED`。

### 5.8 阶段 7：M18/M02/M03 通知与报表

- **M18**：公告编辑/审核/受众/发布/撤回/回执/催阅/模板；通知列表/已读/外部 channel 状态。
- **M02**：仪表盘指标、单位、不可用原因、下钻、角色差异。
- **M03**：报表目录、运行、结果、导出、下载、取消/重试/订阅（若 UI 存在）。

**关键检查：**

- 工资通知不暴露金额；
- 重复投递不重复；
- 撤回/撤权/过期旧链接失效；
- 查询失败不显示 0/空表；
- 导出再次鉴权；无金额列或不能下载；
- 汇总/明细/工资条一致。

### 5.9 阶段 8：12 条跨模块闭环

按矩阵 §6 `MCP-X01`–`MCP-X12` 串行执行。每条闭环必须：

- 使用独立前置数据或可回滚的合成数据；
- 记录源单据、版本、操作者、生效时间；
- 记录审批前后、计算前后、发布/发放前后的对照；
- 引用对应单模块证据 ID；
- 失败时回补并重跑该条。

### 5.10 阶段 9：T19 交接

T18 不执行迁移恢复、备份恢复、最终 GO/NO-GO。将以下内容交给 T19：

- 未执行的 MCP 项和阻塞；
- 真实栈日志/截图/网络路径；
- 失败项的最小测试/实现回补记录；
- AC-02/AC-20/AC-22 需要 T19 的证据清单；
- 生产 demo/reset、升级、备份恢复、PITR、RPO/RTO。

---

## 6. 证据命名与保存

### 6.1 目录

固定使用：

```text
docs/implementation/v24/18-mcp-acceptance/evidence/
  MCP-M01/
    P001/
      MCP-M01-P001-001.md
      screenshot-01.png
      snapshot-01.txt
      network-01.txt
      request-id.txt
    P002/
  MCP-M02/
  ...
  MCP-X01/
  env/
  defects/
```

### 6.2 文件命名

```text
<证据ID>-<NNN>-<动作短名>-<YYYYMMDDTHHMMSSZ>.<ext>
```

示例：

```text
MCP-M19-P143-004-role-save-20261128T101500Z.png
MCP-M19-P143-004-role-save-network-20261128T101500Z.txt
MCP-M13-P089-007-calculate-result-20261128T113000Z.txt
```

要求：

- 时间使用 UTC；文件名不含空格、中文、密码、用户真实标识。
- 一个证据项一个 Markdown 主记录；截图/网络/snapshot 放在同一 `P<入口三位>` 目录，文件名用证据 ID 前缀；不再创建第二层 `<证据ID>/` 子目录。
- 失败证据和重试证据都要保留；不得覆盖。
- 截图不得包含密码、cookie、token、真实 PII。

### 6.3 每个证据项的必备字段

使用 `evidence-template.md`。至少包含：

- 证据 ID、模块、场景、角色/账号、锚点、前置数据 ID；
- 环境：HEAD、SPA build SHA、API URL、DB/Redis/storage 类型、app env；
- 操作路径/Tab/按钮/输入；
- 预期、实际、网络状态/请求 ID、截图/snapshot；
- 刷新、重登、撤权结果；
- 结论：`NOT RUN`/PASS/FAIL/BLOCKED；
- 若失败：缺陷 ID、最小复现、回补测试/实现、重试结果。

### 6.4 不得提交的内容

- 密码、TOTP secret、恢复码、session cookie、CSRF token；
- 真实个人数据、真实工资、真实公司信息；
- `.env`、数据库 dump、Redis dump、对象存储凭据；
- 巨大的 trace/视频，除非用户明确要求并已脱敏。

---

## 7. PASS / FAIL / BLOCKED 判定

### 7.1 PASS

必须同时满足：

1. 真实 `VITE_API_MODE=http`；
2. 真实角色、真实后端、真实 MySQL/Redis/S3；
3. 实际执行了本项要求的 UI 操作；
4. 网络请求状态符合预期；
5. 页面实际中文结果正确；
6. 刷新后持久状态正确；
7. 重登后权限/数据正确（适用时）；
8. 撤权/越权负测正确（适用时）；
9. 截图、snapshot、网络记录齐全；
10. 无未解释的 5xx/403/金额/字段泄露。

### 7.2 FAIL

任一条件：

- 实际结果与预期不符；
- 页面显示与技术错误/栈/API 路径/内部 ID；
- 金额错误/多一分/少一分/浮点痕迹；
- 越权或数据泄露；
- 重复写入/重复支付/重复额度；
- 状态机非法跳转；
- 刷新后状态不一致；
- 错误被伪装为空/0；
- 网络 5xx（非约定 503）；
- 证据显示“点击成功”但业务未持久化。

FAIL 后必须：

1. 保留现场；
2. 记录最小复现；
3. 回补最小测试（先失败）；
4. 回补最小实现；
5. 重跑同一 MCP 路径；
6. 更新证据状态和索引。

### 7.3 BLOCKED

任一条件：

- 前置任务（T05–T17 等）未完成；
- 角色/锚点/数据无法构造；
- 页面入口不存在或明确不可达；
- 458 卡源包权威性未确认、卡→页面/动作映射缺失，或 T20–T24 任务卡缺失；
- 环境依赖不可用；
- 需要用户业务决策或正式修订口径；
- 备份/恢复/升级等没有 MCP UI。

BLOCKED 必须写：

- 阻塞原因；
- 影响哪些证据 ID/AC；
- 解除条件；
- 责任人/用户决策（若需要）。

### 7.4 未执行

未执行一律 `NOT RUN`，不得写 PASS/FAIL。

---

## 8. 缺陷与重试

### 8.1 缺陷记录

以下任一情况开缺陷：

- 越权/数据泄露/金额泄漏；
- 金额/对账/状态错误；
- 半成功/重复写入；
- 页面 5xx；
- 中文文案/枚举/技术码泄露；
- 撤权后旧标签页仍可写；
- 查询失败显示空表/0；
- 导出无法再次鉴权。

缺陷记录至少包含：

- 标题、严重度（Sev1–Sev4）；
- 影响模块/AC；
- 证据 ID + 复现步骤；
- 实际/预期；
- 最小测试和实现回补；
- 重试结果；
- 是否阻断 T18。

### 8.2 重试规则

- 同一个失败必须**先修复再重试**，不能靠重启/清缓存/换账号“消失”；
- 每次重试使用新子编号，保留旧证据；
- 重试后若仍失败，保持 FAIL；
- 若因环境问题重试，记录环境变化和新的 HEAD/构建；
- 不得把“刷新后好了”当作修复，除非有代码变更和回归证据。

---

## 9. 退出条件

### 9.1 单模块可判 PASS 的最低条件

- 模块矩阵中的当前页面/动作全部有证据或明确 `BLOCKED/UNMAPPED`；
- 155 历史入口中该模块的 dispositions 有结论；
- 该模块相关 AC 的 MCP 证据覆盖；
- 状态清单（loading/empty/error/forbidden/partial/conflict）覆盖；
- 角色差异、旧链接/撤权覆盖；
- 无未关闭 Sev1/Sev2 缺陷；
- evidence index 状态更新。

### 9.2 T18 可判 PASS 的最低条件

- 21 模块全部 PASS，或用户正式接受某些 BLOCKED；
- 12 条跨模块闭环全部 PASS；
- 458 卡逐卡有结论且源包已获权威确认；否则 AC-01/AC-21 不能 PASS；
- T01–T24 有权威定义且逐项有结论；否则不能 PASS；
- 所有证据保存在 `docs/implementation/v24/18-mcp-acceptance/evidence/`；
- evidence index 无 PASS 但无证据的项；
- MCP 是人工执行，不是自动 test。

### 9.3 最终输出

T18 最终输出只能是：

```text
T18: NOT RUN / PASS / FAIL / BLOCKED
HEAD:
执行项:
通过:
失败:
阻断:
未执行:
未关闭缺陷:
AC-21 判定:
```

只有所有条件满足才可 `PASS`；否则明确 `FAIL`/`BLOCKED` 并给出原因。

---

## 10. 快速检查清单

开始前：

- [ ] 在本 worktree，HEAD 已记录
- [ ] `VITE_API_MODE=http`，不是 mock
- [ ] MySQL/Redis/S3 为本批次隔离
- [ ] 空库干净安装完成
- [ ] R01–R09（7 核心 + 2 补充）已按 §3.1 核对：R01/R06/R07/R09 已登录，R02 公司级可用但 CASE 审批 BLOCKED，R03/R04/R05/R08 按 BLOCKED 登记
- [ ] 合成数据 A/B/C、合同、薪酬、时间、批次、通知齐备
- [ ] 浏览器 MCP 已连接并能截图/snapshot/网络记录

执行中：

- [ ] 一次只做一个证据项
- [ ] 每个写动作记录网络 + 刷新 + 重登/撤权
- [ ] 每个模块覆盖 loading/empty/error/forbidden/partial/conflict
- [ ] 失败先回补再重试，不覆盖证据
- [ ] 不记录密码/token/PII

结束前：

- [ ] evidence index 全部有状态
- [ ] 无 PASS 无证据
- [ ] 失败/阻塞/未执行清单完整
- [ ] 12 个闭环有结论
- [ ] 458/T20–T24 的缺失已如实记录
- [ ] 458 卡逐卡/按组结论与 `458-card-index.md` 一致
- [ ] 交给 T19 的清单明确
