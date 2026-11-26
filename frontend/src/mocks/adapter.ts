import type {
  ApiAdapter,
  AttendanceDay,
  AttendanceMonth,
  AttendanceStatus,
  AuditEventItem,
  AuthorityBinding,
  CaseDetail,
  CaseSummary,
  DashboardData,
  DashboardMetricCode,
  DirectoryEmployee,
  EffectStatus,
  EmployeeDetail,
  ExportJob,
  MaskedFieldValue,
  LeaveBalance,
  LeaveLedger,
  LeavePreview,
  MetricDrilldown,
  MoveImpact,
  Notice,
  OrgNode,
  PayrollRunDetail,
  PayrollLine,
  PayrollRunState,
  PayrollRunSummary,
  Payslip,
  Principal,
  ReportDefinition,
  ReportResult,
  SessionBootstrap,
  TimelineEvent,
  WorkspaceProjection,
} from '@/api/contracts'
import { ApiError } from '@/api/errors'
import { weekdayLabel } from '@/utils/format'
import { PAGE_REGISTRY } from '@/router/registry'

/** Fixed seed clock keeps screenshots and tests deterministic. */
export const MOCK_NOW = '2026-11-23T09:12:00Z'
const TODAY = '2026-11-23'
const TENANT_TIMEZONE = 'Asia/Shanghai'

const CAPABILITIES = [
  'people.employee.read',
  'people.employee.manage',
  'people.employee.read',
  'org.tree.read',
  'org.unit.manage',
  'workflow.case.read',
  'workflow.case.read',
  'workflow.case.decide',
  'workflow.case.admin',
  'time.leave.request',
  'time.leave.read',
  'time.leave.ledger.read',
  'time.attendance.read',
  'time.attendance.correct.request',
  'payroll.run.read',
  'payroll.run.prepare',
  'payroll.payslip.read.self',
  'payroll.payslip.read',
  'reporting.export',
  'reporting.export',
  'engagement.notice.read',
  'engagement.notice.manage',
  'iam.user.read',
  'iam.role.manage',
  'iam.user.read',
  'iam.binding.manage',
  'iam.binding.manage',
  'iam.audit.read',
  'iam.audit.read',
  'reporting.dashboard.read',
] as const


const PRINCIPAL: Principal = {
  userId: 101,
  tenantId: 1,
  employmentId: 5001,
  displayName: '林若桐',
  email: 'ruotong.lin@demo.example',
  employmentNo: 'DEMO-0101',
  jobTitle: '人力资源业务伙伴',
  orgName: '人力资源部',
  initials: '林',
  isPrivileged: true,
  hasMfa: true,
  authVersion: 7,
  tenantTimezone: TENANT_TIMEZONE,
}

const DEMO_EMPLOYEE_CAPABILITIES = [
  'workflow.case.read',
  'time.leave.request',
  'time.leave.read',
  'time.leave.ledger.read',
  'time.attendance.read',
  'time.attendance.correct.request',
  'payroll.payslip.read.self',
  'engagement.notice.read',
  'reporting.dashboard.read',
] as const



export function sessionForRole(role: 'admin' | 'hr' | 'employee'): SessionBootstrap {
  const capabilities =
    role === 'admin'
      ? [...CAPABILITIES]
      : role === 'hr'
        ? CAPABILITIES.filter((capability) => !capability.startsWith('iam.') && !capability.startsWith('platform.'))
        : [...DEMO_EMPLOYEE_CAPABILITIES]
  const principal: Principal =
    role === 'employee'
      ? {
          ...PRINCIPAL,
          userId: 205,
          employmentId: 5105,
          displayName: '陈嘉禾',
          email: 'jiahe.chen@demo.example',
          employmentNo: 'DEMO-0218',
          jobTitle: '产品运营专员',
          orgName: '数字产品部',
          initials: '陈',
          isPrivileged: false,
        }
      : PRINCIPAL
  return {
    authenticated: true,
    principal,
    capabilities,
    csrfToken: 'mock-csrf-token',
    sessionExpiresAt: '2026-11-23T17:12:00Z',
    mfaEnrollmentRequired: false,
  }
}

function masked(
  display: string | null,
  profile: 'P0_PUBLIC' | 'P1_INTERNAL' | 'P2_SENSITIVE' | 'P3_RESTRICTED' = 'P1_INTERNAL',
  revealable = false,
) {
  return {
    display,
    masked: display?.includes('*') ?? true,
    profile,
    revealable,
    exportable: profile === 'P0_PUBLIC' || profile === 'P1_INTERNAL',
  }
}

const ORG = {
  company: { id: 1, name: '澄明科技（上海）有限公司', code: 'DEMO-CN', manager: '周时安' },
  product: { id: 11, name: '数字产品部', code: 'DP', manager: '邵清越' },
  hr: { id: 12, name: '人力资源部', code: 'HR', manager: '林若桐' },
  finance: { id: 13, name: '财务与行政部', code: 'FA', manager: '许言川' },
  engineering: { id: 14, name: '研发中心', code: 'ENG', manager: '程望舒' },
}

type SeededEmployee = DirectoryEmployee & {
  managerName: string
  legalEntity: string
  workLocation: string
  bankAccount: EmployeeDetail['bankAccount']
  address: EmployeeDetail['address']
  personalEmail: EmployeeDetail['personalEmail']
  contract: EmployeeDetail['contract']
  assignments: EmployeeDetail['assignments']
}

const employeeSeed: SeededEmployee[] = [
  {
    id: 5101,
    employeeNo: 'DEMO-0101',
    name: '林若桐',
    initials: '林',
    orgId: ORG.hr.id,
    orgName: ORG.hr.name,
    positionName: '人力资源业务伙伴',
    status: 'ACTIVE',
    employmentType: '全职',
    hireDate: '2022-03-14',
    phone: masked('138****6621'),
    email: masked('ru****@demo.example'),
    idNumber: masked('310***********1227', 'P2_SENSITIVE'),
    managerName: '周时安',
    legalEntity: '澄明科技（上海）有限公司',
    workLocation: '上海 · 张江',
    bankAccount: masked('**** **** **** 4821', 'P2_SENSITIVE'),
    address: masked('上海市浦东新区****路 88 号', 'P2_SENSITIVE'),
    personalEmail: masked('lin****@personal.example', 'P1_INTERNAL'),
    contract: {
      contractNo: 'CT-DEMO-2024-0101',
      type: '无固定期限劳动合同',
      startDate: '2024-03-15',
      endDate: null,
      daysToExpiry: null,
      status: 'ACTIVE',
    },
    assignments: [
      {
        id: 91,
        orgName: '人力资源部',
        positionName: '人力资源业务伙伴',
        managerName: '周时安',
        validFrom: '2024-03-15',
        validTo: null,
      },
      {
        id: 74,
        orgName: '数字产品部',
        positionName: '产品运营专员',
        managerName: '邵清越',
        validFrom: '2022-03-14',
        validTo: '2024-03-14',
      },
    ],
  },
  {
    id: 5102,
    employeeNo: 'DEMO-0108',
    name: '程望舒',
    initials: '程',
    orgId: ORG.engineering.id,
    orgName: ORG.engineering.name,
    positionName: '研发总监',
    status: 'ACTIVE',
    employmentType: '全职',
    hireDate: '2021-07-01',
    phone: masked('139****1803'),
    email: masked('wa****@demo.example'),
    idNumber: masked('320***********0058', 'P2_SENSITIVE'),
    managerName: '周时安',
    legalEntity: '澄明科技（上海）有限公司',
    workLocation: '上海 · 张江',
    bankAccount: masked('**** **** **** 3097', 'P2_SENSITIVE'),
    address: masked('上海市徐汇区****弄 12 号', 'P2_SENSITIVE'),
    personalEmail: masked('cheng****@personal.example'),
    contract: {
      contractNo: 'CT-DEMO-2023-0108',
      type: '固定期限劳动合同',
      startDate: '2023-07-01',
      endDate: '2027-06-30',
      daysToExpiry: 949,
      status: 'ACTIVE',
    },
    assignments: [
      {
        id: 92,
        orgName: '研发中心',
        positionName: '研发总监',
        managerName: '周时安',
        validFrom: '2023-07-01',
        validTo: null,
      },
    ],
  },
  {
    id: 5103,
    employeeNo: 'DEMO-0112',
    name: '邵清越',
    initials: '邵',
    orgId: ORG.product.id,
    orgName: ORG.product.name,
    positionName: '产品负责人',
    status: 'ACTIVE',
    employmentType: '全职',
    hireDate: '2022-11-07',
    phone: masked('137****2910'),
    email: masked('qi****@demo.example'),
    idNumber: masked('310***********8842', 'P2_SENSITIVE'),
    managerName: '周时安',
    legalEntity: '澄明科技（上海）有限公司',
    workLocation: '上海 · 张江',
    bankAccount: masked('**** **** **** 7760', 'P2_SENSITIVE'),
    address: masked('上海市静安区****路 36 号', 'P2_SENSITIVE'),
    personalEmail: masked('shao****@personal.example'),
    contract: {
      contractNo: 'CT-DEMO-2025-0112',
      type: '固定期限劳动合同',
      startDate: '2025-11-08',
      endDate: '2026-11-30',
      daysToExpiry: 7,
      status: 'EXPIRING',
    },
    assignments: [
      {
        id: 93,
        orgName: '数字产品部',
        positionName: '产品负责人',
        managerName: '周时安',
        validFrom: '2025-11-08',
        validTo: null,
      },
    ],
  },
  {
    id: 5104,
    employeeNo: 'DEMO-0115',
    name: '许言川',
    initials: '许',
    orgId: ORG.finance.id,
    orgName: ORG.finance.name,
    positionName: '财务经理',
    status: 'ACTIVE',
    employmentType: '全职',
    hireDate: '2020-09-21',
    phone: masked('136****4102'),
    email: masked('yc****@demo.example'),
    idNumber: masked('330***********7611', 'P2_SENSITIVE'),
    managerName: '周时安',
    legalEntity: '澄明科技（上海）有限公司',
    workLocation: '上海 · 张江',
    bankAccount: masked('**** **** **** 1188', 'P2_SENSITIVE'),
    address: masked('上海市闵行区****街 5 号', 'P2_SENSITIVE'),
    personalEmail: masked('xu****@personal.example'),
    contract: {
      contractNo: 'CT-DEMO-2023-0115',
      type: '无固定期限劳动合同',
      startDate: '2023-09-22',
      endDate: null,
      daysToExpiry: null,
      status: 'ACTIVE',
    },
    assignments: [
      {
        id: 94,
        orgName: '财务与行政部',
        positionName: '财务经理',
        managerName: '周时安',
        validFrom: '2023-09-22',
        validTo: null,
      },
    ],
  },
  {
    id: 5105,
    employeeNo: 'DEMO-0218',
    name: '陈嘉禾',
    initials: '陈',
    orgId: ORG.product.id,
    orgName: ORG.product.name,
    positionName: '产品运营专员',
    status: 'ACTIVE',
    employmentType: '全职',
    hireDate: '2024-06-03',
    phone: masked('135****7394'),
    email: masked('jh****@demo.example'),
    idNumber: masked('310***********0653', 'P2_SENSITIVE'),
    managerName: '邵清越',
    legalEntity: '澄明科技（上海）有限公司',
    workLocation: '上海 · 张江',
    bankAccount: masked('**** **** **** 2904', 'P2_SENSITIVE'),
    address: masked('上海市长宁区****路 21 号', 'P2_SENSITIVE'),
    personalEmail: masked('chen****@personal.example'),
    contract: {
      contractNo: 'CT-DEMO-2024-0218',
      type: '固定期限劳动合同',
      startDate: '2024-06-03',
      endDate: '2027-06-02',
      daysToExpiry: 556,
      status: 'ACTIVE',
    },
    assignments: [
      {
        id: 95,
        orgName: '数字产品部',
        positionName: '产品运营专员',
        managerName: '邵清越',
        validFrom: '2024-06-03',
        validTo: null,
      },
    ],
  },
  {
    id: 5106,
    employeeNo: 'DEMO-0221',
    name: '宋一澄',
    initials: '宋',
    orgId: ORG.engineering.id,
    orgName: ORG.engineering.name,
    positionName: '前端工程师',
    status: 'PROBATION',
    employmentType: '全职',
    hireDate: '2026-09-15',
    phone: masked('188****5043'),
    email: masked('yc****@demo.example'),
    idNumber: masked('310***********4536', 'P2_SENSITIVE'),
    managerName: '程望舒',
    legalEntity: '澄明科技（上海）有限公司',
    workLocation: '上海 · 张江',
    bankAccount: masked('**** **** **** 6322', 'P2_SENSITIVE'),
    address: masked('上海市杨浦区****路 100 号', 'P2_SENSITIVE'),
    personalEmail: masked('song****@personal.example'),
    contract: {
      contractNo: 'CT-DEMO-2026-0221',
      type: '固定期限劳动合同',
      startDate: '2026-09-15',
      endDate: '2029-09-14',
      daysToExpiry: 1026,
      status: 'ACTIVE',
    },
    assignments: [
      {
        id: 96,
        orgName: '研发中心',
        positionName: '前端工程师',
        managerName: '程望舒',
        validFrom: '2026-09-15',
        validTo: null,
      },
    ],
  },
  {
    id: 5107,
    employeeNo: 'DEMO-0230',
    name: '顾南乔',
    initials: '顾',
    orgId: ORG.product.id,
    orgName: ORG.product.name,
    positionName: '用户研究员',
    status: 'ON_LEAVE',
    employmentType: '全职',
    hireDate: '2023-02-13',
    phone: masked('150****8620'),
    email: masked('nq****@demo.example'),
    idNumber: masked('310***********9905', 'P2_SENSITIVE'),
    managerName: '邵清越',
    legalEntity: '澄明科技（上海）有限公司',
    workLocation: '上海 · 张江',
    bankAccount: masked('**** **** **** 5521', 'P2_SENSITIVE'),
    address: masked('上海市普陀区****路 77 号', 'P2_SENSITIVE'),
    personalEmail: masked('gu****@personal.example'),
    contract: {
      contractNo: 'CT-DEMO-2023-0230',
      type: '固定期限劳动合同',
      startDate: '2023-02-13',
      endDate: '2026-02-12',
      daysToExpiry: null,
      status: 'RENEWAL_PENDING',
    },
    assignments: [
      {
        id: 97,
        orgName: '数字产品部',
        positionName: '用户研究员',
        managerName: '邵清越',
        validFrom: '2023-02-13',
        validTo: null,
      },
    ],
  },
  {
    id: 5108,
    employeeNo: 'DEMO-0236',
    name: '唐见微',
    initials: '唐',
    orgId: ORG.hr.id,
    orgName: ORG.hr.name,
    positionName: '薪酬专员',
    status: 'ACTIVE',
    employmentType: '全职',
    hireDate: '2024-01-08',
    phone: masked('186****3147'),
    email: masked('jw****@demo.example'),
    idNumber: masked('310***********3389', 'P2_SENSITIVE'),
    managerName: '林若桐',
    legalEntity: '澄明科技（上海）有限公司',
    workLocation: '上海 · 张江',
    bankAccount: masked('**** **** **** 8470', 'P2_SENSITIVE'),
    address: masked('上海市浦东新区****路 6 号', 'P2_SENSITIVE'),
    personalEmail: masked('tang****@personal.example'),
    contract: {
      contractNo: 'CT-DEMO-2024-0236',
      type: '固定期限劳动合同',
      startDate: '2024-01-08',
      endDate: '2027-01-07',
      daysToExpiry: 410,
      status: 'ACTIVE',
    },
    assignments: [
      {
        id: 98,
        orgName: '人力资源部',
        positionName: '薪酬专员',
        managerName: '林若桐',
        validFrom: '2024-01-08',
        validTo: null,
      },
    ],
  },
  {
    id: 5109,
    employeeNo: 'DEMO-0244',
    name: '闻栖迟',
    initials: '闻',
    orgId: ORG.finance.id,
    orgName: ORG.finance.name,
    positionName: '会计',
    status: 'ACTIVE',
    employmentType: '全职',
    hireDate: '2025-04-21',
    phone: masked('133****0276'),
    email: masked('qc****@demo.example'),
    idNumber: masked('310***********7761', 'P2_SENSITIVE'),
    managerName: '许言川',
    legalEntity: '澄明科技（上海）有限公司',
    workLocation: '上海 · 张江',
    bankAccount: masked('**** **** **** 1706', 'P2_SENSITIVE'),
    address: masked('上海市虹口区****路 45 号', 'P2_SENSITIVE'),
    personalEmail: masked('wen****@personal.example'),
    contract: {
      contractNo: 'CT-DEMO-2025-0244',
      type: '固定期限劳动合同',
      startDate: '2025-04-21',
      endDate: '2028-04-20',
      daysToExpiry: 514,
      status: 'ACTIVE',
    },
    assignments: [
      {
        id: 99,
        orgName: '财务与行政部',
        positionName: '会计',
        managerName: '许言川',
        validFrom: '2025-04-21',
        validTo: null,
      },
    ],
  },
  {
    id: 5110,
    employeeNo: 'DEMO-0251',
    name: '沈知遥',
    initials: '沈',
    orgId: ORG.engineering.id,
    orgName: ORG.engineering.name,
    positionName: '测试工程师',
    status: 'TERMINATED',
    employmentType: '全职',
    hireDate: '2022-05-09',
    phone: masked('139****9150'),
    email: masked('zy****@demo.example'),
    idNumber: masked('310***********4412', 'P2_SENSITIVE'),
    managerName: '程望舒',
    legalEntity: '澄明科技（上海）有限公司',
    workLocation: '上海 · 张江',
    bankAccount: masked('**** **** **** 0231', 'P2_SENSITIVE'),
    address: masked('上海市宝山区****路 19 号', 'P2_SENSITIVE'),
    personalEmail: masked('shen****@personal.example'),
    contract: {
      contractNo: 'CT-DEMO-2022-0251',
      type: '固定期限劳动合同',
      startDate: '2022-05-09',
      endDate: '2026-08-31',
      daysToExpiry: null,
      status: 'ACTIVE',
    },
    assignments: [
      {
        id: 100,
        orgName: '研发中心',
        positionName: '测试工程师',
        managerName: '程望舒',
        validFrom: '2022-05-09',
        validTo: '2026-10-31',
      },
    ],
  },
  {
    id: 5111,
    employeeNo: 'DEMO-0263',
    name: '陆疏影',
    initials: '陆',
    orgId: ORG.product.id,
    orgName: ORG.product.name,
    positionName: '内容运营',
    status: 'ACTIVE',
    employmentType: '全职',
    hireDate: '2025-08-18',
    phone: masked('155****8024'),
    email: masked('sy****@demo.example'),
    idNumber: masked('310***********1290', 'P2_SENSITIVE'),
    managerName: '邵清越',
    legalEntity: '澄明科技（上海）有限公司',
    workLocation: '上海 · 张江',
    bankAccount: masked('**** **** **** 9133', 'P2_SENSITIVE'),
    address: masked('上海市浦东新区****路 53 号', 'P2_SENSITIVE'),
    personalEmail: masked('lu****@personal.example'),
    contract: {
      contractNo: 'CT-DEMO-2025-0263',
      type: '固定期限劳动合同',
      startDate: '2025-08-18',
      endDate: '2028-08-17',
      daysToExpiry: 633,
      status: 'ACTIVE',
    },
    assignments: [
      {
        id: 101,
        orgName: '数字产品部',
        positionName: '内容运营',
        managerName: '邵清越',
        validFrom: '2025-08-18',
        validTo: null,
      },
    ],
  },
  {
    id: 5112,
    employeeNo: 'DEMO-0275',
    name: '贺兰舟',
    initials: '贺',
    orgId: ORG.engineering.id,
    orgName: ORG.engineering.name,
    positionName: '后端工程师',
    status: 'ACTIVE',
    employmentType: '全职',
    hireDate: '2023-10-30',
    phone: masked('151****6719'),
    email: masked('lz****@demo.example'),
    idNumber: masked('310***********6548', 'P2_SENSITIVE'),
    managerName: '程望舒',
    legalEntity: '澄明科技（上海）有限公司',
    workLocation: '上海 · 张江',
    bankAccount: masked('**** **** **** 3856', 'P2_SENSITIVE'),
    address: masked('上海市浦东新区****路 301 号', 'P2_SENSITIVE'),
    personalEmail: masked('he****@personal.example'),
    contract: {
      contractNo: 'CT-DEMO-2023-0275',
      type: '固定期限劳动合同',
      startDate: '2023-10-30',
      endDate: '2026-12-15',
      daysToExpiry: 22,
      status: 'EXPIRING',
    },
    assignments: [
      {
        id: 102,
        orgName: '研发中心',
        positionName: '后端工程师',
        managerName: '程望舒',
        validFrom: '2023-10-30',
        validTo: null,
      },
    ],
  },
]

