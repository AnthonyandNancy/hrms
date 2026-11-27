<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { RouterLink, useRoute } from 'vue-router'
import PageShell from '@/shared/ui/PageShell.vue'
import { useSessionStore } from '@/stores/session'
import { formatBusinessDate, formatDateTimeInTimezone, formatMoney } from '@/utils/format'
import {
  decideWorkflowCase,
  errorCopy,
  getWorkflowCase,
  getWorkflowTimeline,
  isForbidden,
  retryWorkflowEffect,
  withdrawWorkflowCase,
  type WorkflowCaseSummary,
  type WorkflowDecisionAction,
  type WorkflowEffect,
  type WorkflowTimeline,
  type WorkflowTimelineItem,
} from '../api'
import {
  caseTypeLabel,
  decisionActionLabel,
  effectStatusLabel,
  effectTypeLabel,
  subjectTypeLabel,
  workflowEventLabel,
  workflowNodeLabel,
  workflowStatusLabel,
  workflowStatusTone,
} from '../../labels'
import { nextIdempotencyKey } from '../../platformRead'

const props = defineProps<{ caseId?: string | number }>()

const route = useRoute()
const session = useSessionStore()
const canRead = computed(() => session.can('workflow.case.read'))
const canDecide = computed(() => session.can('workflow.case.decide'))
const canAdmin = computed(() => session.can('workflow.case.admin'))

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
const selectedAction = ref<WorkflowDecisionAction | null>(null)
const comment = ref('')
const adminOverrideReason = ref('')
const showOverrideReason = ref(false)

const withdrawOpen = ref(false)
const withdrawComment = ref('')
const withdrawing = ref(false)

const retryingEffectId = ref<number | null>(null)
const retryEffectId = ref<number | null>(null)
const retryReason = ref('')
const retryMessage = ref('')
const retryError = ref('')

const OPEN_STATUSES = new Set(['DRAFT', 'SUBMITTED', 'IN_REVIEW'])
const decided = computed(() =>
  detail.value !== null && !OPEN_STATUSES.has(detail.value.applicationStatus),
)

const decisionContext = computed(() => detail.value?.decisionContext ?? {
  isAwaitingMe: false,
  canAdminOverride: false,
  requiresReason: false,
})

const awaitingMe = computed(() =>
  decisionContext.value.isAwaitingMe || detail.value?.isAwaitingMe === true,
)
const canShowDecision = computed(() =>
  canDecide.value
  && !decided.value
  && (awaitingMe.value || decisionContext.value.canAdminOverride),
)
const isAdminOverride = computed(() =>
  canShowDecision.value
  && !awaitingMe.value
  && decisionContext.value.canAdminOverride,
)

const readonlyReason = computed(() => {
  if (detail.value === null) return ''
  if (decided.value) return '该案例已有最终结果，审批链与时间线仅供查阅。'
  if (!canDecide.value) return '你可以查看该案例，但没有审批决策权限。'
  if (!canShowDecision.value) return '当前案例没有指派给你的待办，仅供查阅。'
  return ''
})

const hasTimeline = computed(() => (timeline.value?.items.length ?? 0) > 0)
const isEmpty = computed(() => !loading.value && loadError.value === null && detail.value === null)
const canWithdraw = computed(() =>
  detail.value !== null
  && detail.value.isMine
  && ['DRAFT', 'SUBMITTED', 'IN_REVIEW'].includes(detail.value.applicationStatus),
)

const decidedByLabel = computed(() => {
  if (detail.value === null) return '—'
  return detail.value.decidedByDisplayName
    ?? (detail.value.decidedByUserId === null ? '尚未决定' : '未命名审批人')
})

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
  'employee_no',
  'effective_from',
  'effective_to',
  'valid_from',
  'valid_to',
  'amount_minor',
  'total_amount_minor',
  'base_salary_cents',
  'currency_code',
])

