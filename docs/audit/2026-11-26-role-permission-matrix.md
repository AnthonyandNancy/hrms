# 五类角色权限正负矩阵（V2.1 第 2 册 Task 11）

- 证据提交：`RoleMatrixTest`（7 个用例 / 69 条断言），与全量后端套件同批通过（1184 tests / 57,606 assertions）。
- 执行环境：SQLite 内存库 + 真实 `RequireCapability` 中间件 + 真实 `CapabilityAuthorizer` + 真实 `AuthorityBinding` 行；仅跨模块目录（people/organization/payroll/case）使用显式内存端口。
- 授权绑定来源：`RoleTemplateCatalog` 的代码自有模板，按每个 grant 声明的锚点写入，未做锚点提升。

## 1. 角色映射

计划书写的是「部门经理」，代码自有的角色代码是 `LINE_MANAGER`。本矩阵按真实代码执行，差异记录如下：

| 计划口径 | 实际角色代码 | 实际锚点 |
| --- | --- | --- |
| 系统管理员 | `SYSTEM_ADMIN` | COMPANY（通配） |
| 人事管理员 | `HR_ADMIN` | COMPANY |
| 部门经理 | `LINE_MANAGER` | MANAGED（直属下属） |
| 普通员工 | `EMPLOYEE` | SELF |
| 工资专员 | `PAYROLL_SPECIALIST` | PAYROLL_RUN + COMPANY |

## 2. 正负矩阵结果

| 用例 | 角色 | 期望 | 实际 | 结论 |
| --- | --- | --- | --- | --- |
| 身份与权限目录 | 五类全部 | `/v1/me` 200、`/v1/me/permissions` 200 | 一致 | PASS |
| 用户列表与建档 | `SYSTEM_ADMIN` | GET 200 / POST 201 | 一致 | PASS |
| 用户建档拒绝 | `LINE_MANAGER` | POST 403 `CAPABILITY_DENIED` | 一致 | PASS |
| 用户列表拒绝 | `EMPLOYEE` | GET/POST 403 `CAPABILITY_DENIED` | 一致 | PASS |
| 工资专员的用户管理拒绝 | `PAYROLL_SPECIALIST` | POST `/v1/users` 403 | 一致 | PASS |
| 员工目录拒绝 | `EMPLOYEE` | GET `/v1/employees` 403 | 一致 | PASS |
| 导出能力拒绝 | 无 `reporting.export` 的身份 | POST `/v1/reporting/exports` 403 | 一致 | PASS |
| 字段档位拒绝 | 持 `reporting.export` 但上限 P2 | POST 422 `EXPORT_FIELD_HIDDEN` | 一致 | PASS |

## 3. 发现的产品缺陷（未在本任务修复）

| # | 严重度 | 现象 | 期望 | 实际 | 归属 |
| --- | --- | --- | --- | --- | --- |
| 1 | 高 | `PAYROLL_SPECIALIST` 无法列出工资批次 | `GET /v1/payroll/runs` 200（角色说明为「准备工资批次」） | 403 `CAPABILITY_DENIED`：角色按 `PAYROLL_RUN` 锚点授予 `payroll.run.read`，而路由要求 `COMPANY` 锚点 | Volume 3 Task 12 |
| 2 | 中 | 部门经理无法读员工目录 | 计划期望 ORG_UNIT 锚点可读本部门员工 | `LINE_MANAGER` 实为 MANAGED 锚点，`GET /v1/employees` 403 | Volume 3 Task 2/5 |
| 3 | 中 | 越权访问他人档案返回 403 而非 404 | 不泄露资源是否存在（404 `OUT_OF_SCOPE`） | 403 `CAPABILITY_DENIED` | Volume 3 Task 3 |

三条均已在 `RoleMatrixTest` 中以注释标注并断言当前真实行为，未通过放宽断言掩盖。

## 4. 真实栈 E2E

`frontend/tests/e2e/real/role-matrix.spec.ts` 使用真实 Playwright 配置与两个密码环境变量（`DEMO_SEED_PASSWORD` / `HRMS_DEMO_PASSWORD`），基于 Cookie 登录，覆盖每角色可见菜单组、一次禁止页跳转、一个隐藏/禁用按钮、一次导出拒绝提示。

- 本环境 Docker 守护进程不可用，**NOT RUN (no docker daemon)**。
- 该规格已通过 `playwright test --list` 校验可被收集，未声称执行通过。

## 5. 复现命令

```bash
cd backend && ../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpunit --colors=never --filter='RoleMatrixTest'
cd backend && ../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpunit --colors=never
cd frontend && pnpm exec playwright test --list tests/e2e/real/role-matrix.spec.ts
```
