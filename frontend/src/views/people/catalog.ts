import type { PeoplePageConfig } from './types'
import {
  CERTIFICATE_COLUMNS,
  CHANGE_COLUMNS,
  CONTACT_COLUMNS,
  CONTRACT_COLUMNS,
  DEPARTMENT_COLUMNS,
  EDUCATION_COLUMNS,
  EMPLOYEE_COLUMNS,
  EXPERIENCE_COLUMNS,
  GAP,
  IMPORT_COLUMNS,
  MEMBER_COLUMNS,
  MOVEMENT_COLUMNS,
  POSITION_COLUMNS,
  POSITION_STAFF_COLUMNS,
  STAFFING_COLUMNS,
  TIMELINE_COLUMNS,
} from './catalog-columns'

const EMPLOYEE_DOMAIN = { domain: 'employees' as const, group: '员工档案' }
const DEPARTMENT_DOMAIN = { domain: 'departments' as const, group: '部门管理' }
const POSITION_DOMAIN = { domain: 'positions' as const, group: '岗位管理' }
const MOVEMENT_DOMAIN = { domain: 'movements' as const, group: '员工异动' }
const CONTRACT_DOMAIN = { domain: 'contracts' as const, group: '劳动合同' }

const EMPLOYEE_PAGES: PeoplePageConfig[] = [
  { ...EMPLOYEE_DOMAIN, key: 'hr/employees/index', title: '员工列表', description: '按业务日期、组织与关键词查询员工主数据；敏感字段使用服务端字段档位。', readLabel: '员工', read: { kind: 'employee-list' }, columns: EMPLOYEE_COLUMNS },
  { ...EMPLOYEE_DOMAIN, key: 'hr/employees/create', title: '新增员工', description: '依次创建人员、员工关系与组织任职；每一步都调用真实 People 接口。', readLabel: '最近员工', read: { kind: 'employee-list' }, columns: EMPLOYEE_COLUMNS, form: 'employee-create' },
  {
    ...EMPLOYEE_DOMAIN, key: 'hr/employees/by-id', title: '档案详情', description: '查看员工身份、当前任职、直属主管与条款摘要。', readLabel: '员工档案', read: { kind: 'employee-detail' }, columns: EMPLOYEE_COLUMNS,
    detailFields: [
      { key: 'employment_id', label: '员工关系编号' },
      { key: 'employee_no', label: '工号' },
      { key: 'status', label: '状态' },
      { key: 'employment_type', label: '用工类型' },
      { key: 'current_assignment.org_unit_id', label: '当前组织' },
      { key: 'current_assignment.position_id', label: '当前岗位' },
      { key: 'manager_employment_id', label: '直属主管' },
      { key: 'terms.work_location', label: '工作地点' },
      { key: 'terms.work_schedule', label: '工作安排' },
      { key: 'terms.probation_end_date', label: '试用期结束' },
    ],
  },
  { ...EMPLOYEE_DOMAIN, key: 'hr/employees/by-id/education', title: '教育经历', description: '教育经历按版本保存；编辑会生成新版本并保留历史。', readLabel: '教育经历', read: { kind: 'employee-profile', resource: 'education' }, columns: EDUCATION_COLUMNS, resource: 'education' },
  { ...EMPLOYEE_DOMAIN, key: 'hr/employees/by-id/work-history', title: '工作经历', description: '维护入职前的工作履历与离职原因。', readLabel: '工作经历', read: { kind: 'employee-profile', resource: 'experiences' }, columns: EXPERIENCE_COLUMNS, resource: 'experiences' },
  { ...EMPLOYEE_DOMAIN, key: 'hr/employees/by-id/contacts', title: '家庭/紧急联系人', description: '联系人电话属于 P2 敏感字段；页面只渲染服务端遮罩结果，不展示明文。', readLabel: '联系人', read: { kind: 'employee-profile', resource: 'contacts' }, columns: CONTACT_COLUMNS, resource: 'contacts' },
  { ...EMPLOYEE_DOMAIN, key: 'hr/employees/by-id/credentials', title: '证照与资格', description: '维护证书类型、编号、签发机构与有效期。', readLabel: '证照', read: { kind: 'employee-profile', resource: 'certificates' }, columns: CERTIFICATE_COLUMNS, resource: 'certificates' },
  { ...EMPLOYEE_DOMAIN, key: 'hr/employees/by-id/history', title: '任职/异动履历', description: '按生效日期查看雇佣、任职与条款版本事件。', readLabel: '履历事件', read: { kind: 'employee-timeline' }, columns: TIMELINE_COLUMNS },
  {
    ...EMPLOYEE_DOMAIN, key: 'hr/employees/by-id/files', title: '员工附件', description: '查看员工档案中已登记的合同文档元数据；通用附件能力等待后端接口。', readLabel: '合同文档', read: { kind: 'employee-detail' },
    columns: [
      { key: 'employment_id', label: '员工关系', width: '100px' },
      { key: 'employee_no', label: '工号', width: '110px' },
      { key: 'status', label: '状态', width: '90px' },
    ],
    gap: GAP.files,
  },
  { ...EMPLOYEE_DOMAIN, key: 'hr/employees/by-id/changes', title: '员工档案变更审批', description: '发起档案更正申请并查看状态；提交后进入审批。', readLabel: '变更申请', read: { kind: 'employee-profile', resource: 'change_requests' }, columns: CHANGE_COLUMNS, resource: 'change_requests' },
  { ...EMPLOYEE_DOMAIN, key: 'hr/employees/imports', title: '导入校验结果', description: '按员工选择导入批次，查看逐行校验汇总；不渲染原始错误 JSON。', readLabel: '导入批次', read: { kind: 'employee-imports' }, columns: IMPORT_COLUMNS },
]

