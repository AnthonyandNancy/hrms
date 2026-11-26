export type PlatformMode =
  | 'dashboard'
  | 'reports-index'
  | 'reports-detail'
  | 'reports-exports'
  | 'reports-builder'
  | 'reports-subscriptions'
  | 'reports-talent'
  | 'notice-list'
  | 'notice-governance'
  | 'account'
  | 'users'
  | 'roles'
  | 'menus'
  | 'permissions'
  | 'data-scopes'
  | 'field-policies'
  | 'workflow'
  | 'self-service'
  | 'benefits'
  | 'calendars'
  | 'settings'
  | 'notifications'
  | 'audit'

export type PlatformPageConfig = {
  key: string
  title: string
  breadcrumb: string
  description: string
  mode: PlatformMode
  /** Dashboard metric drill-down used by dashboard subpages. */
  metricKey?: string
  /** Code-owned report definition used by the reports subpage. */
  reportCode?: string
  /** A deliberately visible backend gap. Never replaced with synthetic data. */
  gap?: string
  gapPath?: string
  /**
   * A truthful capability boundary where the platform relies on operational
   * tooling outside the HRMS API. Unlike `gap`, it is not an unfinished
   * endpoint and never counts as an executable gap marker.
   */
  limitation?: string
  limitationPath?: string
  /** Notice governance panel served by a real API. */
  noticePanel?: 'approval' | 'audience' | 'reads' | 'templates'
}

const DASHBOARD_DESCRIPTION =
  '指标来自 Reporting 的同一授权投影；页面只展示接口实际返回的指标、列表与可用状态。'

const REPORTS_DESCRIPTION =
  '报表目录、运行结果与导出任务全部来自 Reporting API；字段档位和可导出口径由服务端返回。'

const NOTICE_DESCRIPTION =
  '公告受众、审批决定、阅读确认、导出、模板与归档全部来自 Engagement/Workflow 真实接口；不展示虚构的阅读人或审批人。'

