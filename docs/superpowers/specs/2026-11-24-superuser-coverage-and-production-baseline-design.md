# 最高权限完整性、生产基线数据与去演示化：解决方案设计

- **状态：** 待批准（等待项目负责人确认后进入实施计划）
- **日期：** 2026-11-24
- **替代/修订：** 修订 ADR-0004 第 7 条、ADR-0013「不保留永久超级管理员」的表述边界；新增 ADR-0016、ADR-0017
- **不修订：** `docs/HRMS_V1.0_解决方案_第一性原理重构.md`、`docs/HRMS_V1.0_实施计划_30周路线图.md`（已批准，v1.1）
- **触发来源：** 负责人指令「系统管理员拥有最高权限，拥有全部权限」「项目以实际需要上线的标准执行，去掉演示字眼」「系统管理员最高权限下需要有默认初始数据」

---

## 1. 问题陈述

### 1.1 表面需求

1. 系统管理员（`SYSTEM_ADMIN`）必须拥有全部权限。
2. 系统里不应存在"演示"性质的痕迹，应按生产上线标准交付。
3. 生产初始状态应带有参考数据（权限页面数据等），而不是空库。

### 1.2 为什么这三件事是同一个问题

三条需求看似分属授权、文案、种子数据三个不相干的领域。但把代码当证据核查后，它们收敛到同一个根因：

> **系统没有"生产首次部署"这个概念。**它只有"开发/演示"这一种出生方式，于是：
> - 权限模型按演示场景裁剪（够演示就行）；
> - 首批用户是共用密码的假账号；
> - 参考数据靠临时脚本零散塞入；
> - 假数据反过来成为前端唯一的数据来源，真实接口虽已实现却无人调用。

### 1.3 代码证据

以下每一条都在本机 Docker 栈（`http://localhost:5174`）或源码中实测得到，非推测。

| # | 事实 | 证据位置 |
|---|---|---|
| E-01 | `SUPER_ADMIN` 只发放 20 / 76 个能力 | `RoleTemplateCatalog::build()` |
| E-02 | `SYSTEM_ADMIN` 只发放 10 / 76 个能力 | 同上；`GET /v1/me/capabilities` 返回 10 条 |
| E-03 | **9 个能力没有任何角色发放**：`payroll.run.review`、`payroll.run.approve`、`payroll.run.publish`、`payroll.run.close`、`payroll.run.reopen`、`payroll.parameters.manage`、`workflow.case.admin`、`talent.performance.manage`、`talent.training.manage` | 遍历 10 个模板，均为 `<NO ROLE>` |
| E-04 | 因此**任何租户都无法完成一次工资审批链**：路由要求 `payroll.run.approve`，但无任何角色可被授予该能力 | `routes/api.php:543`、E-03 |
| E-05 | 朴素地把 76 个能力全给同一人会**完全失去权限**：`SodRules::PAIRS` 含 6 对冲突，`assertAllowed()` 抛 `SodConflictException` | `SodRules::conflicts()` 实测输出 |
| E-06 | 而 `SodRules::assertAllowed()` 在授权热路径**永不触发**（`matchingBindings()` 只按单个 capability 查询，冲突对需要两个不同 code） | `CapabilityAuthorizer::matchingBindings()` |
| E-07 | 结论：SoD 规则**在写路径误伤合法操作，在读路径零保护**，同时可被"直接写 `authority_bindings`"绕过（`SeedDemoCommand` 正是如此） | E-05、E-06、`SeedDemoCommand` |
| E-08 | `demo.admin` 有 **0 个 MFA factor**，但 `mfa_enrolled_at` 已写入 | 实测 `MfaFactor` 计数为 0 |
| E-09 | 用种子里的"万能码 246810"做 step-up 返回 `MFA_CODE_INVALID` | 实测 `POST /v1/auth/mfa/step-up` |
| E-10 | 后果：**创建用户、分配角色、创建绑定、维护薪酬法定参数，全部永久返回 403 `MFA_STEP_UP_REQUIRED`** | 实测 `POST /v1/users`、`POST /v1/users/4/roles` |
| E-11 | **密码单独即可获得完整读权限（含工资条）**：登录响应 `mfa_verified=false`，且不返回任何 MFA 挑战，会话直接可用 | 实测 `POST /v1/auth/login` → 200 |
| E-12 | `mfa_required` 的判定硬编码 4 个薪酬能力名，新增敏感能力不会自动纳入 | `MeController::requiresMfa()`、`MfaService::requiresMfa()` |
| E-13 | 前端默认 `VITE_API_MODE=mock`，且 mock 数据被打进生产 bundle（`index-*.js` 1.14 MB，含 19 处 `demo.example`） | `frontend/src/api/index.ts`；`grep frontend/dist` |
| E-14 | `/v1/users`、`/v1/users/{id}/bindings`、`/v1/roles`、`/v1/capabilities` **均已返回真实数据**，但前端 `getAdminSnapshot()` 把 `users` 写死为空数组 | 实测 200；`http.ts:536` |
| E-15 | `docker_up.sh` 中 `time:seed-leave-types … \|\| true` 吞掉失败；`payroll:seed-parameters`、`lifecycle:seed-templates` **从未执行** | `deploy/scripts/docker_up.sh` |
| E-16 | 法定节假日、法人工作日历、班次模板**没有任何生产播种命令**（仅测试里有构造） | `grep` 全仓确认 |
| E-17 | 功能开关 5 个默认全 `false`，且 `feature:` 中间件**没有挂在任何路由上**（0 处使用） | `routes/api.php`；`bootstrap/app.php` |
| E-18 | UI 提供 `TENANT` 锚点选项，后端 `AnchorType` 枚举中**不存在**该类型 | `AdminView.vue:455` vs `AnchorType.php` |
| E-19 | **字段档位投影在生产路径上几乎未接线**：`Authorizer::fields()` 零调用点；薪酬读接口直接输出 `gross_cents`/`net_cents` | 全仓 `grep`；`PayrollPayslipController::summary()` |
| E-20 | `payroll.run.read` 的能力上限是 `P2_SENSITIVE`，但 `/v1/payroll/runs/{id}/employees` 返回 P3 级金额，**能力档位与输出档位不一致** | `CapabilityCatalog` vs `PayrollRunService::employees()` |
| E-21 | `docker_up.sh`、`README.md`、`LoginView.vue`、`AppShell.vue`、`MfaEnrollmentDialog.vue` 共 5 处用户可见的"演示"表述与"任意 6 位数字"提示 | `grep 演示` |
| E-22 | 员工详情接口输出 `person.email` 明文，未走 P1 遮罩（因为 E-19） | 实测 `GET /v1/employees/1` |
| E-23 | **`iam:sync-capabilities` 会把 `*` 绑定判为"引用了未知能力"并直接失败**：该命令用 `whereNotIn('capability_code', $codes)` 做孤儿检查，而 `*` 不在目录中 | `SyncCapabilitiesCommand` 实测确认 |

