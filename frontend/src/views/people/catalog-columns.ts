import type { PeopleColumn, PeopleGap } from './types'

export const EMPLOYEE_COLUMNS: readonly PeopleColumn[] = [
  { key: 'employee_no', label: '工号', width: '110px' },
  { key: 'person_name', label: '姓名', minWidth: '140px' },
  { key: 'status', label: '状态', width: '90px' },
  { key: 'employment_type', label: '用工类型', width: '110px' },
  { key: 'current_assignment.org_unit_id', label: '组织', width: '90px' },
  { key: 'current_assignment.position_id', label: '岗位', width: '90px' },
]

export const EDUCATION_COLUMNS: readonly PeopleColumn[] = [
  { key: 'school', label: '院校', minWidth: '160px' },
  { key: 'degree', label: '学历', width: '100px' },
  { key: 'major', label: '专业', minWidth: '130px' },
  { key: 'start_date', label: '开始日期', width: '115px' },
  { key: 'end_date', label: '结束日期', width: '115px' },
]

export const EXPERIENCE_COLUMNS: readonly PeopleColumn[] = [
  { key: 'company', label: '任职单位', minWidth: '160px' },
  { key: 'title', label: '岗位', minWidth: '140px' },
  { key: 'start_date', label: '开始日期', width: '115px' },
  { key: 'end_date', label: '结束日期', width: '115px' },
  { key: 'leave_reason', label: '离职原因', minWidth: '140px' },
]

export const CONTACT_COLUMNS: readonly PeopleColumn[] = [
  { key: 'name', label: '联系人', minWidth: '140px' },
  { key: 'relationship', label: '关系', width: '90px' },
  { key: 'phone', label: '联系电话', width: '170px' },
  { key: 'email', label: '邮箱', minWidth: '180px' },
  { key: 'is_emergency', label: '紧急联系人', width: '100px' },
]

export const CERTIFICATE_COLUMNS: readonly PeopleColumn[] = [
  { key: 'certificate_type', label: '证照类型', minWidth: '130px' },
  { key: 'certificate_no', label: '证照编号', minWidth: '140px' },
  { key: 'issuer', label: '签发机构', minWidth: '140px' },
  { key: 'issued_on', label: '签发日期', width: '115px' },
  { key: 'expires_on', label: '到期日期', width: '115px' },
]

export const TIMELINE_COLUMNS: readonly PeopleColumn[] = [
  { key: 'effective_date', label: '生效日期', width: '115px' },
  { key: 'event', label: '事件', minWidth: '150px' },
  { key: 'type', label: '记录类型', width: '100px' },
  { key: 'id', label: '记录编号', width: '90px' },
]

export const CHANGE_COLUMNS: readonly PeopleColumn[] = [
  { key: 'status', label: '状态', width: '100px' },
  { key: 'reason', label: '变更原因', minWidth: '210px' },
  { key: 'diff', label: '变更项', width: '100px' },
  { key: 'submitted_at', label: '提交时间', width: '165px' },
]

export const IMPORT_COLUMNS: readonly PeopleColumn[] = [
  { key: 'original_name', label: '文件名', minWidth: '180px' },
  { key: 'status', label: '校验状态', width: '110px' },
  { key: 'total_rows', label: '总行数', width: '80px' },
  { key: 'valid_rows', label: '有效行', width: '80px' },
  { key: 'failed_rows', label: '失败行', width: '80px' },
]

export const DEPARTMENT_COLUMNS: readonly PeopleColumn[] = [
  { key: 'code', label: '部门编码', width: '125px' },
  { key: 'name', label: '部门名称', minWidth: '170px' },
  { key: 'type', label: '组织类型', width: '110px' },
  { key: 'status', label: '状态', width: '90px' },
  { key: 'parent_id', label: '上级部门', width: '90px' },
  { key: 'direct_headcount', label: '在编人数', width: '90px' },
]

export const MEMBER_COLUMNS: readonly PeopleColumn[] = [
  { key: 'employment_id', label: '员工', minWidth: '140px' },
  { key: 'assignment_id', label: '任职记录', width: '100px' },
  { key: 'assignment_type', label: '任职类型', width: '110px' },
  { key: 'is_primary', label: '主任职', width: '80px' },
  { key: 'valid_from', label: '开始日期', width: '115px' },
  { key: 'valid_to', label: '结束日期', width: '125px' },
]