function applicationTimeline(
  submittedAt: string,
  approver: string,
  approvedAt: string,
  effect: EffectStatus,
  scheduledAt: string | null,
  effectFailedReason: string | null,
) {
  const events: TimelineEvent[] = [
    {
      id: `tl-${submittedAt}`,
      lane: 'APPLICATION' as const,
      title: '申请人提交',
      detail: '材料已齐备，进入审批流程',
      actor: '陈嘉禾',
      occurredAt: submittedAt,
      status: 'SUBMITTED',
    },
    {
      id: `tl-${approvedAt}`,
      lane: 'APPLICATION' as const,
      title: `审批通过 · ${approver}`,
      detail: '同意申请，进入业务生效',
      actor: approver,
      occurredAt: approvedAt,
      status: 'APPROVED',
    },
  ]
  if (effect === 'FAILED') {
    events.push({
      id: `tl-effect-failed-${approvedAt}`,
      lane: 'EFFECT' as const,
      title: '业务生效失败',
      detail: effectFailedReason ?? '生效目标发生并发冲突，已进入自动重试队列',
      actor: '系统',
      occurredAt: '2026-11-23T08:52:00Z',
      status: 'FAILED',
    })
  } else if (effect === 'SCHEDULED') {
    events.push({
      id: `tl-effect-scheduled-${approvedAt}`,
      lane: 'EFFECT' as const,
      title: '已排定生效时间',
      detail: scheduledAt ? `将于 ${scheduledAt.slice(0, 10)} 生效` : '等待生效窗口',
      actor: '系统',
      occurredAt: approvedAt,
      status: 'SCHEDULED',
    })
  } else if (effect === 'APPLIED') {
    events.push({
      id: `tl-effect-applied-${approvedAt}`,
      lane: 'EFFECT' as const,
      title: '业务效果已生效',
      detail: '组织与任职投影已刷新',
      actor: '系统',
      occurredAt: '2026-11-21T02:00:00Z',
      status: 'APPLIED',
    })
  } else if (effect === 'REVERSED') {
    events.push({
      id: `tl-effect-reversed-${approvedAt}`,
      lane: 'EFFECT' as const,
      title: '业务效果已冲正',
      detail: '冲正分录已写入，原记录保留',
      actor: '系统',
      occurredAt: '2026-11-22T06:30:00Z',
      status: 'REVERSED',
    })
  }
  return events
}

type CaseSeed = Omit<CaseDetail, 'timeline'> & {
  failureReason?: string | null
  scheduledAt?: string | null
  approvedAt?: string
}

const caseSeed: CaseSeed[] = [
  {
    id: 8201,
    caseNo: 'CASE-2026-08201',
    title: '年假申请 · 2 天',
    type: 'LEAVE_REQUEST',
    applicantName: '陈嘉禾',
    applicantEmploymentNo: 'DEMO-0218',
    applicantOrgName: '数字产品部',
    appliedAt: '2026-11-22T01:35:00Z',
    applicationStatus: 'IN_APPROVAL',
    effectStatus: null,
    currentNode: '直属经理审批',
    dueAt: '2026-11-25T10:00:00Z',
    isMine: true,
    isAwaitingMe: false,
    description: '申请 2026 年 11 月 27 日至 11 月 28 日休年假 2 天，用于家庭事务。',
    facts: [
      { label: '假期类型', value: '法定年假' },
      { label: '请假日期', value: '2026-11-27 至 2026-11-28' },
      { label: '折合天数', value: '2.00 天' },
      { label: '余额影响', value: '12.00 → 10.00 天' },
    ],
    applicant: '陈嘉禾 · DEMO-0218',
    approver: '邵清越',
    effectSummary: '审批通过后将占用 2 天年假并写入假勤账本',
    effectScheduledAt: null,
    effectFailedReason: null,
    availableActions: [],
    scheduledAt: null,
  },
  {
    id: 8202,
    caseNo: 'CASE-2026-08202',
    title: '调岗 · 陈嘉禾 → 增长产品组',
    type: 'TRANSFER',
    applicantName: '陈嘉禾',
    applicantEmploymentNo: 'DEMO-0218',
    applicantOrgName: '数字产品部',
    appliedAt: '2026-11-20T03:10:00Z',
    applicationStatus: 'APPROVED',
    effectStatus: 'SCHEDULED',
    currentNode: null,
    dueAt: null,
    isMine: true,
    isAwaitingMe: false,
    description: '因组织调整，将陈嘉禾的任职组织由「产品运营组」调整至「增长产品组」，岗位不变。',
    facts: [
      { label: '原组织', value: '数字产品部 / 产品运营组' },
      { label: '目标组织', value: '数字产品部 / 增长产品组' },
      { label: '新汇报线', value: '邵清越' },
      { label: '生效日期', value: '2026-12-01' },
    ],
    applicant: '林若桐 · DEMO-0101',
    approver: '周时安',
    effectSummary: '审批已通过，任职与组织投影将在 2026-12-01 00:00（Asia/Shanghai）切换',
    effectScheduledAt: '2026-12-01T00:00:00+08:00',
    effectFailedReason: null,
    availableActions: [],
    scheduledAt: '2026-12-01',
  },
  {
    id: 8203,
    caseNo: 'CASE-2026-08203',
    title: '合同续签 · 贺兰舟',
    type: 'CONTRACT_RENEWAL',
    applicantName: '贺兰舟',
    applicantEmploymentNo: 'DEMO-0275',
    applicantOrgName: '研发中心',
    appliedAt: '2026-11-18T06:20:00Z',
    applicationStatus: 'APPROVED',
    effectStatus: 'FAILED',
    currentNode: null,
    dueAt: null,
    isMine: false,
    isAwaitingMe: false,
    description: '合同于 2026-12-15 到期，人事专员发起续签，期限 3 年，薪资条款保持不变。',
    facts: [
      { label: '合同编号', value: 'CT-DEMO-2023-0275' },
      { label: '原到期日', value: '2026-12-15' },
      { label: '新合同期限', value: '2026-12-16 至 2029-12-15' },
      { label: '条款版本', value: 'TERMS-v12' },
    ],
    applicant: '唐见微 · DEMO-0236',
    approver: '林若桐',
    effectSummary: '审批已通过，但新合同条款写入失败；原合同仍然有效，系统正在重试',
    effectFailedReason: '条款版本 TERMS-v12 与当前有效版本冲突（effect unique constraint）',
    effectScheduledAt: null,
    availableActions: ['RETRY_EFFECT'],
    scheduledAt: null,
    failureReason: '条款版本 TERMS-v12 与当前有效版本冲突（effect unique constraint）',
  },
  {
    id: 8204,
    caseNo: 'CASE-2026-08204',
    title: '事假申请 · 0.5 天',
    type: 'LEAVE_REQUEST',
    applicantName: '顾南乔',
    applicantEmploymentNo: 'DEMO-0230',
    applicantOrgName: '数字产品部',
    appliedAt: '2026-11-23T00:45:00Z',
    applicationStatus: 'IN_APPROVAL',
    effectStatus: null,
    currentNode: '直属经理审批',
    dueAt: '2026-11-24T10:00:00Z',
    isMine: false,
    isAwaitingMe: true,
    description: '申请 2026 年 11 月 24 日下午事假 0.5 天。',
    facts: [
      { label: '假期类型', value: '事假（无薪）' },
      { label: '请假日期', value: '2026-11-24 下午' },
      { label: '折合天数', value: '0.50 天' },
      { label: '余额影响', value: '事假不占用年假余额' },
    ],
    applicant: '顾南乔 · DEMO-0230',
    approver: '邵清越',
    effectSummary: '审批通过后将写入当日假勤记录',
    effectScheduledAt: null,
    effectFailedReason: null,
    availableActions: ['APPROVE', 'REJECT', 'RETURN'],
    scheduledAt: null,
  },
  {
    id: 8205,
    caseNo: 'CASE-2026-08205',
    title: '补卡申请 · 11 月 19 日',
    type: 'ATTENDANCE_CORRECTION',
    applicantName: '陆疏影',
    applicantEmploymentNo: 'DEMO-0263',
    applicantOrgName: '数字产品部',
    appliedAt: '2026-11-19T11:05:00Z',
    applicationStatus: 'APPROVED',
    effectStatus: 'APPLIED',
    currentNode: null,
    dueAt: null,
    isMine: false,
    isAwaitingMe: false,
    description: '11 月 19 日下班忘记打卡，申请补录 18:32 下班时间。',
    facts: [
      { label: '考勤日期', value: '2026-11-19' },
      { label: '原记录', value: '上班 09:02 / 下班缺失' },
      { label: '补录时间', value: '下班 18:32' },
      { label: '影响', value: '当日出勤工时 +8 小时 30 分' },
    ],
    applicant: '陆疏影 · DEMO-0263',
    approver: '邵清越',
    effectSummary: '考勤日记录已更新，工时汇总已刷新',
    effectScheduledAt: null,
    effectFailedReason: null,
    availableActions: [],
    scheduledAt: null,
  },
  {
    id: 8206,
    caseNo: 'CASE-2026-08206',
    title: '年假申请 · 5 天',
    type: 'LEAVE_REQUEST',
    applicantName: '宋一澄',
    applicantEmploymentNo: 'DEMO-0221',
    applicantOrgName: '研发中心',
    appliedAt: '2026-11-15T08:00:00Z',
    applicationStatus: 'REJECTED',
    effectStatus: null,
    currentNode: null,
    dueAt: null,
    isMine: false,
    isAwaitingMe: false,
    description: '申请 12 月 21 日至 12 月 25 日休年假 5 天，与项目发布时间冲突。',
    facts: [
      { label: '假期类型', value: '法定年假' },
      { label: '请假日期', value: '2026-12-21 至 2026-12-25' },
      { label: '折合天数', value: '5.00 天' },
      { label: '审批意见', value: '版本发布窗口冲突，建议调整至 1 月' },
    ],
    applicant: '宋一澄 · DEMO-0221',
    approver: '程望舒',
    effectSummary: '申请被驳回，无业务效果产生',
    effectScheduledAt: null,
    effectFailedReason: null,
    availableActions: [],
    scheduledAt: null,
  },
  {
    id: 8207,
    caseNo: 'CASE-2026-08207',
    title: '调岗 · 陆疏影 → 增长产品组',
    type: 'TRANSFER',
    applicantName: '陆疏影',
    applicantEmploymentNo: 'DEMO-0263',
    applicantOrgName: '数字产品部',
    appliedAt: '2026-11-16T02:20:00Z',
    applicationStatus: 'APPROVED',
    effectStatus: 'REVERSED',
    currentNode: null,
    dueAt: null,
    isMine: false,
    isAwaitingMe: false,
    description: '原调岗申请已通过并生效，后因岗位编制取消，按流程冲正。',
    facts: [
      { label: '原组织', value: '数字产品部 / 内容运营组' },
      { label: '目标组织', value: '数字产品部 / 增长产品组' },
      { label: '生效日期', value: '2026-11-17' },
      { label: '冲正原因', value: '建设编制调整，目标岗位取消' },
    ],
    applicant: '林若桐 · DEMO-0101',
    approver: '周时安',
    effectSummary: '原调岗效果已冲正，历史记录与冲正原因保留',
    effectScheduledAt: null,
    effectFailedReason: null,
    availableActions: [],
    scheduledAt: null,
  },
]