**核心判断：**E-04 与 E-05 同时成立，说明当前架构既**不能表达"全部权限"**，也**没有真正执行"职责分离"**。这是一个结构性缺陷，不是配置遗漏。加几个能力项无法修复它，只会把矛盾往后推。

---

## 2. 第一性原理分析

### 2.1 授权系统只有两个不可再分的职责

任何访问控制机制，无论形态，都必须回答两个问题：

1. **覆盖（Coverage）**：谁**能**做这件事？
2. **归因（Attribution）**：这件事**是谁**做的，能否独立复核？

当前实现把这两个职责塞进同一张规则表 `SodRules::PAIRS`，于是产生对称的失败：

- 为了归因，就删减能力覆盖面 → `SUPER_ADMIN` 只剩 20/76，9 个能力成为孤儿，工资审批链在数据上不可达（E-03/E-04）。
- 而真正的归因能力（审计账本、step-up 强制、到期复核、独立审计员）恰恰是**坏的**（E-08/E-09/E-11）。

**因此第一性结论：覆盖面必须用"全集"机制表达，归因必须用"证据"机制表达，二者不能共用一张规则表。**

### 2.2 职责分离其实有两类，混在一起必然出错

现有 `SodRules::PAIRS` 把两件性质完全不同的事写成同一种"冲突对"：

| 类型 | 定义 | 现有规则 | 本质 | 对最高权限者 |
|---|---|---|---|---|
| **A 累积型**（Cumulative） | 同一主体**同时持有**两项对立权限 | `iam.binding.manage × iam.audit.read`、`iam.role.manage × iam.audit.read`、`people.employee.manage × iam.audit.read` | **岗位设计**问题：一个人是否该同时拥有"改权限"和"看审计" | 超级管理员**必然**同时持有——这是它的定义，不是缺陷 |
| **B 对象型**（Object-level） | 同一主体对**同一对象**连做两步 | 制单≠复核≠审批≠发布；break-glass 申请人≠批准人；重开需双人 | **内控不变量**：任何人都不能自审 | **对任何人都不破例**，包括 root |

B 类已有正确实现（`ApprovalService::assertSod()`、`BreakGlassService`、`ReopenService`），是不变量级检查，与能力清单无关。

A 类被错误地实现成了"硬阻断"，且阻断点选错（写路径误伤、读路径失效，E-05/E-06）。

**处置：**A 类降级为"允许 + 要求确认 + 告警 + 定期复核"；B 类保持不变并作为唯一的不变量级 SoD。

### 2.3 "全部权限"必须在能力目录增长时仍然成立

若用"把 76 行全写进模板"表达全部权限，则每新增一个能力都必须人工补齐 76→77，漏一次就退化。E-01 正是这种漂移的既成事实（`SUPER_ADMIN` 停在 20）。

