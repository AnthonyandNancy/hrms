import type { TalentFormKind, TalentPageConfig } from './types'

const CYCLE_COLUMNS = ['cycle_id', 'name', 'period_from', 'period_to', 'status'] as const
const TEMPLATE_COLUMNS = ['template_id', 'code', 'name', 'weight_total', 'version', 'published_at'] as const
const REQUISITION_COLUMNS = ['requisition_id', 'requisition_no', 'title', 'status', 'headcount', 'case_id'] as const
const CANDIDATE_COLUMNS = ['candidate_id', 'display_name', 'status', 'has_email'] as const
const PROGRAM_COLUMNS = ['program_id', 'code', 'title', 'status'] as const
const OFFER_COLUMNS = ['offer_id', 'candidate_id', 'requisition_id', 'version', 'status', 'start_date'] as const
const REVIEW_COLUMNS = ['review_id', 'cycle_id', 'employment_id', 'manager_employment_id', 'status', 'self_rating', 'manager_rating'] as const
const INDICATOR_COLUMNS = ['indicator_id', 'code', 'name', 'category', 'data_source', 'active'] as const
const GOAL_COLUMNS = ['goal_id', 'cycle_id', 'employment_id', 'indicator_id', 'title', 'target_value', 'weight', 'status'] as const
const FEEDBACK_COLUMNS = ['feedback_id', 'cycle_id', 'review_id', 'from_employment_id', 'to_employment_id', 'feedback_type', 'rating', 'anonymous'] as const
const CALIBRATION_COLUMNS = ['calibration_id', 'cycle_id', 'review_id', 'previous_rating', 'calibrated_rating', 'reason', 'calibrated_by'] as const
const RESULT_COLUMNS = ['review_id', 'cycle_id', 'employment_id', 'cycle_status', 'status', 'self_rating', 'manager_rating', 'calibrated_rating'] as const
const REPORT_COLUMNS = ['cycle_id', 'name', 'status', 'review_count', 'submitted_reviews', 'draft_reviews', 'calibrated_reviews'] as const
const SESSION_COLUMNS = ['session_id', 'program_id', 'starts_on', 'ends_on', 'capacity', 'location', 'status'] as const
const ENROLLMENT_COLUMNS = ['enrollment_id', 'session_id', 'employment_id', 'status', 'completion_result'] as const

const PERFORMANCE_CREATES: readonly TalentFormKind[] = ['cycle-create']
const INDICATOR_CREATES: readonly TalentFormKind[] = ['indicator-create']
const GOAL_CREATES: readonly TalentFormKind[] = ['goal-create']
const FEEDBACK_CREATES: readonly TalentFormKind[] = ['feedback-create']
const CALIBRATION_CREATES: readonly TalentFormKind[] = ['calibration-create']
const RESULT_CREATES: readonly TalentFormKind[] = ['cycle-publish']
const TEMPLATE_CREATES: readonly TalentFormKind[] = ['template-create']
const REVIEW_CREATES: readonly TalentFormKind[] = ['review-create', 'review-submit', 'review-appeal']
const REQUISITION_CREATES: readonly TalentFormKind[] = ['requisition-create']
const OPENING_CREATES: readonly TalentFormKind[] = ['opening-publish']
const CANDIDATE_CREATES: readonly TalentFormKind[] = ['candidate-create']
const INTERVIEW_CREATES: readonly TalentFormKind[] = ['interview-schedule']
const OFFER_CREATES: readonly TalentFormKind[] = ['offer-create', 'offer-accept']
const PROGRAM_CREATES: readonly TalentFormKind[] = ['program-create']
const SESSION_CREATES: readonly TalentFormKind[] = ['session-create']
const ENROLLMENT_CREATES: readonly TalentFormKind[] = ['enrollment-create', 'enrollment-complete']

