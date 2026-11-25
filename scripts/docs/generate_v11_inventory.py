#!/usr/bin/env python3
"""Generate the V1.1 page inventory from the authoritative implementation book.

This is a documentation generator, not application code. It keeps the page
count and route list machine-derived so the plan cannot drift from the V1.1
source by hand-editing a table.
"""

from __future__ import annotations

import re
from collections import Counter
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SOURCE = ROOT / "HRMS-V1.1实施书.md"
OUTPUT = ROOT / "docs/audit/2026-11-25-v11-page-inventory.md"

MENU_META = {
    "概览 / 仪表盘": ("仪表盘", "reporting.dashboard.read", "Wave 5"),
    "概览 / 报表中心": ("报表中心", "reporting.export", "Wave 5"),
    "组织与人事 / 员工档案": ("员工档案", "people.employee.read", "Wave 2"),
    "组织与人事 / 部门管理": ("部门管理", "org.unit.read", "Wave 2"),
    "组织与人事 / 岗位管理": ("岗位管理", "org.position.read", "Wave 2"),
    "组织与人事 / 员工异动": ("员工异动", "lifecycle.case.read", "Wave 2"),
    "组织与人事 / 劳动合同": ("劳动合同", "people.contract.read", "Wave 2"),
    "考勤与假期 / 考勤打卡": ("考勤打卡", "time.attendance.read", "Wave 3"),
    "考勤与假期 / 请假管理": ("请假管理", "time.leave.read", "Wave 3"),
    "考勤与假期 / 加班管理": ("加班管理", "time.overtime.read", "Wave 3"),
    "考勤与假期 / 出差管理": ("出差管理", "time.travel.read", "Wave 3"),
    "薪酬与绩效 / 薪资管理": ("薪资管理", "payroll.run.read", "Wave 3"),
    "薪酬与绩效 / 绩效考核": ("绩效考核", "performance.cycle.read", "Wave 4"),
    "招聘与培训 / 招聘需求": ("招聘需求", "recruitment.requisition.read", "Wave 4"),
    "招聘与培训 / 候选人": ("候选人", "recruitment.candidate.read", "Wave 4"),
    "招聘与培训 / 培训管理": ("培训管理", "training.program.read", "Wave 4"),
    "行政与系统 / 公告通知": ("公告通知", "engagement.notice.read", "Wave 5"),
}


def parse() -> tuple[list[dict[str, str]], list[dict[str, str]]]:
    text = SOURCE.read_text(encoding="utf-8")
    parts = re.split(r"\n## (03A\.\d+[^\n]*)\n", text)
    pages: list[dict[str, str]] = []

    for index in range(1, len(parts), 2):
        section = parts[index].strip()
        # Strip the numeric section prefix so the metadata keys stay readable.
        section_key = re.sub(r"^03A\.\d+\s+", "", section).strip()
        body = parts[index + 1]
        rows = re.findall(r"^\|\s*([^|]+?)\s*\|\s*`([^`]+)`\s*\|", body, re.M)
        menu, capability, wave = MENU_META.get(section_key, (section_key, "UNKNOWN", "UNKNOWN"))
        for order, (name, route) in enumerate(rows, 1):
            pages.append(
                {
                    "section": section,
                    "menu": menu,
                    "order": str(order),
                    "name": name.strip(),
                    "route": route.strip(),
                    "capability": capability,
                    "wave": wave,
                }
            )

    start = text.find("# 03B.")
    end = text.find("# 04. 权限体系")
    section = text[start:end]
    modules: list[dict[str, str]] = []

    for match in re.finditer(r"## (03B\.\d+[^\n]*)\n(.*?)(?=\n## 03B\.|\Z)", section, re.S):
        title = match.group(1).strip()
        code = title.split()[0].strip()
        # 03B.15 and 03B.16 are requirements/descriptions, not standalone
        # platform modules. Only 03B.1-03B.14 count.
        if code not in {f"03B.{index}" for index in range(1, 15)}:
            continue
        body = match.group(2)
        path = re.search(r"\*\*建议菜单与路径：\*\*\s*`([^`]+)`", body)
        pages_text = re.search(r"页面包括：\s*([^\n]+)", body)
        entities = re.search(r"\*\*主要实体：\*\*\s*([^\n]+)", body)
        acceptance = re.search(r"\*\*验收用例：\*\*\s*([^\n]+)", body)

        def clean(value: re.Match[str] | None) -> str:
            return value.group(1).strip().replace("|", "／") if value else "—"

        modules.append(
            {
                "code": code,
                "name": title.split(" ", 1)[1].strip() if " " in title else title,
                "path": clean(path),
                "pages": clean(pages_text),
                "entities": clean(entities),
                "acceptance": clean(acceptance),
            }
        )

    return pages, modules