这是典型的**枚举式策略腐化**：策略副本与真实资源集合失去同步。

**处置：**引入通配符 `*` 作为一等公民，由代码拥有的模板产生，在解析时求值。

### 2.4 演示模式掩盖了安全缺陷，因此去演示化是安全需求

E-08/E-09/E-10/E-11 这四条放在一起说明：**这个系统在演示模式下"看起来能用"，但它的 MFA 从未真正参与登录，特权写操作全部不可用。**"任意 6 位数字可通过演示环境验证"这句话不是文案瑕疵，它是一段**为了演示而伪造的安全承诺**，并直接产出了 E-08。

同理，E-13/E-14 说明：前端跑在假数据上，因此真实接口的缺陷（E-19/E-20/E-22）从未被发现。

**处置：**去掉演示字眼是**结果**，不是任务。任务是为系统建立一条**不依赖假数据也能启动**的生产出生路径。

### 2.5 生产初始状态的定义

"参考数据"与"业务数据"必须区分：

- **参考数据（Reference data）**：系统运行所必需的目录与配置。缺失时系统无法工作（无假期类型不能开假账户、无法人日历不能建班次）。**生产必须有。**
- **业务数据（Business data）**：员工、工资批次、审批案例。**生产必须为空**，由真实业务产生。

负责人要求"参考数据 + 1 个管理员，0 名员工"，正是这条界线。

### 2.6 跨领域参照

| 领域 | 最高权限如何建模 | 可借用之处 |
|---|---|---|
| Kubernetes | `cluster-admin` = `*` on `*`，但仍是**普通可绑定 Role**，绑定本身被审计 | 通配符是一等公民，不是隐藏后门；授权行为可追溯 |
| AWS IAM | `AdministratorAccess` 是 **AWS 托管策略**，新服务上线自动纳入 | 枚举式策略必然腐化；托管策略才能跟上资源增长 |
| PostgreSQL | `SUPERUSER` 是**角色属性**，不是权限集合 | 属性化避免清单漏项 |
| Linux | `root` / `sudo ALL` 全权，但强制 `auditd` 与 `sudo` 日志 | 全权必须与全量审计绑定出现 |
| 银行/财务内控 | maker-checker：制单与复核必须不同人，**即使 CFO 也不能自审** | B 类不变量对最高权限者同样适用 |
| 医疗信息 | break-glass 应急通道：有时限、有理由、有告警 | 应急通道必须存在，但必须可回收 |

**六个领域的一致答案：广度可以给满，但必须限时、留痕、可复核；唯一不可让渡的是"同一对象的先后两步"。**

---

## 3. 设计决策

### D1｜最高权限用通配符表达

**决策：**`SUPER_ADMIN` 与 `SYSTEM_ADMIN` 各自展开为**一条** `capability_code = '*'` 的授权绑定（每个法人一条），而不是 N 条能力绑定。

理由：
- 新增能力自动覆盖，零漂移（对照 E-01）。
- 一条绑定的审计语义清晰：「此人持有本法人全部能力」。
- 与 k8s `cluster-admin`、AWS `AdministratorAccess` 同构。

**约束（必须同时实现，否则变成后门）：**
1. `*` **只能**由代码拥有的 `RoleTemplateCatalog` 产生；`POST /v1/users/{id}/bindings` 显式拒绝 `capability_code='*'`，返回 422。
2. `*` 不得出现在 `capabilities` 表（该表是能力目录镜像，`*` 不是能力）。因此绑定表对 `capability_code` **不加外键**（现状本就没有外键，见 §1.3 迁移定义）。
3. `*` 绑定**必须带到期时间**：`SUPER_ADMIN` ≤ 30 天，`SYSTEM_ADMIN` ≤ 12 个月。到期后默认拒绝，续期需重新走授权流程并留审计。
4. `*` 绑定**必须** `field_profile = P3_RESTRICTED`（负责人已确认系统管理员可见工资数额，见 D6）。
5. 每次使用 `*` 授权执行**写操作**时，额外记录 `wildcard_grant_used` 审计事件（读操作按 §D2 的敏感访问审计规则处理）。
6. **必须显式承认 `*` 是已知的合法能力码外值。** 三处代码假定"绑定中的 `capability_code` 一定在目录内"，需同步修正（E-23）：
   - `SyncCapabilitiesCommand` 的孤儿检查：`whereNotIn` 前先排除 `*`；
   - `UserAdminController::resolveRoleAnchors` 邻近的角色分配校验：`*` 不参与"能力是否存在"检查；
   - `AccessReviewCommand` 的高风险清单：`*` 视为 `critical` 等级参与复核。

**为什么不做"物化展开"（明确记录被否决的方案）：**