const DEPARTMENT_PAGES: PeoplePageConfig[] = [
  { ...DEPARTMENT_DOMAIN, key: 'hr/departments/index', title: '组织架构树', description: '按业务日期查看生效组织层级、编码与状态。', readLabel: '组织节点', read: { kind: 'org-tree' }, columns: DEPARTMENT_COLUMNS },
  { ...DEPARTMENT_DOMAIN, key: 'hr/departments/list', title: '部门列表', description: '部门列表带直接与汇总在编人数，可按编码或名称筛选。', readLabel: '部门', read: { kind: 'department-list' }, columns: DEPARTMENT_COLUMNS },
  { ...DEPARTMENT_DOMAIN, key: 'hr/departments/editor', title: '新建/编辑部门', description: '创建组织节点并指定上级、类型与生效期；编码由后端校验。', readLabel: '现有组织', read: { kind: 'org-tree' }, columns: DEPARTMENT_COLUMNS, form: 'department-create' },
  { ...DEPARTMENT_DOMAIN, key: 'hr/departments/members', title: '部门成员', description: '查看部门当前任职并提交主任职迁移；历史任职不会覆盖。', readLabel: '部门成员', read: { kind: 'department-members' }, columns: MEMBER_COLUMNS, form: 'department-member' },
  { ...DEPARTMENT_DOMAIN, key: 'hr/departments/move', title: '部门迁移', description: '先预览受影响员工、主管与跨法人风险，再提交带生效日期的迁移。', readLabel: '可迁移组织', read: { kind: 'org-tree' }, columns: DEPARTMENT_COLUMNS, form: 'department-move' },
  { ...DEPARTMENT_DOMAIN, key: 'hr/departments/staffing', title: '部门编制', description: '对比核定编制、在编人数、空缺与超编状态。', readLabel: '部门编制', read: { kind: 'department-staffing' }, columns: STAFFING_COLUMNS },
]