function toCaseDetail(seed: CaseSeed): CaseDetail {
  const approvedAt = seed.approvedAt ?? '2026-11-23T02:15:00Z'
  const timeline =
    seed.applicationStatus === 'IN_APPROVAL'
      ? [
          {
            id: `tl-submit-${seed.id}`,
            lane: 'APPLICATION' as const,
            title: '申请人提交',
            detail: '材料已齐备，进入审批流程',
            actor: seed.applicantName,
            occurredAt: seed.appliedAt,
            status: 'SUBMITTED',
          },
          {
            id: `tl-current-${seed.id}`,
            lane: 'APPLICATION' as const,
            title: '等待直属经理审批',
            detail: seed.dueAt ? '承诺审批时限内处理' : null,
            actor: seed.approver,
            occurredAt: seed.appliedAt,
            status: 'IN_APPROVAL',
          },
        ]
      : seed.applicationStatus === 'REJECTED'
        ? [
            {
              id: `tl-submit-${seed.id}`,
              lane: 'APPLICATION' as const,
              title: '申请人提交',
              detail: null,
              actor: seed.applicantName,
              occurredAt: seed.appliedAt,
              status: 'SUBMITTED',
            },
            {
              id: `tl-reject-${seed.id}`,
              lane: 'APPLICATION' as const,
              title: `已驳回 · ${seed.approver ?? '审批人'}`,
              detail: '版本发布窗口冲突，建议调整至 1 月',
              actor: seed.approver,
              occurredAt: '2026-11-16T03:00:00Z',
              status: 'REJECTED',
            },
          ]
        : applicationTimeline(
            seed.appliedAt,
            seed.approver ?? '审批人',
            approvedAt,
            seed.effectStatus ?? 'APPLIED',
            seed.scheduledAt ?? null,
            seed.failureReason ?? null,
          )
  return {
    ...seed,
    effectFailedReason: seed.failureReason ?? null,
    timeline,
  }
}

const caseDetails = caseSeed.map((seed) => toCaseDetail(seed))

const notices: Notice[] = [
  {
    id: 301,
    title: '关于 2026 年 12 月薪酬发放日的安排',
    summary: '12 月工资提前至 12 月 8 日发放，请各部门在 12 月 2 日前完成考勤确认。',
    body: [
      '各同事：',
      '因年终结算安排，2026 年 12 月工资发放日由 12 月 10 日提前至 12 月 8 日。考勤与休假数据确认截止时间为 12 月 2 日 18:00。',
      '如对本月考勤记录有异议，请于截止时间前在「假勤 → 考勤日历」发起补正申请。工资条将于发放当日发布。',
      '人力资源部',
      '2026 年 11 月 20 日',
    ],
    status: 'PUBLISHED',
    audienceLabel: '全体员工',
    publishedAt: '2026-11-20T02:00:00Z',
    retractedAt: null,
    authorName: '人力资源部',
    requiresAck: true,
    acknowledged: false,
    acknowledgementCount: 42,
    audienceCount: 86,
  },
  {
    id: 302,
    title: '个人信息保护培训（Q4）报名开启',
    summary: '面向全体员工的数据隐私与信息安全培训，11 月 28 日 14:00 线上举行。',
    body: [
      '为落实个人信息保护要求，全体员工需在 Q4 完成一次数据隐私与信息安全培训。',
      '时间：11 月 28 日 14:00–15:00；形式：线上会议（会议链接将通过站内信发送）。',
      '如无法参加直播，请在 12 月 5 日前观看回放并完成测验。',
    ],
    status: 'PUBLISHED',
    audienceLabel: '全体员工',
    publishedAt: '2026-11-18T06:30:00Z',
    retractedAt: null,
    authorName: '信息安全办公室',
    requiresAck: true,
    acknowledged: true,
    acknowledgementCount: 71,
    audienceCount: 86,
  },
  {
    id: 303,
    title: '研发中心 Q4 技术分享会',
    summary: '四场内部技术分享，11 月 27 日起每周四 16:30 举行，欢迎跨部门参加。',
    body: [
      '研发中心 Q4 技术分享会日程：11 月 27 日可观测性实践，12 月 4 日前端性能预算，12 月 11 日数据一致性，12 月 18 日年度复盘。',
      '地点：张江 3F 多功能厅，线上同步。无需报名，欢迎参加。',
    ],
    status: 'PUBLISHED',
    audienceLabel: '研发中心 + 数字产品部',
    publishedAt: '2026-11-17T03:15:00Z',
    retractedAt: null,
    authorName: '程望舒',
    requiresAck: false,
    acknowledged: true,
    acknowledgementCount: 0,
    audienceCount: 34,
  },
  {
    id: 304,
    title: '年度体检预约提醒',
    summary: '请尚未预约的同事在 12 月 10 日前完成预约，逾期名额将释放。',
    body: [
      '2026 年度员工体检已开放预约，体检机构为仁和健康管理中心，套餐与预约方式详见附件。',
      '预约截止：2026 年 12 月 10 日。如有特殊需求，请联系人力资源部唐见微。',
    ],
    status: 'PUBLISHED',
    audienceLabel: '全体员工',
    publishedAt: '2026-11-12T02:00:00Z',
    retractedAt: null,
    authorName: '人力资源部',
    requiresAck: false,
    acknowledged: false,
    acknowledgementCount: 0,
    audienceCount: 86,
  },
  {
    id: 305,
    title: '办公区消防演练通知（草稿）',
    summary: '拟于 12 月 3 日 15:00 进行消防疏散演练，发布待安全委员会确认。',
    body: ['演练范围：张江 3F/4F 办公区。', '请各部门安全员确认参加人数后由行政部统一发布。'],
    status: 'DRAFT',
    audienceLabel: '上海办公区',
    publishedAt: null,
    retractedAt: null,
    authorName: '行政部',
    requiresAck: true,
    acknowledged: false,
    acknowledgementCount: 0,
    audienceCount: 0,
  },
]

const leaves: LeaveBalance[] = [
  {
    leaveTypeCode: 'ANNUAL',
    leaveTypeName: '法定年假',
    unit: 'DAY',
    granted: 15,
    used: 3,
    pending: 2,
    available: 12,
    expiresOn: '2026-12-31',
  },
  {
    leaveTypeCode: 'SICK',
    leaveTypeName: '病假',
    unit: 'DAY',
    granted: 10,
    used: 1,
    pending: 0,
    available: 9,
    expiresOn: '2026-12-31',
  },
  {
    leaveTypeCode: 'PERSONAL',
    leaveTypeName: '事假（无薪）',
    unit: 'DAY',
    granted: 0,
    used: 0.5,
    pending: 0,
    available: 0,
    expiresOn: null,
  },
  {
    leaveTypeCode: 'COMP_TIME',
    leaveTypeName: '调休',
    unit: 'HOUR',
    granted: 16,
    used: 4,
    pending: 0,
    available: 12,
    expiresOn: '2027-03-31',
  },
]

function ledgerFor(code: string): LeaveLedger {
  const balance = leaves.find((item) => item.leaveTypeCode === code) ?? leaves[0]!
  const day = balance.unit === 'DAY'
  return {
    leaveTypeName: balance.leaveTypeName,
    unit: balance.unit,
    opening: day ? 15 : 16,
    closing: balance.available,
    entries: [
      {
        id: 'ledger-1',
        occurredAt: '2026-01-01T00:00:00Z',
        businessDate: '2026-01-01',
        entryType: 'GRANT',
        quantity: day ? 12 : 16,
        balanceAfter: day ? 12 : 16,
        reason: '2026 年度期初额度',
        sourceCaseNo: null,
      },
      {
        id: 'ledger-2',
        occurredAt: '2026-03-02T03:40:00Z',
        businessDate: '2026-03-09',
        entryType: 'CONSUME',
        quantity: day ? -1 : -4,
        balanceAfter: day ? 11 : 12,
        reason: '年假 · 春季家庭旅行',
        sourceCaseNo: 'CASE-2026-03118',
      },
      {
        id: 'ledger-3',
        occurredAt: '2026-06-30T00:00:00Z',
        businessDate: '2026-06-30',
        entryType: 'GRANT',
        quantity: day ? 3 : 0,
        balanceAfter: day ? 14 : 12,
        reason: '工龄调整补发 3 天',
        sourceCaseNo: null,
      },
      {
        id: 'ledger-4',
        occurredAt: '2026-08-14T07:20:00Z',
        businessDate: '2026-08-20',
        entryType: 'CONSUME',
        quantity: day ? -2 : 0,
        balanceAfter: day ? 12 : 12,
        reason: '年假 · 搬家',
        sourceCaseNo: 'CASE-2026-07421',
      },
      {
        id: 'ledger-5',
        occurredAt: '2026-11-22T01:35:00Z',
        businessDate: '2026-11-27',
        entryType: 'RESERVE',
        quantity: day ? -2 : 0,
        balanceAfter: day ? 10 : 12,
        reason: '待审批请假占用',
        sourceCaseNo: 'CASE-2026-08201',
      },
    ],
  }
}

