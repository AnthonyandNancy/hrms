<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { RouterLink, useRoute } from 'vue-router'
import PageShell from '@/shared/ui/PageShell.vue'
import { useSessionStore } from '@/stores/session'
import { formatBusinessDate, formatDateTimeInTimezone } from '@/utils/format'
import {
  decideWorkflowCase,
  getWorkflowCase,
  getWorkflowTimeline,
  isForbidden,
  platformErrorCopy,
  type WorkflowCaseSummary,
  type WorkflowDecisionAction,
  type WorkflowTimeline,
  type WorkflowTimelineItem,
} from '../../api'
import {
  caseTypeLabel,
  decisionActionLabel,
  effectStatusLabel,
  effectTypeLabel,
  subjectTypeLabel,
  workflowEventLabel,
  workflowStatusLabel,
  workflowStatusTone,
} from '../../labels'
import { nextIdempotencyKey } from '../../platformRead'

const props = defineProps<{ caseId?: string | number }>()

const route = useRoute()
const session = useSessionStore()
const canRead = computed(() => session.can('workflow.case.read'))
const canDecide = computed(() => session.can('workflow.case.decide'))

const resolvedCaseId = computed<number | null>(() => {
  const raw = props.caseId ?? route.params.id
  const value = Array.isArray(raw) ? raw[0] : raw
  const parsed = Number(value)
  return Number.isSafeInteger(parsed) && parsed > 0 ? parsed : null
})

const loading = ref(true)
const loadError = ref<unknown>(null)
const detail = ref<WorkflowCaseSummary | null>(null)
const timeline = ref<WorkflowTimeline | null>(null)
const partial = ref(false)
const partialReason = ref('')
const actionError = ref('')
const actionMessage = ref('')
const deciding = ref<WorkflowDecisionAction | null>(null)
const comment = ref('')

const OPEN_STATUSES = new Set(['DRAFT', 'SUBMITTED', 'IN_REVIEW'])
const decided = computed(() =>
  detail.value !== null && !OPEN_STATUSES.has(detail.value.applicationStatus),
)
const awaitingMe = computed(() => detail.value?.isAwaitingMe === true && !decided.value)
const readOnlyReason = computed(() => {
  if (detail.value === null) return ''
  if (decided.value) return '该案例已有最终结果，审批链与时间线仅供查阅。'
  if (!canDecide.value) return '你可以查看该案例，但没有审批决策权限。'
  if (!awaitingMe.value) return '当前案例没有指派给你的待办，仅供查阅。'
  return ''
})

const hasTimeline = computed(() => (timeline.value?.items.length ?? 0) > 0)
const isEmpty = computed(() => !loading.value && loadError.value === null && detail.value === null)

const knownSummaryKeys = new Set([
  'title',
  'reason',
  'destination',
  'purpose',
  'from_date',
  'to_date',
  'leave_type_code',
  'minutes',
  'half_day',
  'notice_id',
  'audience_count',
  'employment_id',
])

const payloadSummary = computed(() => {
  const payload = detail.value === null ? undefined : (detail.value as WorkflowCaseSummary & { payload?: unknown }).payload
  if (payload === null || typeof payload !== 'object' || Array.isArray(payload)) return []
  return Object.entries(payload as Record<string, unknown>)
    .filter(([key]) => knownSummaryKeys.has(key))
    .map(([key, value]) => ({ label: payloadLabel(key), value: payloadValue(value) }))
})

function payloadLabel(key: string): string {
  const labels: Record<string, string> = {
    title: '申请主题',
    reason: '申请原因',
    destination: '目的地',
    purpose: '出差事由',
    from_date: '开始日期',
    to_date: '结束日期',
    leave_type_code: '假期类型',
    minutes: '申请时长',
    half_day: '是否半天',
    notice_id: '公告编号',
    audience_count: '受众数量',
    employment_id: '员工任职编号',
  }
  return labels[key] ?? '业务摘要'
}

function payloadValue(value: unknown): string {
  if (value === null || value === undefined || value === '') return '—'
  if (typeof value === 'boolean') return value ? '是' : '否'
  if (typeof value === 'string') {
    if (/^\d{4}-\d{2}-\d{2}$/.test(value)) return formatBusinessDate(value)
    return value
  }
  if (typeof value === 'number') return String(value)
  return '结构化摘要'
}

function statusTone(code: string): string {
  return `plain-chip--${workflowStatusTone(code)}`
}

