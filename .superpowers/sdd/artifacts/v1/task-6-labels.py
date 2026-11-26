#!/usr/bin/env python3
"""Insert x-action-label into every capability-guarded write operation in openapi.yaml."""
from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[4]
SPEC = ROOT / 'docs/openapi/openapi.yaml'

# method, path -> Chinese label
LABELS: dict[tuple[str, str], str] = {
    ('POST', '/v1/auth/break-glass'): '申请紧急授权',
    ('POST', '/v1/auth/break-glass/{id}/revoke'): '撤销紧急授权',
    ('POST', '/v1/auth/logout'): '退出登录',
    ('POST', '/v1/auth/logout-all'): '退出全部会话',
    ('POST', '/v1/auth/mfa/step-up'): '完成二次验证',
    ('POST', '/v1/auth/mfa/totp/confirm'): '确认动态口令',
    ('POST', '/v1/auth/mfa/totp/enroll'): '绑定动态口令',
    ('POST', '/v1/auth/password'): '修改密码',
    ('POST', '/v1/auth/webauthn/assert/options'): '发起安全密钥验证',
    ('POST', '/v1/auth/webauthn/register/options'): '注册安全密钥',
    ('POST', '/v1/benefits/enrollments'): '登记社保基数调整',
    ('POST', '/v1/benefits/plans'): '新增福利方案版本',
    ('POST', '/v1/benefits/transactions'): '登记福利缴款',
    ('DELETE', '/v1/bindings/{id}'): '撤销授权绑定',
    ('POST', '/v1/cases'): '发起审批事项',
    ('POST', '/v1/cases/{caseId}/decisions'): '提交审批决定',
    ('POST', '/v1/cases/{id}/submit'): '提交审批事项',
    ('POST', '/v1/cases/{id}/withdraw'): '撤回审批事项',
    ('POST', '/v1/contract-templates'): '新增合同模板版本',
    ('POST', '/v1/contract-templates/{id}/publish'): '发布合同模板',
    ('POST', '/v1/delegations'): '新增审批委托',
    ('DELETE', '/v1/delegations/{id}'): '撤销审批委托',
    ('POST', '/v1/departments/{id}/members'): '调入部门成员',
    ('DELETE', '/v1/documents/{document_id}'): '删除员工附件',
    ('POST', '/v1/effects/{id}/retry'): '重试审批副作用',
    ('POST', '/v1/employees/{employmentId}/certificates'): '新增员工证书',
    ('PATCH', '/v1/employees/{employmentId}/certificates/{id}'): '编辑员工证书',
    ('POST', '/v1/employees/{employmentId}/change_requests'): '新增员工变更申请',
    ('PATCH', '/v1/employees/{employmentId}/change_requests/{id}'): '编辑员工变更申请',
    ('POST', '/v1/employees/{employmentId}/change_requests/{id}/submit'): '提交员工变更申请',
    ('POST', '/v1/employees/{employmentId}/contacts'): '新增紧急联系人',
    ('PATCH', '/v1/employees/{employmentId}/contacts/{id}'): '编辑紧急联系人',
    ('POST', '/v1/employees/{employmentId}/education'): '新增教育经历',
    ('PATCH', '/v1/employees/{employmentId}/education/{id}'): '编辑教育经历',
    ('POST', '/v1/employees/{employmentId}/experiences'): '新增工作经历',
    ('PATCH', '/v1/employees/{employmentId}/experiences/{id}'): '编辑工作经历',
    ('POST', '/v1/employees/{employmentId}/imports'): '导入员工资料',
    ('PATCH', '/v1/employees/{employmentId}/imports/{id}'): '更新导入记录',
    ('DELETE', '/v1/employees/{employment_id}'): '归档员工',
    ('POST', '/v1/employees/{employment_id}/restore'): '恢复员工',
    ('DELETE', '/v1/employment-contracts/{id}'): '删除劳动合同',
    ('POST', '/v1/employment-contracts/{id}/restore'): '恢复劳动合同',
    ('POST', '/v1/employment-contracts/{id}/amendments'): '新增合同变更',
    ('POST', '/v1/employment-contracts/{id}/terminate'): '终止劳动合同',
    ('POST', '/v1/employments'): '新增员工',
    ('POST', '/v1/employments/{employmentId}/contracts'): '签订劳动合同',
    ('POST', '/v1/employments/{employment_id}/assignments'): '新增任职记录',
    ('POST', '/v1/employments/{employment_id}/document-files'): '上传员工附件',
    ('POST', '/v1/employments/{employment_id}/documents'): '关联员工附件',
    ('POST', '/v1/employments/{employment_id}/terms'): '新增合同条款',
    ('POST', '/v1/employments/{employment_id}/transfer'): '转移员工主体',
    ('POST', '/v1/engagement/notice-templates'): '发布公告模板',
    ('POST', '/v1/engagement/notices'): '发布公告',
    ('POST', '/v1/engagement/notices/{notice}/acknowledge'): '确认已读公告',
    ('POST', '/v1/engagement/notices/{notice}/archive'): '归档公告',
    ('POST', '/v1/engagement/notices/{notice}/decision'): '审批公告',
    ('POST', '/v1/engagement/notices/{notice}/publish'): '发布已审公告',
    ('POST', '/v1/engagement/notices/{notice}/restore'): '恢复公告',
    ('POST', '/v1/engagement/notices/{notice}/retract'): '撤回公告',
    ('POST', '/v1/engagement/notices/{notice}/submit'): '提交公告审批',
    ('POST', '/v1/engagement/notifications/{notification}/read'): '标记通知已读',
    ('POST', '/v1/leave/requests'): '兼容入口申请请假',
    ('POST', '/v1/leave/requests/{caseId}/cancel'): '兼容入口撤销请假',
    ('POST', '/v1/lifecycle/contract-renewals'): '发起合同续签',
    ('POST', '/v1/lifecycle/offboardings'): '发起离职流程',
    ('POST', '/v1/lifecycle/onboarding'): '发起入职流程',
    ('POST', '/v1/lifecycle/probation-confirmations'): '发起转正流程',
    ('POST', '/v1/lifecycle/rehires'): '发起返聘流程',
    ('POST', '/v1/lifecycle/salary-changes'): '发起调薪流程',
    ('POST', '/v1/lifecycle/secondments'): '发起借调安排',
    ('POST', '/v1/lifecycle/transfers'): '发起调岗流程',
    ('POST', '/v1/notification-templates'): '新增通知模板',
    ('POST', '/v1/notifications/deliveries/{id}/retry'): '重试通知发送',
    ('POST', '/v1/notifications/dispatch'): '发送通知',
    ('PATCH', '/v1/onboarding-tasks/{id}'): '完成入职任务',
    ('POST', '/v1/org-units'): '新增部门',
    ('POST', '/v1/org-units/{id}/move'): '移动部门',
    ('POST', '/v1/overtime/records/{recordId}/settlements'): '结算加班',
    ('POST', '/v1/payroll/adjustments'): '应用调薪调整',
    ('POST', '/v1/payroll/employee-plans'): '新增薪资档案版本',
    ('POST', '/v1/payroll/items'): '新增薪资项目',
    ('POST', '/v1/payroll/runs'): '新增工资批次',
    ('POST', '/v1/payroll/runs/{id}/approve'): '审批工资批次',
    ('POST', '/v1/payroll/runs/{id}/calculate'): '试算工资批次',
    ('POST', '/v1/payroll/runs/{id}/cancel'): '取消工资批次',
    ('POST', '/v1/payroll/runs/{id}/close'): '关闭工资批次',
    ('POST', '/v1/payroll/runs/{id}/corrections'): '创建冲正批次',
    ('POST', '/v1/payroll/runs/{id}/lock-inputs'): '锁定计薪数据',
    ('POST', '/v1/payroll/runs/{id}/mark-paid'): '登记工资发放',
    ('POST', '/v1/payroll/runs/{id}/publish'): '发布工资批次',
    ('POST', '/v1/payroll/runs/{id}/reopen'): '重开工资批次',
    ('POST', '/v1/payroll/runs/{id}/review'): '复核工资批次',
    ('POST', '/v1/payroll/schemes'): '新增薪酬方案',
    ('POST', '/v1/payroll/statutory-parameters'): '新增社保参数版本',
    ('POST', '/v1/performance/cycles'): '新增绩效周期',
    ('POST', '/v1/performance/cycles/{id}/publish'): '发布绩效结果',
    ('POST', '/v1/performance/feedback'): '记录绩效反馈',
    ('POST', '/v1/performance/goals'): '设定绩效目标',
    ('POST', '/v1/performance/indicators'): '新增绩效指标',
    ('POST', '/v1/performance/reviews'): '新增绩效评估',
    ('POST', '/v1/performance/reviews/{id}/appeals'): '发起绩效申诉',
    ('POST', '/v1/performance/reviews/{id}/calibration'): '校准绩效评估',
    ('POST', '/v1/performance/reviews/{id}/submit'): '提交绩效评估',
    ('POST', '/v1/performance/templates'): '发布绩效模板',
    ('POST', '/v1/persons'): '新增自然人档案',
    ('PUT', '/v1/platform/features/{flag}'): '切换功能开关',
    ('POST', '/v1/positions'): '新增岗位',
    ('POST', '/v1/positions/grades'): '维护岗位职级',
    ('PATCH', '/v1/positions/{id}'): '编辑岗位',
    ('POST', '/v1/positions/{id}/requirements'): '新增岗位任职要求',
    ('POST', '/v1/recruitment/candidates/imports'): '导入候选人',
    ('POST', '/v1/recruitment/candidates/{id}/evaluations'): '记录候选人评价',
    ('POST', '/v1/recruitment/candidates/{id}/communications'): '记录候选人沟通',
    ('POST', '/v1/recruitment/candidates/{id}/talent-pool'): '加入人才库',
    ('POST', '/v1/recruitment/candidates/{id}/convert'): '录用转入职',
    ('POST', '/v1/recruitment/candidates'): '新增候选人',
    ('POST', '/v1/recruitment/candidates/{id}/interviews'): '安排面试',
    ('POST', '/v1/recruitment/offers'): '发起录用审批',
    ('POST', '/v1/recruitment/offers/{id}/accept'): '接受录用通知',
    ('POST', '/v1/recruitment/requisitions'): '发起招聘需求',
    ('POST', '/v1/recruitment/requisitions/{id}/openings'): '发布招聘岗位',
    ('POST', '/v1/reporting/definitions'): '保存报表定义',
    ('POST', '/v1/reporting/exports'): '创建导出任务',
    ('POST', '/v1/reporting/run'): '运行报表',
    ('POST', '/v1/reporting/subscriptions'): '新增报表订阅',
    ('PATCH', '/v1/reporting/subscriptions/{subscriptionId}'): '编辑报表订阅',
    ('DELETE', '/v1/reporting/subscriptions/{subscriptionId}'): '删除报表订阅',
    ('POST', '/v1/roles'): '新增角色',
    ('PUT', '/v1/roles/{id}/pages'): '保存角色页面权限',
    ('PUT', '/v1/roles/{id}/capabilities'): '保存角色动作权限',
    ('POST', '/v1/roles/{id}/copy'): '复制角色',
    ('POST', '/v1/system/calendars/{legal_entity_id}/publish'): '发布工作日历',
    ('PUT', '/v1/system/data-scopes/{id}'): '保存数据范围',
    ('POST', '/v1/system/dictionaries'): '新增字典项',
    ('PATCH', '/v1/system/dictionaries/{id}'): '编辑字典项',
    ('PUT', '/v1/system/field-policies/{field_key}'): '保存字段策略',
    ('POST', '/v1/system/menus'): '新增菜单覆盖',
    ('PATCH', '/v1/system/menus/{page_code}'): '编辑菜单覆盖',
    ('DELETE', '/v1/system/menus/{page_code}'): '删除菜单覆盖',
    ('PUT', '/v1/system/parameters'): '保存系统参数',
    ('POST', '/v1/time/attendance/closings'): '关闭考勤期间',
    ('POST', '/v1/time/attendance/closings/{id}/reopen'): '重开考勤期间',
    ('POST', '/v1/time/attendance/corrections'): '申请补卡',
    ('POST', '/v1/time/attendance/recalculate'): '重算考勤期间',
    ('POST', '/v1/time/attendance/rules'): '发布考勤规则',
    ('POST', '/v1/time/leave/preview'): '预览请假时长',
    ('POST', '/v1/time/leave/requests'): '申请请假',
    ('POST', '/v1/time/leave/requests/{caseId}/cancel'): '撤销请假申请',
    ('POST', '/v1/time/overtime/requests'): '申请加班',
    ('POST', '/v1/time/overtime/requests/{id}/confirm'): '确认加班时长',
    ('POST', '/v1/time/punches/import'): '导入打卡记录',
    ('POST', '/v1/time/shift-schedules'): '安排班次',
    ('POST', '/v1/training/courses'): '新增培训课程',
    ('POST', '/v1/training/enrollments'): '报名培训',
    ('POST', '/v1/training/enrollments/{id}/complete'): '登记培训完成',
    ('POST', '/v1/training/exams'): '新增培训考试',
    ('POST', '/v1/training/exams/{id}/publish'): '发布培训考试',
    ('POST', '/v1/training/feedback'): '提交培训反馈',
    ('POST', '/v1/training/plans'): '新增培训计划',
    ('POST', '/v1/training/programs'): '新增培训项目',
    ('POST', '/v1/training/sessions'): '新增培训场次',
    ('POST', '/v1/training/sessions/{id}/checkins'): '培训签到',
    ('POST', '/v1/travel/expenses'): '登记差旅费用',
    ('POST', '/v1/travel/policies'): '发布差旅政策',
    ('POST', '/v1/travel/requests'): '申请出差',
    ('POST', '/v1/travel/requests/{id}/approve'): '审批出差申请',
    ('POST', '/v1/travel/requests/{id}/cancel'): '取消出差申请',
    ('POST', '/v1/users'): '新增用户',
    ('PATCH', '/v1/users/{id}'): '编辑用户状态',
    ('POST', '/v1/users/{id}/bindings'): '新增授权绑定',
    ('POST', '/v1/users/{id}/roles'): '分配角色',
    ('DELETE', '/v1/users/{id}/roles/{roleCode}'): '撤销角色',
    ('POST', '/v1/workflow/templates'): '新增流程模板',
}