const POSITION_PAGES: PeoplePageConfig[] = [
  { ...POSITION_DOMAIN, key: 'hr/positions/index', title: '岗位目录', description: '查看生效岗位、职级、核定编制与实时占用。', readLabel: '岗位', read: { kind: 'positions' }, columns: POSITION_COLUMNS },
  { ...POSITION_DOMAIN, key: 'hr/positions/editor', title: '岗位编辑', description: '创建岗位，或修订已有岗位的职级、名称与编制上限。', readLabel: '岗位', read: { kind: 'positions' }, columns: POSITION_COLUMNS, form: 'position-editor', selectable: true },
  { ...POSITION_DOMAIN, key: 'hr/positions/headcount', title: '岗位编制', description: '以服务端占用数对比核定编制与空缺；调整编制通过岗位 PATCH 提交。', readLabel: '岗位编制', read: { kind: 'positions' }, columns: POSITION_COLUMNS, form: 'position-headcount', selectable: true },
  { ...POSITION_DOMAIN, key: 'hr/positions/requirements', title: '岗位任职资格', description: '查看岗位职级并在后端资格接口就绪后配置任职条件。', readLabel: '岗位', read: { kind: 'positions' }, columns: POSITION_COLUMNS, gap: GAP.requirements },
  { ...POSITION_DOMAIN, key: 'hr/positions/grades', title: '职级/职等', description: '按岗位现有 grade 字段聚合查看职级占用。', readLabel: '岗位职级', read: { kind: 'positions' }, columns: POSITION_COLUMNS, gap: GAP.grades },
  { ...POSITION_DOMAIN, key: 'hr/positions/staff', title: '岗位任职人员', description: '读取岗位与员工当前任职；因后端无岗位员工子接口，只做员工列表的只读匹配。', readLabel: '岗位与当前任职', read: { kind: 'position-staff' }, columns: POSITION_STAFF_COLUMNS, gap: GAP.positionStaff },
]

const MOVEMENT_PAGES: PeoplePageConfig[] = [
  { ...MOVEMENT_DOMAIN, key: 'hr/movements/index', title: '异动列表', description: '查看全部生命周期案例的申请与生效状态。', readLabel: '异动案例', read: { kind: 'lifecycle-cases' }, columns: MOVEMENT_COLUMNS },
  { ...MOVEMENT_DOMAIN, key: 'hr/movements/onboarding', title: '入职办理', description: '发起入职案例并查看入职清单。', readLabel: '入职案例', read: { kind: 'lifecycle-cases', caseType: 'ONBOARDING' }, columns: MOVEMENT_COLUMNS, form: 'movement-onboarding' },
  { ...MOVEMENT_DOMAIN, key: 'hr/movements/probation', title: '转正管理', description: '发起试用期转正确认并查看审批状态。', readLabel: '转正案例', read: { kind: 'lifecycle-cases', caseType: 'PROBATION_CONFIRM' }, columns: MOVEMENT_COLUMNS, form: 'movement-probation' },
  { ...MOVEMENT_DOMAIN, key: 'hr/movements/transfer', title: '调岗/调部门', description: '发起未来生效的任职变更与部门调动。', readLabel: '调岗案例', read: { kind: 'lifecycle-cases', caseType: 'ASSIGNMENT_CHANGE' }, columns: MOVEMENT_COLUMNS, form: 'movement-transfer' },
  { ...MOVEMENT_DOMAIN, key: 'hr/movements/salary-change', title: '调薪管理', description: '调薪使用独立敏感审批，金额以最小货币单位提交。', readLabel: '调薪案例', read: { kind: 'lifecycle-cases', caseType: 'TERMS_CHANGE' }, columns: MOVEMENT_COLUMNS, form: 'movement-salary' },
  { ...MOVEMENT_DOMAIN, key: 'hr/movements/secondment', title: '借调/兼岗', description: '查看调岗类案例；独立借调流程等待后端命令。', readLabel: '借调相关案例', read: { kind: 'lifecycle-cases', caseType: 'ASSIGNMENT_CHANGE' }, columns: MOVEMENT_COLUMNS, gap: GAP.secondment },
  { ...MOVEMENT_DOMAIN, key: 'hr/movements/offboarding', title: '离职办理', description: '发起离职案例并记录离职类型、最后工作日与交接清单。', readLabel: '离职案例', read: { kind: 'lifecycle-cases', caseType: 'OFFBOARD' }, columns: MOVEMENT_COLUMNS, form: 'movement-offboarding' },
  {
    ...MOVEMENT_DOMAIN, key: 'hr/movements/by-id', title: '异动审批详情', description: '查看案例域状态、申请状态、生效状态与入职/离职清单。', readLabel: '异动案例', read: { kind: 'lifecycle-case-detail' }, columns: [],
    detailFields: [
      { key: 'case_no', label: '案例编号' },
      { key: 'case_type', label: '异动类型' },
      { key: 'application_status', label: '申请状态' },
      { key: 'effect_status', label: '生效状态' },
      { key: 'domain_status', label: '域状态' },
      { key: 'employment_id', label: '员工关系' },
      { key: 'result_employment_id', label: '结果员工关系' },
    ],
  },
  { ...MOVEMENT_DOMAIN, key: 'hr/movements/calendar', title: '异动日历', description: '按拟生效日期查看未来与历史异动案例。', readLabel: '异动日历', read: { kind: 'lifecycle-cases' }, columns: MOVEMENT_COLUMNS },
]