function timelineTitle(item: WorkflowTimelineItem): string {
  if (item.kind === 'DECISION') return '审批决定'
  if (item.kind === 'EFFECT') return effectTypeLabel(item.effectType ?? '')
  return workflowEventLabel(item.eventType ?? '')
}

function timelineDetail(item: WorkflowTimelineItem): string {
  if (item.kind === 'DECISION') {
    const action = decisionActionLabel(item.action ?? '')
    return item.comment === null ? action : `${action}：${item.comment}`
  }
  if (item.kind === 'EFFECT') {
    return `生效状态：${effectStatusLabel(item.effectStatus ?? '')}`
  }
  return '流程状态已更新。'
}

function actorText(userId: number | null): string {
  return userId === null ? '系统' : `用户 #${userId}`
}

async function loadDetail(): Promise<void> {
  if (resolvedCaseId.value === null) {
    loading.value = false
    detail.value = null
    loadError.value = null
    return
  }

  loading.value = true
  loadError.value = null
  partial.value = false
  partialReason.value = ''
  actionError.value = ''
  actionMessage.value = ''

  try {
    detail.value = await getWorkflowCase(resolvedCaseId.value)
  } catch (error) {
    detail.value = null
    loadError.value = error
    loading.value = false
    return
  }

  try {
    timeline.value = await getWorkflowTimeline(resolvedCaseId.value)
  } catch (error) {
    timeline.value = null
    partial.value = true
    partialReason.value = isForbidden(error)
      ? '审批链与时间线不在当前数据范围内，摘要仍可查看。'
      : `审批链与时间线加载失败：${platformErrorCopy(error)}`
  } finally {
    loading.value = false
  }
}

async function submitDecision(action: WorkflowDecisionAction): Promise<void> {
  if (resolvedCaseId.value === null) return
  const actionLabel = decisionActionLabel(action)
  if (comment.value.trim() === '') {
    actionError.value = '请填写审批意见后再提交决定。'
    return
  }

  deciding.value = action
  actionError.value = ''
  actionMessage.value = ''
  try {
    const key = nextIdempotencyKey(`workflow-${resolvedCaseId.value}-${action.toLowerCase()}`)
    await decideWorkflowCase(resolvedCaseId.value, action, comment.value.trim(), key)
    actionMessage.value = `已记录${actionLabel}决定，正在刷新案例状态。`
    ElMessage.success(actionMessage.value)
    await loadDetail()
  } catch (error) {
    actionError.value = platformErrorCopy(error)
    ElMessage.error(actionError.value)
  } finally {
    deciding.value = null
  }
}

function retry(): void {
  void loadDetail()
}

onMounted(() => {
  if (!canRead.value) {
    loading.value = false
    return
  }
  void loadDetail()
})
</script>