曾考虑"模板声明 `*`，同步时展开为 76 条绑定"。否决理由：
- 两处真相（模板里的 `*` 与库里的 76 行），每次能力目录变更都要核对 diff，正是 E-01 漂移的温床；
- 能力目录 76 → 100 时，单个超管的绑定行数线性膨胀，`authority_binding_lookup_idx` 与缓存键基数同步膨胀；
- 审计语义变模糊：76 行无法一眼看出"这是超管"还是"恰好手动授了 76 项"。

通配符单行方案在审计上更清晰："此人持有本法人全部能力"，一行即事实。

**二阶影响（必须在本设计内处理）：**

`GET /v1/me/capabilities` 若原样返回 `*`，前端 `visibleNavigation()` 会用 `granted.has('workflow.case.read')` 逐项比对，全部为 false → **管理员看到空菜单**。这是"后端正确、前端空壳"的经典事故。

**处置：**`/v1/me/capabilities` 在响应时把 `*` **展开**为当前目录全部能力码，并附 `wildcard: true` 标志供 UI 提示。展开发生在序列化层，不写回数据库。

**三阶影响：**

- 通配符一旦合法，团队会倾向滥用（给非管理员也发 `*`）。缓解：`capability:audit` 增加门禁——`*` 只允许出现在 `SUPER_ADMIN` / `SYSTEM_ADMIN` 两个模板中，其他模板出现即失败。
- 未来若需要"部门级全权"，不要引入 `ORG_UNIT` 通配符，而应先评估是否真的需要；YAGNI。

### D2｜职责分离拆分为 A/B 两类

**决策：**

1. **`SodRules::assertAllowed()` 的硬阻断语义整体删除。**`SodRules` 从"冲突判决器"改为"**职责分离风险目录**"：`SodRules::risks(array $codes): list<array{0:string,1:string,kind:string}>`，只报告，不抛异常。理由见下方"为什么"。
2. **删除** `CapabilityAuthorizer::matchingBindings()` 中的 `SodRules::assertAllowed()` 调用（E-06 死代码；它捕获异常后静默返回空数组，表现为"莫名其妙全部 403"，且永不可能触发——因为它只按单个 capability 查询）。
3. **B 类不变量只在对象层硬执行**，即既有实现，保持不变并补测试：
   - `ApprovalService::assertSod()`：制单 ≠ 复核 ≠ 审批 ≠ 发布（同一 `payroll_run`）；
   - `BreakGlassService::activate()`：申请人 ≠ 批准人；
   - `ReopenService`：重开需两位不同批准人。
4. **A/B 两类在授权时刻降级为"确认 + 审计 + 复核"**：
   - 分配角色或绑定时，若结果能力集合命中任一风险对，响应 `200` 并带 `warnings[]`；请求体缺 `acknowledge_sod_risk: true` 时返回 `422 SOD_RISK_NOT_ACKNOWLEDGED`，并列出命中的组合；
   - 写 `security.sod_risk_accepted` 审计事件（操作者、被授权者、命中组合、`kind`、理由）；
   - `iam:access-review` 与新增 `GET /v1/access-review` 把命中项列为**必须复核项**；
   - 超管 `*` 天然命中全部风险对，故复核清单必然包含超管——这是设计意图，不是漏网。

**为什么 B 类不能靠"能力集合冲突"硬阻断（关键推理）：**

B 类是「**同一个人对同一个对象**连做两步」。这个约束的作用域是**对象**，不是**能力集合**。一旦用能力集合表达，会产生两个无法同时避免的错误：

- 若硬阻断，则 `SUPER_ADMIN` 的 `*` 必然展开出 `prepare + approve`，**超管无法被创建**，D1 直接失效；
- 若不阻断，则该规则在能力层完全没有任何约束力，正如现状（E-06）。

二者都错，因为**约束挂错了层级**。正确做法是：把不变量移到它真正的作用域（对象），把能力集合层面的判断降级为治理信号。这也解释了为什么现有实现"写路径误伤、读路径零保护"——同一个对象层约束被投影到了错误的层级。

**因此 `SodRules::PAIRS` 中现有的六对，全部转为风险信号**（三对 A 类 + 三对 B 类），不再有任何一对产生硬失败。硬失败只保留在服务层的对象检查里。

### D3｜覆盖不变量（防复发）

**决策：**`capability:audit` 新增三条**硬失败**门禁：

| 门禁 | 规则 | 直接捕获 |
|---|---|---|
| G-COV-1 | 每个目录内能力必须被至少一个角色模板发放，或在 intrinsic 白名单（`iam.session.manage`）中 | E-03 的 9 个孤儿 |
| G-COV-2 | 每个模板发放的能力必须存在于目录（或为 `*`） | 拼写错误、删除能力后遗留 |
| G-COV-3 | 每条受保护路由要求的能力必须被至少一个模板发放 | E-04（无人能审批工资） |

G-COV-3 需要从已注册路由抽取 `capability:` 中间件参数——`CapabilityAuditCommand` 已有该抽取逻辑（`capability(Route $route)`），只需复用。

