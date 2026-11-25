# ADR-0025: 内网 MFA 与运维姿态

- **状态：** Accepted — 2026-11-25
- **决策：** C7（内网 MFA 策略）
- **取代：** ADR-0017 第 3 条的“生产默认强制 MFA”作为绝对门槛；保留 MFA 能力
- **复审触发：** 系统接入外网/远程办公、管理员数量增长、出现凭证泄露事件、或内网边界变化

## 背景

V1.1 §11 与 §03B.1 要求高权限 MFA/强认证；当前代码默认 `IAM_MFA_ENABLED=false`
（ADR-0018）。项目负责人确认：系统**仅在内网部署**，无外网暴露，环境变量/密钥
不构成本轮交付门槛；目标是全量落地 V1.1 功能，而不是把安全基础设施作为阻塞项。

## 决定

1. **MFA 能力保留并可用**：TOTP、恢复码、step-up、break-glass 均按现状保留；
   管理员可在 `/account/security` 启用。
2. **内网生产默认开启 MFA**；安装器在首次安装时生成 TOTP 并打印一次性密钥；
   若运维选择关闭，必须显式设置 `IAM_MFA_ENABLED=false`，安装器记录
   `install.mfa_skipped` 审计。
3. **MFA 不作为本轮交付的阻塞门槛**：验收以功能页面、权限、真实栈 E2E 为主；
   MFA 的缺失不会让 Wave 1–5 失败，但会在 Wave 6 的生产姿态检查中列为
   `RECOMMENDED`，不是 FAIL。
4. **环境变量/密钥不构成本轮门槛**：内网部署；不要求 KMS、外部密钥托管、公网渗透、
   SBOM 签名作为阻塞项；仍禁止把真实口令写入仓库，安装器生成独立 `APP_KEY`。
5. **审计不可省**：登录、失败、MFA 启用/关闭、break-glass、敏感读取、导出仍写审计。

## 备选方案

| 方案 | 拒绝原因 |
|---|---|
| 生产强制 MFA 才能上线 | 与用户“功能优先、内网”决定冲突；会拖慢 140 页交付 |
| 完全删除 MFA | 丢失内网纵深防御；恢复成本高 |
| 用 SSO/IdP 替代 | 内网无 IdP；外部依赖不在本轮范围 |
| 把密钥管理纳入本轮 | 内网、无外网暴露；投入产出比低 |

## 后果

- `IAM_MFA_ENABLED` 默认仍为 `false`，但生产安装器推荐开启；文档明确标注。
- `MfaPolicy`、`MfaController`、`RequireMfaEnrollment`、`RequireMfaStepUp` 不改语义。
- `install.mfa_skipped` 审计可被 `/system/audit` 查询。
- 安全证据从“强制 MFA”转为“MFA 可用 + 审计 + 内网边界声明”。

## 验证

- `MfaLoginChallengeTest`、`MfaEnrollmentInvariantTest`、`MfaPolicyTest` 保持通过。
- 安装器：`IAM_MFA_ENABLED=true` 时创建真实 TOTP；`false` 时写 `install.mfa_skipped`。
- `/account/security` 页面可启用/重新绑定 MFA。
- 审计：MFA 启用/关闭、失败、break-glass 均可按 `request_id` 查询。

## 复审触发

- 接入外网、远程办公、第三方运维。
- 管理员数量 > 5 或出现共享账号。
- 凭证泄露/钓鱼事件。
- 法规或集团安全基线要求强制 MFA。
