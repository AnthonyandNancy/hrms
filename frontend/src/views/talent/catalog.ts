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
const TRAINING_PLAN_COLUMNS = ['plan_id', 'code', 'title', 'year', 'budget_cents', 'currency', 'status'] as const
const TRAINING_COURSE_COLUMNS = ['course_id', 'plan_id', 'code', 'title', 'instructor', 'delivery_mode', 'duration_minutes', 'cost_cents', 'status'] as const
const TRAINING_CHECKIN_COLUMNS = ['checkin_id', 'session_id', 'employment_id', 'checked_in_at', 'checked_out_at', 'study_minutes'] as const
const TRAINING_EXAM_COLUMNS = ['exam_id', 'course_id', 'code', 'title', 'version', 'passing_score', 'max_score', 'status'] as const
const TRAINING_FEEDBACK_COLUMNS = ['training_feedback_id', 'enrollment_id', 'employment_id', 'rating', 'comments', 'status'] as const
const TRAINING_RECORD_COLUMNS = ['enrollment_id', 'employment_id', 'course_code', 'course_title', 'status', 'completion_result', 'score', 'study_minutes', 'checkin_count', 'feedback_count', 'latest_exam_version'] as const
const TRAINING_REPORT_COLUMNS = ['plan_id', 'code', 'title', 'year', 'budget_cents', 'training_cost_cents', 'enrolled_count', 'completed_count', 'completion_rate_bps', 'exam_pass_count', 'study_minutes_total', 'feedback_count', 'average_feedback_rating_bps'] as const
const BOARD_COLUMNS = ['requisition_no', 'title', 'status', 'headcount', 'openings_count', 'candidate_count', 'interview_count', 'offer_count', 'hired_count', 'vacancy_count', 'fill_rate_bps'] as const
const BUDGET_COLUMNS = ['requisition_no', 'title', 'headcount', 'budget_minor', 'budget_currency', 'committed_offer_minor', 'budget_variance_minor', 'filled_headcount', 'vacancy_count'] as const
const RECRUITMENT_REPORT_COLUMNS = ['requisition_no', 'title', 'headcount', 'openings_count', 'candidate_count', 'interview_count', 'offer_count', 'accepted_offers', 'hired_count', 'fill_rate_bps', 'time_to_hire_days'] as const
const EVALUATION_COLUMNS = ['evaluation_id', 'candidate_id', 'rating', 'recommendation', 'strengths', 'concerns', 'status'] as const
const COMMUNICATION_COLUMNS = ['communication_id', 'candidate_id', 'channel', 'direction', 'subject', 'consent_basis', 'sent_at'] as const
const TALENT_POOL_COLUMNS = ['tag_id', 'candidate_id', 'tag', 'reason', 'retention_until', 'status'] as const