**二阶影响：**未来新增路由/能力时若忘记挂到模板上，CI 立即失败。这会给 PR 增加摩擦，但摩擦点在"新增能力"这个低频高风险的架构动作上，是值得的。补救方式写进 `AGENTS.md`：新增能力 → 加入 `CapabilityCatalog` → 加入至少一个模板 → 加负向测试。

### D4｜演示与生产的运行模式分离

**决策：**引入唯一的生产入口命令 `hrms:install`，并让演示数据成为**可选**而非默认。

```
hrms:install
  ├── 前置校验：APP_KEY、DB 连通、时钟、S3 —— 失败即中止，不写半成品
  ├── 创建/校验法人（--legal-entity-code / --name / --country / --timezone / --currency）
  ├── 创建根组织单元
  ├── 工作日历（workweek、standard_day_minutes）
  ├── 公共节假日（--holidays-file，CSV；未提供则跳过并明确告警）
  ├── iam:sync-capabilities            （能力目录 + 10 个模板）
  ├── workflow:seed-templates
  ├── lifecycle:seed-templates
  ├── time:seed-leave-types            （--annual-days / --legal-basis 必填，不再吞错）
  ├── payroll:seed-parameters          （--approved-by 必填；缺省跳过并告警）
  ├── 报表定义同步、班次模板（STANDARD 9:00–18:00）
  ├── 创建首个 SYSTEM_ADMIN（--admin-username / --admin-email）
  │     └── 真实 TOTP 密钥 + otpauth:// URI + 恢复码，仅打印一次
  ├── 功能开关：payroll/time/self_service 由 --enable 显式开启
  └── 审计 install.completed（含每个步骤的 created/skipped 计数）
```

**关键性质：**

- **幂等**：重复执行安全，已存在项计为 `skipped`。
- **不静默失败**：任何一步失败 → 整体失败，已写入的部分保留但明确报告"安装未完成"，重跑可续。
- **零业务数据**：不创建员工、不创建工资批次、不创建审批案例。
- **不写死密码**：管理员密码来自 `--admin-password-stdin` 或环境变量；TOTP 密钥随机生成。

`docker_up.sh` 改为：默认执行 `hrms:install`；`--demo` 才额外装载 12 人合成数据。删除所有 `|| true`。

**关于"参考数据 + 1 个管理员，0 名员工"的落地清单**（负责人已确认）：

| 类别 | 内容 | 状态 |
|---|---|---|
| 能力目录 | 76 个能力 | 必须有 |
| 角色模板 | 10 个，含 `*` 超管 | 必须有 |
| 工作流模板 | 8 个（leave.request、attendance.correction、transfer.request、contract.renewal、onboarding.request、offboarding.request、payroll.approval、generic.approval） | 必须有 |
| 生命周期模板 | 6 类 + 入职清单任务 | 必须有 |
| 假期类型 | ANNUAL / SICK / PERSONAL | 必须有（法定依据需输入） |
| 法人工作日历 | 1 条（时区、工作周、标准工时） | 必须有 |
| 公共节假日 | 由 `--holidays-file` 提供 | 有输入则载入，否则告警跳过 |
| 班次模板 | STANDARD | 必须有 |
| 薪酬法定参数 | 由 `--approved-by` + 参数文件提供 | 有输入则载入，否则告警跳过 |
| 报表定义 | 全部内置定义 | 必须有 |
| 功能开关 | 5 个（默认 off，`--enable` 显式开启） | 必须有 |
| 管理员账号 | 1 个 `SYSTEM_ADMIN` + 真实 TOTP | 必须有 |
| 员工 / 工资批次 / 案例 | **无** | 必须为 0 |

### D5｜MFA 从"声明"变成"事实"

**决策：**

1. **登录必须真正挑战 MFA。** 若用户 `requiresMfa()` 为真且已登记 TOTP，`POST /v1/auth/login` 返回 `MFA_REQUIRED` 挑战（含 `challenge_id`、`masked_destination`），**不签发会话 cookie**；只有 `POST /v1/auth/mfa/step-up` 成功后会话才成立。前端 `session.store` 已支持 `MFA_REQUIRED` 状态（`contracts.ts:85`），属补后端。
2. **`requiresMfa()` 由能力驱动，不再硬编码。**判据改为：用户持有任一 `field_profile_max` 秩 ≥ `P2_SENSITIVE` 的能力（含 `*`），或 `is_privileged = true`。E-12 的硬编码清单删除。
3. **`mfa_enrolled_at` 只能由真实 factor 确认写入。** 增加不变量：`mfa_enrolled_at != null` ⟺ 存在 `mfa_factors` 中 `confirmed_at != null AND disabled_at IS NULL` 的行。`hrms:install` 与任何种子都不得直接写该列。
4. **删除所有伪造安全承诺的文案**：`MfaEnrollmentDialog.vue` 的"任意 6 位数字可通过演示环境验证"、`LoginView.vue` 的"使用演示验证码 246810"及其按钮。
5. `RequireMfaStepUp` 保持现状（它要求 `hasMfa()`，即 session 的 `mfa_verified`）；登录改造后该值才真正有语义。