<template>
  <PageShell
    data-testid="workflow-case-detail-page"
    title="审批案例详情"
    description="查看业务摘要、审批链与时间线；只有当前待办指派人在具备决策能力时才能提交决定。"
  >
    <template #actions>
      <RouterLink to="/workflow" class="workspace-link" data-testid="workflow-back">返回流程中心</RouterLink>
    </template>

    <section v-if="!canRead" class="panel" data-testid="workflow-detail-forbidden">
      <div class="panel__body">
        <h2 class="panel__title">无权查看该审批案例</h2>
        <p class="muted">当前账号没有审批流程查看权限。请联系系统管理员确认授权范围。</p>
      </div>
    </section>

    <section v-else-if="loading" class="panel" data-testid="workflow-detail-loading">
      <div class="panel__body">
        <el-skeleton :rows="6" animated />
        <span class="visually-hidden">正在加载审批案例详情</span>
      </div>
    </section>

    <section
      v-else-if="loadError !== null && isForbidden(loadError)"
      class="panel"
      data-testid="workflow-detail-forbidden"
    >
      <div class="panel__body">
        <h2 class="panel__title">案例不可查看</h2>
        <p class="muted">该案例可能不在你的数据范围内，或已被撤回。请联系系统管理员确认。</p>
      </div>
    </section>

    <section v-else-if="loadError !== null" class="panel" data-testid="workflow-detail-error">
      <div class="panel__body">
        <h2 class="panel__title">审批案例加载失败</h2>
        <p class="muted">{{ platformErrorCopy(loadError) }}</p>
        <el-button type="primary" plain @click="retry">重试</el-button>
      </div>
    </section>

    <section v-else-if="isEmpty" class="panel" data-testid="workflow-detail-empty">
      <div class="panel__body">
        <h2 class="panel__title">未找到审批案例</h2>
        <p class="muted">请从流程中心列表进入案例，或确认链接中的案例编号是否正确。</p>
      </div>
    </section>

    <template v-else-if="detail !== null">
      <p v-if="partial" class="inline-warning" data-testid="workflow-detail-partial">
        {{ partialReason }}
      </p>

      <p v-if="actionMessage" class="inline-success" data-testid="workflow-action-message">{{ actionMessage }}</p>
      <p v-if="actionError" class="inline-error" role="alert" data-testid="workflow-action-error">{{ actionError }}</p>

      <section class="panel" data-testid="workflow-case-summary">
        <div class="panel__header">
          <div>
            <h2 class="panel__title">{{ detail.title }}</h2>
            <p class="panel__subtitle mono">{{ detail.caseNo }}</p>
          </div>
          <div class="detail-chips">
            <span class="plain-chip" :class="statusTone(detail.applicationStatus)">
              {{ workflowStatusLabel(detail.applicationStatus) }}
            </span>
            <span class="plain-chip plain-chip--info">{{ effectStatusLabel(detail.effectStatus) }}</span>
          </div>
        </div>
        <div class="panel__body">
          <el-descriptions :column="2" border>
            <el-descriptions-item label="事项类型">{{ caseTypeLabel(detail.type) }}</el-descriptions-item>
            <el-descriptions-item label="业务对象">
              {{ subjectTypeLabel(detail.subjectType) }}
              <template v-if="detail.subjectId !== null"> #{{ detail.subjectId }}</template>
            </el-descriptions-item>
            <el-descriptions-item label="发起人">用户 #{{ detail.requesterUserId }}</el-descriptions-item>
            <el-descriptions-item label="业务日期">{{ formatBusinessDate(detail.businessDate) }}</el-descriptions-item>
            <el-descriptions-item label="提交时间">
              {{ formatDateTimeInTimezone(detail.submittedAt) }}
            </el-descriptions-item>
            <el-descriptions-item label="结束时间">
              {{ formatDateTimeInTimezone(detail.closedAt) }}
            </el-descriptions-item>
            <el-descriptions-item label="当前环节">
              {{ detail.currentNodeKey === null ? '已结束' : '审批处理中' }}
            </el-descriptions-item>
            <el-descriptions-item label="流程模板">
              第 {{ detail.templateVersion }} 版
            </el-descriptions-item>
          </el-descriptions>

          <el-descriptions v-if="payloadSummary.length > 0" :column="2" border class="detail-payload">
            <el-descriptions-item v-for="item in payloadSummary" :key="item.label" :label="item.label">
              {{ item.value }}
            </el-descriptions-item>
          </el-descriptions>
        </div>
      </section>

      <section v-if="readOnlyReason !== ''" class="panel" data-testid="workflow-readonly">
        <div class="panel__body">
          <h2 class="panel__title">只读查看</h2>
          <p class="muted">{{ readOnlyReason }}</p>
        </div>
      </section>

      <section v-if="awaitingMe" class="panel" data-testid="workflow-decision-actions">
        <div class="panel__header">
          <div>
            <h2 class="panel__title">当前待办决定</h2>
            <p class="panel__subtitle">决定会写入不可变审批记录；提交前请填写审批意见。</p>
          </div>
        </div>
        <div class="panel__body decision-body">
          <p v-if="!canDecide" class="muted">当前账号缺少审批决策能力，仅可查看。</p>
          <template v-else>
            <label class="decision-comment">
              <span>审批意见 *</span>
              <el-input
                v-model="comment"
                data-testid="workflow-decision-comment"
                type="textarea"
                :rows="3"
                maxlength="1000"
                show-word-limit
                placeholder="请说明同意、驳回或退回补充的依据"
              />
            </label>
            <div class="decision-buttons">
              <el-button
                type="primary"
                :loading="deciding === 'APPROVE'"
                data-testid="workflow-decision-approve"
                @click="submitDecision('APPROVE')"
              >
                同意
              </el-button>
              <el-button
                type="warning"
                plain
                :loading="deciding === 'RETURN'"
                data-testid="workflow-decision-return"
                @click="submitDecision('RETURN')"
              >
                退回补充
              </el-button>
              <el-button
                type="danger"
                plain
                :loading="deciding === 'REJECT'"
                data-testid="workflow-decision-reject"
                @click="submitDecision('REJECT')"
              >
                驳回
              </el-button>
            </div>
          </template>
        </div>
      </section>

      <section class="panel" data-testid="workflow-timeline-panel">
        <div class="panel__header">
          <div>
            <h2 class="panel__title">审批链与时间线</h2>
            <p class="panel__subtitle">流程事件、审批决定与生效处理按发生时间排列。</p>
          </div>
        </div>
        <div class="panel__body">
          <ol v-if="hasTimeline" class="workflow-timeline" data-testid="workflow-timeline">
            <li v-for="(item, index) in timeline?.items ?? []" :key="`${item.kind}-${index}`" class="workflow-timeline__item">
              <span class="workflow-timeline__marker" aria-hidden="true" />
              <div class="workflow-timeline__content">
                <div class="workflow-timeline__heading">
                  <span class="plain-chip plain-chip--info">{{ item.kind === 'DECISION' ? '审批决定' : item.kind === 'EFFECT' ? '生效处理' : '流程事件' }}</span>
                  <strong>{{ timelineTitle(item) }}</strong>
                  <time :datetime="item.occurredAt ?? undefined">
                    {{ formatDateTimeInTimezone(item.occurredAt) }}
                  </time>
                </div>
                <p class="workflow-timeline__detail">{{ timelineDetail(item) }}</p>
                <p class="workflow-timeline__actor">操作人：{{ actorText(item.actorUserId) }}</p>
              </div>
            </li>
          </ol>
          <div v-else class="empty-inline" data-testid="workflow-timeline-empty">
            暂无流程时间线记录。
          </div>
        </div>
      </section>
    </template>
  </PageShell>