const PERFORMANCE_CREATES: readonly TalentFormKind[] = ['cycle-create']
const INDICATOR_CREATES: readonly TalentFormKind[] = ['indicator-create']
const GOAL_CREATES: readonly TalentFormKind[] = ['goal-create']
const FEEDBACK_CREATES: readonly TalentFormKind[] = ['feedback-create']
const CALIBRATION_CREATES: readonly TalentFormKind[] = ['calibration-create']
const RESULT_CREATES: readonly TalentFormKind[] = ['cycle-publish']
const TEMPLATE_CREATES: readonly TalentFormKind[] = ['template-create']
const REVIEW_CREATES: readonly TalentFormKind[] = ['review-create', 'review-submit', 'review-appeal']
const REQUISITION_CREATES: readonly TalentFormKind[] = ['requisition-create']
const IMPORT_CREATES: readonly TalentFormKind[] = ['candidate-import']
const OPENING_CREATES: readonly TalentFormKind[] = ['opening-publish']
const CANDIDATE_CREATES: readonly TalentFormKind[] = ['candidate-create']
const INTERVIEW_CREATES: readonly TalentFormKind[] = ['interview-schedule']
const EVALUATION_CREATES: readonly TalentFormKind[] = ['evaluation-create']
const COMMUNICATION_CREATES: readonly TalentFormKind[] = ['communication-create']
const TALENT_POOL_CREATES: readonly TalentFormKind[] = ['talent-pool-tag']
const CONVERT_CREATES: readonly TalentFormKind[] = ['candidate-convert']
const OFFER_CREATES: readonly TalentFormKind[] = ['offer-create', 'offer-accept']
const SESSION_CREATES: readonly TalentFormKind[] = ['session-create']
const ENROLLMENT_CREATES: readonly TalentFormKind[] = ['enrollment-create', 'enrollment-complete']
const PLAN_CREATES: readonly TalentFormKind[] = ['plan-create']
const COURSE_CREATES: readonly TalentFormKind[] = ['course-create']
const CHECKIN_CREATES: readonly TalentFormKind[] = ['checkin-create']
const EXAM_CREATES: readonly TalentFormKind[] = ['exam-create', 'exam-publish']
const TRAINING_FEEDBACK_CREATES: readonly TalentFormKind[] = ['training-feedback-create']

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
  board: {
    title: '漏斗口径',
    body: '看板数值只来自需求、开放岗位、面试、Offer 与候选人转换记录，不生成预测或演示行。',
    points: ['需求批准后才允许发布开放岗位', '招聘人数与已入职人数均按实时行计数'],
  },
  budget: {
    title: '预算口径',
    body: '预算与实际 Offer 成本均以整数货币最小单位表示；未配置预算时保持 null，不以 0 代替。',
    points: ['金额为整数分', '编制缺口按需求人数减去已入职人数'],
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
  trainingPlanBudget: {
    title: '计划预算',
    body: '预算与费用均以整数分提交，页面不做货币浮点换算；年度计划绑定课程与场次后进入报表口径。',
    points: ['金额字段为整数分', '计划编码在租户内唯一'],
  },
  trainingCourse: {
    title: '课程目录',
    body: '课程可关联培训计划，讲师、形式、时长与成本均由服务端保存并按实时台账返回。',
    points: ['课程编码在租户内唯一', '课程成本为整数分'],
  },
  trainingCheckin: {
    title: '签到幂等',
    body: '同一场次与同一任职只保留一条签到；重复提交返回原签到记录，不移动首次签到时间。',
    points: ['学时由签到/签退时间派生', '服务端不接收客户端学时字段'],
  },
  trainingExam: {
    title: '考试版本',
    body: '草稿可继续编辑；发布后的版本永久冻结，任何修改都会形成新的版本行。',
    points: ['已发布版本不能静默修改', '重复发布返回 EXAM_ALREADY_PUBLISHED'],
  },
  trainingFeedback: {
    title: '反馈追加',
    body: '反馈仅追加，不覆盖历史行；同一幂等键重放返回原反馈编号。',
    points: ['反馈仅追加，不覆盖历史行', '幂等重放返回 200'],
  },
  trainingRecord: {
    title: '档案口径',
    body: '档案由报名、完成、签到、考试版本与反馈实时聚合；学时由签到/签退时间派生，不额外存储。',
    points: ['学时由签到/签退时间派生', '证书未建表时不展示占位值'],
  },
  trainingReport: {
    title: '报表口径',
    body: '报表比率以基点表示（10000 = 100%），预算与成本均为整数分，所有数字来自实时聚合。',
    points: ['报表比率以基点表示', '完成率与通过率不推算缺失数据'],
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
    description: '按实时需求台账查看审批状态；审批决策继续由通用审批流程按 case_id 处理。',
    readPath: '/v1/recruitment/requisitions',
    readLabel: '待审批招聘需求',
    columns: REQUISITION_COLUMNS,
    forms: [],
    panels: [PANEL.openings],
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
    description: '按需求实时聚合渠道、面试、Offer 与入职转化进展。',
    readPath: '/v1/recruitment/board',
    readLabel: '招聘漏斗看板',
    columns: BOARD_COLUMNS,
    forms: [],
    panels: [PANEL.board],
  },
  {
    ...RECRUITMENT_REQUEST_BASE,
    key: 'recruitment/requests/budget',
    title: '招聘预算与编制',
    description: '预算、已承诺 Offer 成本与编制缺口均来自实时需求与转化聚合。',
    readPath: '/v1/recruitment/budget',
    readLabel: '招聘预算',
    columns: BUDGET_COLUMNS,
    forms: [],
    panels: [PANEL.budget],
  },
  {
    ...RECRUITMENT_REQUEST_BASE,
    key: 'recruitment/requests/reports',
    title: '招聘统计',
    description: '按需求统计开放岗位、候选人、面试、Offer、入职与填补率。',
    readPath: '/v1/recruitment/reports',
    readLabel: '招聘统计',
    columns: RECRUITMENT_REPORT_COLUMNS,
    forms: [],
    panels: [PANEL.reports],
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
    description: '按行导入候选人，逐行返回接受或拒绝原因；拒绝行不会被静默丢弃。',
    readPath: '/v1/recruitment/candidates',
    readLabel: '现有候选人',
    columns: CANDIDATE_COLUMNS,
    forms: IMPORT_CREATES,
    panels: [PANEL.candidate],
  },
  {
    ...RECRUITMENT_CANDIDATE_BASE,
    key: 'recruitment/candidates/pipeline',
    title: '招聘流程看板',
    description: '按需求实时查看候选人、面试、Offer 与入职转化阶段。',
    readPath: '/v1/recruitment/board',
    readLabel: '招聘漏斗',
    columns: BOARD_COLUMNS,
    forms: [],
    panels: [PANEL.board],
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
    description: '按候选人记录结构化评分、录用建议与评语；读取会写敏感访问审计。',
    readPath: '/v1/recruitment/candidates/{id}/evaluations',
    readLabel: '面试评价',
    columns: EVALUATION_COLUMNS,
    forms: EVALUATION_CREATES,
    panels: [PANEL.candidate],
    routeFilterKey: 'candidate_id',
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
    description: '按候选人记录渠道、方向、内容与同意基础，读取会写敏感访问审计。',
    readPath: '/v1/recruitment/candidates/{id}/communications',
    readLabel: '候选人沟通',
    columns: COMMUNICATION_COLUMNS,
    forms: COMMUNICATION_CREATES,
    panels: [PANEL.candidate],
    routeFilterKey: 'candidate_id',
  },
  {
    ...RECRUITMENT_CANDIDATE_BASE,
    key: 'recruitment/candidates/talent-pool',
    title: '人才库与标签',
    description: '按候选人维护人才库标签、原因与留存截止日；读取会写敏感访问审计。',
    readPath: '/v1/recruitment/candidates/{id}/talent-pool',
    readLabel: '人才库标签',
    columns: TALENT_POOL_COLUMNS,
    forms: TALENT_POOL_CREATES,
    panels: [PANEL.candidate],
    routeFilterKey: 'candidate_id',
  },
  {
    ...RECRUITMENT_CANDIDATE_BASE,
    key: 'recruitment/candidates/convert',
    title: '录用转入职',
    description: '将已接受 Offer 的候选人幂等转为唯一员工任职；同一候选人的新幂等键重放返回原任职。',
    readPath: '/v1/recruitment/offers',
    readLabel: 'Offer 台账',
    columns: OFFER_COLUMNS,
    forms: CONVERT_CREATES,
    panels: [PANEL.offers],
  },
]