const payloadSummary = computed(() => {
  if (detail.value === null) return []
  return Object.entries(detail.value.payload)
    .filter(([key]) => knownSummaryKeys.has(key))
    .map(([key, value]) => ({ label: payloadLabel(key), value: payloadValue(key, value) }))
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
    minutes: '申请时长（分钟）',
    half_day: '是否半天',
    notice_id: '公告编号',
    audience_count: '受众数量',
    employment_id: '员工任职编号',
    employee_no: '员工编号',
    effective_from: '生效日期',
    effective_to: '失效日期',
    valid_from: '开始日期',
    valid_to: '结束日期',
    amount_minor: '金额',
    total_amount_minor: '合计金额',
    base_salary_cents: '基本工资',
    currency_code: '币种',
  }
  return labels[key] ?? '业务摘要'
}

function payloadValue(key: string, value: unknown): string {
  if (value === null || value === undefined || value === '') return '—'
  if (typeof value === 'boolean') return value ? '是' : '否'
  if (typeof value === 'number') {
    if (key.endsWith('_minor') || key.endsWith('_cents')) return formatMoney(value)
    return String(value)
  }
  if (typeof value === 'string') {
    if (/^\d{4}-\d{2}-\d{2}$/.test(value)) return formatBusinessDate(value)
    return value
  }
  return '结构化摘要'
}

function statusTone(code: string): string {
  return `plain-chip--${workflowStatusTone(code)}`
}

function effectTone(code: string): string {
  if (code === 'APPLIED') return 'plain-chip--success'
  if (code === 'FAILED') return 'plain-chip--danger'
  if (code === 'NONE') return 'plain-chip--muted'
  return 'plain-chip--info'
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
    const suffix = item.failureSummary === null ? '' : `；${item.failureSummary}`
    return `生效状态：${effectStatusLabel(item.effectStatus ?? '')}（第 ${item.attempts ?? 0} 次尝试）${suffix}`
  }
  return '流程状态已更新。'
}

function actorText(item: WorkflowTimelineItem): string {
  const actor = item.decidedByDisplayName
    ?? item.actorDisplayName
    ?? (item.actorUserId === null ? '系统' : '未命名操作人')
  const parts = [`操作人：${actor}`]
  if (item.onBehalfOfDisplayName !== null || item.onBehalfOfUserId !== null) {
    parts.push(`被代理人：${item.onBehalfOfDisplayName ?? '未命名被代理人'}`)
  }
  if (item.delegationId !== null) parts.push(`委派编号 ${item.delegationId}`)
  if (item.adminOverride) parts.push('管理员覆盖')
  if (item.reason !== null) parts.push(`覆盖理由：${item.reason}`)
  return parts.join(' · ')
}

function effectFailureText(effect: WorkflowEffect): string {
  return effect.failureSummary ?? '业务效果执行失败，详情见审计记录。'
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
    timeline.value = null
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
      : `审批链与时间线加载失败：${errorCopy(error)}`
  } finally {
    loading.value = false
  }
}

function openDecision(action: WorkflowDecisionAction): void {
  if (!canShowDecision.value) return
  selectedAction.value = action
  actionMessage.value = ''
  actionError.value = ''
  if (isAdminOverride.value || decisionContext.value.requiresReason) {
    showOverrideReason.value = true
    if (adminOverrideReason.value.trim().length < 10) {
      actionError.value = '管理员覆盖需填写至少 10 个字符的理由。'
    }
  }
}

const decisionSubmitDisabled = computed(() => {
  if (selectedAction.value === null || deciding.value !== null) return true
  if (comment.value.trim() === '') return true
  if (isAdminOverride.value && adminOverrideReason.value.trim().length < 10) return true
  return false
})