function attendanceFor(month = '2026-11'): AttendanceMonth {
  const days: AttendanceDay[] = []
  const [yearText, monthText] = month.split('-')
  const year = Number(yearText)
  const monthNumber = Number(monthText)
  const total = new Date(Date.UTC(year, monthNumber, 0)).getUTCDate()
  const statuses: AttendanceStatus[] = ['PRESENT', 'PRESENT', 'PRESENT', 'LATE', 'PRESENT', 'REMOTE', 'REST']
  for (let index = 1; index <= total; index += 1) {
    const date = `${month}-${String(index).padStart(2, '0')}`
    const weekday = weekdayLabel(date)
    const isWeekend = weekday === '周六' || weekday === '周日'
    const status: AttendanceStatus = isWeekend
      ? 'REST'
      : date === '2026-11-19'
        ? 'LATE'
        : date === '2026-11-20'
          ? 'ABSENT'
          : date === '2026-11-24'
            ? 'LEAVE'
            : (statuses[index % statuses.length] ?? 'PRESENT')
    const present = !['REST', 'ABSENT', 'LEAVE', 'HOLIDAY'].includes(status)
    days.push({
      date,
      weekday,
      status,
      scheduledStart: isWeekend ? null : '09:00',
      scheduledEnd: isWeekend ? null : '18:00',
      firstIn: present ? (status === 'LATE' ? '09:27' : '08:56') : null,
      lastOut: present ? (date === '2026-11-23' ? null : '18:24') : null,
      workedMinutes: present ? (status === 'LATE' ? 477 : 508) : 0,
      scheduledMinutes: isWeekend ? 0 : 480,
      exceptionLabel:
        status === 'LATE'
          ? '迟到 27 分钟'
          : status === 'ABSENT'
            ? '缺勤，未提交请假'
            : date === '2026-11-23'
              ? '下班打卡缺失，待补正'
              : null,
    })
  }
  const exceptions = days.filter((day) => day.exceptionLabel)
  return {
    month,
    timezone: TENANT_TIMEZONE,
    employeeName: '陈嘉禾',
    days,
    summary: {
      expectedDays: 20,
      presentDays: 18,
      lateCount: 1,
      absentCount: 1,
      leaveDays: 1,
      remoteDays: 4,
    },
    exceptions,
  }
}

const payrollRuns: PayrollRunSummary[] = [
  {
    id: 7001,
    runNo: 'PR-2026-11',
    period: '2026-11',
    legalEntity: '澄明科技（上海）有限公司',
    state: 'REVIEWED',
    employeeCount: 86,
    grossCents: 2_184_650_00,
    netCents: 1_678_240_00,
    payDate: '2026-12-10',
    updatedAt: '2026-11-23T07:30:00Z',
    isCorrection: false,
    freshAt: '2026-11-23T07:32:00Z',
  },
  {
    id: 7002,
    runNo: 'PR-2026-10',
    period: '2026-10',
    legalEntity: '澄明科技（上海）有限公司',
    state: 'PAID',
    employeeCount: 86,
    grossCents: 2_171_400_00,
    netCents: 1_669_820_00,
    payDate: '2026-11-10',
    updatedAt: '2026-11-10T03:00:00Z',
    isCorrection: false,
    freshAt: '2026-11-23T06:00:00Z',
  },
  {
    id: 7003,
    runNo: 'PR-2026-10-C1',
    period: '2026-10',
    legalEntity: '澄明科技（上海）有限公司',
    state: 'PUBLISHED',
    employeeCount: 2,
    grossCents: 12_800_00,
    netCents: 9_960_00,
    payDate: '2026-11-15',
    updatedAt: '2026-11-15T02:10:00Z',
    isCorrection: true,
    freshAt: '2026-11-23T06:00:00Z',
  },
  {
    id: 7004,
    runNo: 'PR-2026-12',
    period: '2026-12',
    legalEntity: '澄明科技（上海）有限公司',
    state: 'DRAFT',
    employeeCount: 86,
    grossCents: 0,
    netCents: 0,
    payDate: '2026-12-10',
    updatedAt: '2026-11-23T01:00:00Z',
    isCorrection: false,
    freshAt: '2026-11-23T01:00:00Z',
  },
]

const payrollLines: PayrollLine[] = [
  {
    employmentId: 5001,
    employeeName: '林若桐',
    employeeNo: 'DEMO-0101',
    orgName: '人力资源部',
    grossCents: 3_250_000,
    deductionsCents: 785_000,
    netCents: 2_465_000,
    varianceCents: 0,
    status: 'OK',
  },
  {
    employmentId: 5002,
    employeeName: '程望舒',
    employeeNo: 'DEMO-0108',
    orgName: '研发中心',
    grossCents: 5_480_000,
    deductionsCents: 1_312_000,
    netCents: 4_168_000,
    varianceCents: 12_000,
    status: 'REVIEW',
  },
  {
    employmentId: 5003,
    employeeName: '邵清越',
    employeeNo: 'DEMO-0112',
    orgName: '数字产品部',
    grossCents: 4_200_000,
    deductionsCents: 1_020_000,
    netCents: 3_180_000,
    varianceCents: 0,
    status: 'OK',
  },
  {
    employmentId: 5004,
    employeeName: '许言川',
    employeeNo: 'DEMO-0115',
    orgName: '财务与行政部',
    grossCents: 3_900_000,
    deductionsCents: 940_000,
    netCents: 2_960_000,
    varianceCents: -8_000,
    status: 'REVIEW',
  },
  {
    employmentId: 5005,
    employeeName: '陈嘉禾',
    employeeNo: 'DEMO-0218',
    orgName: '数字产品部',
    grossCents: 1_860_000,
    deductionsCents: 412_000,
    netCents: 1_448_000,
    varianceCents: 0,
    status: 'OK',
  },
]

function payrollDetail(id: number): PayrollRunDetail {
  const run = payrollRuns.find((item) => item.id === id) ?? payrollRuns[0]!
  return {
    ...run,
    inputLockedAt: run.state === 'DRAFT' ? null : '2026-11-20T02:00:00Z',
    calculatedAt: ['DRAFT', 'INPUT_LOCKED'].includes(run.state) ? null : '2026-11-21T03:20:00Z',
    approvedAt: ['DRAFT', 'INPUT_LOCKED', 'CALCULATED', 'REVIEWED'].includes(run.state)
      ? null
      : '2026-11-22T06:40:00Z',
    publishedAt: ['DRAFT', 'INPUT_LOCKED', 'CALCULATED', 'REVIEWED', 'APPROVED'].includes(run.state)
      ? null
      : '2026-11-23T01:00:00Z',
    reconciliation: {
      sourceHeadcount: 86,
      calculatedHeadcount: 86,
      grossVarianceCents: 0,
      netVarianceCents: 0,
      blockedIssues: run.id === 7001 ? 1 : 0,
      note:
        run.id === 7001
          ? '有 1 名员工的本月绩效奖金与输入快照不一致，需薪酬专员确认后重新计算。'
          : '输入快照、计算结果与银行发放文件一致。',
    },
    lines: payrollLines,
    history: [
      {
        state: 'DRAFT',
        occurredAt: '2026-11-15T01:00:00Z',
        actor: '唐见微',
        note: '创建 2026-11 工资批次',
      },
      {
        state: 'INPUT_LOCKED',
        occurredAt: '2026-11-20T02:00:00Z',
        actor: '唐见微',
        note: '锁定考勤、合同与假勤输入',
      },
      {
        state: 'CALCULATED',
        occurredAt: '2026-11-21T03:20:00Z',
        actor: '系统',
        note: '计算完成，86/86 人头匹配',
      },
      {
        state: 'REVIEWED',
        occurredAt: '2026-11-22T06:40:00Z',
        actor: '林若桐',
        note: '复核完成，保留 1 项待确认差异',
      },
    ],
    availableActions:
      run.state === 'REVIEWED'
        ? ['APPROVE', 'CREATE_CORRECTION']
        : run.state === 'DRAFT'
          ? ['LOCK_INPUT']
          : run.state === 'APPROVED'
            ? ['PUBLISH', 'CREATE_CORRECTION']
            : ['CREATE_CORRECTION'],
  }
}

const payslips: Payslip[] = [
  {
    id: 9101,
    runId: 7002,
    period: '2026-10',
    employeeName: '陈嘉禾',
    employeeNo: 'DEMO-0218',
    orgName: '数字产品部',
    legalEntity: '澄明科技（上海）有限公司',
    payDate: '2026-11-10',
    grossCents: 1_860_000,
    earningLines: [
      { label: '基本工资', cents: 1_500_000 },
      { label: '岗位津贴', cents: 200_000 },
      { label: '绩效奖金', cents: 150_000 },
      { label: '交通补贴', cents: 10_000 },
    ],
    deductionLines: [
      { label: '养老保险（个人）', cents: 96_000 },
      { label: '医疗保险（个人）', cents: 24_000 },
      { label: '失业保险（个人）', cents: 6_000 },
      { label: '住房公积金（个人）', cents: 120_000 },
      { label: '个人所得税', cents: 166_000 },
    ],
    netCents: 1_448_000,
    employerCostCents: 2_264_400,
    state: 'PAID',
    note: null,
  },
  {
    id: 9102,
    runId: 7003,
    period: '2026-10',
    employeeName: '陈嘉禾',
    employeeNo: 'DEMO-0218',
    orgName: '数字产品部',
    legalEntity: '澄明科技（上海）有限公司',
    payDate: '2026-11-15',
    grossCents: 60_000,
    earningLines: [{ label: '10 月绩效奖金补发', cents: 60_000 }],
    deductionLines: [{ label: '个人所得税（补差）', cents: 6_200 }],
    netCents: 53_800,
    employerCostCents: 60_000,
    state: 'CORRECTION',
    note: '更正批次 PR-2026-10-C1 · 原批次已发放不可修改',
  },
  {
    id: 9103,
    runId: 7001,
    period: '2026-11',
    employeeName: '陈嘉禾',
    employeeNo: 'DEMO-0218',
    orgName: '数字产品部',
    legalEntity: '澄明科技（上海）有限公司',
    payDate: '2026-12-10',
    grossCents: 1_880_000,
    earningLines: [
      { label: '基本工资', cents: 1_500_000 },
      { label: '岗位津贴', cents: 200_000 },
      { label: '绩效奖金', cents: 170_000 },
      { label: '交通补贴', cents: 10_000 },
    ],
    deductionLines: [
      { label: '养老保险（个人）', cents: 96_000 },
      { label: '医疗保险（个人）', cents: 24_000 },
      { label: '失业保险（个人）', cents: 6_000 },
      { label: '住房公积金（个人）', cents: 120_000 },
      { label: '个人所得税', cents: 170_800 },
    ],
    netCents: 1_463_200,
    employerCostCents: 2_288_400,
    state: 'PUBLISHED',
    note: '工资条已发布，发放日为 2026-12-10',
  },
]

const roles = [
  {
    id: '1',
    code: 'HR_ADMIN',
    name: 'HR 管理员',
    template: true,
    memberCount: 2,
    capabilityCount: 22,
    riskLevel: 'HIGH' as const,
    owner: '林若桐',
    purpose: '维护人员、组织、假勤与薪酬主数据',
    expiresOn: null,
  },
  {
    id: '2',
    code: 'PAYROLL_SPECIALIST',
    name: '薪酬专员',
    template: true,
    memberCount: 1,
    capabilityCount: 9,
    riskLevel: 'HIGH' as const,
    owner: '唐见微',
    purpose: '工资计算、复核与发布',
    expiresOn: null,
  },
  {
    id: '3',
    code: 'DEPT_MANAGER',
    name: '部门经理',
    template: true,
    memberCount: 8,
    capabilityCount: 11,
    riskLevel: 'MEDIUM' as const,
    owner: '人力资源部',
    purpose: '部门人员查看与审批',
    expiresOn: null,
  },
  {
    id: '4',
    code: 'AUDITOR',
    name: '审计员',
    template: true,
    memberCount: 1,
    capabilityCount: 8,
    riskLevel: 'MEDIUM' as const,
    owner: '周时安',
    purpose: '只读审计与访问复核',
    expiresOn: null,
  },
  {
    id: '5',
    code: 'TEMP_REPORT_READER',
    name: '临时报表查看（例外）',
    template: false,
    memberCount: 1,
    capabilityCount: 3,
    riskLevel: 'MEDIUM' as const,
    owner: '许言川',
    purpose: '年终结算期间查看薪酬成本报表',
    expiresOn: '2026-12-31',
  },
]

const capabilities = [
  {
    code: 'people.employee.read',
    name: '查看员工目录',
    description: '按组织与 as-of 日期查看授权范围内员工',
    anchorTypes: ['ORG_UNIT', 'SELF'],
    maxFieldProfile: 'P1_INTERNAL' as const,
    riskLevel: 'LOW' as const,
    separationRule: null,
  },
  {
    code: 'people.employee.read',
    name: '查看敏感身份字段',
    description: '逐字段查看身份证号、银行卡等 P2 字段并记录审计',
    anchorTypes: ['SELF', 'ORG_UNIT'],
    maxFieldProfile: 'P2_SENSITIVE' as const,
    riskLevel: 'HIGH' as const,
    separationRule: '需单独字段绑定，禁止批量导出',
  },
  {
    code: 'workflow.case.decide',
    name: '审批工作流案例',
    description: '对授权范围内待办提交同意、驳回或退回',
    anchorTypes: ['ORG_UNIT', 'SELF'],
    maxFieldProfile: 'P1_INTERNAL' as const,
    riskLevel: 'MEDIUM' as const,
    separationRule: '不得审批本人发起的案例',
  },
  {
    code: 'workflow.case.admin',
    name: '重试生效任务',
    description: '对 FAILED 状态 Effect 发起幂等重试',
    anchorTypes: ['TENANT', 'ORG_UNIT'],
    maxFieldProfile: 'P2_SENSITIVE' as const,
    riskLevel: 'HIGH' as const,
    separationRule: '需与审批决定人分离',
  },
  {
    code: 'org.unit.manage',
    name: '移动组织节点',
    description: '发起组织移动并查看影响预览',
    anchorTypes: ['ORG_UNIT'],
    maxFieldProfile: 'P1_INTERNAL' as const,
    riskLevel: 'HIGH' as const,
    separationRule: '影响超过 50 人需双人复核',
  },
  {
    code: 'payroll.run.prepare',
    name: '管理工资批次',
    description: '锁定输入、计算、复核、审批与发布工资批次',
    anchorTypes: ['LEGAL_ENTITY', 'PAYROLL_RUN'],
    maxFieldProfile: 'P3_RESTRICTED' as const,
    riskLevel: 'HIGH' as const,
    separationRule: '制单、复核与发布不可为同一人',
  },
  {
    code: 'payroll.payslip.read.self',
    name: '查看本人工资条',
    description: '读取当前主体本人已发布工资条',
    anchorTypes: ['SELF'],
    maxFieldProfile: 'P3_RESTRICTED' as const,
    riskLevel: 'MEDIUM' as const,
    separationRule: null,
  },
  {
    code: 'reporting.export',
    name: '导出报表',
    description: '按目的导出授权字段，生成带水印短效文件',
    anchorTypes: ['TENANT', 'ORG_UNIT', 'LEGAL_ENTITY'],
    maxFieldProfile: 'P2_SENSITIVE' as const,
    riskLevel: 'HIGH' as const,
    separationRule: '不可导出 P3 字段',
  },
  {
    code: 'iam.binding.manage',
    name: '管理授权绑定',
    description: '授予或回收能力、锚点、字段与时限',
    anchorTypes: ['TENANT'],
    maxFieldProfile: 'P1_INTERNAL' as const,
    riskLevel: 'HIGH' as const,
    separationRule: '授权人与审计人分离',
  },
  {
    code: 'iam.audit.read',
    name: '查看审计日志',
    description: '检索授权、导出、敏感字段访问与决策事件',
    anchorTypes: ['TENANT'],
    maxFieldProfile: 'P1_INTERNAL' as const,
    riskLevel: 'MEDIUM' as const,
    separationRule: null,
  },
]

