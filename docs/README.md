# HRMS V1.0 文档索引

本目录包含两份正式交付件的 Markdown 源文件与插图。文档版本为 v1.1：设计决策已按推荐完成研究并定稿，组织签认与触发式决策分别登记。

| 文档 | 源文件 | 正式交付 |
| 解决方案：第一性原理重构 | `HRMS_V1.0_解决方案_第一性原理重构.md` | `../交付物/HRMS_V1.0_解决方案_第一性原理重构.docx` / `.pdf` |
| 实施计划：30 周落地路线图 | `HRMS_V1.0_实施计划_30周路线图.md` | `../交付物/HRMS_V1.0_实施计划_30周路线图.docx` / `.pdf` |

## 变更提案（superpowers 工作流）

| 提案 | 设计文档 | 修改计划 | 状态 |
|---|---|---|---|
| 最高权限完整性、生产基线数据与去演示化 | `superpowers/specs/2026-11-24-superuser-coverage-and-production-baseline-design.md` | `superpowers/plans/2026-11-24-superuser-coverage-and-production-baseline.md` | 已批准，按 Subagent-Driven 执行 |
| V1.1 生产完整性（17 菜单、140 业务页、14 平台模块、6 基础页、26 工作包） | `superpowers/specs/2026-11-25-v11-production-completion-design.md` | `superpowers/plans/2026-11-25-v11-production-completion-implementation-plan.md` | **已确认**；页面/权限蓝皮书 `audit/2026-11-25-v11-page-access-blueprint.md`；清单 `audit/2026-11-25-v11-page-inventory.md`；审计 `audit/2026-11-25-v11-gap-audit.md` |
| V1.1 缺口补全（63 项 → 12 能力集群 + 3 内核） | `superpowers/specs/2026-11-25-v11-gap-completion-design.md` | `superpowers/plans/2026-11-25-v11-gap-completion-implementation-plan.md` | **待用户确认**；ADR-0028～0032 |

该提案修订（不重写）上述两份已批准交付件，涉及 ADR-0004 第 7 条与 ADR-0013 的表述边界，并新增 ADR-0016、ADR-0017。


## 阅读顺序

1. 先读解决方案 00–04 章：确认问题定义、五项已定稿决策、备选路线与五层模型。
2. 再读第 05–09 章：事实内核、时间、授权与身份、决策/生效、账本与关账；薪酬与身份参数分别在 9.8 与 7.11。
3. 读第 12–16 章：二阶/三阶影响、复杂度预算、度量、风险与可逆性、结论与签认。
4. 读**附录 E 决策登记册**：D-01–D-25 设计决策、S-01–S-08 组织签认、T-01–T-06 触发式决策。
5. 读实施计划第 01–05 章：承诺边界、工程基线、30 周路线、工作包、质量门禁。
6. 读实施计划第 08–12 章：迁移切换、合规验收、治理、风险、Hypercare。
7. 读实施计划第 15–16 章：ADR 清单与模板、签认页与变更规则。

## 不要跳过的事

- 解决方案附录 E 是决策单一事实源：25 项设计决策已定稿，8 项组织签认按 W2–W8 完成，6 项触发式决策按阈值复审。
- 解决方案 16.2 与实施计划 10.4 的同一组签认事项需要责任人签字；未签认阻断对应 release，不阻断设计定稿。
- 实施计划第 15 章的 ADR-0001–0015 必须在表列截止日前落成；逾期实现视为架构违规。
- 安全、工资正确性、迁移对账三类门禁不可豁免。

## 重新生成

```bash
python3 make_diagrams.py
python3 build_docs.py
powershell -NoProfile -ExecutionPolicy Bypass -File convert_final.ps1
```
- [ADR-0016](adr/0016-wildcard-superuser-and-sod-layering.md)
- [ADR-0017](adr/0017-production-install-baseline-and-mfa-login.md)
- [ADR-0018](adr/0018-password-only-login.md)
- [ADR-0024](adr/0024-superuser-full-authority-and-p3-audit.md)
- [ADR-0025](adr/0025-intranet-mfa-and-operational-posture.md)
- [ADR-0026](adr/0026-page-coverage-registry.md)
- [ADR-0027](adr/0027-page-permission-and-role-allocation.md)
- [ADR-0028](adr/0028-capability-loop-projection-contract-platform-kernel.md)
- [ADR-0029](adr/0029-projection-contract-and-freshness.md)
- [ADR-0030](adr/0030-command-envelope-and-effect-retry.md)
- [ADR-0031](adr/0031-platform-kernel-ownership.md)
- [ADR-0032](adr/0032-scope-and-field-policy-write-model.md)
