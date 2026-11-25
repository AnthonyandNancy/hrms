#!/usr/bin/env python3
"""Generate RegistryEntry literals for frontend/src/router/registry.ts.

This script is a developer tool, not runtime code. It reads the machine-
generated inventory so the 140 business page entries are never hand-counted.
The platform entries are derived from the blueprint's 03B table.
"""

from __future__ import annotations

import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
INVENTORY = ROOT / "docs/audit/2026-11-25-v11-page-inventory.md"
BLUEPRINT = ROOT / "docs/audit/2026-11-25-v11-page-access-blueprint.md"

MENU_GROUP = {
    "仪表盘": "仪表盘",
    "报表中心": "报表中心",
    "员工档案": "员工档案",
    "部门管理": "部门管理",
    "岗位管理": "岗位管理",
    "员工异动": "员工异动",
    "劳动合同": "劳动合同",
    "考勤打卡": "考勤打卡",
    "请假管理": "请假管理",
    "加班管理": "加班管理",
    "出差管理": "出差管理",
    "薪资管理": "薪资管理",
    "绩效考核": "绩效考核",
    "招聘需求": "招聘需求",
    "候选人": "候选人",
    "培训管理": "培训管理",
    "公告通知": "公告通知",
}

MENU_ORDER = {name: index + 1 for index, name in enumerate(MENU_GROUP)}

# Stable component keys. A missing view still gets an explicit key so the
# coverage audit can report it as missing rather than silently hiding it.
COMPONENT_PREFIX = {
    "仪表盘": "dashboard",
    "报表中心": "reports",
    "员工档案": "hr/employees",
    "部门管理": "hr/departments",
    "岗位管理": "hr/positions",
    "员工异动": "hr/movements",
    "劳动合同": "hr/contracts",
    "考勤打卡": "attendance",
    "请假管理": "leave",
    "加班管理": "overtime",
    "出差管理": "travel",
    "薪资管理": "payroll",
    "绩效考核": "performance",
    "招聘需求": "recruitment/requests",
    "候选人": "recruitment/candidates",
    "培训管理": "training",
    "公告通知": "notices",
}

# Each page has a primary read API. These are canonical product paths from
# the blueprint; the audit marks any path that does not yet exist in OpenAPI
# as contract-pending rather than claiming implementation.
MENU_API = {
    "仪表盘": "GET /v1/reporting/dashboard",
    "报表中心": "GET /v1/reporting/definitions",
    "员工档案": "GET /v1/employees",
    "部门管理": "GET /v1/org-units/tree",
    "岗位管理": "GET /v1/positions",
    "员工异动": "GET /v1/lifecycle/cases",
    "劳动合同": "GET /v1/employee-contracts",
    "考勤打卡": "GET /v1/time/attendance",
    "请假管理": "GET /v1/time/leave/requests",
    "加班管理": "GET /v1/overtime",
    "出差管理": "GET /v1/travel",
    "薪资管理": "GET /v1/payroll/runs",
    "绩效考核": "GET /v1/performance/cycles",
    "招聘需求": "GET /v1/recruitment/requisitions",
    "候选人": "GET /v1/recruitment/candidates",
    "培训管理": "GET /v1/training/programs",
    "公告通知": "GET /v1/engagement/notices",
}


def slug(path: str) -> str:
    cleaned = path.strip("/") or "home"
    cleaned = re.sub(r":([a-zA-Z_][a-zA-Z0-9_]*)", r"by-\1", cleaned)
    cleaned = re.sub(r"[^a-zA-Z0-9]+", "-", cleaned).strip("-").lower()
    return cleaned


def component_key(menu: str, path: str) -> str:
    prefix = COMPONENT_PREFIX[menu]
    tail = path.strip("/")
    # Strip the most specific route prefix first, then the menu root. This
    # maps /hr/employees/:id/education to hr/employees/by-id/education and
    # never duplicates the /employees segment.
    for candidate in [prefix, prefix.split("/")[0]]:
        if tail == candidate:
            return f"{prefix}/index"
        if tail.startswith(candidate + "/"):
            rest = tail[len(candidate) + 1 :]
            rest = re.sub(r":([a-zA-Z_][a-zA-Z0-9_]*)", lambda m: "by-" + m.group(1), rest)
            return f"{prefix}/{rest}"
    rest = re.sub(r":([a-zA-Z_][a-zA-Z0-9_]*)", lambda m: "by-" + m.group(1), tail)
    return f"{prefix}/{rest}"


def sql_array(values: list[str]) -> str:
    return "[" + ", ".join(repr(value).replace("'", '"') for value in values) + "]"