const PANEL = {
  cycles: {
    title: '周期推进',
    body: '周期列表来自实时接口，状态变更与阶段推进需在后续流程接口补齐后开放。',
    points: ['周期起止日期由后端校验', '周期状态由服务端状态机维护'],
  },
  templates: {
    title: '模板版本',
    body: '每个模板保存为不可变版本，同一编码再次发布时版本号递增。',
    points: ['权重合计必须等于 100', '服务端仍会二次校验权重'],
  },
  reviews: {
    title: '评审权限',
    body: '评分材料按员工授权范围展示，当前只展示服务端已脱敏或已授权的字段。',
    points: ['提交后评审记录不可直接改写', '申诉会创建独立审批 Case'],
  },
  indicators: {
    title: '指标治理',
    body: '指标编码在租户内唯一，停用与启停通过服务端字段维护，不使用前端缓存推断。',
    points: ['租户内指标编码唯一', '指标清单实时读取'],
  },
  goals: {
    title: '目标计划',
    body: '目标通过周期与任职引用员工事实，权重由服务端按单目标校验范围。',
    points: ['周期发布后目标冻结', '目标变更需保留服务端审计'],
  },
  feedback: {
    title: '360 反馈边界',
    body: '匿名标记由服务端保存并原样返回，页面不还原评价人身份。',
    points: ['匿名反馈不展示评价人', '周期发布后停止收集'],
  },
  calibration: {
    title: '校准约束',
    body: '校准只写入 calibrated_rating，历史值以追加记录保留，自评与主管评分永不改写。',
    points: ['校准写入独立证据表', '已发布周期禁止再次校准'],
  },
  results: {
    title: '结果发布',
    body: '发布前要求周期内所有评审均已提交；发布后评审与校准均被冻结。',
    points: ['未全部提交返回 REVIEWS_NOT_SUBMITTED', '发布后写入返回 CYCLE_PUBLISHED'],
  },
  reports: {
    title: '统计口径',
    body: '报表只统计周期、评审与校准真实行数；没有数据时保持为空，不生成推算分布。',
    points: ['提交数按 SUBMITTED 状态统计', '校准数按评审去重'],
  },
  openings: {
    title: '发布规则',
    body: '仅已批准的需求允许发布招聘岗位，发布失败时保留在表单并展示服务端业务原因。',
    points: ['同一需求与渠道重复发布幂等', '撤回与关闭接口尚未提供'],
  },
  budget: {
    title: '预算口径',
    body: '当前后端未提供预算字段或预算聚合接口，本页不展示推算数据。',
    points: ['待补预算接口', '待补编制缺口接口'],
  },
  candidate: {
    title: '隐私边界',
    body: '候选人列表只展示服务端返回的存在性标记，页面不拼接或猜测手机号与邮箱。',
    points: ['联系方式按字段权限控制', '简历下载需独立授权'],
  },
  offers: {
    title: 'Offer 版本',
    body: '同一候选人的新 Offer 形成新版本，接受后服务端会作废旧版本。',
    points: ['薪资以最小货币单位提交', '接受 Offer 会生成入职草稿'],
  },
  training: {
    title: '培训登记',
    body: '场次容量与重复报名由服务端在事务内保证，页面按服务端返回的报名编号展示。',
    points: ['重复报名不会新增第二行', '完成记录需提交考核结果'],
  },
} satisfies Record<string, { title: string; body: string; points: readonly string[] }>

const PERFORMANCE_BASE = {
  domain: 'performance' as const,
  group: '绩效考核',
}

const RECRUITMENT_REQUEST_BASE = {
  domain: 'recruitment-requests' as const,
  group: '招聘需求',
}

const RECRUITMENT_CANDIDATE_BASE = {
  domain: 'recruitment-candidates' as const,
  group: '候选人',
}

const TRAINING_BASE = {
  domain: 'training' as const,
  group: '培训管理',
}