def main() -> int:
    source = SPEC.read_text(encoding='utf-8')
    lines = source.splitlines()
    current_path: str | None = None
    inserted = 0
    expected: set[tuple[str, str]] = set()
    out: list[str] = []
    i = 0

    while i < len(lines):
        line = lines[i]
        path_match = re.match(r'^  (/v1/[^:]+):\s*$', line)

        if path_match:
            current_path = path_match.group(1)

        method_match = re.match(r'^    (post|put|patch|delete):\s*$', line)

        if method_match and current_path is not None:
            method = method_match.group(1).upper()
            # find the x-capability line within this operation block
            depth_end = i + 1
            cap_line = None

            while depth_end < len(lines):
                candidate = lines[depth_end]
                if re.match(r'^    [a-z]', candidate) or re.match(r'^  /v1/', candidate):
                    break
                if re.match(r'^      x-capability: ', candidate):
                    cap_line = depth_end
                depth_end += 1

            if cap_line is None:
                # Public allowlisted write (login / MFA challenge): no capability and no label.
                out.extend(lines[i:depth_end])
                i = depth_end
                continue

            label = LABELS.get((method, current_path))

            if label is None:
                raise SystemExit(f'missing label for {method} {current_path}')

            # copy through capability line, then insert label after it
            expected.add((method, current_path))
            out.extend(lines[i:cap_line + 1])
            out.append(f'      x-action-label: {label}')
            inserted += 1
            i = cap_line + 1
            continue

        out.append(line)
        i += 1

    missing = expected - set(LABELS)
    extra = set(LABELS) - expected

    if missing or extra:
        raise SystemExit(f'label map mismatch; missing={sorted(missing)} extra={sorted(extra)}')

    SPEC.write_text('\n'.join(out) + '\n', encoding='utf-8')
    print(f'inserted {inserted} x-action-label entries')

    return 0


if __name__ == '__main__':
    sys.exit(main())