export const PLATFORM_PAGE_CONFIGS: PlatformPageConfig[] = [
  {
    key: 'dashboard/index',
    title: '首页看板',
    breadcrumb: '仪表盘',
    description: DASHBOARD_DESCRIPTION,
    mode: 'dashboard',
  },
  {
    key: 'dashboard/departments',
    title: '部门人数分析',
    breadcrumb: '仪表盘',
    description: '在职人数使用与下钻列表相同的 as-of 与组织范围谓词；部门明细从指标接口读取。',
    mode: 'dashboard',
    metricKey: 'headcount',
  },
  {
    key: 'dashboard/workforce',
    title: '员工构成',
    breadcrumb: '仪表盘',
    description: '展示服务端实际返回的在职与试用期指标；性别、年龄等维度接口不存在时不臆造分布。',
    mode: 'dashboard',
  },
  {
    key: 'dashboard/changes',
    title: '近期人事动态',
    breadcrumb: '仪表盘',
    description: '展示 Reporting 已提供的待办、合同到期与公告投影；生命周期事件流没有独立接口时明确标注。',
    mode: 'dashboard',
  },
  {
    key: 'dashboard/tasks',
    title: '工作提醒',
    breadcrumb: '仪表盘',
    description: '待审批与合同到期来自同一报表投影；任务状态不拆成第二套口径。',
    mode: 'dashboard',
  },
  {
    key: 'dashboard/recruitment',
    title: '招聘进展',
    breadcrumb: '仪表盘',
    description: '招聘漏斗来自 Reporting 指标接口；接口标记不可用或没有阶段行时只显示真实原因。',
    mode: 'dashboard',
    metricKey: 'recruitment_funnel',
  },
  {
    key: 'reports/index',
    title: '报表目录',
    breadcrumb: '报表中心',
    description: REPORTS_DESCRIPTION,
    mode: 'reports-index',
  },
  {
    key: 'reports/headcount',
    title: '人力资源报表',
    breadcrumb: '报表中心',
    description: REPORTS_DESCRIPTION,
    mode: 'reports-detail',
    reportCode: 'WORKFORCE_HEADCOUNT',
  },
  {
    key: 'reports/attendance',
    title: '考勤假期报表',
    breadcrumb: '报表中心',
    description: '假期负债使用 LEAVE_LIABILITY 定义和统一运行接口；考勤汇总等未发布定义不会在页面推算。',
    mode: 'reports-detail',
    reportCode: 'LEAVE_LIABILITY',
  },
  {
    key: 'reports/payroll',
    title: '薪资成本报表',
    breadcrumb: '报表中心',
    description: '薪资成本使用 PAYROLL_COST 定义和统一运行接口；字段是否可见由服务端字段档位裁剪。',
    mode: 'reports-detail',
    reportCode: 'PAYROLL_COST',
  },
  {
    key: 'reports/hiring',
    title: '招聘转化报表',
    breadcrumb: '报表中心',
    description: '招聘漏斗使用 RECRUITMENT_FUNNEL 定义和统一运行接口；阶段不可用时不伪造转化数。',
    mode: 'reports-detail',
    reportCode: 'RECRUITMENT_FUNNEL',
  },
  {
    key: 'reports/talent',
    title: '绩效培训报表',
    breadcrumb: '报表中心',
    description: '绩效周期报表与培训计划报表来自 Talent 授权投影；金额为整数分，比率按基点展示。',
    mode: 'reports-talent',
  },
  {
    key: 'reports/builder',
    title: '自定义报表',
    breadcrumb: '报表中心',
    description: '自定义定义只能从服务端批准的投影、字段和筛选白名单保存；SQL 与自由条件由后端拒绝。',
    mode: 'reports-builder',
  },
  {
    key: 'reports/subscriptions',
    title: '报表订阅',
    breadcrumb: '报表中心',
    description: '订阅只保存定义、收件人与频率；创建和每次执行都会重新校验收件人的报告读取权限。',
    mode: 'reports-subscriptions',
  },
  {
    key: 'reports/exports',
    title: '导出任务',
    breadcrumb: '报表中心',
    description: '导出历史返回 source_hash、字段白名单和审计轨迹；下载使用短效签名链接。',
    mode: 'reports-exports',
  },
  {
    key: 'notices/index',
    title: '公告列表',
    breadcrumb: '公告通知',
    description: NOTICE_DESCRIPTION,
    mode: 'notice-list',
  },
  {
    key: 'notices/approval',
    title: '公告审核',
    breadcrumb: '公告通知',
    description: '公告审批由 Workflow Case 记录；列表、通过、驳回与发布均调用真实端点。',
    mode: 'notice-governance',
    noticePanel: 'approval',
  },
  {
    key: 'notices/audience',
    title: '公告发布范围',
    breadcrumb: '公告通知',
    description: '按当前组织/角色目录实时解析受众，人数与用户 ID 不使用公告上的缓存值。',
    mode: 'notice-governance',
    noticePanel: 'audience',
  },
  {
    key: 'notices/reads',
    title: '公告阅读记录',
    breadcrumb: '公告通知',
    description: '阅读人明细来自确认台账；导出仅允许白名单字段并写入敏感字段访问审计。',
    mode: 'notice-governance',
    noticePanel: 'reads',
  },
  {
    key: 'notices/templates',
    title: '公告模板',
    breadcrumb: '公告通知',
    description: '公告模板按代码版本化发布；变量由服务端校验，历史版本不被覆盖。',
    mode: 'notice-governance',
    noticePanel: 'templates',
  },
  {
    key: 'account',
    title: '登录与账号安全',
    breadcrumb: '系统管理',
    description: '账号状态、MFA 注册与会话撤销均使用 IAM 真实接口；恢复码只在确认时返回一次。',
    mode: 'account',
  },
  {
    key: 'system/system-users',
    title: '用户管理',
    breadcrumb: '系统管理',
    description: '用户创建、状态变更与角色分配使用 IAM 管理接口；每次写入都有幂等键。',
    mode: 'users',
  },
  {
    key: 'system/system-roles',
    title: '角色管理',
    breadcrumb: '系统管理',
    description: '角色、能力包与复制由 IAM 管理接口维护；能力码仍由代码拥有。',
    mode: 'roles',
  },
  {
    key: 'system/system-menus',
    title: '菜单/页面管理',
    breadcrumb: '系统管理',
    description: '菜单覆盖只影响展示标签、排序与可见性，不改变能力判定。',
    mode: 'menus',
  },
  {
    key: 'system/system-permissions',
    title: '操作权限配置',
    breadcrumb: '系统管理',
    description: '按当前主体展示菜单、动作、字段档位与数据范围；能力码只读。',
    mode: 'permissions',
  },
  {
    key: 'system/system-data-scopes',
    title: '数据范围权限',
    breadcrumb: '系统管理',
    description: '数据范围只允许选择代码拥有的白名单模板，不接受任意谓词。',
    mode: 'data-scopes',
  },
  {
    key: 'system/system-field-policies',
    title: '字段级权限',
    breadcrumb: '系统管理',
    description: '字段策略只能收紧敏感字段档位；放宽请求由服务端拒绝。',
    mode: 'field-policies',
  },
  {
    key: 'workflow',
    title: '审批流程中心',
    breadcrumb: '系统管理',
    description: '模板与我的待办来自 Workflow API；节点和效果只做结构化摘要，不输出原始 JSON。',
    mode: 'workflow',
  },
  {
    key: 'self-service',
    title: '员工自助门户',
    breadcrumb: '系统管理',
    description: '自助投影只读取当前员工自己的档案、假期、工资条与公告。',
    mode: 'self-service',
  },
  {
    key: 'benefits',
    title: '社保公积金与福利',
    breadcrumb: '系统管理',
    description: '福利方案按生效日版本化，参保基数变更保留历史；金额为整数分，费率由服务端以定点小数返回。',
    mode: 'benefits',
  },
  {
    key: 'system/system-calendars',
    title: '节假日与工作日历',
    breadcrumb: '系统管理',
    description: '工作日历与假期类型来自 Time API；发布新版本会递增版本并写审计。',
    mode: 'calendars',
  },
  {
    key: 'system/system-settings',
    title: '字典、参数与文件',
    breadcrumb: '系统管理',
    description: '字典与参数通过 Platform 服务写入；敏感参数加密存储且只返回遮罩。',
    mode: 'settings',
  },
  {
    key: 'notifications',
    title: '消息与通知中心',
    breadcrumb: '系统管理',
    description: '投递日志、失败重试、模板与事件派发全部来自 Platform 通知接口。',
    mode: 'notifications',
  },
  {
    key: 'system/system-audit',
    title: '日志审计与运维',
    breadcrumb: '系统管理',
    description: '认证事件与访问复核使用 IAM 审计接口；备份恢复和任务监控由外部运维平台承担，HRMS API 尚未提供查询端点。',
    mode: 'audit',
    limitation: '备份恢复与任务监控尚未提供',
    limitationPath: '认证事件与访问复核已接入真实接口',
  },
]

export const PLATFORM_PAGE_KEYS = PLATFORM_PAGE_CONFIGS.map((page) => page.key)

const CONFIG_BY_KEY = new Map(PLATFORM_PAGE_CONFIGS.map((page) => [page.key, page]))

export function platformPageConfig(key: string): PlatformPageConfig | null {
  return CONFIG_BY_KEY.get(key) ?? null
}