export const STAFFING_COLUMNS: readonly PeopleColumn[] = [
  { key: 'code', label: '部门编码', width: '125px' },
  { key: 'name', label: '部门名称', minWidth: '170px' },
  { key: 'headcount_limit', label: '核定编制', width: '90px' },
  { key: 'staffed', label: '在编人数', width: '90px' },
  { key: 'vacancies', label: '空缺', width: '80px' },
  { key: 'overstaffed', label: '超编', width: '80px' },
]

export const POSITION_COLUMNS: readonly PeopleColumn[] = [
  { key: 'code', label: '岗位编码', width: '125px' },
  { key: 'title', label: '岗位名称', minWidth: '170px' },
  { key: 'grade', label: '职级', width: '80px' },
  { key: 'headcount_limit', label: '核定编制', width: '90px' },
  { key: 'occupancy', label: '已占用', width: '80px' },
  { key: 'vacancies', label: '空缺', width: '80px' },
  { key: 'status', label: '状态', width: '80px' },
]

export const MOVEMENT_COLUMNS: readonly PeopleColumn[] = [
  { key: 'case_no', label: '异动单号', width: '155px' },
  { key: 'case_type', label: '异动类型', width: '165px' },
  { key: 'application_status', label: '申请状态', width: '105px' },
  { key: 'effect_status', label: '生效状态', width: '105px' },
  { key: 'employment_id', label: '员工', width: '90px' },
  { key: 'effective_at', label: '拟生效日', width: '165px' },
]

export const CONTRACT_COLUMNS: readonly PeopleColumn[] = [
  { key: 'contract_no', label: '合同编号', width: '165px' },
  { key: 'contract_type', label: '合同类型', width: '120px' },
  { key: 'start_date', label: '开始日期', width: '115px' },
  { key: 'end_date', label: '结束日期', width: '115px' },
  { key: 'status', label: '状态', width: '90px' },
  { key: 'version', label: '版本', width: '65px' },
  { key: 'days_to_expiry', label: '剩余天数', width: '90px' },
]

export const POSITION_STAFF_COLUMNS: readonly PeopleColumn[] = [
  { key: 'position_code', label: '岗位编码', width: '125px' },
  { key: 'position_title', label: '岗位名称', minWidth: '160px' },
  { key: 'employee_no', label: '工号', width: '110px' },
  { key: 'person_name', label: '姓名', minWidth: '140px' },
  { key: 'employment_id', label: '员工关系', width: '100px' },
]

export const GAP = {
  files: {
    label: '员工附件',
    plannedPath: '/v1/employments/{employment_id}/documents',
    detail: '当前只提供合同类文档的元数据；通用员工附件上传、下载与删除接口尚未在 V1.1 后端开放。',
  },
  requirements: {
    label: '岗位任职资格',
    plannedPath: '/v1/positions/{id}/requirements',
    detail: '岗位任职资格、技能条件与版本化配置接口尚未提供；页面只展示岗位目录中的职级信息。',
  },
  grades: {
    label: '职级/职等',
    plannedPath: '/v1/positions/grades',
    detail: '独立职级目录接口尚未提供；当前仅按岗位的 grade 字段做只读查看，不写入新的职级主数据。',
  },
  secondment: {
    label: '借调/兼岗',
    plannedPath: '/v1/lifecycle/secondments',
    detail: '后端目前只有 PRIMARY/SECONDMENT/MATRIX/PROJECT 任职类型与调岗命令，没有独立借调生命周期命令。页面只读展示调岗案例，不伪造借调审批。',
  },
  contractChanges: {
    label: '合同变更/终止',
    plannedPath: '/v1/employments/{employment_id}/contracts/{id}',
    detail: '合同台账仅支持读取与创建新版本；合同变更、终止与附件接口尚未提供，页面不伪造变更按钮。',
  },
  contractTemplates: {
    label: '合同模板',
    plannedPath: '/v1/contract-templates',
    detail: '合同模板、模板版本与发布权限接口尚未提供；页面只展示到期合同只读列表，不提供模板编辑。',
  },
  positionStaff: {
    label: '岗位任职人员',
    plannedPath: '/v1/positions/{id}/staff',
    detail: '后端未提供按岗位查询员工的子接口；当前从员工列表当前任职中匹配已加载页数据，不保证覆盖全员，页面会显示部分数据标记。',
  },
} satisfies Record<string, PeopleGap>