**二阶影响：**所有用特权账号登录的既有测试会因 `MFA_REQUIRED` 而失败。已知 `tests/Support/AuthenticatesRequests.php` 与 6 个直接打 `/v1/auth/login` 的测试文件受影响（共 7 个文件）。**实施计划的第一步必须是测量实际失败面**，而不是先改代码。

**三阶影响：**若未来引入 OIDC（GATE-01），`MFA_REQUIRED` 挑战需能被 IdP 的 `acr` 声明旁路。设计中把"是否需要本地挑战"抽象为 `MfaPolicy::challengeRequired(User)`，为 IdP 留出替换点，但不预实现。

### D6｜字段档位真正接线，并修正档位语义

**背景：**E-19/E-20/E-22 说明档位模型虽已实现，但生产路径几乎没用。负责人已确认 `SYSTEM_ADMIN` 可见工资数额（P3），因此**不能**用"P3 永不输出"来表达保护——那与需求直接冲突。

**决策：**

1. **P3 的语义从"永不输出"改为"仅 P3 档位持有者可见"。**具体实现位置在 `CapabilityAuthorizer::fields()`，而非 `FieldProfile::isHidden()`：
   - `fields()` 已经算出 `$effective`（覆盖该行的最高档位）；
   - 生成 per-field profile 时，P3 字段在 `$effective->rank() >= P3` 时映射为 P3，否则映射为 P3 且**保持隐藏**；
   - 即：`FieldSet` 增加一个 `allowRestrictedVisible: bool` 构造参数（由 `fields()` 传入 `$effective->rank() >= P3`），`isVisible()`/`mask()` 据此决定 P3 是否输出。
   - 不修改 `FieldProfile::isHidden()` 本身：它是纯值对象，无调用方上下文，改它会污染所有使用点（`ReportDefinitionCatalog`、`ReportService` 均按"P3 永不可导出"使用，那层语义是对的）。
   - 破坏性影响：`FieldProjectionTest::test_..._p3_hidden` 需改为"P3 绑定可见、P2 绑定不可见"两条断言；`FieldProfileTest` 中 `isHidden()` 的断言**不变**（值对象语义不变）。
2. **P3 即便可见也永远不可导出、不可筛选、不可排序**，且每次输出写 `sensitive_field_access` 审计（`FieldSet::mask()` 已经是唯一出口，在此处审计）。`ReportService` 的 `EXPORT_FIELD_HIDDEN` 行为不变。
3. **薪酬读路径接入投影。** `PayrollPayslipController::summary()`、`PayrollRunService::employees()` 改为接收 `FieldSet` 并按投影输出；员工详情/列表走 `people.employee.read`（P2 上限，故 `id_number`/`bank_account` 遮罩，`salary` 不出现）。
4. **能力上限与输出档位对齐。** `payroll.run.read` 上限为 P2，但 `/runs/{id}/employees` 输出 P3 金额（E-20）→ 该端点的金额字段改由 `payroll.payslip.read`（P3 上限）投影决定；只持 `payroll.run.read` 时返回 `employment_id`、`status`、`failure_reason` 与不含金额的聚合，金额键整体缺席（而非 null），避免"有键无值"被误读。
5. **`*` 绑定的档位固定 `P3_RESTRICTED`**，因此超管可见金额（符合负责人决定），导出仍需 `payroll.export`（超管经 `*` 持有）并触发审计与 A 类复核。
6. **`TENANT` 锚点从 UI 删除**（E-18 幻想选项，后端枚举中不存在）。

### D7｜前端只保留一套真相

**决策：**

1. **生产 bundle 禁含 mock。**构建时若 `VITE_API_MODE !== 'http'` 则构建失败；并增加产物门禁：`dist` 中出现 `demo.example` / `createMockAdapter` 即失败。
2. `getAdminSnapshot()` 改为真实聚合：
   - `users` ← `GET /v1/users`（已实现，E-14）
   - `bindings` ← `GET /v1/users/{id}/bindings`（按当前选中用户）
   - `audit` ← `GET /v1/auth/events`（仅当持有 `iam.audit.read`；`SYSTEM_ADMIN` 持有 `*` 故可见）
   - `accessReview` ← 新增 `GET /v1/access-review`（复用 `AccessReviewCommand` 的计算，仅读）
   - 删除两处"投影未发布"占位文案
3. 侧栏租户名来自 `/v1/me` 返回的真实租户信息（新增字段 `tenant_name`、`legal_entity_name`），删除 `DEMO · 上海` 硬编码。
4. `MfaEnrollmentDialog` 与 `LoginView` 删除演示码提示（D5.4）。