function bindings(): AuthorityBinding[] {
  return [
    {
      id: 1,
      principalName: '林若桐',
      principalEmploymentNo: 'DEMO-0101',
      capabilityCode: 'people.employee.read',
      capabilityName: '查看敏感身份字段',
      anchorType: 'ORG_UNIT',
      anchorName: '人力资源部',
      fieldProfiles: ['P2_SENSITIVE'],
      purpose: '入职材料核验',
      validFrom: '2026-01-01',
      expiresOn: '2027-01-01',
      status: 'ACTIVE',
      riskLevel: 'HIGH',
    },
    {
      id: 2,
      principalName: '唐见微',
      principalEmploymentNo: 'DEMO-0236',
      capabilityCode: 'payroll.run.prepare',
      capabilityName: '管理工资批次',
      anchorType: 'LEGAL_ENTITY',
      anchorName: '澄明科技（上海）有限公司',
      fieldProfiles: ['P1_INTERNAL', 'P2_SENSITIVE'],
      purpose: '月度工资计算与复核',
      validFrom: '2025-06-01',
      expiresOn: null,
      status: 'ACTIVE',
      riskLevel: 'HIGH',
    },
    {
      id: 3,
      principalName: '许言川',
      principalEmploymentNo: 'DEMO-0115',
      capabilityCode: 'reporting.export',
      capabilityName: '导出报表',
      anchorType: 'ORG_UNIT',
      anchorName: '财务与行政部',
      fieldProfiles: ['P1_INTERNAL'],
      purpose: '年度预算复盘',
      validFrom: '2026-11-01',
      expiresOn: '2026-12-31',
      status: 'EXPIRING',
      riskLevel: 'HIGH',
    },
    {
      id: 4,
      principalName: '宋一澄',
      principalEmploymentNo: 'DEMO-0221',
      capabilityCode: 'people.employee.read',
      capabilityName: '查看员工目录',
      anchorType: 'ORG_UNIT',
      anchorName: '研发中心',
      fieldProfiles: ['P0_PUBLIC'],
      purpose: '新员工协作查询',
      validFrom: '2026-11-01',
      expiresOn: '2026-11-20',
      status: 'EXPIRED',
      riskLevel: 'LOW',
    },
    {
      id: 5,
      principalName: '沈知遥',
      principalEmploymentNo: 'DEMO-0251',
      capabilityCode: 'people.employee.read',
      capabilityName: '查看员工目录',
      anchorType: 'ORG_UNIT',
      anchorName: '研发中心',
      fieldProfiles: ['P0_PUBLIC'],
      purpose: '离职后权限回收',
      validFrom: '2025-01-01',
      expiresOn: '2026-10-31',
      status: 'REVOKED',
      riskLevel: 'LOW',
    },
  ]
}

const auditEvents: AuditEventItem[] = [
  {
    id: '01JBH8H2C8R3K9W2A6Q4',
    occurredAt: '2026-11-23T08:51:00Z',
    actorName: '林若桐',
    action: 'SENSITIVE_FIELD_REVEAL',
    target: '员工 DEMO-0275 · 身份证号',
    result: 'SUCCESS',
    purpose: '合同续签身份核验',
    requestId: 'req_01jb8k72c9',
    ipAddress: '10.20.4.18',
  },
  {
    id: '01JBH8G4Z5X2P7M1T3N9',
    occurredAt: '2026-11-23T08:35:00Z',
    actorName: '许言川',
    action: 'REPORT_EXPORT',
    target: '薪酬成本报表 · 2026-10',
    result: 'SUCCESS',
    purpose: 'PAYROLL_RECONCILIATION',
    requestId: 'req_01jb8j2v1a',
    ipAddress: '10.20.6.33',
  },
  {
    id: '01JBH8F5V4N6B2C8X1L7',
    occurredAt: '2026-11-23T08:22:00Z',
    actorName: '宋一澄',
    action: 'CASE_VIEW',
    target: 'CASE-2026-08203',
    result: 'DENIED',
    purpose: null,
    requestId: 'req_01jb8h8p4k',
    ipAddress: '10.20.7.91',
  },
  {
    id: '01JBH8E9Q1M7Z3S5D2F8',
    occurredAt: '2026-11-23T08:01:00Z',
    actorName: '唐见微',
    action: 'PAYROLL_RUN_REVIEW',
    target: 'PR-2026-11',
    result: 'SUCCESS',
    purpose: null,
    requestId: 'req_01jb8g0t6b',
    ipAddress: '10.20.4.22',
  },
  {
    id: '01JBH8D3W8K5R2Y9H6C4',
    occurredAt: '2026-11-22T07:48:00Z',
    actorName: '林若桐',
    action: 'BINDING_CREATE',
    target: '许言川 · reporting.export',
    result: 'SUCCESS',
    purpose: '年度预算复盘时限授权',
    requestId: 'req_01jb70x3v2',
    ipAddress: '10.20.4.18',
  },
  {
    id: '01JBH8C7T2R8N4D6J1M5',
    occurredAt: '2026-11-22T06:12:00Z',
    actorName: '未知会话',
    action: 'LOGIN',
    target: 'jiahe.chen@demo.example',
    result: 'DENIED',
    purpose: null,
    requestId: 'req_01jb6z8q9r',
    ipAddress: '113.88.41.7',
  },
]

const reportDefinitions: ReportDefinition[] = [
  {
    id: 1,
    code: 'HEADCOUNT_BY_ORG',
    name: '组织人数与编制',
    description: '按 as-of 日期统计在职人数、试用期人数与编制占用',
    asOfSupported: true,
    drilldownRoute: '/dashboard/metric/headcount',
    fields: [
      { key: 'org_path', label: '组织路径', profile: 'P0_PUBLIC', exportable: true },
      { key: 'headcount', label: '在编人数', profile: 'P0_PUBLIC', exportable: true },
      { key: 'probation', label: '试用期', profile: 'P0_PUBLIC', exportable: true },
      { key: 'vacancy', label: '空缺编制', profile: 'P0_PUBLIC', exportable: true },
    ],
    filters: [
      { key: 'as_of', label: 'as-of 日期', type: 'DATE_RANGE' },
      { key: 'org', label: '组织', type: 'ORG' },
    ],
  },
  {
    id: 2,
    code: 'PAYROLL_COST',
    name: '薪酬成本',
    description: '按法人、部门统计应发、实发与雇主成本',
    asOfSupported: false,
    drilldownRoute: null,
    fields: [
      { key: 'legal_entity', label: '法人', profile: 'P0_PUBLIC', exportable: true },
      { key: 'org_path', label: '组织路径', profile: 'P0_PUBLIC', exportable: true },
      { key: 'gross', label: '应发合计', profile: 'P2_SENSITIVE', exportable: false },
      { key: 'net', label: '实发合计', profile: 'P2_SENSITIVE', exportable: false },
      { key: 'employer_cost', label: '雇主成本', profile: 'P2_SENSITIVE', exportable: false },
    ],
    filters: [
      { key: 'period', label: '工资期间', type: 'SELECT', options: ['2026-11', '2026-10', '2026-09'] },
      { key: 'legal_entity', label: '法人', type: 'SELECT', options: ['澄明科技（上海）有限公司'] },
    ],
  },
  {
    id: 3,
    code: 'CONTRACT_EXPIRY',
    name: '合同到期清单',
    description: '未来 90 天到期合同与续签处理状态',
    asOfSupported: true,
    drilldownRoute: '/dashboard/metric/contract_expiring',
    fields: [
      { key: 'employee', label: '员工', profile: 'P0_PUBLIC', exportable: true },
      { key: 'org_path', label: '组织路径', profile: 'P0_PUBLIC', exportable: true },
      { key: 'end_date', label: '到期日', profile: 'P0_PUBLIC', exportable: true },
      { key: 'days_remaining', label: '剩余天数', profile: 'P0_PUBLIC', exportable: true },
    ],
    filters: [{ key: 'window', label: '预警窗口', type: 'SELECT', options: ['30 天', '60 天', '90 天'] }],
  },
]

function reportFor(code: string): ReportResult {
  if (code === 'PAYROLL_COST') {
    return {
      definitionCode: code,
      title: '薪酬成本',
      subtitle: '2026-11 · 澄明科技（上海）有限公司',
      columns: [
        { key: 'legal_entity', label: '法人', profile: 'P0_PUBLIC' },
        { key: 'org_path', label: '组织路径', profile: 'P0_PUBLIC' },
        { key: 'gross', label: '应发合计', profile: 'P2_SENSITIVE' },
        { key: 'net', label: '实发合计', profile: 'P2_SENSITIVE' },
        { key: 'employer_cost', label: '雇主成本', profile: 'P2_SENSITIVE' },
      ],
      rows: [
        {
          legal_entity: '澄明科技（上海）有限公司',
          org_path: '研发中心',
          gross: '¥1,286,320.00',
          net: '¥988,470.00',
          employer_cost: '¥1,558,214.00',
        },
        {
          legal_entity: '澄明科技（上海）有限公司',
          org_path: '数字产品部',
          gross: '¥542,860.00',
          net: '¥416,750.00',
          employer_cost: '¥656,410.00',
        },
        {
          legal_entity: '澄明科技（上海）有限公司',
          org_path: '人力资源部',
          gross: '¥188,470.00',
          net: '¥145,620.00',
          employer_cost: '¥228,090.00',
        },
        {
          legal_entity: '澄明科技（上海）有限公司',
          org_path: '财务与行政部',
          gross: '¥167,000.00',
          net: '¥127,400.00',
          employer_cost: '¥202,130.00',
        },
      ],
      chart: [
        { label: '研发中心', value: 1286320 },
        { label: '数字产品部', value: 542860 },
        { label: '人力资源部', value: 188470 },
        { label: '财务与行政部', value: 167000 },
      ],
      totalRows: 4,
      generatedAt: MOCK_NOW,
      watermark: '林若桐 · 2026-11-23 17:12 · 仅限内部核对',
    }
  }
  if (code === 'CONTRACT_EXPIRY') {
    return {
      definitionCode: code,
      title: '合同到期清单',
      subtitle: '未来 90 天 · as-of 2026-11-23',
      columns: [
        { key: 'employee', label: '员工', profile: 'P0_PUBLIC' },
        { key: 'org_path', label: '组织路径', profile: 'P0_PUBLIC' },
        { key: 'end_date', label: '到期日', profile: 'P0_PUBLIC' },
        { key: 'days_remaining', label: '剩余天数', profile: 'P0_PUBLIC' },
      ],
      rows: [
        { employee: '贺兰舟', org_path: '研发中心 / 平台工程组', end_date: '2026-12-15', days_remaining: 22 },
        { employee: '顾南乔', org_path: '数字产品部 / 用户研究组', end_date: '2026-11-30', days_remaining: 7 },
      ],
      chart: [
        { label: '30 天内', value: 1 },
        { label: '31–60 天', value: 0 },
        { label: '61–90 天', value: 1 },
      ],
      totalRows: 2,
      generatedAt: MOCK_NOW,
      watermark: '林若桐 · 2026-11-23 17:12 · 仅限内部核对',
    }
  }
  return {
    definitionCode: 'HEADCOUNT_BY_ORG',
    title: '组织人数与编制',
    subtitle: 'as-of 2026-11-23 · 澄明科技（上海）有限公司',
    columns: [
      { key: 'org_path', label: '组织路径', profile: 'P0_PUBLIC' },
      { key: 'headcount', label: '在编人数', profile: 'P0_PUBLIC' },
      { key: 'probation', label: '试用期', profile: 'P0_PUBLIC' },
      { key: 'vacancy', label: '空缺编制', profile: 'P0_PUBLIC' },
    ],
    rows: [
      { org_path: '研发中心', headcount: 38, probation: 4, vacancy: 3 },
      { org_path: '数字产品部', headcount: 24, probation: 2, vacancy: 1 },
      { org_path: '人力资源部', headcount: 9, probation: 0, vacancy: 1 },
      { org_path: '财务与行政部', headcount: 7, probation: 1, vacancy: 0 },
      { org_path: '总经办', headcount: 8, probation: 0, vacancy: 0 },
      { org_path: '合计', headcount: 86, probation: 7, vacancy: 5 },
    ],
    chart: [
      { label: '研发中心', value: 38 },
      { label: '数字产品部', value: 24 },
      { label: '人力资源部', value: 9 },
      { label: '财务与行政部', value: 7 },
      { label: '总经办', value: 8 },
    ],
    totalRows: 6,
    generatedAt: MOCK_NOW,
    watermark: '林若桐 · 2026-11-23 17:12 · 仅限内部核对',
  }
}