async function submitDecision(): Promise<void> {
  const action = selectedAction.value
  if (resolvedCaseId.value === null || detail.value === null || action === null) return
  const trimmedComment = comment.value.trim()
  if (trimmedComment === '') {
    actionError.value = '请填写审批意见后再提交决定。'
    return
  }

  const reason = adminOverrideReason.value.trim()
  if (isAdminOverride.value && reason.length < 10) {
    actionError.value = '管理员覆盖需填写至少 10 个字符的理由。'
    showOverrideReason.value = true
    return
  }

  deciding.value = action
  actionError.value = ''
  actionMessage.value = ''
  try {
    const key = nextIdempotencyKey(`workflow-${resolvedCaseId.value}-${action.toLowerCase()}`)
    await decideWorkflowCase(
      resolvedCaseId.value,
      action,
      trimmedComment,
      key,
      isAdminOverride.value ? reason : undefined,
    )
    actionMessage.value = `已记录${decisionActionLabel(action)}决定；正在刷新申请与生效状态。`
    ElMessage.success(actionMessage.value)
    showOverrideReason.value = false
    selectedAction.value = null
    comment.value = ''
    adminOverrideReason.value = ''
    await loadDetail()
  } catch (error) {
    actionError.value = errorCopy(error)
    ElMessage.error(actionError.value)
  } finally {
    deciding.value = null
  }
}

async function submitWithdraw(): Promise<void> {
  if (resolvedCaseId.value === null || withdrawComment.value.trim() === '') {
    actionError.value = '请填写撤回原因。'
    return
  }
  withdrawing.value = true
  actionError.value = ''
  try {
    await withdrawWorkflowCase(
      resolvedCaseId.value,
      withdrawComment.value.trim(),
      nextIdempotencyKey(`workflow-withdraw-${resolvedCaseId.value}`),
    )
    withdrawOpen.value = false
    withdrawComment.value = ''
    actionMessage.value = '案例已撤回；待办与流程实例已由服务端取消。'
    ElMessage.success(actionMessage.value)
    await loadDetail()
  } catch (error) {
    actionError.value = errorCopy(error)
    ElMessage.error(actionError.value)
  } finally {
    withdrawing.value = false
  }
}

function openRetry(effect: WorkflowEffect): void {
  if (!canAdmin.value) return
  retryEffectId.value = effect.effectId
  retryReason.value = ''
  retryMessage.value = ''
  retryError.value = ''
}