const CONTRACT_PAGES: PeoplePageConfig[] = [
  { ...CONTRACT_DOMAIN, key: 'hr/contracts/index', title: '合同台账', description: '按员工查看合同版本、状态与剩余到期天数。', readLabel: '合同', read: { kind: 'contracts-ledger' }, columns: CONTRACT_COLUMNS },
  { ...CONTRACT_DOMAIN, key: 'hr/contracts/editor', title: '合同录入/续签', description: '为选中员工创建合同或续签新版本；历史合同不会被覆盖。', readLabel: '员工合同', read: { kind: 'contracts-ledger' }, columns: CONTRACT_COLUMNS, form: 'contract-editor' },
  {
    ...CONTRACT_DOMAIN, key: 'hr/contracts/by-id', title: '合同详情', description: '查看单个合同版本、续签链与到期信息。', readLabel: '合同', read: { kind: 'contract-detail' }, columns: [],
    detailFields: [
      { key: 'contract_no', label: '合同编号' },
      { key: 'contract_type', label: '合同类型' },
      { key: 'employment_id', label: '员工关系' },
      { key: 'start_date', label: '开始日期' },
      { key: 'end_date', label: '结束日期' },
      { key: 'signed_on', label: '签署日期' },
      { key: 'status', label: '状态' },
      { key: 'version', label: '版本' },
      { key: 'previous_contract_id', label: '上一版本' },
      { key: 'renewed_by_id', label: '续签版本' },
      { key: 'days_to_expiry', label: '剩余天数' },
    ],
  },
  { ...CONTRACT_DOMAIN, key: 'hr/contracts/renewals', title: '合同续签', description: '查看到期窗口合同并创建续签版本。', readLabel: '到期合同', read: { kind: 'contract-expiring' }, columns: CONTRACT_COLUMNS, form: 'contract-renewal' },
  { ...CONTRACT_DOMAIN, key: 'hr/contracts/changes', title: '合同变更/终止', description: '查看合同台账；变更和终止等待后端版本化命令。', readLabel: '合同', read: { kind: 'contracts-ledger' }, columns: CONTRACT_COLUMNS, gap: GAP.contractChanges },
  { ...CONTRACT_DOMAIN, key: 'hr/contracts/expiry', title: '到期预警', description: '按 60 天窗口读取到期合同，页面内标记 60、30、7 天区间。', readLabel: '到期合同', read: { kind: 'contract-expiring', withinDays: 60 }, columns: CONTRACT_COLUMNS },
  { ...CONTRACT_DOMAIN, key: 'hr/contracts/templates', title: '合同模板', description: '查看合同只读数据；模板管理等待后端接口。', readLabel: '到期合同', read: { kind: 'contract-expiring', withinDays: 60 }, columns: CONTRACT_COLUMNS, gap: GAP.contractTemplates },
]

export const PEOPLE_PAGE_CONFIGS: readonly PeoplePageConfig[] = [
  ...EMPLOYEE_PAGES,
  ...DEPARTMENT_PAGES,
  ...POSITION_PAGES,
  ...MOVEMENT_PAGES,
  ...CONTRACT_PAGES,
]

const CONFIGS = new Map(PEOPLE_PAGE_CONFIGS.map((config) => [config.key, config]))

export function peoplePageConfig(key: string): PeoplePageConfig | null {
  return CONFIGS.get(key) ?? null
}

export const PEOPLE_COMPONENT_KEYS: readonly string[] = PEOPLE_PAGE_CONFIGS.map((config) => config.key)