**关于 `SYSTEM_ADMIN` 与审计的说明：**原设计刻意让 `SYSTEM_ADMIN` 缺少 `iam.audit.read`（职责分离）。在 `*` 模型下该限制不再成立——`*` 覆盖 `iam.audit.read`。这是负责人决定的直接后果，用以下手段承接风险，而非偷偷保留例外：
- A 类 SoD 复核清单包含"超管持有审计权"；
- `AUDITOR` 角色保持独立，用于**交叉复核**；
- 审计表只追加、无更新/删除 HTTP 面，超管也只能读。

### D8｜OpenAPI 与文档同步

**决策：**

1. 新增 `GET /v1/access-review` 必须先进 `docs/openapi/iam.yaml` 再实现（契约即法律）。
2. `*` 通配符需要在 OpenAPI 中作为 `capability_code` 的合法枚举外值记录（`oneOf: [enum(76), const "*"]`），并说明只有模板路径可产生。
3. `docs/openapi/openapi.yaml` 的 `servers` 从 `http://localhost:8000` 更新为 `http://localhost:5174`（Docker 唯一入口）。
4. 新增 ADR-0016（通配符最高权限与 SoD 两类拆分）、ADR-0017（生产安装基线、MFA 登录挑战、能力驱动的 MFA 判据、P3 语义修正）。
5. 修订 ADR-0004 第 7 条与 ADR-0013「不保留永久超级管理员」的措辞：改为"不保留**不受时限与复核约束的**超级管理员"。`docs/02-permissions.md` 同步。

---

## 4. 二阶与三阶影响汇总

| 影响 | 描述 | 处置 |
|---|---|---|
| **二阶-1** | `/v1/me/capabilities` 返回 `*` 会让前端菜单全空 | D1：序列化层展开为 76 条 + `wildcard` 标志 |
| **二阶-2** | `SYSTEM_ADMIN` 获得 `*` 后自动获得 `iam.audit.read`，与旧"职责分离"设计冲突 | D7：不偷偷保留例外，改为 A 类复核 + `AUDITOR` 交叉复核，并修订文档 |
| **二阶-3** | P3 语义改变会破坏既有 `FieldProjectionTest`、`FieldProfileTest` | 计划中列为"破坏性测试重写"专项任务，先改测试再改实现 |
| **二阶-4** | 登录强制 MFA 挑战会打破 7 个测试文件 | 计划第一步：测量失败面，再决定是否引入测试替身 `MfaPolicy::fake()` |
| **二阶-5** | G-COV-1/2/3 会给未来 PR 增加摩擦 | 写进 `AGENTS.md` 的"新增能力三步法"，并在错误信息中直接给出修复指引 |
| **三阶-1** | 通配符可能被滥用 | `capability:audit` 限制 `*` 只出现在两个模板；UI 不提供 `*` 选项 |
| **三阶-2** | 生产安装器成为新的关键路径，其缺陷会阻断所有部署 | 安装器必须幂等 + 分步报告 + 集成测试（SQLite 与 MySQL 各一次） |
| **三阶-3** | 强制 MFA 会提高运维账号的登录摩擦 | 提供恢复码、break-glass 双人通道；文档写明离线保存要求 |
| **三阶-4** | 参考数据中的法定参数若填错，会污染所有工资计算 | 参数必须带 `--approved-by` 与 `evidence_ref`；`is_demo` 标志保留，安装器写入的参数标记 `is_demo=false` |
| **长期维护** | 能力目录增长不再需要同步修改超管模板 | D1 + G-COV-1 共同保证 |
| **长期维护** | 安装清单成为唯一参考数据真相源 | 每个 seed 命令保留独立可用性，安装器只做编排，不重复实现 |

---

## 5. 明确不做（YAGNI）

- 不引入 `CUSTOM` 锚点或任意 SQL 谓词语言。
- 不引入 `ORG_UNIT` / `ORG_SUBTREE` 级通配符。
- 不实现完整的"访问复核工作流"（审批、签字、归档）；本设计只提供 `GET /v1/access-review` 只读投影 + 既有 CLI。
- 不做多租户开通自助化；`hrms:install` 只支持单租户单法人（多法人可重复执行）。
- 不实现 IdP/OIDC 旁路（GATE-01 仍为组织门禁），只留抽象点。
- 不重写已批准的解决方案与实施计划文档。

---

## 6. 验收标准