const exportJobs = new Map<string, ExportJob>()

function orgTree(): OrgNode[] {
  return [
    {
      id: ORG.company.id,
      code: ORG.company.code,
      name: ORG.company.name,
      type: 'COMPANY',
      parentId: null,
      legalEntityId: 1,
      managerName: ORG.company.manager,
      headcount: 86,
      directHeadcount: 8,
      children: [
        {
          id: ORG.engineering.id,
          code: ORG.engineering.code,
          name: ORG.engineering.name,
          type: 'DIVISION',
          parentId: ORG.company.id,
          legalEntityId: 1,
          managerName: ORG.engineering.manager,
          headcount: 38,
          directHeadcount: 2,
          children: [
            {
              id: 141,
              code: 'ENG-PLATFORM',
              name: '平台工程组',
              type: 'TEAM',
              parentId: null,
              legalEntityId: 1,
              managerName: '贺兰舟',
              headcount: 14,
              directHeadcount: 14,
              children: [],
            },
            {
              id: 142,
              code: 'ENG-PRODUCT',
              name: '产品研发组',
              type: 'TEAM',
              parentId: null,
              legalEntityId: 1,
              managerName: '宋一澄',
              headcount: 13,
              directHeadcount: 13,
              children: [],
            },
            {
              id: 143,
              code: 'ENG-QA',
              name: '质量保障组',
              type: 'TEAM',
              parentId: null,
              legalEntityId: 1,
              managerName: '沈知遥',
              headcount: 9,
              directHeadcount: 9,
              children: [],
            },
          ],
        },
        {
          id: ORG.product.id,
          code: ORG.product.code,
          name: ORG.product.name,
          type: 'DIVISION',
          parentId: ORG.company.id,
          legalEntityId: 1,
          managerName: ORG.product.manager,
          headcount: 24,
          directHeadcount: 3,
          children: [
            {
              id: 111,
              code: 'DP-GROWTH',
              name: '增长产品组',
              type: 'TEAM',
              parentId: null,
              legalEntityId: 1,
              managerName: '邵清越',
              headcount: 11,
              directHeadcount: 11,
              children: [],
            },
            {
              id: 112,
              code: 'DP-OPS',
              name: '产品运营组',
              type: 'TEAM',
              parentId: null,
              legalEntityId: 1,
              managerName: '陈嘉禾',
              headcount: 8,
              directHeadcount: 8,
              children: [],
            },
            {
              id: 113,
              code: 'DP-RESEARCH',
              name: '用户研究组',
              type: 'TEAM',
              parentId: null,
              legalEntityId: 1,
              managerName: '顾南乔',
              headcount: 2,
              directHeadcount: 2,
              children: [],
            },
          ],
        },
        {
          id: ORG.hr.id,
          code: ORG.hr.code,
          name: ORG.hr.name,
          type: 'DEPARTMENT',
          parentId: null,
          legalEntityId: 1,
          managerName: ORG.hr.manager,
          headcount: 9,
          directHeadcount: 9,
          children: [],
        },
        {
          id: ORG.finance.id,
          code: ORG.finance.code,
          name: ORG.finance.name,
          type: 'DEPARTMENT',
          parentId: null,
          legalEntityId: 1,
          managerName: ORG.finance.manager,
          headcount: 7,
          directHeadcount: 7,
          children: [],
        },
      ],
    },
  ]
}

export function buildMoveImpact(sourceName: string, targetName: string, effectiveDate: string): MoveImpact {
  return {
    sourceName,
    targetName,
    effectiveDate,
    affectedEmployees: 8,
    affectedManagers: 2,
    affectedPayrollCostCenters: 1,
    crossEntityWarning: false,
  }
}

function findOrgName(nodes: OrgNode[], id: number): OrgNode | null {
  for (const node of nodes) {
    if (node.id === id) return node
    const child = findOrgName(node.children, id)
    if (child) return child
  }
  return null
}

function dashboard(): DashboardData {
  return {
    asOf: TODAY,
    freshness: '投影更新于 2026-11-23 17:05（Asia/Shanghai）',
    greeting: '林若桐，下午好',
    metrics: [
      {
        code: 'headcount',
        status: 'AVAILABLE' as const,
        reason: null,
        sourceHash: 'mock',
        computedAt: MOCK_NOW,
        label: '在职人数',
        value: 86,
        unit: '人',
        trend: '较上月 +2',
        trendDirection: 'UP',
        drilldownLabel: '查看在职名单',
      },
      {
        code: 'probation',
        status: 'AVAILABLE' as const,
        reason: null,
        sourceHash: 'mock',
        computedAt: MOCK_NOW,
        label: '试用期人数',
        value: 7,
        unit: '人',
        trend: '2 人本月转正',
        trendDirection: 'FLAT',
        drilldownLabel: '查看试用期名单',
      },
      {
        code: 'turnover',
        status: 'AVAILABLE' as const,
        reason: null,
        sourceHash: 'mock',
        computedAt: MOCK_NOW,
        label: '年度主动离职率',
        value: 6.4,
        unit: '%',
        trend: '低于目标 8%',
        trendDirection: 'DOWN',
        drilldownLabel: '查看离职明细',
      },
      {
        code: 'pending_approvals',
        status: 'AVAILABLE' as const,
        reason: null,
        sourceHash: 'mock',
        computedAt: MOCK_NOW,
        label: '待我审批',
        value: 3,
        unit: '件',
        trend: '最长等待 1 天',
        trendDirection: 'FLAT',
        drilldownLabel: '进入审批中心',
      },
      {
        code: 'contract_expiring',
        status: 'AVAILABLE' as const,
        reason: null,
        sourceHash: 'mock',
        computedAt: MOCK_NOW,
        label: '合同 30 天内到期',
        value: 2,
        unit: '份',
        trend: '1 份待续签',
        trendDirection: 'UP',
        drilldownLabel: '查看到期清单',
      },
      {
        code: 'recruitment_funnel',
        status: 'AVAILABLE' as const,
        reason: null,
        sourceHash: 'mock',
        computedAt: MOCK_NOW,
        label: '招聘漏斗在途',
        value: 18,
        unit: '人',
        trend: '面试 6 · Offer 2',
        trendDirection: 'UP',
        drilldownLabel: '查看招聘漏斗',
      },
    ],
    pendingApprovals: caseDetails
      .filter((item) => item.isAwaitingMe)
      .slice(0, 5)
      .map((item) => ({
        id: item.id,
        caseNo: item.caseNo,
        title: item.title,
        status: item.applicationStatus,
        ageDays: 1,
        dueAt: item.dueAt,
      })),
    recentNotices: notices
      .filter((notice) => notice.status === 'PUBLISHED')
      .slice(0, 5)
      .map((notice) => ({
        id: notice.id,
        title: notice.title,
        publishedAt: notice.publishedAt,
        requiresAck: notice.requiresAck,
        acknowledged: notice.acknowledged,
        authorName: notice.authorName,
      })),
    contractAlerts: [
      {
        employmentId: 5107,
        employeeName: '顾南乔',
        employeeNo: 'DEMO-0230',
        orgName: '数字产品部 / 用户研究组',
        endDate: '2026-11-30',
        daysRemaining: 7,
      },
      {
        employmentId: 5112,
        employeeName: '贺兰舟',
        employeeNo: 'DEMO-0275',
        orgName: '研发中心 / 平台工程组',
        endDate: '2026-12-15',
        daysRemaining: 22,
      },
    ],
  }
}

function metricDrilldown(metric: DashboardMetricCode): MetricDrilldown {
  const config: Record<DashboardMetricCode, { label: string; predicate: string; rows: MetricDrilldown['rows'] }> = {
    headcount: {
      label: '在职人数',
      predicate: 'employment.status = ACTIVE ∧ assignment.org ∈ 授权组织 ∧ as_of = 2026-11-23',
      rows: employeeSeed.map((employee) => ({
        id: String(employee.id),
        primary: employee.name,
        secondary: `${employee.employeeNo} · ${employee.orgName}`,
        status: employee.status,
        detail: employee.positionName,
        link: `/employees?employee=${employee.id}`,
      })),
    },
    probation: {
      label: '试用期人数',
      predicate: 'employment.status = PROBATION ∧ assignment.org ∈ 授权组织 ∧ as_of = 2026-11-23',
      rows: employeeSeed
        .filter((employee) => employee.status === 'PROBATION')
        .map((employee) => ({
          id: String(employee.id),
          primary: employee.name,
          secondary: `${employee.employeeNo} · ${employee.orgName}`,
          status: 'PROBATION',
          detail: `入职 ${employee.hireDate}`,
          link: `/employees?employee=${employee.id}`,
        })),
    },
    turnover: {
      label: '年度主动离职率',
      predicate: 'termination.type = VOLUNTARY ∧ termination.date ∈ 2026 ∧ scope = 授权组织',
      rows: [
        {
          id: '5109',
          primary: '沈知遥',
          secondary: '研发中心 / 质量保障组',
          status: 'TERMINATED',
          detail: '离职日 2026-10-31 · 主动离职',
          link: null,
        },
        {
          id: '5081',
          primary: '苏念安',
          secondary: '数字产品部 / 产品运营组',
          status: 'TERMINATED',
          detail: '离职日 2026-06-18 · 主动离职',
          link: null,
        },
      ],
    },
    pending_approvals: {
      label: '待我审批',
      predicate: 'case.current_node.assignee = principal ∧ case.application_status = IN_APPROVAL',
      rows: caseDetails
        .filter((item) => item.isAwaitingMe)
        .map((item) => ({
          id: String(item.id),
          primary: item.title,
          secondary: `${item.caseNo} · ${item.applicantName}`,
          status: item.applicationStatus,
          detail: item.currentNode ?? '',
          link: `/cases/${item.id}`,
        })),
    },
    contract_expiring: {
      label: '合同 30 天内到期',
      predicate: 'contract.end_date ≤ as_of + 30 ∧ contract.status = ACTIVE ∧ org ∈ 授权组织',
      rows: dashboard().contractAlerts.map((item) => ({
        id: String(item.employmentId),
        primary: item.employeeName,
        secondary: `${item.orgName} · ${item.employeeNo}`,
        status: 'EXPIRING',
        detail: `${item.endDate} 到期（${item.daysRemaining} 天）`,
        link: `/employees?employee=${item.employmentId}`,
      })),
    },
    recruitment_funnel: {
      label: '招聘漏斗在途',
      predicate: 'requisition.status = OPEN ∧ candidate.stage ∈ {SCREEN, INTERVIEW, OFFER} ∧ org ∈ 授权组织',
      rows: [
        {
          id: 'R-2026-014',
          primary: '高级前端工程师',
          secondary: '研发中心 · 平台工程组',
          status: 'INTERVIEW',
          detail: '筛选 12 · 面试 4 · Offer 1',
          link: null,
        },
        {
          id: 'R-2026-021',
          primary: '产品运营专员',
          secondary: '数字产品部 · 产品运营组',
          status: 'OFFER',
          detail: '筛选 8 · 面试 2 · Offer 1',
          link: null,
        },
      ],
    },
  }
  const selected = config[metric]
  return {
    metric,
    label: selected.label,
    predicate: selected.predicate,
    asOf: TODAY,
    total: selected.rows.length,
    columns: [
      { key: 'primary', label: '主记录' },
      { key: 'secondary', label: '所属组织 / 编号' },
      { key: 'status', label: '状态' },
      { key: 'detail', label: '说明' },
    ],
    rows: selected.rows,
  }
}

function workspace(principal: Principal): WorkspaceProjection {
  const employee = employeeSeed.find((item) => item.employeeNo === principal.employmentNo) ?? employeeSeed[0]!
  return {
    profile: {
      employmentId: 5105,
      name: principal.displayName,
      initials: principal.initials,
      employeeNo: principal.employmentNo,
      jobTitle: principal.jobTitle,
      orgName: principal.orgName,
      managerName: employee.managerName,
      workLocation: employee.workLocation,
      email: principal.email,
      phone: employee.phone,
    },
    contract: employee.contract,
    businessDate: '2026-11-23',
    attendanceSummary: attendanceFor().summary,
    attendanceMonth: '2026-11',
    attendanceException: { date: '2026-11-23', label: '下班打卡缺失' },
    leaveBalances: leaves,
    myRequests: caseDetails
      .filter((item) => item.isMine)
      .slice(0, 4)
      .map((item) => ({
        case_id: item.id,
        leave_type_id: 1,
        from_date: item.appliedAt.slice(0, 10),
        to_date: item.appliedAt.slice(0, 10),
        minutes: 480,
        state: item.applicationStatus,
      })),
    payslips: payslipsFor(principal)
      .slice(0, 3)
      .map((slip) => ({
        id: slip.id,
        period: slip.period,
        payDate: slip.payDate,
        netCents: slip.netCents,
        state: slip.state,
      })),
    notices: notices
      .filter((notice) => notice.status === 'PUBLISHED')
      .slice(0, 3)
      .map((notice) => ({
        id: notice.id,
        title: notice.title,
        publishedAt: notice.publishedAt,
        requiresAck: notice.requiresAck,
        acknowledged: notice.acknowledged,
        authorName: notice.authorName,
      })),
    freshness: '2026-11-23T09:05:00Z',
  }
}

