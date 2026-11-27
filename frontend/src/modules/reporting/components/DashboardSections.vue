<script setup lang="ts">
import { computed } from 'vue'
import { formatBusinessDate } from '@/utils/format'
import {
  metricAvailabilityLabel,
  metricReasonText,
  type DashboardContractAlert,
  type DashboardNotice,
  type DashboardPendingApproval,
  type DashboardRecruitmentRow,
  type DashboardSections,
  formatMetric,
  type MetricCardMetric,
} from '../api'

const props = defineProps<{
  sections: DashboardSections
  metrics: MetricCardMetric[]
  pendingApprovals: DashboardPendingApproval[]
  contractAlerts: DashboardContractAlert[]
  recentNotices: DashboardNotice[]
  asOf: string
}>()

const headcount = computed(() => props.metrics.find((metric) => metric.code === 'headcount') ?? null)
const probation = computed(() => props.metrics.find((metric) => metric.code === 'probation') ?? null)
const pendingMetric = computed(() => props.metrics.find((metric) => metric.code === 'pending_approvals') ?? null)
const payrollMetric = computed(() => props.metrics.find((metric) => metric.code === 'payroll_cost_by_entity') ?? null)
const recruitmentMetric = computed(() => props.metrics.find((metric) => metric.code === 'recruitment_funnel') ?? null)
const payrollAmount = computed(() => {
  const metric = payrollMetric.value
  if (metric === null) return { value: '—', unit: '' }
  return formatMetric(metric)
})

const departmentTotal = computed(() => props.sections.departments.reduce((total, row) => total + row.count, 0))
const maxDepartment = computed(() => Math.max(1, ...props.sections.departments.map((row) => row.count)))
const workforceTotal = computed(() => props.sections.workforce.reduce((total, row) => total + row.count, 0))
const maxWorkforce = computed(() => Math.max(1, ...props.sections.workforce.map((row) => row.count)))
const recruitmentRows = computed<DashboardRecruitmentRow[]>(() => props.sections.recruitment)
const maxRecruitment = computed(() => Math.max(1, ...recruitmentRows.value.map((row) => row.count)))

function metricState(metric: MetricCardMetric | null): string {
  if (metric === null) return '指标未返回'
  return metricAvailabilityLabel(metric.status)
}

function metricReason(metric: MetricCardMetric | null): string {
  if (metric === null) return '仪表盘接口未返回该指标。'
  return metricReasonText(metric)
}

function alertSummary(alert: DashboardContractAlert): string {
  return `${alert.orgName || '未返回组织'} · ${alert.employeeNo || '未返回编号'}`
}
</script>