| 编号 | 标准 | 验证方式 |
|---|---|---|
| AC-01 | `SYSTEM_ADMIN` 通过 `/v1/me/capabilities` 看到 76 条全部能力且 `wildcard=true` | 集成测试 + 真实栈 curl |
| AC-02 | 新增第 77 个能力后，`SYSTEM_ADMIN` **无需改动模板**即持有 | 单元测试：动态注册假能力后断言 `permits()` 为真 |
| AC-03 | `POST /v1/users/{id}/bindings` 传 `capability_code='*'` 返回 422 | 特性测试 |
| AC-04 | 只读模板（如 `EMPLOYEE`）中出现 `*` 时 `capability:audit` 失败 | 命令测试 |
| AC-05 | 9 个原孤儿能力全部被某模板发放 | `capability:audit` G-COV-1 通过 |
| AC-06 | 每条路由要求的能力都被某模板发放 | G-COV-3 通过 |
| AC-07 | 同一人即使持有 `payroll.run.prepare + approve`，也**不能**审批自己制的批次（对象层 B 类） | `ApprovalService` 既有测试保持绿 + 新增"超管自审"负例 |
| AC-08 | 同一人可被授予 `iam.binding.manage + iam.audit.read`，但缺 `acknowledge_sod_risk` 时 422，带确认时 200 且写审计 | 特性测试 |
| AC-08b | 同一人被授予 `payroll.run.prepare + approve` 时同样只产生风险信号，不阻断授权 | 特性测试 |
| AC-08c | 对象层不变量不被放宽：制单人审批自己的批次仍返回 409 `SOD_CONFLICT` | 特性测试 |
| AC-09 | `hrms:install` 在空库执行后：10 模板、8 工作流模板、6 生命周期模板、3 假期类型、1 日历、1 班次、1 管理员、**0 员工** | 集成测试断言各表计数 |
| AC-10 | `hrms:install` 重复执行不产生重复行 | 连续执行两次，计数不变 |
| AC-11 | 安装器的管理员有 1 个已确认 TOTP factor，且 `mfa_enrolled_at` 非空 | 集成测试 |
| AC-12 | 特权用户登录未完成 TOTP 前不签发会话 | 特性测试：`MFA_REQUIRED` 且无 cookie |
| AC-13 | `mfa_enrolled_at` 非空 ⟺ 存在已确认 factor | 不变量测试（含负例：手工置空 factor 后断言检测到） |
| AC-14 | 登录页、MFA 弹窗、侧栏、README、docker_up 均无"演示/246810/任意 6 位" | `grep` 门禁 |
| AC-15 | 生产 `dist` 无 `demo.example`、无 `createMockAdapter` | 构建门禁 |
| AC-16 | `VITE_API_MODE != http` 时构建失败 | 构建门禁负例 |
| AC-17 | `GET /v1/payroll/payslips` 对 P3 持有者返回金额，对 P2 持有者不返回金额字段 | 特性测试 |
| AC-18 | `GET /v1/payroll/runs/{id}/employees` 对 `payroll.run.read`（P2）持有者**不**返回 `gross_cents` 等金额 | 特性测试 |
| AC-19 | `GET /v1/access-review` 返回未使用高风险绑定与 A 类组合 | 特性测试 + OpenAPI 契约测试 |
| AC-20 | 非超管无法创建含 `*` 的角色或绑定 | 特性测试 |
| AC-20b | 存在 `*` 绑定时 `iam:sync-capabilities` 仍能成功且不删除该绑定 | 命令测试（E-23 回归） |
| AC-21 | `docker compose down -v` 后 `docker_up.sh` 一条命令到达可用状态 | 真实栈演练 |
| AC-22 | 全量门禁 23+3 项通过 | `scripts/verify_release.sh` |

---

## 7. 风险与缓解

| 风险 | 级别 | 缓解 |
|---|---|---|
| P3 语义变更破坏既有报表/导出行为 | 高 | 分两步：先加"有效档位"入参并保持旧默认，再切换调用点；每步独立提交 |
| 登录强制 MFA 打破大量测试 | 高 | 计划首任务测量失败面；提供 `MfaPolicy` 测试替身 |
| 安装器成为单点故障 | 中 | 幂等 + 分步独立可重跑 + MySQL/SQLite 双环境集成测试 |
| `*` 被滥用 | 中 | 模板白名单门禁 + UI 不提供 + 审计 |
| SoD 放宽被误解为"取消内控" | 中 | A/B 两类拆分写进 ADR-0016；B 类不变量保持并加测试 |
| 法定参数误填污染工资 | 高 | `--approved-by` + `evidence_ref` 必填；安装报告明确列出写入参数版本 |

---

## 8. 需负责人确认的状态

| 事项 | 状态 |
|---|---|
| 最高权限建模为通配符 `*` + 限时绑定 | **已确认** |
| `SYSTEM_ADMIN` 可见 P3 工资数额 | **已确认** |
| 生产初始数据 = 参考数据 + 1 管理员 + 0 员工，并补齐功能所需数据 | **已确认** |
| 文档按 superpowers 规范落位 | **已确认** |
| 本设计文档 | **待批准** |

批准后进入 `docs/superpowers/plans/2026-11-24-superuser-coverage-and-production-baseline.md`。