const PERFORMANCE_PAGES: TalentPageConfig[] = [
  {
    ...PERFORMANCE_BASE,
    key: 'performance/index',
    title: '绩效周期',
    description: '查看季度与年度绩效周期，建立新周期的起止边界。',
    readPath: '/v1/performance/cycles',
    readLabel: '绩效周期',
    columns: CYCLE_COLUMNS,
    forms: PERFORMANCE_CREATES,
    panels: [PANEL.cycles],
  },
  {
    ...PERFORMANCE_BASE,
    key: 'performance/templates',
    title: '考核模板',
    description: '维护带版本号的考核模板，分项权重合计必须为 100。',
    readPath: '/v1/performance/templates',
    readLabel: '考核模板',
    columns: TEMPLATE_COLUMNS,
    forms: TEMPLATE_CREATES,
    panels: [PANEL.templates],
  },
  {
    ...PERFORMANCE_BASE,
    key: 'performance/indicators',
    title: '指标库',
    description: '维护可复用绩效指标、分类与数据来源；编码在租户内唯一。',
    readPath: '/v1/performance/indicators',
    readLabel: '绩效指标',
    columns: INDICATOR_COLUMNS,
    forms: INDICATOR_CREATES,
    panels: [PANEL.indicators],
  },
  {
    ...PERFORMANCE_BASE,
    key: 'performance/goals',
    title: '目标计划',
    description: '为周期内员工设定目标、目标值、权重与截止日期。',
    readPath: '/v1/performance/goals',
    readLabel: '绩效目标',
    columns: GOAL_COLUMNS,
    forms: GOAL_CREATES,
    panels: [PANEL.goals],
  },
  {
    ...PERFORMANCE_BASE,
    key: 'performance/reviews',
    title: '自评/主管评分',
    description: '创建评审、提交自评与主管评分；提交后记录不可直接改写。',
    readPath: '/v1/performance/reviews',
    readLabel: '绩效评审',
    columns: REVIEW_COLUMNS,
    forms: REVIEW_CREATES,
    panels: [PANEL.reviews],
  },
  {
    ...PERFORMANCE_BASE,
    key: 'performance/feedback',
    title: '360 反馈',
    description: '记录同级、主管、自评与跨团队反馈，并保存匿名标记。',
    readPath: '/v1/performance/feedback',
    readLabel: '360 反馈',
    columns: FEEDBACK_COLUMNS,
    forms: FEEDBACK_CREATES,
    panels: [PANEL.feedback],
  },
  {
    ...PERFORMANCE_BASE,
    key: 'performance/calibration',
    title: '绩效校准',
    description: '追加校准等级与理由；只写 calibrated_rating，不改原始评分。',
    readPath: '/v1/performance/calibration',
    readLabel: '校准记录',
    columns: CALIBRATION_COLUMNS,
    forms: CALIBRATION_CREATES,
    panels: [PANEL.calibration],
  },
  {
    ...PERFORMANCE_BASE,
    key: 'performance/results',
    title: '结果发布',
    description: '发布前校验全部评审已提交；发布后评审与校准冻结。',
    readPath: '/v1/performance/results',
    readLabel: '绩效结果',
    columns: RESULT_COLUMNS,
    forms: RESULT_CREATES,
    panels: [PANEL.results],
  },
  {
    ...PERFORMANCE_BASE,
    key: 'performance/appeals',
    title: '绩效申诉',
    description: '按已提交评审发起绩效申诉；重复申诉由服务端阻断。',
    readPath: '/v1/performance/reviews',
    readLabel: '绩效评审',
    columns: REVIEW_COLUMNS,
    forms: ['review-appeal'],
    panels: [PANEL.reviews],
  },
  {
    ...PERFORMANCE_BASE,
    key: 'performance/reports',
    title: '绩效报表',
    description: '按周期统计评审提交、草稿与校准覆盖；所有数字均由服务端实时聚合。',
    readPath: '/v1/performance/reports',
    readLabel: '绩效完成度',
    columns: REPORT_COLUMNS,
    forms: [],
    panels: [PANEL.reports],
  },
]