def parse_pages() -> list[dict[str, str]]:
    text = INVENTORY.read_text(encoding="utf-8")
    rows = re.findall(
        r"^\|\s*(\d+)\s*\|\s*([^|]+?)\s*\|\s*([^|]+?)\s*\|\s*`([^`]+)`\s*\|\s*`([^`]+)`\s*\|\s*(Wave \d+)\s*\|$",
        text,
        re.M,
    )
    pages = []
    for number, menu, name, route, capability, wave in rows:
        pages.append(
            {
                "number": number,
                "menu": menu.strip(),
                "name": name.strip(),
                "route": route.strip(),
                "capability": capability.strip(),
                "wave": wave.strip(),
            }
        )
    return pages


def parse_modules() -> list[dict[str, str]]:
    text = INVENTORY.read_text(encoding="utf-8")
    rows = re.findall(
        r"^\|\s*(03B\.\d+)\s*\|\s*([^|]+?)\s*\|\s*`([^`]+)`\s*\|",
        text,
        re.M,
    )
    return [
        {"code": code, "name": name.strip(), "route": route.strip()}
        for code, name, route in rows
        if code != "03B.15" and code != "03B.16"
    ]


def module_capability(module: str) -> str:
    return {
        "用户管理": "iam.user.read",
        "角色管理": "iam.role.manage",
        "菜单/页面管理": "iam.menu.manage",
        "操作权限配置": "iam.permission.read",
        "数据范围权限": "iam.scope.manage",
        "字段级权限": "iam.field.manage",
        "审批流程中心": "workflow.case.read",
        "员工自助门户": "iam.session.manage",
        "社保公积金与福利": "benefit.read",
        "节假日与工作日历": "time.calendar.read",
        "字典、参数与文件": "system.settings.manage",
        "消息与通知中心": "notification.read",
        "日志审计与运维": "iam.audit.read",
        "登录与账号安全": "iam.session.manage",
    }[module]


def module_route(module: dict[str, str]) -> str:
    # 03B.1's operational entry is the account-security hub. /login and the
    # individual MFA/session pages are the six base entries, so this keeps all
    # 160 registry paths unique.
    if module["code"] == "03B.1":
        return "/account"
    return module["route"]


def module_component(route: str) -> str:
    return "system/" + slug(route) if route.startswith("/system") else slug(route)


def emit() -> str:
    pages = parse_pages()
    modules = parse_modules()
    if len(pages) != 140:
        raise SystemExit(f"expected 140 business pages, got {len(pages)}")
    if len(modules) != 14:
        raise SystemExit(f"expected 14 platform modules, got {len(modules)}")

    lines = []
    for page in pages:
        menu = page["menu"]
        path = page["route"]
        name = slug(path)
        if path == "/hr/employees/:id":
            name = "employee-detail"
        lines.append(
            "  {"
            f"path: {page['route']!r}, name: {name!r}, componentKey: {component_key(menu, path)!r}, "
            f"capability: {page['capability']!r}, "
            f"menu: {{ group: {menu!r}, label: {page['name']!r}, order: {page['number']}, icon: 'Menu' }}, "
            f"layer: 'business', wave: {page['wave']!r}, "
            f"requiredApis: [{MENU_API[menu]!r}], "
            "states: [...REQUIRED_STATES], "
            f"e2e: ['{page['number']}-{name}'], "
            "negativeTests: [...REQUIRED_NEGATIVE_TESTS],"
            "},"
        )
    for module in modules:
        route = module_route(module)
        name = slug(route)
        capability = module_capability(module["name"])
        lines.append(
            "  {"
            f"path: {route!r}, name: {name!r}, componentKey: {module_component(route)!r}, "
            f"capability: {capability!r}, "
            f"menu: {{ group: '系统管理', label: {module['name']!r}, order: {int(module['code'].split('.')[1]) + 100}, icon: 'Menu' }}, "
            "layer: 'platform', wave: 'Wave 5', "
            f"requiredApis: [{MENU_API.get(module['name'], 'GET /v1/me/permissions')!r}], "
            "states: [...REQUIRED_STATES], "
            f"e2e: ['platform-{module['code'].replace('.', '-')}'], "
            "negativeTests: [...REQUIRED_NEGATIVE_TESTS],"
            "},"
        )
    return "\n".join(lines)


if __name__ == "__main__":
    body = emit()
    output = ROOT / ".superpowers/sdd/registry-entries.ts"
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(body + "\n", encoding="utf-8")
    print(f"wrote {output}")
    print(f"business=140 platform=14 total={140 + 14}")