<template>
  <div class="dashboard-sections" data-testid="dashboard-sections">
    <section id="dashboard-departments" class="panel dashboard-section" data-testid="dashboard-section-departments">
      <div class="panel__header">
        <div>
          <h2 class="panel__title">部门人数分析</h2>
          <p class="panel__subtitle">按服务端返回的授权员工行聚合；不另算部门分母，不补造组织。</p>
        </div>
        <span class="plain-chip">截至 {{ formatBusinessDate(asOf) }}</span>
      </div>

      <div v-if="headcount?.status === 'AVAILABLE' && sections.departments.length > 0" class="panel__body">
        <div class="dashboard-section__summary">
          <span>在职合计 <strong class="tabular">{{ departmentTotal }}</strong> 人</span>
          <span>覆盖 {{ sections.departments.length }} 个组织</span>
        </div>
        <ul class="dashboard-bar-list" aria-label="部门人数明细">
          <li v-for="row in sections.departments" :key="row.label" class="dashboard-bar-row">
            <span class="dashboard-bar-row__label" :title="row.label">{{ row.label }}</span>
            <span class="dashboard-bar-row__track">
              <span class="dashboard-bar-row__fill" :style="{ width: `${Math.max(4, (row.count / maxDepartment) * 100)}%` }" />
            </span>
            <span class="dashboard-bar-row__value tabular">{{ row.count }} 人</span>
          </li>
        </ul>
      </div>
      <div v-else-if="headcount?.status === 'AVAILABLE'" class="panel__body">
        <p class="empty-inline">当前授权范围没有可展示的部门人数。</p>
      </div>
      <div v-else class="panel__body dashboard-unavailable" data-testid="dashboard-departments-unavailable">
        <p><strong>{{ metricState(headcount) }}</strong></p>
        <p class="small muted">{{ metricReason(headcount) }}</p>
      </div>
    </section>

    <section id="dashboard-workforce" class="panel dashboard-section" data-testid="dashboard-section-workforce">
      <div class="panel__header">
        <div>
          <h2 class="panel__title">员工构成</h2>
          <p class="panel__subtitle">使用同一授权员工行的在职状态构成；接口未提供的维度不会推算。</p>
        </div>
        <span class="plain-chip">{{ metricState(headcount) }}</span>
      </div>

      <div v-if="headcount?.status === 'AVAILABLE' && sections.workforce.length > 0" class="panel__body">
        <div class="dashboard-section__summary">
          <span>在职合计 <strong class="tabular">{{ workforceTotal }}</strong> 人</span>
          <span>试用期 <strong class="tabular">{{ probation?.status === 'AVAILABLE' ? probation.value : '—' }}</strong> 人</span>
        </div>
        <ul class="dashboard-bar-list" aria-label="员工状态构成">
          <li v-for="row in sections.workforce" :key="row.key" class="dashboard-bar-row">
            <span class="dashboard-bar-row__label">{{ row.label }}</span>
            <span class="dashboard-bar-row__track">
              <span class="dashboard-bar-row__fill dashboard-bar-row__fill--neutral" :style="{ width: `${Math.max(4, (row.count / maxWorkforce) * 100)}%` }" />
            </span>
            <span class="dashboard-bar-row__value tabular">{{ row.count }} 人</span>
          </li>
        </ul>
        <p v-if="probation?.status !== 'AVAILABLE'" class="small muted dashboard-section__note">
          试用期指标{{ probation ? `：${metricReason(probation)}` : '未返回。' }}
        </p>
      </div>
      <div v-else-if="headcount?.status === 'AVAILABLE'" class="panel__body">
        <p class="empty-inline">当前授权范围没有可展示的员工状态构成。</p>
      </div>
      <div v-else class="panel__body dashboard-unavailable" data-testid="dashboard-workforce-unavailable">
        <p><strong>{{ metricState(headcount) }}</strong></p>
        <p class="small muted">{{ metricReason(headcount) }}</p>
      </div>
    </section>

    <section id="dashboard-changes" class="panel dashboard-section" data-testid="dashboard-section-changes">
      <div class="panel__header">
        <div>
          <h2 class="panel__title">近期动态</h2>
          <p class="panel__subtitle">合同到期与最近公告来自同一次仪表盘响应；没有事件流时不推断。</p>
        </div>
        <RouterLink to="/notices" class="small">公告中心</RouterLink>
      </div>
      <div class="panel__body dashboard-section__stack">
        <div>
          <h3 class="dashboard-section__subtitle">合同到期提醒</h3>
          <ul v-if="contractAlerts.length > 0" class="dashboard-event-list">
            <li v-for="alert in contractAlerts" :key="`${alert.employmentId}-${alert.endDate}`">
              <div>
                <p class="dashboard-event-list__title">{{ alert.employeeName || '未返回姓名' }}</p>
                <p class="small muted">{{ alertSummary(alert) }}</p>
              </div>
              <span class="plain-chip">{{ alert.daysRemaining }} 天后到期</span>
            </li>
          </ul>
          <p v-else class="empty-inline">当前没有合同到期提醒。</p>
        </div>
        <div>
          <h3 class="dashboard-section__subtitle">最近公告</h3>
          <ul v-if="recentNotices.length > 0" class="dashboard-event-list">
            <li v-for="notice in recentNotices" :key="notice.id">
              <div>
                <p class="dashboard-event-list__title">{{ notice.title }}</p>
                <p class="small muted">{{ notice.authorName || '系统发布' }}</p>
              </div>
              <span v-if="notice.requiresAck && !notice.acknowledged" class="plain-chip">待确认</span>
            </li>
          </ul>
          <p v-else class="empty-inline">当前没有可见公告。</p>
        </div>
      </div>
    </section>

    <section id="dashboard-tasks" class="panel dashboard-section" data-testid="dashboard-section-tasks">
      <div class="panel__header">
        <div>
          <h2 class="panel__title">工作提醒</h2>
          <p class="panel__subtitle">待办与指标卡使用同一截至日期和授权范围。</p>
        </div>
        <RouterLink to="/workflow" class="small">流程中心</RouterLink>
      </div>
      <div v-if="pendingMetric?.status === 'AVAILABLE' && pendingApprovals.length > 0" class="panel__body">
        <ul class="dashboard-event-list">
          <li v-for="item in pendingApprovals" :key="item.id">
            <div>
              <p class="dashboard-event-list__title">{{ item.title || '未返回审批事项' }}</p>
              <p class="small muted">{{ item.caseNo }} · 已等待 {{ item.ageDays }} 天</p>
            </div>
            <span class="plain-chip">{{ item.statusLabel }}</span>
          </li>
        </ul>
      </div>
      <div v-else-if="pendingMetric?.status === 'AVAILABLE'" class="panel__body">
        <p class="empty-inline">当前没有待审批任务。</p>
      </div>
      <div v-else class="panel__body dashboard-unavailable" data-testid="dashboard-tasks-unavailable">
        <p><strong>{{ metricState(pendingMetric) }}</strong></p>
        <p class="small muted">{{ metricReason(pendingMetric) }}</p>
      </div>
    </section>

    <section id="dashboard-recruitment" class="panel dashboard-section" data-testid="dashboard-section-recruitment">
      <div class="panel__header">
        <div>
          <h2 class="panel__title">招聘进展</h2>
          <p class="panel__subtitle">招聘阶段人数来自招聘模块发布的授权投影；未配置时不显示推断值。</p>
        </div>
        <span class="plain-chip">{{ metricState(recruitmentMetric) }}</span>
      </div>

      <div v-if="recruitmentMetric?.status === 'AVAILABLE' && recruitmentRows.length > 0" class="panel__body">
        <ul class="dashboard-bar-list" aria-label="招聘阶段人数">
          <li v-for="row in recruitmentRows" :key="row.stage" class="dashboard-bar-row">
            <span class="dashboard-bar-row__label">{{ row.label }}</span>
            <span class="dashboard-bar-row__track">
              <span class="dashboard-bar-row__fill dashboard-bar-row__fill--recruitment" :style="{ width: `${Math.max(4, (row.count / maxRecruitment) * 100)}%` }" />
            </span>
            <span class="dashboard-bar-row__value tabular">{{ row.count }} 人</span>
          </li>
        </ul>
      </div>
      <div v-else-if="recruitmentMetric?.status === 'AVAILABLE'" class="panel__body">
        <p class="empty-inline">当前没有招聘阶段人数。</p>
      </div>
      <div v-else class="panel__body dashboard-unavailable" data-testid="dashboard-recruitment-unavailable">
        <p><strong>{{ metricState(recruitmentMetric) }}</strong></p>
        <p class="small muted">{{ metricReason(recruitmentMetric) }}</p>
      </div>
    </section>

    <section class="panel dashboard-section" data-testid="dashboard-section-payroll">
      <div class="panel__header">
        <div>
          <h2 class="panel__title">薪酬成本</h2>
          <p class="panel__subtitle">只统计已发布工资批次的雇主成本，金额按元显示。</p>
        </div>
        <span class="plain-chip">{{ metricState(payrollMetric) }}</span>
      </div>
      <div v-if="payrollMetric?.status === 'AVAILABLE'" class="panel__body dashboard-section__summary">
        <span>雇主成本</span>
        <strong class="tabular">{{ payrollAmount.value }} {{ payrollAmount.unit }}</strong>
        <RouterLink to="/payroll" class="small">进入薪资核算</RouterLink>
      </div>
      <div v-else class="panel__body dashboard-unavailable" data-testid="dashboard-payroll-unavailable">
        <p><strong>{{ metricState(payrollMetric) }}</strong></p>
        <p class="small muted">{{ metricReason(payrollMetric) }}</p>
      </div>
    </section>
  </div>