const REQUEST_PAGES: TalentPageConfig[] = [
  {
    ...RECRUITMENT_REQUEST_BASE,
    key: 'recruitment/requests/index',
    title: '招聘需求列表',
    description: '按实时台账查看招聘需求编号、岗位、状态与编制。',
    readPath: '/v1/recruitment/requisitions',
    readLabel: '招聘需求',
    columns: REQUISITION_COLUMNS,
    forms: [],
    panels: [PANEL.openings],
  },
  {
    ...RECRUITMENT_REQUEST_BASE,
    key: 'recruitment/requests/create',
    title: '招聘需求新建',
    description: '创建后自动进入审批流程，审批通过后才允许开放岗位。',
    readPath: '/v1/recruitment/requisitions',
    readLabel: '现有招聘需求',
    columns: REQUISITION_COLUMNS,
    forms: REQUISITION_CREATES,
    panels: [PANEL.openings],
  },
  {
    ...RECRUITMENT_REQUEST_BASE,
    key: 'recruitment/requests/approval',
    title: '招聘需求审批',
    description: '审批动作由通用审批流程处理，需求台账保持只读。',
    readPath: '/v1/recruitment/requisitions',
    readLabel: '待审批招聘需求',
    columns: REQUISITION_COLUMNS,
    forms: [],
    panels: [PANEL.openings],
    pending: {
      label: '招聘需求审批动作',
      plannedPath: '/v1/workflow/cases/{id}/decisions',
      detail: '本域未提供审批决策路由；请从审批流程中心按 case_id 处理，页面不伪造本地审批按钮。',
    },
  },
  {
    ...RECRUITMENT_REQUEST_BASE,
    key: 'recruitment/requests/openings',
    title: '招聘岗位发布',
    description: '对已批准需求发布招聘岗位；未批准需求会显示服务端业务原因。',
    readPath: '/v1/recruitment/requisitions',
    readLabel: '招聘需求',
    columns: REQUISITION_COLUMNS,
    forms: OPENING_CREATES,
    panels: [PANEL.openings],
  },
  {
    ...RECRUITMENT_REQUEST_BASE,
    key: 'recruitment/requests/board',
    title: '招聘进度看板',
    description: '需求人数与实际入职转化进展。',
    readPath: '/v1/recruitment/requisitions',
    readLabel: '后续招聘看板',
    columns: REQUISITION_COLUMNS,
    forms: [],
    panels: [PANEL.openings],
    pending: {
      label: '招聘进度看板',
      plannedPath: '/v1/recruitment/requests/board',
      detail: '后端仅有需求台账，渠道、阶段与转化聚合接口未提供。',
    },
  },
  {
    ...RECRUITMENT_REQUEST_BASE,
    key: 'recruitment/requests/budget',
    title: '招聘预算与编制',
    description: '预算、实际成本与编制缺口管理。',
    readPath: '/v1/recruitment/requisitions',
    readLabel: '后续预算',
    columns: REQUISITION_COLUMNS,
    forms: [],
    panels: [PANEL.budget],
    pending: {
      label: '招聘预算与编制',
      plannedPath: '/v1/recruitment/requisitions/{id}/budget',
      detail: '需求创建模型不包含预算字段，且无预算聚合或编制缺口接口。',
    },
  },
  {
    ...RECRUITMENT_REQUEST_BASE,
    key: 'recruitment/requests/reports',
    title: '招聘统计',
    description: '渠道、周期与需求达成率统计。',
    readPath: '/v1/recruitment/requisitions',
    readLabel: '后续招聘统计',
    columns: REQUISITION_COLUMNS,
    forms: [],
    panels: [PANEL.openings],
    pending: {
      label: '招聘统计',
      plannedPath: '/v1/recruitment/requisitions/reports',
      detail: '后端未定义招聘渠道、周期、达成率或导出统计接口。',
    },
  },
]