const TRAINING_PAGES: TalentPageConfig[] = [
  {
    ...TRAINING_BASE,
    key: 'training/index',
    title: '培训计划',
    description: '维护年度培训计划与整数分预算，所有金额不经过浮点计算。',
    readPath: '/v1/training/plans',
    readLabel: '培训计划',
    columns: TRAINING_PLAN_COLUMNS,
    forms: PLAN_CREATES,
    panels: [PANEL.trainingPlanBudget],
  },
  {
    ...TRAINING_BASE,
    key: 'training/courses',
    title: '课程库',
    description: '维护课程、讲师、形式、时长与成本，并关联培训计划。',
    readPath: '/v1/training/courses',
    readLabel: '课程库',
    columns: TRAINING_COURSE_COLUMNS,
    forms: COURSE_CREATES,
    panels: [PANEL.trainingCourse],
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
    description: '按场次与任职登记签到/签退；学时只从时间证据派生。',
    readPath: '/v1/training/checkins',
    readLabel: '签到记录',
    columns: TRAINING_CHECKIN_COLUMNS,
    forms: CHECKIN_CREATES,
    panels: [PANEL.trainingCheckin],
  },
  {
    ...TRAINING_BASE,
    key: 'training/exams',
    title: '培训考试',
    description: '考试内容按版本冻结；已发布版本不可静默修改。',
    readPath: '/v1/training/exams',
    readLabel: '考试版本',
    columns: TRAINING_EXAM_COLUMNS,
    forms: EXAM_CREATES,
    panels: [PANEL.trainingExam],
  },
  {
    ...TRAINING_BASE,
    key: 'training/feedback',
    title: '培训反馈',
    description: '按报名追加满意度与效果反馈，历史反馈永不被覆盖。',
    readPath: '/v1/training/feedback',
    readLabel: '培训反馈',
    columns: TRAINING_FEEDBACK_COLUMNS,
    forms: TRAINING_FEEDBACK_CREATES,
    panels: [PANEL.trainingFeedback],
  },
  {
    ...TRAINING_BASE,
    key: 'training/records',
    title: '培训档案',
    description: '个人培训记录聚合报名、完成、签到学时、考试版本与反馈数量。',
    readPath: '/v1/training/records',
    readLabel: '个人培训记录',
    columns: TRAINING_RECORD_COLUMNS,
    forms: [],
    panels: [PANEL.trainingRecord],
  },
  {
    ...TRAINING_BASE,
    key: 'training/reports',
    title: '培训报表',
    description: '按计划聚合参加、完成、考试通过、学时与反馈；比率以基点表示。',
    readPath: '/v1/training/reports',
    readLabel: '培训计划报表',
    columns: TRAINING_REPORT_COLUMNS,
    forms: [],
    panels: [PANEL.trainingReport],
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