</template>

<style scoped>
.dashboard-sections {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(380px, 1fr));
  gap: 16px;
}

.dashboard-section {
  min-width: 0;
}

.dashboard-section__summary {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 8px 20px;
  color: var(--color-ink-secondary);
  font-size: 12px;
}

.dashboard-section__summary strong {
  color: var(--color-ink);
  font-size: 18px;
}

.dashboard-section__stack {
  display: grid;
  gap: 18px;
}

.dashboard-section__subtitle {
  margin: 0 0 8px;
  color: var(--color-ink-secondary);
  font-size: 12px;
  font-weight: 650;
}

.dashboard-section__note {
  margin-top: 10px;
}

.dashboard-bar-list,
.dashboard-event-list {
  display: grid;
  gap: 8px;
  margin: 12px 0 0;
  padding: 0;
  list-style: none;
}

.dashboard-bar-row {
  display: grid;
  grid-template-columns: minmax(96px, 1.2fr) minmax(80px, 2fr) auto;
  align-items: center;
  gap: 10px;
  font-size: 12px;
}

.dashboard-bar-row__label {
  overflow: hidden;
  color: var(--color-ink-secondary);
  text-overflow: ellipsis;
  white-space: nowrap;
}

.dashboard-bar-row__track {
  overflow: hidden;
  height: 8px;
  background: #edf1ef;
  border-radius: 999px;
}

.dashboard-bar-row__fill {
  display: block;
  height: 100%;
  background: #2f7d67;
  border-radius: inherit;
}

.dashboard-bar-row__fill--neutral {
  background: #6b7f97;
}

.dashboard-bar-row__fill--recruitment {
  background: #9a6b2f;
}

.dashboard-bar-row__value {
  color: var(--color-ink);
  font-size: 12px;
  font-weight: 650;
  text-align: right;
}

.dashboard-event-list li {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  padding: 8px 0;
  border-bottom: 1px solid #edf1ef;
}

.dashboard-event-list__title {
  margin: 0;
  font-size: 12px;
  font-weight: 600;
}

.dashboard-unavailable p {
  margin: 0;
  font-size: 12px;
  line-height: 1.6;
}

.dashboard-unavailable p + p {
  margin-top: 4px;
}

@media (max-width: 720px) {
  .dashboard-sections {
    grid-template-columns: minmax(0, 1fr);
  }

  .dashboard-bar-row {
    grid-template-columns: minmax(80px, 1fr) minmax(60px, 1.6fr) auto;
  }
}
</style>