function applyCaseAction(id: number, action: 'APPROVE' | 'REJECT' | 'RETURN' | 'RETRY_EFFECT', comment?: string): CaseDetail {
  const index = caseDetails.findIndex((item) => item.id === id)
  if (index < 0) throw new ApiError('NOT_FOUND', '未找到该审批案例')
  const current = caseDetails[index]!
  if (action === 'RETRY_EFFECT') {
    if (current.effectStatus !== 'FAILED') {
      throw new ApiError('CONFLICT', '只有生效失败的案例可以重试')
    }
    const retried: CaseDetail = {
      ...current,
      effectStatus: 'APPLIED',
      effectSummary: '重试成功，合同条款已写入并从下一合同周期起生效',
      effectFailedReason: null,
      availableActions: [],
      timeline: [
        ...current.timeline,
        {
          id: `tl-retry-${Date.now()}`,
          lane: 'EFFECT',
          title: '管理员重试生效',
          detail: '重试成功，条款版本已更新',
          actor: '林若桐',
          occurredAt: MOCK_NOW,
          status: 'APPLIED',
        },
      ],
    }
    caseDetails[index] = retried
    return retried
  }
  if (current.applicationStatus !== 'IN_APPROVAL' || !current.availableActions.includes(action)) {
    throw new ApiError('CONFLICT', '该案例当前状态不允许此操作')
  }
  const decided: CaseDetail = {
    ...current,
    applicationStatus: action === 'APPROVE' ? 'APPROVED' : action === 'REJECT' ? 'REJECTED' : 'IN_APPROVAL',
    effectStatus: action === 'APPROVE' ? 'APPLIED' : null,
    currentNode: action === 'RETURN' ? '申请人补充材料' : null,
    availableActions: [],
    effectSummary: action === 'APPROVE' ? '审批通过，假勤占用已生效' : '决定已记录，无业务效果',
    isAwaitingMe: false,
    timeline: [
      ...current.timeline,
      {
        id: `tl-decision-${Date.now()}`,
        lane: 'APPLICATION',
        title:
          action === 'APPROVE'
            ? '已同意 · 林若桐'
            : action === 'REJECT'
              ? '已驳回 · 林若桐'
              : '已退回补充材料 · 林若桐',
        detail: comment ?? null,
        actor: '林若桐',
        occurredAt: MOCK_NOW,
        status: action === 'APPROVE' ? 'APPROVED' : action === 'REJECT' ? 'REJECTED' : 'IN_APPROVAL',
      },
      ...(action === 'APPROVE'
        ? [
            {
              id: `tl-effect-${Date.now()}`,
              lane: 'EFFECT' as const,
              title: '业务效果已生效',
              detail: '假勤账本占用已写入',
              actor: '系统',
              occurredAt: MOCK_NOW,
              status: 'APPLIED',
            },
          ]
        : []),
    ],
  }
  caseDetails[index] = decided
  return decided
}

function matchEmployee(employee: SeededEmployee, query: { search: string; status: string; orgId: number | null }): boolean {
  const search = query.search.trim().toLowerCase()
  if (search) {
    const haystack = `${employee.name} ${employee.employeeNo} ${employee.positionName} ${employee.orgName}`.toLowerCase()
    if (!haystack.includes(search)) return false
  }
  if (query.status && employee.status !== query.status) return false
  if (query.orgId) {
    const tree = orgTree()
    const node = findOrgName(tree, query.orgId)
    const ids = new Set<number>()
    const collect = (item: OrgNode) => {
      ids.add(item.id)
      item.children.forEach(collect)
    }
    if (node) collect(node)
    if (!ids.has(employee.orgId)) return false
  }
  return true
}

const wait = <T>(value: T, delay = 120): Promise<T> =>
  new Promise((resolve) => {
    setTimeout(() => resolve(value), delay)
  })

type MockRole = 'admin' | 'hr' | 'employee'

/**
 * Mock transport only: a non-sensitive demo-role cookie reproduces session
 * cookie semantics across reloads. It is NOT an access token and only exists in
 * VITE_API_MODE=mock. The HTTP adapter uses the real HttpOnly session cookie.
 */
function readMockRole(): MockRole | null {
  if (typeof document === 'undefined') return null
  const match = document.cookie.match(/(?:^|;\s*)hrms_mock_role=(admin|hr|employee)(?:;|$)/)
  return (match?.[1] as MockRole | undefined) ?? null
}

function writeMockRole(next: MockRole): void {
  if (typeof document === 'undefined') return
  document.cookie = `hrms_mock_role=${next}; path=/; SameSite=Lax`
}

function clearMockRole(): void {
  if (typeof document === 'undefined') return
  document.cookie = 'hrms_mock_role=; Max-Age=0; path=/; SameSite=Lax'
}

function payslipsFor(principal: Principal | null): Payslip[] {
  if (!principal) return []
  return payslips.map((slip) => ({
    ...slip,
    employeeName: principal.displayName,
    employeeNo: principal.employmentNo,
    orgName: principal.orgName,
  }))
}