const CANDIDATE_PAGES: TalentPageConfig[] = [
  {
    ...RECRUITMENT_CANDIDATE_BASE,
    key: 'recruitment/candidates/index',
    title: '候选人列表',
    description: '候选人状态与联系方式存在性；不展示明文联系方式。',
    readPath: '/v1/recruitment/candidates',
    readLabel: '候选人',
    columns: CANDIDATE_COLUMNS,
    forms: CANDIDATE_CREATES,
    panels: [PANEL.candidate],
  },
  {
    ...RECRUITMENT_CANDIDATE_BASE,
    key: 'recruitment/candidates/by-id',
    title: '候选人档案',
    description: '从候选人列表读取后按路由参数定位档案，联系方式保持脱敏。',
    readPath: '/v1/recruitment/candidates',
    readLabel: '候选人',
    columns: CANDIDATE_COLUMNS,
    forms: [],
    panels: [PANEL.candidate],
    routeFilterKey: 'candidate_id',
  },
  {
    ...RECRUITMENT_CANDIDATE_BASE,
    key: 'recruitment/candidates/imports',
    title: '简历解析/导入',
    description: '上传、解析、重复识别与人工校验。',
    readPath: '/v1/recruitment/candidates',
    readLabel: '后续简历导入',
    columns: CANDIDATE_COLUMNS,
    forms: [],
    panels: [PANEL.candidate],
    pending: {
      label: '简历解析/导入',
      plannedPath: '/v1/recruitment/candidates/imports',
      detail: '后端未提供文件上传、解析任务或重复合并接口；仅有候选人新建接口。',
    },
  },
  {
    ...RECRUITMENT_CANDIDATE_BASE,
    key: 'recruitment/candidates/pipeline',
    title: '招聘流程看板',
    description: '按招聘阶段展示候选人流转。',
    readPath: '/v1/recruitment/candidates',
    readLabel: '后续招聘漏斗',
    columns: CANDIDATE_COLUMNS,
    forms: [],
    panels: [PANEL.candidate],
    pending: {
      label: '招聘流程看板',
      plannedPath: '/v1/recruitment/candidates/pipeline',
      detail: '候选人查询不返回阶段流转，阶段迁移接口也未提供。',
    },
  },
  {
    ...RECRUITMENT_CANDIDATE_BASE,
    key: 'recruitment/candidates/interviews',
    title: '面试安排',
    description: '为候选人安排面试轮次、时间与面试官。',
    readPath: '/v1/recruitment/candidates',
    readLabel: '候选人',
    columns: CANDIDATE_COLUMNS,
    forms: INTERVIEW_CREATES,
    panels: [PANEL.candidate],
  },
  {
    ...RECRUITMENT_CANDIDATE_BASE,
    key: 'recruitment/candidates/evaluations',
    title: '面试评价',
    description: '结构化评分与录用意见。',
    readPath: '/v1/recruitment/candidates',
    readLabel: '后续面试评价',
    columns: CANDIDATE_COLUMNS,
    forms: [],
    panels: [PANEL.candidate],
    pending: {
      label: '面试评价',
      plannedPath: '/v1/recruitment/candidates/{id}/evaluations',
      detail: '面试创建接口不写评价；结构化评价读取与提交接口均未提供。',
    },
  },
  {
    ...RECRUITMENT_CANDIDATE_BASE,
    key: 'recruitment/candidates/offers',
    title: 'Offer 管理',
    description: '创建 Offer 版本并按服务端流程审批，查看实时 Offer 台账。',
    readPath: '/v1/recruitment/offers',
    readLabel: 'Offer 台账',
    columns: OFFER_COLUMNS,
    forms: OFFER_CREATES,
    panels: [PANEL.offers],
  },
  {
    ...RECRUITMENT_CANDIDATE_BASE,
    key: 'recruitment/candidates/communications',
    title: '候选人沟通',
    description: '联系记录、沟通模板与同意审计。',
    readPath: '/v1/recruitment/candidates',
    readLabel: '后续候选人沟通',
    columns: CANDIDATE_COLUMNS,
    forms: [],
    panels: [PANEL.candidate],
    pending: {
      label: '候选人沟通',
      plannedPath: '/v1/recruitment/candidates/{id}/communications',
      detail: '后端无沟通模板、发送记录或同意管理接口。',
    },
  },
  {
    ...RECRUITMENT_CANDIDATE_BASE,
    key: 'recruitment/candidates/talent-pool',
    title: '人才库与标签',
    description: '标签、状态与留存期管理。',
    readPath: '/v1/recruitment/candidates',
    readLabel: '后续人才库',
    columns: CANDIDATE_COLUMNS,
    forms: [],
    panels: [PANEL.candidate],
    pending: {
      label: '人才库与标签',
      plannedPath: '/v1/recruitment/candidates/talent-pool',
      detail: '候选人模型未暴露标签或留存策略接口。',
    },
  },
  {
    ...RECRUITMENT_CANDIDATE_BASE,
    key: 'recruitment/candidates/convert',
    title: '录用转入职',
    description: '接受 Offer 并生成唯一入职草稿；不在此页伪造员工主档。',
    readPath: '/v1/recruitment/offers',
    readLabel: 'Offer 台账',
    columns: OFFER_COLUMNS,
    forms: ['offer-accept'],
    panels: [PANEL.offers],
    pending: {
      label: '录用转入职映射确认',
      plannedPath: '/v1/recruitment/candidates/{id}/convert',
      detail: '真实接受动作 /v1/recruitment/offers/{id}/accept 已上线并会生成入职草稿；候选人到员工主档的转换结果查询与附件交接接口尚未提供。',
    },
  },
]