def main() -> None:
    pages, modules = parse()
    counts = Counter(page["menu"] for page in pages)
    waves = Counter(page["wave"] for page in pages)

    lines = [
        "# V1.1 页面与平台模块清单（机器生成）",
        "",
        "> 来源：`HRMS-V1.1实施书.md` 第 03A/03B 章。不要手改本文件；运行 `python scripts/docs/generate_v11_inventory.py` 重新生成。",
        f"> 源文件 SHA-256：{__import__('hashlib').sha256(SOURCE.read_bytes()).hexdigest()[:16]}",
        "",
        "## 1. 汇总",
        "",
        f"- 03A 截图菜单：**{len(counts)}** 个",
        f"- 03A 页面：**{len(pages)}** 个",
        f"- 03B 平台模块：**{len(modules)}** 个",
        "",
        "| 波次 | 页面数 | 范围 |",
        "|---|---:|---|",
    ]

    wave_scope = {
        "Wave 2": "组织与人事：员工、部门、岗位、异动、合同",
        "Wave 3": "假勤与薪酬：考勤、请假、加班、出差、薪资",
        "Wave 4": "绩效、招聘、培训",
        "Wave 5": "概览、报表、公告，以及 03B 平台页面",
    }
    for wave in ["Wave 2", "Wave 3", "Wave 4", "Wave 5"]:
        lines.append(f"| {wave} | {waves.get(wave, 0)} | {wave_scope[wave]} |")

    lines.extend(
        [
            "",
            "| 菜单 | 页面数 | 主能力域 | 波次 |",
            "|---|---:|---|---|",
        ]
    )
    for menu, count in counts.items():
        capability = next(page["capability"] for page in pages if page["menu"] == menu)
        wave = next(page["wave"] for page in pages if page["menu"] == menu)
        lines.append(f"| {menu} | {count} | `{capability}` | {wave} |")

    lines.extend(
        [
            "",
            "## 2. 03A 页面清单",
            "",
            "| # | 菜单 | 页面 | 路由 | 主能力域 | 波次 |",
            "|---:|---|---|---|---|---|",
        ]
    )
    for index, page in enumerate(pages, 1):
        lines.append(
            f"| {index} | {page['menu']} | {page['name']} | `{page['route']}` | `{page['capability']}` | {page['wave']} |"
        )

    lines.extend(
        [
            "",
            "## 3. 03B 平台模块清单",
            "",
            "| 编号 | 模块 | 建议路由 | 页面/功能 | 主要实体 | 验收用例 |",
            "|---|---|---|---|---|---|",
        ]
    )
    for module in modules:
        lines.append(
            f"| {module['code']} | {module['name']} | `{module['path']}` | {module['pages']} | {module['entities']} | {module['acceptance']} |"
        )

    lines.extend(
        [
            "",
            "## 4. 验收规则",
            "",
            "- 页面注册表必须与本清单一一对应；多一个或少一个都失败。",
            "- 03A 的 140 个页面是主验收范围；03B 的 14 个模块必须各有独立路由、权限、API、空/错/无权限态与真实 E2E。",
            "- 主能力域是页面默认所需的最小能力；具体动作（create/update/approve/export）在角色分配中单独授予。",
            "- 页面可见性 = 页面授权 AND 动作能力；仅有菜单授权不能调用 API，仅有动作能力未授权页面也不会出现在菜单。",
            "",
        ]
    )

    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    OUTPUT.write_text("\n".join(lines), encoding="utf-8")
    print(f"wrote {OUTPUT.relative_to(ROOT)}")
    print(f"pages={len(pages)} menus={len(counts)} modules={len(modules)} waves={dict(waves)}")


if __name__ == "__main__":
    main()