export function createMockAdapter(options: { delay?: number } = {}): ApiAdapter {
  const latency = options.delay ?? 120
  let role: MockRole = 'admin'
  let currentSession: SessionBootstrap = {
    ...sessionForRole(role),
    authenticated: false,
    principal: null,
    capabilities: [],
    csrfToken: null,
    sessionExpiresAt: null,
  }
  const current = <T>(value: T): Promise<T> => wait(value, latency)

  return {
    auth: {
      permissions: async () => {
        const capabilities = new Set(currentSession.capabilities)
        const pages = PAGE_REGISTRY
          .filter((page) => page.capability === 'public' || capabilities.has(page.capability))
          .map((page) => ({
            page_code: page.name,
            path: page.path,
            menu_group: page.menu?.group,
            menu_label: page.menu?.label,
            required_capability: page.capability,
          }))
        return current({
          pages,
          menus: [],
          actions: [...capabilities],
          field_capabilities: [],
          scope_labels: [],
          auth_version: currentSession.principal?.authVersion ?? 1,
          wildcard: capabilities.has('*'),
        })
      },
      bootstrap: () => {
        const restored = readMockRole()
        if (restored) {
          role = restored
          currentSession = sessionForRole(restored)
        }
        return current(currentSession)
      },
      login: async ({ username, password }) => {
        const loginName = username.trim().toLowerCase()
        if (password !== 'Demo!2026') {
          throw new ApiError('UNAUTHENTICATED', '账号或密码不正确')
        }
        if (loginName.startsWith('mfa@') || loginName.startsWith('mfa.')) {
          return wait(
            {
              status: 'MFA_REQUIRED' as const,
              challengeId: 'mock-mfa-challenge',
              maskedDestination: '认证器应用 · ·••• 8821',
            },
            latency,
          )
        }
        role = loginName.includes('employee') || loginName.startsWith('jiahe.')
          ? 'employee'
          : loginName.includes('hr') || loginName.startsWith('hr.')
            ? 'hr'
            : 'admin'
        writeMockRole(role)
        currentSession = sessionForRole(role)
        return wait(
          {
            status: 'AUTHENTICATED' as const,
            session: currentSession,
          },
          latency,
        )
      },
      verifyTotp: async ({ code }) => {
        if (!/^\d{6}$/.test(code)) {
          throw new ApiError('VALIDATION_FAILED', '请输入 6 位动态验证码')
        }
        if (code === '000000') {
          throw new ApiError('UNAUTHENTICATED', '动态验证码不正确或已过期')
        }
        role = 'admin'
        writeMockRole('admin')
        currentSession = sessionForRole('admin')
        return current(currentSession)
      },
      logout: async () => {
        clearMockRole()
        currentSession = { ...currentSession, authenticated: false, principal: null, capabilities: [] }
        await wait(null, latency)
      },
      enrollMfa: () =>
        current({
          secretPreview: 'JBSW Y3DP EHPK 3PXP',
          otpauthUri: 'otpauth://totp/HRMS:ruotong.lin@demo.example?secret=JBSWY3DPEHPK3PXP&issuer=HRMS',
          recoveryCodes: ['7K9M-2Q4X', 'P3W8-6N1T', 'R5Y2-8C4V', 'H9B3-2M7Q', 'T6N8-4W1K', 'C2P5-9R3X'],
        }),
      confirmMfa: async (code) => {
        if (!/^\d{6}$/.test(code)) {
          throw new ApiError('VALIDATION_FAILED', '动态验证码格式不正确')
        }
        return current({ enabled: true })
      },
      demoAccounts: () => [
        { role: 'admin', label: '超级管理员', username: 'demo.admin', password: 'Demo!2026', note: '完整治理与薪酬权限' },
        { role: 'hr', label: 'HR 管理员', username: 'demo.hr', password: 'Demo!2026', note: '人员、审批、考勤与薪酬批次' },
        { role: 'employee', label: '普通员工', username: 'demo.employee', password: 'Demo!2026', note: '自助与本人数据' },
        { role: 'mfa', label: 'MFA 演示账号', username: 'mfa@demo.example', password: 'Demo!2026', note: '触发动态口令第二步' },
      ],
    },
    employees: {
      listEmployees: async (query) => {
        const filtered = employeeSeed.filter((employee) =>
          matchEmployee(employee, {
            search: query.search ?? '',
            status: query.status ?? '',
            orgId: query.orgId ?? null,
          }),
        )
        const start = (query.page - 1) * query.pageSize
        const items = filtered.slice(start, start + query.pageSize).map(
          ({ managerName: _managerName, legalEntity: _legalEntity, workLocation: _workLocation, bankAccount: _bankAccount, address: _address, personalEmail: _personalEmail, contract: _contract, assignments: _assignments, ...employee }) => {
            void _managerName
            void _legalEntity
            void _workLocation
            void _bankAccount
            void _address
            void _personalEmail
            void _contract
            void _assignments
            return employee
          },
        )
        return current({
          items,
          page: query.page,
          page_size: query.pageSize,
          total: filtered.length,
        })
      },
      /**
       * Mock-only guarded reveal. Values are synthetic and generated by the
       * adapter, never derived from the mask client-side. The HTTP adapter
       * calls a server endpoint that enforces the field profile + purpose.
       */
      revealField: async (id, field, purpose) => {
        if (!CAPABILITIES.includes('people.employee.read')) {
          throw new ApiError('FORBIDDEN', '缺少敏感字段查看能力')
        }
        if (purpose.trim().length < 3) {
          throw new ApiError('VALIDATION_FAILED', '请填写至少 3 个字的查看目的')
        }
        const employee = employeeSeed.find((item) => item.id === id)
        if (!employee) throw new ApiError('NOT_FOUND', '未找到该员工')
        const source = (employee as unknown as Record<string, MaskedFieldValue>)[field]
        if (!source) throw new ApiError('NOT_FOUND', '字段不存在或不在可查看范围')
        const clearValues: Record<string, string> = {
          idNumber: '310101199103182227',
          bankAccount: '6222 0210 0100 4821',
          address: '上海市浦东新区祖冲之路 88 号 3 号楼 502 室',
          personalEmail: 'lin.ruotong@personal.example',
          phone: '138 6621 0943',
          email: 'ruotong.lin@demo.example',
        }
        return current({
          ...source,
          display: clearValues[field] ?? source.display,
          masked: false,
        })
      },
      getEmployee: async (id) => {
        const employee = employeeSeed.find((item) => item.id === id)
        if (!employee) throw new ApiError('NOT_FOUND', '未找到该员工，或已超出你的数据范围')
        const detail: EmployeeDetail = {
          ...employee,
          timeline: [
            {
              id: 'emp-1',
              lane: 'SYSTEM',
              title: '入职与首份任职',
              detail: `${employee.orgName} · ${employee.positionName}`,
              actor: '系统',
              occurredAt: `${employee.hireDate}T00:00:00Z`,
              status: 'APPLIED',
            },
            ...employee.assignments.slice(1).map((assignment, index) => ({
              id: `emp-assignment-${assignment.id}`,
              lane: 'SYSTEM' as const,
              title: '任职变更',
              detail: `${assignment.orgName} · ${assignment.positionName}`,
              actor: '人力资源部',
              occurredAt: `${assignment.validFrom}T02:00:00Z`,
              status: index === 0 ? 'REVERSED' : null,
            })),
            {
              id: 'emp-contract',
              lane: 'SYSTEM',
              title: employee.contract.status === 'EXPIRING' ? '合同即将到期' : '合同续签生效',
              detail: `${employee.contract.contractNo} · ${employee.contract.type}`,
              actor: '系统',
              occurredAt: '2026-11-01T02:00:00Z',
              status: employee.contract.status,
            },
          ],
          fieldAccessNote:
            employee.id === 5105
              ? '这是你的本人档案，可查看全部字段；敏感字段查看会记录审计。'
              : '当前以 HR 管理员范围查看。P2 字段保持遮罩，单独查看需要 people.employee.read 绑定与填写目的。',
        }
        return current(detail)
      },
    },
    organization: {
      getOrgTree: () => current(orgTree()),
      previewOrgMove: async (nodeId, targetId) => {
        const tree = orgTree()
        const source = findOrgName(tree, nodeId)
        const target = findOrgName(tree, targetId)
        if (!source || !target) throw new ApiError('NOT_FOUND', '组织节点不存在或已过期')
        const impact = buildMoveImpact(source.name, target.name, '')
        return current({
          affectedEmployees: impact.affectedEmployees,
          affectedManagers: impact.affectedManagers,
          affectedPayrollCostCenters: impact.affectedPayrollCostCenters,
          crossEntityWarning: impact.crossEntityWarning,
        })
      },
      moveOrg: async (nodeId, targetId) => {
        const tree = orgTree()
        const source = findOrgName(tree, nodeId)
        const target = findOrgName(tree, targetId)
        if (!source || !target) throw new ApiError('NOT_FOUND', '组织节点不存在或已过期')
        if (target.children.some((child) => child.id === nodeId)) {
          throw new ApiError('VALIDATION_FAILED', '不能将组织移动到自己的下级节点')
        }
        return current({ moved: true, oldOrgUnitId: nodeId })
      },
    },
    cases: {
      listCases: async (query) => {
        const status = query.status ?? 'ALL'
        const filtered = caseDetails.filter((item) => {
          if (query.scope === 'mine' && !item.isMine) return false
          if (query.scope === 'approvals' && !item.isAwaitingMe && !item.timeline.some((event) => event.actor === '林若桐' && event.lane === 'APPLICATION')) {
            return false
          }
          if (status !== 'ALL' && item.applicationStatus !== status) return false
          return true
        })
        const start = (query.page - 1) * query.pageSize
        return current({
          items: filtered.slice(start, start + query.pageSize),
          page: query.page,
          page_size: query.pageSize,
          total: filtered.length,
        })
      },
      getCase: async (id) => {
        const detail = caseDetails.find((item) => item.id === id)
        if (!detail) throw new ApiError('NOT_FOUND', '未找到该案例，或你不在授权范围内')
        return current(detail)
      },
      decideCase: async (id, request) => current(applyCaseAction(id, request.action, request.comment)),
      retryCaseEffect: async (id) => current(applyCaseAction(id, 'RETRY_EFFECT')),
    },
    leave: {
      getLeaveBalances: () => current(leaves),
      getLeaveRequests: () => current(caseDetails.filter((item) => item.isMine && item.type === 'LEAVE_REQUEST')),
      previewLeave: async (payload) => {
        const annual = leaves[0]!
        const conflicts: LeavePreview['conflicts'] = []
        if (payload.startDate >= '2026-12-21' && payload.startDate <= '2026-12-25') {
          conflicts.push({ date: '2026-12-22', label: '全员年会 · 非工作日', severity: 'WARNING' })
        }
        if (payload.startDate === '2026-11-24') {
          conflicts.push({ date: '2026-11-24', label: '团队季度评审，2 名同事已请假', severity: 'BLOCKING' })
        }
        const start = new Date(`${payload.startDate}T00:00:00Z`)
        const end = new Date(`${payload.endDate}T00:00:00Z`)
        let workingDays = 0
        for (let cursor = new Date(start); cursor <= end; cursor.setUTCDate(cursor.getUTCDate() + 1)) {
          const day = cursor.getUTCDay()
          if (day !== 0 && day !== 6) workingDays += 1
        }
        const days = payload.halfDay ? 0.5 : workingDays
        return current({
          workingDays,
          requestedDays: days,
          availableBefore: annual.available,
          availableAfter: Math.max(annual.available - days, 0),
          unit: 'DAY',
          conflicts,
          note:
            conflicts.length > 0
              ? '存在冲突提示，提交前请与直属经理确认安排。'
              : '工作日计算已排除周末；法定节假日按租户日历扣除。',
        })
      },
      requestLeave: async (payload) => {
        if (payload.reason.trim().length < 5) {
          throw new ApiError('VALIDATION_FAILED', '请假事由至少 5 个字', {
            fieldErrors: { reason: ['请假事由至少 5 个字'] },
          })
        }
        const id = 8300 + caseDetails.length
        const result: CaseSummary = {
          id,
          caseNo: `CASE-2026-0${id}`,
          title: `年假申请 · 待计算`,
          type: 'LEAVE_REQUEST',
          applicantName: '陈嘉禾',
          applicantEmploymentNo: 'DEMO-0218',
          applicantOrgName: '数字产品部',
          appliedAt: MOCK_NOW,
          applicationStatus: 'SUBMITTED',
          effectStatus: null,
          currentNode: '直属经理审批',
          dueAt: '2026-11-26T10:00:00Z',
          isMine: true,
          isAwaitingMe: false,
        }
        caseDetails.unshift({
          ...result,
          description: payload.reason,
          facts: [
            { label: '假期类型', value: '法定年假' },
            { label: '请假日期', value: `${payload.startDate} 至 ${payload.endDate}` },
          ],
          applicant: '陈嘉禾 · DEMO-0218',
          approver: '邵清越',
          effectSummary: '审批通过后占用年假并写入假勤账本',
          effectScheduledAt: null,
          effectFailedReason: null,
          availableActions: [],
          timeline: [
            {
              id: `tl-${Date.now()}`,
              lane: 'APPLICATION',
              title: '申请人提交',
              detail: payload.reason,
              actor: '陈嘉禾',
              occurredAt: MOCK_NOW,
              status: 'SUBMITTED',
            },
          ],
        })
        return current({
          caseId: id,
          caseNo: result.caseNo,
          applicationStatus: 'SUBMITTED',
          effectStatus: null,
        })
      },
      cancelLeave: async (caseId) => {
        const target = caseDetails.find((item) => item.id === caseId)
        if (!target) throw new ApiError('NOT_FOUND', '未找到该请假申请')
        if (!['SUBMITTED', 'IN_APPROVAL'].includes(target.applicationStatus)) {
          throw new ApiError('CONFLICT', '该申请已进入不可撤回状态')
        }
        target.applicationStatus = 'WITHDRAWN'
        return current(target)
      },
      getLeaveLedger: async (leaveTypeCode) => current(ledgerFor(leaveTypeCode)),
    },
    attendance: {
      getAttendance: (month) => current(attendanceFor(month)),
      submitCorrection: async (payload) => {
        if (payload.reason.trim().length < 5) {
          throw new ApiError('VALIDATION_FAILED', '补正原因至少 5 个字')
        }
        return current({ accepted: true, requestNo: `COR-2026-${String(7100 + payload.date.length).padStart(5, '0')}` })
      },
    },
    payroll: {
      listRuns: () => current(payrollRuns),
      getRun: async (id) => {
        if (!payrollRuns.some((run) => run.id === id)) {
          throw new ApiError('NOT_FOUND', '未找到该工资批次，或你不在授权范围内')
        }
        return current(payrollDetail(id))
      },
      runAction: async (id, action) => {
        if (role === 'employee') {
          throw new ApiError('FORBIDDEN', '普通员工不能执行工资批次操作')
        }
        if (!payrollRuns.some((run) => run.id === id)) {
          throw new ApiError('NOT_FOUND', '未找到该工资批次')
        }
        const detail = payrollDetail(id)
        const transitions: Partial<Record<PayrollRunDetail['availableActions'][number], PayrollRunState>> = {
          LOCK_INPUT: 'INPUT_LOCKED',
          CALCULATE: 'CALCULATED',
          REVIEW: 'REVIEWED',
          APPROVE: 'APPROVED',
          PUBLISH: 'PUBLISHED',
        }
        const next = transitions[action]
        if (next) {
          const run = payrollRuns.find((item) => item.id === id)!
          run.state = next
          run.updatedAt = MOCK_NOW
          const detailIndex = payrollRuns.findIndex((item) => item.id === id)
          void detailIndex
          return current({ ...detail, state: next })
        }
        return current(detail)
      },
      listPayslips: async () => {
        if (currentSession.principal?.employmentNo === 'DEMO-0218') {
          return current(payslips.filter((slip) => slip.employeeNo === 'DEMO-0218'))
        }
        return current(
          payslips.map((slip) => ({
            ...slip,
            employeeName: currentSession.principal?.displayName ?? slip.employeeName,
            employeeNo: currentSession.principal?.employmentNo ?? slip.employeeNo,
            orgName: currentSession.principal?.orgName ?? slip.orgName,
          })),
        )
      },
      getPayslip: async (id) => {
        const slip = payslips.find((item) => item.id === id)
        if (!slip) throw new ApiError('NOT_FOUND', '未找到该工资条')
        if (currentSession.principal?.employmentNo === 'DEMO-0218' && slip.employeeNo !== 'DEMO-0218') {
          throw new ApiError('NOT_FOUND', '未找到该工资条')
        }
        return current({
          ...slip,
          employeeName: currentSession.principal?.employmentNo === 'DEMO-0218' ? slip.employeeName : (currentSession.principal?.displayName ?? slip.employeeName),
          employeeNo: currentSession.principal?.employmentNo ?? slip.employeeNo,
          orgName: currentSession.principal?.orgName ?? slip.orgName,
        })
      },
    },
    admin: {
      loadUserBindings: async () => [],
      getAdminSnapshot: async () => {
        if (role !== 'admin') throw new ApiError('FORBIDDEN', '需要管理员权限')
        const users = employeeSeed.slice(0, 7).map((employee, index) => ({
          id: 9000 + index,
          name: employee.name,
          email: `${employee.employeeNo.toLowerCase()}@demo.example`,
          employmentNo: employee.employeeNo,
          orgName: employee.orgName,
          roles: index === 0 ? ['HR 管理员', '审计员'] : index === 1 ? ['薪酬专员'] : ['普通员工'],
          status: (index === 6 ? 'SUSPENDED' : 'ACTIVE') as 'ACTIVE' | 'SUSPENDED' | 'LOCKED',
          mfaEnabled: index < 3,
          lastLoginAt: index === 6 ? null : '2026-11-23T06:20:00Z',
        }))
        return current({
          users: { items: users, page: 1, page_size: 10, total: 86 },
          roles,
          capabilities,
          bindings: bindings(),
          accessReview: [
            {
              id: 1,
              principalName: '许言川',
              employmentNo: 'DEMO-0115',
              roleName: '临时报表查看',
              scope: '财务与行政部',
              riskLevel: 'HIGH',
              reason: '年度预算复盘（2026-12-31 到期）',
              lastUsedAt: '2026-11-23T08:35:00Z',
              dueOn: '2026-12-31',
              status: 'PENDING',
            },
            {
              id: 2,
              principalName: '宋一澄',
              employmentNo: 'DEMO-0221',
              roleName: '普通员工',
              scope: '研发中心',
              riskLevel: 'LOW',
              reason: '90 天未使用目录查看能力',
              lastUsedAt: null,
              dueOn: '2026-11-30',
              status: 'PENDING',
            },
            {
              id: 3,
              principalName: '沈知遥',
              employmentNo: 'DEMO-0251',
              roleName: '普通员工',
              scope: '已离职 2026-10-31',
              riskLevel: 'LOW',
              reason: '离职后权限回收完成',
              lastUsedAt: '2026-10-28T08:10:00Z',
              dueOn: '2026-11-23',
              status: 'REVOKED',
            },
          ],
          audit: { items: auditEvents, page: 1, page_size: 20, total: auditEvents.length },
        })
      },
      createBinding: async (payload) => {
        if (role !== 'admin') throw new ApiError('FORBIDDEN', '需要管理员权限')
        const created: AuthorityBinding = {
          ...payload,
          id: bindings().length + 1,
          status: 'ACTIVE',
          riskLevel: 'HIGH',
        }
        return current(created)
      },
    },
    notices: {
      listNotices: async (filter = 'all') => {
        if (filter === 'unread') return current(notices.filter((notice) => !notice.acknowledged))
        if (filter === 'managed') return current(notices)
        return current(notices.filter((notice) => notice.status === 'PUBLISHED'))
      },
      getNotice: async (id) => {
        const notice = notices.find((item) => item.id === id)
        if (!notice) throw new ApiError('NOT_FOUND', '未找到该公告')
        return current(notice)
      },
      acknowledgeNotice: async (id) => {
        const notice = notices.find((item) => item.id === id)
        if (!notice) throw new ApiError('NOT_FOUND', '未找到该公告')
        notice.acknowledged = true
        notice.acknowledgementCount += 1
        return current(notice)
      },
      publishNotice: async (payload) => {
        if (!currentSession.capabilities.includes('engagement.notice.manage')) {
          throw new ApiError('FORBIDDEN', '缺少 engagement.notice.manage 能力')
        }
        const notice: Notice = {
          id: 306,
          title: payload.title,
          summary: payload.body.slice(0, 70),
          body: payload.body.split('\n').filter(Boolean),
          status: 'PUBLISHED',
          audienceLabel: payload.audienceLabel,
          publishedAt: payload.publishAt,
          retractedAt: null,
          authorName: '林若桐',
          requiresAck: payload.requiresAck,
          acknowledged: false,
          acknowledgementCount: 0,
          audienceCount: 86,
        }
        notices.unshift(notice)
        return current(notice)
      },
      retractNotice: async (id) => {
        const notice = notices.find((item) => item.id === id)
        if (!notice) throw new ApiError('NOT_FOUND', '未找到该公告')
        notice.status = 'RETRACTED'
        notice.retractedAt = MOCK_NOW
        return current(notice)
      },
    },
    dashboard: {
      getDashboard: () => current(dashboard()),
      getMetricDrilldown: (metric) => current(metricDrilldown(metric)),
    },
    reporting: {
      getReportDefinitions: () => current(reportDefinitions),
      runReport: (code) => current(reportFor(code)),
      createExport: async (payload) => {
        if (!currentSession.capabilities.includes('reporting.export')) {
          throw new ApiError('FORBIDDEN', '缺少 reporting.export 能力')
        }
        const definition = reportDefinitions.find((item) => item.code === payload.reportCode)
        if (!definition) throw new ApiError('NOT_FOUND', '未找到报表定义')
        const allowedFields = definition.fields.filter((field) => field.exportable).map((field) => field.key)
        const forbidden = payload.fields.filter((field) => !allowedFields.includes(field))
        if (forbidden.length > 0) {
          throw new ApiError('FORBIDDEN', '所选字段不在你的导出字段白名单内')
        }
        const job: ExportJob = {
          id: `EXP-${Date.now()}`,
          reportCode: payload.reportCode,
          reportName: definition.name,
          status: 'COMPLETED',
          purpose: payload.purpose,
          rowCount: definition.code === 'PAYROLL_COST' ? 86 : reportFor(definition.code).totalRows,
          createdAt: MOCK_NOW,
          expiresAt: '2026-11-24T09:12:00Z',
          downloadCount: 0,
          maxDownloads: 5,
          watermark: '导出人：林若桐 · 2026-11-23 17:12 · 仅限内部使用',
          fileName: `${definition.code}_20261123.csv`,
        }
        exportJobs.set(job.id, job)
        return current(job)
      },
      downloadExport: async (jobId) => {
        const job = exportJobs.get(jobId)
        if (!job) throw new ApiError('NOT_FOUND', '导出文件不存在或已过期')
        const lines = ['\uFEFF员工编号,姓名,组织,应发,实发,水印']
        for (const line of payrollLines) {
          lines.push(
            `${line.employeeNo},${line.employeeName},${line.orgName},${line.grossCents},${line.netCents},${job.watermark}`,
          )
        }
        return current(new Blob([lines.join('\n')], { type: 'text/csv;charset=utf-8' }))
      },
    },
    workspace: {
      get: () => current(workspace(currentSession.principal ?? PRINCIPAL)),
    },
  }
}

export function createSeededMockAdapter(): ApiAdapter {
  return createMockAdapter()
}