const TRAINING_PAGES: TalentPageConfig[] = [
  {
    ...TRAINING_BASE,
    key: 'training/index',
    title: '培训计划',
    description: '培训计划、预算与审批。',
    readPath: '/v1/training/programs',
    readLabel: '培训项目',
    columns: PROGRAM_COLUMNS,
    forms: PROGRAM_CREATES,
    panels: [PANEL.training],
    pending: {
      label: '培训计划预算与审批',
      plannedPath: '/v1/training/plans',
      detail: '后端当前只有培训项目与场次；计划预算和审批接口未提供。',
    },
  },
  {
    ...TRAINING_BASE,
    key: 'training/courses',
    title: '课程库',
    description: '课程、讲师、形式与材料。',
    readPath: '/v1/training/programs',
    readLabel: '课程库',
    columns: PROGRAM_COLUMNS,
    forms: [],
    panels: [PANEL.training],
    pending: {
      label: '课程库管理',
      plannedPath: '/v1/training/courses',
      detail: '当前培训项目接口不等同于课程库；课程、讲师与材料接口未提供。',
    },
  },
  {
    ...TRAINING_BASE,
    key: 'training/sessions',
    title: '培训场次',
    description: '创建场次并查看实时场次台账，维护时间、容量与地点。',
    readPath: '/v1/training/sessions',
    readLabel: '培训场次',
    columns: SESSION_COLUMNS,
    forms: SESSION_CREATES,
    panels: [PANEL.training],
  },
  {
    ...TRAINING_BASE,
    key: 'training/enrollments',
    title: '培训报名',
    description: '提交报名并按服务端返回的报名编号展示，重复提交不会新增行。',
    readPath: '/v1/training/programs',
    readLabel: '培训项目',
    columns: PROGRAM_COLUMNS,
    forms: ENROLLMENT_CREATES,
    panels: [PANEL.training],
  },
  {
    ...TRAINING_BASE,
    key: 'training/checkins',
    title: '培训签到',
    description: '签到、补签与到场记录。',
    readPath: '/v1/training/programs',
    readLabel: '后续签到',
    columns: PROGRAM_COLUMNS,
    forms: [],
    panels: [PANEL.training],
    pending: {
      label: '培训签到',
      plannedPath: '/v1/training/sessions/{id}/checkins',
      detail: '后端仅支持完成登记，未提供签到名单、签到或补签接口。',
    },
  },
  {
    ...TRAINING_BASE,
    key: 'training/exams',
    title: '培训考试',
    description: '题库、考试与成绩管理。',
    readPath: '/v1/training/programs',
    readLabel: '后续培训考试',
    columns: PROGRAM_COLUMNS,
    forms: [],
    panels: [PANEL.training],
    pending: {
      label: '培训考试',
      plannedPath: '/v1/training/exams',
      detail: '后端无题库、试卷、成绩或补考接口；完成登记中的 score 不等于考试管理。',
    },
  },
  {
    ...TRAINING_BASE,
    key: 'training/feedback',
    title: '培训反馈',
    description: '满意度与效果评估问卷。',
    readPath: '/v1/training/programs',
    readLabel: '后续培训反馈',
    columns: PROGRAM_COLUMNS,
    forms: [],
    panels: [PANEL.training],
    pending: {
      label: '培训反馈',
      plannedPath: '/v1/training/feedback',
      detail: '后端无问卷、满意度或效果评估接口。',
    },
  },
  {
    ...TRAINING_BASE,
    key: 'training/records',
    title: '培训档案',
    description: '个人与部门培训记录、学时与证书。',
    readPath: '/v1/training/report',
    readLabel: '培训完成记录',
    columns: ENROLLMENT_COLUMNS,
    forms: [],
    panels: [PANEL.training],
    pending: {
      label: '培训档案证书',
      plannedPath: '/v1/training/records',
      detail: '培训报表可返回完成记录，但学时、证书与个人档案聚合接口未提供。',
    },
  },
  {
    ...TRAINING_BASE,
    key: 'training/reports',
    title: '培训报表',
    description: '参加率、完成率、考试通过率与费用。',
    readPath: '/v1/training/report',
    readLabel: '培训完成记录',
    columns: ENROLLMENT_COLUMNS,
    forms: [],
    panels: [PANEL.training],
    pending: {
      label: '培训统计聚合与费用',
      plannedPath: '/v1/training/reports',
      detail: '现有 /v1/training/report 只返回完成记录，不返回聚合率或费用。',
    },
  },
]

export const TALENT_PAGES: readonly TalentPageConfig[] = [
  ...PERFORMANCE_PAGES,
  ...REQUEST_PAGES,
  ...CANDIDATE_PAGES,
  ...TRAINING_PAGES,
]

export const TALENT_COMPONENT_KEYS = TALENT_PAGES.map((page) => page.key)

const TALENT_BY_KEY = new Map(TALENT_PAGES.map((page) => [page.key, page]))

export function talentPageConfig(key: string): TalentPageConfig | null {
  return TALENT_BY_KEY.get(key) ?? null
}
