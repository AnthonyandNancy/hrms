export type PlatformMode =
  | 'dashboard'
  | 'reports-index'
  | 'reports-detail'
  | 'reports-exports'
  | 'reports-builder'
  | 'reports-subscriptions'
  | 'reports-talent'
  | 'notice-list'
  | 'notice-create'
  | 'notice-gap'
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
}

const DASHBOARD_DESCRIPTION =
  '指标来自 Reporting 的同一授权投影；页面只展示接口实际返回的指标、列表与可用状态。'

const REPORTS_DESCRIPTION =
  '报表目录、运行结果与导出任务全部来自 Reporting API；字段档位和可导出口径由服务端返回。'

const NOTICE_DESCRIPTION =
  '公告受众、阅读确认与撤回由 Engagement API 校验；没有接口的发布范围、阅读明细与模板不回填假数据。'

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
    description: '假期负债使用 LEAVE_LIABILITY 定义与统一授权投影；字段档位和可导出口径由服务端返回。',
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
    description: '绩效与培训明细来自 Talent 的授权投影；报表目录同时展示可复用的 Reporting 定义。',
    mode: 'reports-talent',
  },
  {
    key: 'reports/builder',
    title: '自定义报表',
    breadcrumb: '报表中心',
    description: '自定义定义只能从服务端批准的投影、字段与筛选白名单中保存，禁止 SQL 或自由条件。',
    mode: 'reports-builder',
  },
  {
    key: 'reports/subscriptions',
    title: '报表订阅',
    breadcrumb: '报表中心',
    description: '订阅仅保存定义、频率与收件人；执行前重新鉴权收件人的当前 reporting.report.read。',
    mode: 'reports-subscriptions',
  },
  {
    key: 'reports/exports',
    title: '导出任务',
    breadcrumb: '报表中心',
    description: '导出历史展示 source_hash、字段白名单与审计轨迹；下载使用短效签名链接。',
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
    key: 'notices/editor',
    title: '公告编辑',
    breadcrumb: '公告通知',
    description: '创建公告时使用真实标题、正文、受众与确认要求；创建结果由服务端返回。',
    mode: 'notice-create',
  },
  {
    key: 'notices/approval',
    title: '公告审核',
    breadcrumb: '公告通知',
    description: '当前公告接口没有独立审核状态或审批端点；仅展示真实公告状态。',
    mode: 'notice-gap',
    gap: '后端接口待补: 公告审核/驳回',
    gapPath: 'POST /v1/engagement/notices/{notice}/approve',
  },
  {
    key: 'notices/audience',
    title: '公告发布范围',
    breadcrumb: '公告通知',
    description: '公告受众已随记录返回；独立的受众预览与校验端点尚未提供。',
    mode: 'notice-gap',
    gap: '后端接口待补: 公告受众预览与校验',
    gapPath: 'GET /v1/engagement/notices/{notice}/audience',
  },
  {
    key: 'notices/reads',
    title: '公告阅读记录',
    breadcrumb: '公告通知',
    description: '展示接口实际返回的确认人数与受众数；阅读人明细接口尚未提供。',
    mode: 'notice-gap',
    gap: '后端接口待补: 公告阅读人明细与导出',
    gapPath: 'GET /v1/engagement/notices/{notice}/reads',
  },
  {
    key: 'notices/templates',
    title: '公告模板',
    breadcrumb: '公告通知',
    description: '当前仅支持直接创建公告，模板与变量目录接口尚未提供。',
    mode: 'notice-gap',
    gap: '后端接口待补: 公告模板与变量',
    gapPath: 'GET /v1/engagement/notice-templates',
  },
  {
    key: 'notices/archive',
    title: '公告归档',
    breadcrumb: '公告通知',
    description: '当前列表只返回可见已发布公告；归档检索与恢复接口尚未提供。',
    mode: 'notice-gap',
    gap: '后端接口待补: 公告归档检索/恢复',
    gapPath: 'POST /v1/engagement/notices/{notice}/archive',
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
    description: '角色与其能力包由服务端返回；当前 checkout 没有角色创建路由。',
    mode: 'roles',
    gap: '后端接口待补: POST /v1/roles',
    gapPath: 'POST /v1/roles',
  },
  {
    key: 'system/system-menus',
    title: '菜单/页面管理',
    breadcrumb: '系统管理',
    description: '菜单可见性来自当前主体的权限投影，并与能力目录交叉展示。',
    mode: 'menus',
    gap: '后端接口待补: 菜单树 CRUD 与排序',
    gapPath: 'GET/POST /v1/menus',
  },
  {
    key: 'system/system-permissions',
    title: '操作权限配置',
    breadcrumb: '系统管理',
    description: '按当前主体展示菜单、动作、字段档位与数据范围；能力码只读。',
    mode: 'permissions',
    gap: '后端接口待补: 角色能力覆盖写入与模拟',
    gapPath: 'PUT /v1/roles/{role_code}/capabilities',
  },
  {
    key: 'system/system-data-scopes',
    title: '数据范围权限',
    breadcrumb: '系统管理',
    description: '数据范围标签来自 /me/permissions 的真实绑定投影；范围配置写入接口尚未提供。',
    mode: 'data-scopes',
    gap: '后端接口待补: 数据范围模板配置与成员预览',
    gapPath: 'PUT /v1/data-scopes/{scope_code}',
  },
  {
    key: 'system/system-field-policies',
    title: '字段级权限',
    breadcrumb: '系统管理',
    description: '字段档位来自当前主体实际有效的授权绑定；字段策略写入接口尚未提供。',
    mode: 'field-policies',
    gap: '后端接口待补: P0–P3 字段策略配置与导出列约束',
    gapPath: 'PUT /v1/field-policies/{field_key}',
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
    description: '当前 checkout 没有福利、社保基数或发放台账接口，因此不展示虚构的缴费数据。',
    mode: 'benefits',
    gap: '后端接口待补: 社保公积金与福利模块',
    gapPath: 'GET /v1/benefits · GET /v1/benefits/contributions · GET /v1/benefits/ledger',
  },
  {
    key: 'system/system-calendars',
    title: '节假日与工作日历',
    breadcrumb: '系统管理',
    description: '工作日历与假期类型来自 Time API；版本、时区、工作周和节假日均为服务端事实。',
    mode: 'calendars',
    gap: '后端接口待补: 日历版本发布与调休日编辑',
    gapPath: 'POST /v1/time/calendars/{legal_entity_id}/versions',
  },
  {
    key: 'system/system-settings',
    title: '字典、参数与文件',
    breadcrumb: '系统管理',
    description: '当前页面展示权限投影中的真实字段与范围；字典、全局参数和文件密级接口尚未提供。',
    mode: 'settings',
    gap: '后端接口待补: 字典、全局参数与文件密级',
    gapPath: 'GET /v1/system/dictionaries · GET /v1/system/parameters · GET /v1/files',
  },
  {
    key: 'notifications',
    title: '消息与通知中心',
    breadcrumb: '系统管理',
    description: '我的待办与可见公告来自真实接口；投递日志、失败重试和模板接口尚未提供。',
    mode: 'notifications',
    gap: '后端接口待补: 通知投递日志与失败重试',
    gapPath: 'GET /v1/notifications/delivery-log',
  },
  {
    key: 'system/system-audit',
    title: '日志审计与运维',
    breadcrumb: '系统管理',
    description: '认证事件与访问复核使用 IAM 审计接口；备份恢复和任务监控接口尚未提供。',
    mode: 'audit',
    gap: '后端接口待补: 备份恢复与任务监控',
    gapPath: 'GET /v1/platform/operations',
  },
]

export const PLATFORM_PAGE_KEYS = PLATFORM_PAGE_CONFIGS.map((page) => page.key)

const CONFIG_BY_KEY = new Map(PLATFORM_PAGE_CONFIGS.map((page) => [page.key, page]))

export function platformPageConfig(key: string): PlatformPageConfig | null {
  return CONFIG_BY_KEY.get(key) ?? null
}

export function platformPageConfigForPath(path: string): PlatformPageConfig | null {
  return PLATFORM_PAGE_CONFIGS.find((page) => path === `/${page.key}`) ?? null
}