</template>

<style scoped>
.workspace-link {
  color: var(--color-brand);
  font-size: 12px;
  text-decoration: none;
}
.workspace-link:hover,
.workspace-link:focus-visible {
  text-decoration: underline;
}
.detail-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}
.detail-payload {
  margin-top: 14px;
}
.decision-body {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.decision-comment {
  display: flex;
  flex-direction: column;
  gap: 6px;
  font-size: 12px;
  font-weight: 600;
}
.decision-buttons {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
.inline-warning {
  padding: 9px 12px;
  color: #8a5a00;
  font-size: 12px;
  background: #fff6e5;
  border: 1px solid #ead49f;
  border-radius: 7px;
}
.workflow-timeline {
  display: flex;
  flex-direction: column;
  gap: 0;
  padding: 0;
  margin: 0;
  list-style: none;
}
.workflow-timeline__item {
  position: relative;
  display: flex;
  gap: 12px;
  padding-bottom: 18px;
}
.workflow-timeline__item::before {
  position: absolute;
  top: 14px;
  bottom: 0;
  left: 5px;
  width: 1px;
  content: '';
  background: var(--color-line);
}
.workflow-timeline__item:last-child::before {
  display: none;
}
.workflow-timeline__marker {
  z-index: 1;
  flex: 0 0 auto;
  width: 11px;
  height: 11px;
  margin-top: 5px;
  background: var(--color-brand);
  border: 2px solid #fff;
  border-radius: 999px;
  box-shadow: 0 0 0 1px var(--color-brand);
}
.workflow-timeline__content {
  min-width: 0;
}
.workflow-timeline__heading {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  font-size: 13px;
}
.workflow-timeline__heading time {
  color: var(--color-ink-muted);
  font-size: 11px;
}
.workflow-timeline__detail {
  margin: 5px 0 2px;
  color: var(--color-ink-secondary);
  font-size: 12px;
  line-height: 1.6;
}
.workflow-timeline__actor {
  margin: 0;
  color: var(--color-ink-muted);
  font-size: 11px;
}
.plain-chip--success {
  color: #146c43;
  background: #e8f6ee;
  border-color: #b7dcc7;
}
.plain-chip--warning {
  color: #8a5a00;
  background: #fff6e5;
  border-color: #ead49f;
}
.plain-chip--danger {
  color: #a12828;
  background: #fdecec;
  border-color: #efc2c2;
}
.plain-chip--info {
  color: #0f5466;
  background: #eaf4f7;
  border-color: #c4dde4;
}
.plain-chip--muted {
  color: var(--color-ink-muted);
}
</style>