async function submitRetry(): Promise<void> {
  if (retryEffectId.value === null) return
  const reason = retryReason.value.trim()
  if (reason.length < 10) {
    retryError.value = '效果重试需填写至少 10 个字符的原因。'
    return
  }

  retryingEffectId.value = retryEffectId.value
  retryError.value = ''
  retryMessage.value = ''
  try {
    const result = await retryWorkflowEffect(
      retryEffectId.value,
      reason,
      nextIdempotencyKey(`workflow-effect-${retryEffectId.value}`),
    )
    await loadDetail()
    if (result.effectStatus === 'APPLIED') {
      retryMessage.value = `生效任务已重试成功（累计尝试 ${result.attempts} 次）；审批决定未重复写入。`
      ElMessage.success(retryMessage.value)
    } else {
      retryMessage.value = result.requiresEscalation
        ? '已重试但仍失败，重试次数已用尽，请查看审计/运维记录并升级处理。'
        : '已重试但仍失败，请查看失败摘要与审计/运维记录；审批决定未重复写入。'
      ElMessage.warning(retryMessage.value)
    }
    retryEffectId.value = null
    retryReason.value = ''
  } catch (error) {
    retryError.value = errorCopy(error)
    ElMessage.error(retryError.value)
  } finally {
    retryingEffectId.value = null
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
    description="查看申请人摘要、审批链、决定上下文与业务生效状态；只有服务端确认的当前待办或管理员覆盖可提交决定。"
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
        <p class="muted">{{ errorCopy(loadError) }}</p>
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
            <span class="plain-chip" :class="effectTone(detail.effectStatus)">
              {{ effectStatusLabel(detail.effectStatus) }}
            </span>
          </div>
        </div>
        <div class="panel__body">
          <el-descriptions :column="2" border>
            <el-descriptions-item label="事项类型">{{ caseTypeLabel(detail.type) }}</el-descriptions-item>
            <el-descriptions-item label="业务对象">
              {{ subjectTypeLabel(detail.subjectType) }}
              <template v-if="detail.subjectId !== null"> #{{ detail.subjectId }}</template>
            </el-descriptions-item>
            <el-descriptions-item label="申请人">
              {{ detail.requesterDisplayName ?? '未命名申请人' }}
            </el-descriptions-item>
            <el-descriptions-item label="当前处理人">
              {{ detail.currentAssigneeDisplayName ?? (detail.currentAssigneeUserId === null ? '—' : '未命名处理人') }}
            </el-descriptions-item>
            <el-descriptions-item label="最近决定人">{{ decidedByLabel }}</el-descriptions-item>
            <el-descriptions-item label="业务日期">{{ formatBusinessDate(detail.businessDate) }}</el-descriptions-item>
            <el-descriptions-item label="提交时间">
              {{ formatDateTimeInTimezone(detail.submittedAt) }}
            </el-descriptions-item>
            <el-descriptions-item label="结束时间">
              {{ formatDateTimeInTimezone(detail.closedAt) }}
            </el-descriptions-item>
            <el-descriptions-item label="当前环节">
              {{ detail.currentNodeKey === null ? '已结束' : workflowNodeLabel(detail.currentNodeKey) }}
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
          <p v-else class="muted small detail-payload">
            服务端未返回可展示的业务摘要字段；页面不会展开原始请求内容。
          </p>
        </div>
      </section>

      <section
        v-if="readonlyReason !== '' && !canShowDecision"
        class="panel"
        data-testid="workflow-readonly"
      >
        <div class="panel__body">
          <h2 class="panel__title">只读查看</h2>
          <p class="muted">{{ readonlyReason }}</p>
        </div>
      </section>

      <section
        v-if="canWithdraw"
        class="panel"
        data-testid="workflow-withdraw-panel"
      >
        <div class="panel__body workflow-withdraw">
          <div>
            <h2 class="panel__title">撤回申请</h2>
            <p class="muted small">仅申请人可撤回处理中的案例；服务端会取消待办并保留审计轨迹。</p>
          </div>
          <el-button plain data-testid="workflow-withdraw-open" @click="withdrawOpen = true">撤回申请</el-button>
        </div>
      </section>

      <section
        v-if="canShowDecision"
        class="panel"
        data-testid="workflow-decision-context"
      >
        <div class="panel__header">
          <div>
            <h2 class="panel__title">当前决定上下文</h2>
            <p class="panel__subtitle">
              <template v-if="isAdminOverride">服务端未判断你是当前待办人；本次提交将按管理员覆盖记录，必须填写至少 10 个字符的理由。</template>
              <template v-else>服务端确认你当前持有该节点的待办任务；决定会写入不可变审批记录。</template>
            </p>
          </div>
          <span class="plain-chip" :class="isAdminOverride ? 'plain-chip--warning' : 'plain-chip--info'">
            {{ isAdminOverride ? '管理员覆盖' : '当前指派待办' }}
          </span>
        </div>
        <div class="panel__body decision-body">
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
          <label v-if="isAdminOverride" class="decision-comment">
            <span>管理员覆盖理由 *（至少 10 个字符）</span>
            <el-input
              v-model="adminOverrideReason"
              data-testid="workflow-admin-override-reason"
              type="textarea"
              :rows="2"
              maxlength="2000"
              placeholder="直属主管长期休假，由管理员按制度代批"
            />
          </label>
          <p v-if="isAdminOverride && showOverrideReason && adminOverrideReason.trim().length < 10" class="inline-warning">
            管理员覆盖需填写至少 10 个字符的理由
          </p>
          <div class="decision-buttons">
            <el-button
              type="primary"
              :loading="deciding === 'APPROVE'"
              :disabled="deciding !== null"
              :plain="selectedAction !== 'APPROVE'"
              data-testid="workflow-decision-approve"
              @click="openDecision('APPROVE')"
            >
              同意
            </el-button>
            <el-button
              type="warning"
              :loading="deciding === 'RETURN'"
              :disabled="deciding !== null"
              :plain="selectedAction !== 'RETURN'"
              data-testid="workflow-decision-return"
              @click="openDecision('RETURN')"
            >
              退回补充
            </el-button>
            <el-button
              type="danger"
              :loading="deciding === 'REJECT'"
              :disabled="deciding !== null"
              :plain="selectedAction !== 'REJECT'"
              data-testid="workflow-decision-reject"
              @click="openDecision('REJECT')"
            >
              驳回
            </el-button>
            <el-button
              type="success"
              :disabled="decisionSubmitDisabled"
              data-testid="workflow-decision-submit"
              @click="submitDecision"
            >
              提交{{ selectedAction === null ? '决定' : decisionActionLabel(selectedAction) }}
            </el-button>
          </div>
        </div>
      </section>

      <section class="panel" data-testid="workflow-effect-panel">
        <div class="panel__header">
          <div>
            <h2 class="panel__title">业务生效</h2>
            <p class="panel__subtitle">申请状态回答“流程走到哪里”；生效状态回答“业务事实是否已改变”。</p>
          </div>
          <span class="plain-chip" :class="effectTone(detail.effectStatus)" data-testid="workflow-effect-status">
            {{ effectStatusLabel(detail.effectStatus) }}
          </span>
        </div>
        <div class="panel__body">
          <div v-if="detail.effects.length === 0" class="empty-inline" data-testid="workflow-effect-unconfigured">
            当前流程没有声明业务生效任务（未调度）。
          </div>
          <ul v-else class="workflow-effects">
            <li
              v-for="effect in detail.effects"
              :key="effect.effectId"
              class="workflow-effect-row"
              :data-testid="`workflow-effect-${effect.effectId}`"
            >
              <div class="workflow-effect-row__main">
                <strong>{{ effectTypeLabel(effect.effectType) }}</strong>
                <span class="plain-chip" :class="effectTone(effect.status)">
                  {{ effectStatusLabel(effect.status) }}
                </span>
                <span class="muted small">尝试 {{ effect.attempts }} 次</span>
                <span v-if="effect.requiresEscalation" class="plain-chip plain-chip--danger">
                  需要升级处理
                </span>
              </div>
              <p v-if="effect.attempts >= 5 && effect.status !== 'APPLIED'" class="muted small">
                重试次数已用尽，不能再通过页面重试；请按审计/运维流程升级处理。
              </p>
              <div
                v-if="effect.status === 'FAILED'"
                class="workflow-effect-row__failed"
                data-testid="workflow-effect-failed"
              >
                <p>{{ effectFailureText(effect) }}</p>
                <el-button
                  v-if="canAdmin"
                  type="danger"
                  plain
                  size="small"
                  data-testid="workflow-effect-retry"
                  @click="openRetry(effect)"
                >
                  重试生效
                </el-button>
                <span v-else class="muted small">效果失败；重试需流程管理员能力。</span>
              </div>
              <p v-else-if="effect.status === 'SCHEDULED'" class="muted small">
                尚未调度执行；到排期日期后由流程运行器处理。
              </p>
            </li>
          </ul>
        </div>
      </section>

      <section class="panel" data-testid="workflow-timeline-panel">
        <div class="panel__header">
          <div>
            <h2 class="panel__title">审批链与时间线</h2>
            <p class="panel__subtitle">流程事件、审批决定与生效处理按发生时间排列；决定人显示名与覆盖理由来自服务端。</p>
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
                <p class="workflow-timeline__actor">{{ actorText(item) }}</p>
                <p v-if="item.kind === 'DECISION' && item.adminOverride" class="workflow-timeline__detail" data-testid="workflow-timeline-override">
                  管理员覆盖理由：{{ item.reason ?? '未返回' }}
                </p>
                <p v-if="item.kind === 'EFFECT' && item.requiresEscalation" class="workflow-timeline__detail">
                  生效重试次数已用尽，需要审计/运维升级处理。
                </p>
              </div>
            </li>
          </ol>
          <div v-else class="empty-inline" data-testid="workflow-timeline-empty">
            暂无流程时间线记录。
          </div>
        </div>
      </section>

      <el-drawer
        v-model="withdrawOpen"
        title="撤回审批申请"
        size="460px"
        :close-on-click-modal="false"
        data-testid="workflow-withdraw-drawer"
      >
        <label class="decision-comment">
          <span>撤回原因 *</span>
          <el-input
            v-model="withdrawComment"
            data-testid="workflow-withdraw-comment"
            type="textarea"
            :rows="3"
            maxlength="2000"
            placeholder="请说明撤回原因"
          />
        </label>
        <template #footer>
          <el-button @click="withdrawOpen = false">取消</el-button>
          <el-button
            type="danger"
            :loading="withdrawing"
            data-testid="workflow-withdraw-submit"
            @click="submitWithdraw"
          >
            确认撤回
          </el-button>
        </template>
      </el-drawer>

      <el-drawer
        :model-value="retryEffectId !== null"
        title="重试失败的业务效果"
        size="480px"
        :close-on-click-modal="false"
        data-testid="workflow-effect-retry-drawer"
        @close="retryEffectId = null"
      >
        <div class="decision-body">
          <p class="muted small">
            重试会按效果编号重新执行业务处理；不会重复写入审批决定。原因至少 10 个字符并写入审计。
          </p>
          <label class="decision-comment">
            <span>重试原因 *</span>
            <el-input
              v-model="retryReason"
              data-testid="workflow-effect-retry-reason"
              type="textarea"
              :rows="3"
              maxlength="2000"
              placeholder="已修复下游服务，按幂等键重试"
            />
          </label>
          <p v-if="retryError" class="inline-error" role="alert">{{ retryError }}</p>
          <p v-if="retryMessage" class="inline-success" data-testid="workflow-effect-retry-message">{{ retryMessage }}</p>
        </div>
        <template #footer>
          <el-button @click="retryEffectId = null">取消</el-button>
          <el-button
            type="primary"
            :loading="retryingEffectId !== null"
            data-testid="workflow-effect-retry-submit"
            @click="submitRetry"
          >
            提交重试
          </el-button>
        </template>
      </el-drawer>
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
.workflow-withdraw {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}
.workflow-effects {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 0;
  margin: 0;
  list-style: none;
}
.workflow-effect-row {
  padding: 10px 12px;
  border: 1px solid var(--color-line);
  border-radius: 8px;
}
.workflow-effect-row__main {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  font-size: 13px;
}
.workflow-effect-row__failed {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding-top: 7px;
  margin-top: 7px;
  color: #a12828;
  font-size: 12px;
  border-top: 1px dashed #efc2c2;
}
.workflow-effect-row__failed p {
  flex: 1 1 360px;
  margin: 0;
}
.inline-warning {
  padding: 9px 12px;
  color: #8a5a00;
  font-size: 12px;
  background: #fff6e5;
  border: 1px solid #ead49f;
  border-radius: 7px;
}
.inline-success,
.inline-error {
  padding: 9px 12px;
  font-size: 12px;
  border-radius: 7px;
}
.inline-success {
  color: #146c43;
  background: #e8f6ee;
  border: 1px solid #b7dcc7;
}
.inline-error {
  color: #a12828;
  background: #fdecec;
  border: 1px solid #efc2c2;
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
