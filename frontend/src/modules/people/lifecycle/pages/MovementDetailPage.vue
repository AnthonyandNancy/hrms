<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ApiError, errorCode, errorCopy } from '@/api/errors'
import { useSessionStore } from '@/stores/session'
import { formatBusinessDate, formatDateTimeInTimezone } from '@/utils/format'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import LoadingState from '@/components/LoadingState.vue'
import MovementTimeline from '../components/MovementTimeline.vue'
import {
  domainStatusLabel,
  effectStatusLabel,
  employeeLabel,
  formatMinorUnits,
  isDecidedApplicationStatus,
  isOpenApplicationStatus,
  loadMovementCaseDetail,
  listEmployees,
  listOrgUnits,
  listSecondments,
  secondmentToRow,
  listUsers,
  movementKindForCaseType,
  movementKindLabel,
  orgUnitName,
  stableSubmissionKey,
  statusLabel,
  submitMovementDecision,
  terminationTypeLabel,
  type EmployeeOption,
  type MovementCaseDetail,
  type OrgUnitOption,
  type Secondment,
  type StableSubmissionKey,
  type UserOption,
} from '../api'

defineOptions({ name: 'MovementDetailPage' })

const route = useRoute()
const router = useRouter()
const session = useSessionStore()

const canRead = computed(() => session.can('lifecycle.case.read'))
const canDecide = computed(() => session.can('workflow.case.decide'))

const loading = ref(false)
const loaded = ref(false)
const errorMessage = ref('')
const errorRequestId = ref<string | null>(null)
const errorCodeValue = ref('')
const detail = ref<MovementCaseDetail | null>(null)
const secondment = ref<Secondment | null>(null)
const users = ref<UserOption[]>([])
const employees = ref<EmployeeOption[]>([])
const orgUnits = ref<OrgUnitOption[]>([])

const comment = ref('')
const decisionSubmitting = ref<'APPROVE' | 'REJECT' | null>(null)
const decisionError = ref('')
const decisionSuccess = ref('')
const decisionKey = ref<StableSubmissionKey | null>(null)

const routeId = computed(() => {
  const value = route.params.id
  if (typeof value !== 'string' || !/^\d+$/.test(value)) return null
  const parsed = Number(value)
  return Number.isSafeInteger(parsed) && parsed > 0 ? parsed : null
})

const isSecondmentRoute = computed(() => route.query.type === 'secondment')

const lifecycle = computed(() => detail.value?.lifecycle ?? null)
const workflow = computed(() => detail.value?.workflow ?? null)
const kind = computed(() => {
  if (secondment.value !== null) return 'secondment'
  if (lifecycle.value === null) return null
  return movementKindForCaseType(lifecycle.value.caseType)
})

const typeLabel = computed(() => (kind.value === null ? '员工异动' : movementKindLabel(kind.value)))
const payload = computed<Record<string, unknown>>(() => lifecycle.value?.payload ?? {})
const effectiveDate = computed<string | null>(() => {
  if (secondment.value !== null) return secondment.value.validFrom
  if (lifecycle.value === null) return null
  const kindValue = kind.value
  if (kindValue === 'onboarding') return textValue(payload.value.hire_date)
  if (kindValue === 'offboarding') return textValue(payload.value.last_working_date)
  if (kindValue === 'secondment') {
    return textValue(payload.value.effective_from) ?? textValue(payload.value.valid_from)
  }
  return textValue(payload.value.effective_date) ?? textValue(payload.value.effective_from)
})

const secondmentRow = computed(() => (secondment.value === null ? null : secondmentToRow(secondment.value)))
const applicationStatus = computed(
  () =>
    workflow.value?.applicationStatus ??
    secondmentRow.value?.applicationStatus ??
    lifecycle.value?.applicationStatus ??
    '',
)
const effectStatus = computed(
  () =>
    workflow.value?.effectStatus ??
    secondmentRow.value?.effectStatus ??
    lifecycle.value?.effectStatus ??
    '',
)
const canViewSalary = computed(() => session.can('lifecycle.terms.manage'))
const decided = computed(() => isDecidedApplicationStatus(applicationStatus.value))
const awaitingDecision = computed(
  () => canDecide.value && !decided.value && isOpenApplicationStatus(applicationStatus.value) && workflow.value?.isAwaitingMe === true,
)
const currentNodeKey = computed(() => workflow.value?.currentNodeKey ?? null)
const requesterUserId = computed(() => workflow.value?.requesterUserId ?? null)
const initiatorLabel = computed(() => {
  const id = requesterUserId.value
  if (id === null) return '—'
  const known = users.value.find((user) => user.id === id)?.displayName
  if (known !== undefined) return known
  const current = session.principal
  if (current !== null && current.userId === id) return current.displayName
  return `用户 #${id}`
})
const employeeText = computed(() => {
  if (secondment.value !== null) {
    return secondment.value.employeeNo === ''
      ? secondment.value.personName || `员工关系 #${secondment.value.employmentId}`
      : `${secondment.value.personName || '员工'}（${secondment.value.employeeNo}）`
  }
  const employmentId = lifecycle.value?.employmentId ?? lifecycle.value?.resultEmploymentId ?? null
  if (employmentId === null) return '—'
  const match = employees.value.find((employee) => employee.employmentId === employmentId)
  if (match !== undefined) return employeeLabel(match)
  const employeeNo = lifecycle.value?.employment?.employeeNo ?? ''
  return employeeNo === '' ? `员工关系 #${employmentId}` : `员工关系 #${employmentId}（${employeeNo}）`
})

const targetOrgText = computed(() => {
  if (secondment.value !== null) return orgUnitName(secondment.value.hostOrgUnitId, orgUnits.value)
  const orgUnitId = numberValue(isRecord(payload.value) ? payload.value.org_unit_id : null)
  if (orgUnitId === null) return '—'
  return orgUnitName(orgUnitId, orgUnits.value)
})

const salaryText = computed(() => {
  if (!canViewSalary.value) return '受限字段（需 lifecycle.terms.manage 与 P3 字段策略）'
  const amount = numberValue(payload.value.base_salary_minor)
  if (amount === null) return '—'
  const currency = textValue(payload.value.salary_currency) ?? 'CNY'
  return formatMinorUnits(amount, currency)
})

const dateLabel = computed(() => {
  if (secondment.value !== null) return '借调开始日'
  if (kind.value === 'offboarding') return '最后工作日'
  return '生效日期'
})
const dateHint = computed(() => {
  if (secondment.value !== null) {
    return '借调任职从开始日生效，到结束日结束；主任职任职历史保持不变。'
  }
  if (kind.value === 'offboarding') return '任职将在最后工作日之后一天结束。'
  if (kind.value === 'onboarding') return '批准后按入职日期建档，审批通过前不会创建员工。'
  return '批准后从生效日期开始执行，不会立即变更；审批通过只是生效前提。'
})

function isRecord(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
}

function textValue(value: unknown): string | null {
  return typeof value === 'string' && value !== '' ? value : null
}

function numberValue(value: unknown): number | null {
  if (typeof value === 'number' && Number.isSafeInteger(value)) return value
  if (typeof value === 'string' && /^\d+$/.test(value)) return Number(value)
  return null
}

function captureError(reason: unknown): void {
  errorCodeValue.value = errorCode(reason)
  if (reason instanceof ApiError) {
    errorRequestId.value = reason.requestId
    errorMessage.value = reason.code === 'NOT_FOUND'
      ? '未找到该异动案例，可能已被移出你的数据范围或链接有误。'
      : reason.code === 'FORBIDDEN'
        ? '无权读取该异动案例。'
        : reason.message || errorCopy(reason)
  } else {
    errorRequestId.value = null
    errorMessage.value = reason instanceof Error ? reason.message : '异动案例暂时无法加载。'
  }
}

async function loadLookups(): Promise<void> {
  const userPromise = session.can('iam.user.read') ? listUsers() : Promise.resolve([])
  const employeePromise = session.can('people.employee.read')
    ? listEmployees()
    : Promise.resolve<EmployeeOption[]>([])
  const orgPromise = session.can('org.unit.read')
    ? listOrgUnits()
    : Promise.resolve<OrgUnitOption[]>([])

  const [userResult, employeeResult, orgResult] = await Promise.allSettled([
    userPromise,
    employeePromise,
    orgPromise,
  ])

  if (userResult.status === 'fulfilled') users.value = userResult.value
  if (employeeResult.status === 'fulfilled') employees.value = employeeResult.value
  if (orgResult.status === 'fulfilled') orgUnits.value = orgResult.value
}

async function loadSecondment(assignmentId: number): Promise<void> {
  if (!session.can('lifecycle.secondment.manage')) {
    secondment.value = null
    return
  }
  const result = await listSecondments()
  secondment.value = result.items.find((item) => item.assignmentId === assignmentId) ?? null
}

async function load(): Promise<void> {
  if (!canRead.value) {
    loaded.value = true
    return
  }
  const id = routeId.value
  if (id === null) {
    loaded.value = true
    return
  }

  loading.value = true
  loaded.value = false
  errorMessage.value = ''
  errorRequestId.value = null
  errorCodeValue.value = ''
  detail.value = null
  secondment.value = null
  decisionError.value = ''
  await loadLookups()

  try {
    if (isSecondmentRoute.value) {
      await loadSecondment(id)
      if (secondment.value === null) {
        errorMessage.value = '未找到该借调任职记录。'
      }
    } else {
      detail.value = await loadMovementCaseDetail(id)
      const secondmentAssignment = numberValue(detail.value.lifecycle.payload.assignment_id)
      if (secondmentAssignment !== null) {
        await loadSecondment(secondmentAssignment)
      }
    }
  } catch (reason) {
    captureError(reason)
  } finally {
    loading.value = false
    loaded.value = true
  }
}

async function decide(action: 'APPROVE' | 'REJECT'): Promise<void> {
  if (!awaitingDecision.value || !canDecide.value) return
  const caseId = lifecycle.value?.caseId ?? null
  if (caseId === null) return

  const payloadForDecision = { action, comment: comment.value.trim() }
  decisionKey.value = stableSubmissionKey(decisionKey.value, `movement-${action.toLowerCase()}`, payloadForDecision)
  decisionSubmitting.value = action
  decisionError.value = ''
  decisionSuccess.value = ''

  try {
    await submitMovementDecision(caseId, action, comment.value, decisionKey.value.key)
    comment.value = ''
    await load()
    decisionSuccess.value = action === 'APPROVE' ? '审批决定已提交：批准。' : '审批决定已提交：驳回。'
  } catch (reason) {
    if (reason instanceof ApiError) {
      decisionError.value =
        reason.code === 'CONFLICT'
          ? '案例状态已变化（可能已被他人处理），请刷新后查看最新状态。'
          : reason.message || '审批决定未提交，请稍后重试。'
    } else {
      decisionError.value = reason instanceof Error ? reason.message : '审批决定未提交，请稍后重试。'
    }
  } finally {
    decisionSubmitting.value = null
  }
}

function backToList(): void {
  void router.push('/hr/movements')
}

function openEmployee(): void {
  const employmentId = lifecycle.value?.employmentId ?? lifecycle.value?.resultEmploymentId ?? null
  if (employmentId === null) return
  void router.push(`/hr/employees/${employmentId}`)
}

function fieldValue(label: string, value: string | null): { label: string; value: string } {
  return { label, value: value === null || value === '' ? '—' : value }
}

const facts = computed(() => {
  if (kind.value === 'secondment' && secondment.value !== null) {
    return [
      fieldValue('员工', employeeText.value),
      fieldValue('接收组织', targetOrgText.value),
      fieldValue('借调开始', formatBusinessDate(secondment.value.validFrom)),
      fieldValue('借调结束', formatBusinessDate(secondment.value.validTo)),
      fieldValue('任职类型', '并行借调任职（SECONDMENT）'),
      fieldValue('原因', textValue(payload.value.reason) ?? '—'),
    ]
  }
  const record = lifecycle.value
  if (record === null) return []
  const entries: Array<{ label: string; value: string }> = [
    fieldValue('员工', employeeText.value),
    fieldValue('案例编号', record.caseNo),
    fieldValue('发起人', initiatorLabel.value),
    fieldValue(dateLabel.value, formatBusinessDate(effectiveDate.value)),
  ]
  if (kind.value === 'transfer') entries.push(fieldValue('目标组织', targetOrgText.value))
  if (kind.value === 'salary') entries.push(fieldValue('新基本工资', salaryText.value))
  if (kind.value === 'probation') entries.push(fieldValue('转正意见', textValue(payload.value.comment) ?? '—'))
  if (kind.value === 'offboarding') {
    entries.push(fieldValue('离职类型', terminationTypeLabel(textValue(payload.value.termination_type) ?? '')))
  }
  entries.push(fieldValue('原因', textValue(payload.value.reason) ?? textValue(payload.value.reason_code) ?? '—'))
  return entries
})

const timelineItems = computed(() => detail.value?.timeline?.items ?? [])

watch(
  () => route.fullPath,
  () => {
    if (isSecondmentRoute.value || /^\d+$/.test(String(route.params.id ?? ''))) {
      void load()
    }
  },
)

onMounted(() => {
  void load()
})
</script>

<template>
  <div class="movement-detail" data-page="movement-detail">
    <header class="movement-detail__header">
      <div>
        <button type="button" class="movement-detail__back" data-testid="movement-back" @click="backToList">
          ← 返回员工异动
        </button>
        <p class="movement-detail__breadcrumb">员工与组织 · 员工异动 · {{ typeLabel }}</p>
        <h1 class="movement-detail__title">
          {{ typeLabel }}详情
          <span v-if="lifecycle !== null" class="movement-detail__case-no">{{ lifecycle.caseNo }}</span>
        </h1>
      </div>
      <div class="movement-detail__actions">
        <button type="button" class="movement-detail__secondary" @click="load">刷新</button>
        <button
          v-if="lifecycle !== null && (lifecycle.employmentId !== null || lifecycle.resultEmploymentId !== null)"
          type="button"
          class="movement-detail__secondary"
          data-testid="movement-employee-link"
          @click="openEmployee"
        >
          查看员工档案
        </button>
      </div>
    </header>

    <section v-if="!canRead" class="movement-state" role="alert">
      <h2>无权访问该异动案例</h2>
      <p>当前账号缺少 lifecycle.case.read 能力，页面不会发起任何读取请求。</p>
    </section>

    <template v-else>
      <ErrorState
        v-if="errorMessage !== ''"
        title="异动案例暂时无法加载"
        :description="errorMessage"
        :code="errorCodeValue"
        :request-id="errorRequestId"
        @retry="load"
      />
      <LoadingState v-else-if="loading" :rows="6" label="正在加载异动案例" />
      <EmptyState
        v-else-if="!loaded || (detail === null && secondment === null)"
        title="未找到异动案例"
        description="请从员工异动工作台选择一条真实案例，或检查链接中的案例编号。"
      />
      <template v-else>
        <p v-if="detail?.partial" class="movement-detail__warning" role="status" data-testid="movement-detail-partial">
          部分数据：{{ detail.partialReason }}
        </p>

        <section class="movement-detail__effective" data-testid="movement-effective-banner">
          <div>
            <span class="movement-detail__effective-label">{{ dateLabel }}</span>
            <strong class="movement-detail__effective-date">{{ formatBusinessDate(effectiveDate) }}</strong>
          </div>
          <p>{{ dateHint }}</p>
        </section>

        <section class="movement-detail__facts" aria-labelledby="movement-facts-title">
          <header>
            <h2 id="movement-facts-title">对象摘要</h2>
            <div class="movement-detail__chips">
              <span class="movement-chip">{{ statusLabel(applicationStatus) }}</span>
              <span class="movement-chip">{{ effectStatusLabel(effectStatus) }}</span>
              <span v-if="lifecycle !== null" class="movement-chip">
                {{ domainStatusLabel(lifecycle.domainStatus) }}
              </span>
            </div>
          </header>
          <dl>
            <div v-for="fact in facts" :key="fact.label" class="movement-detail__fact">
              <dt>{{ fact.label }}</dt>
              <dd>{{ fact.value }}</dd>
            </div>
          </dl>
          <p v-if="lifecycle !== null" class="movement-detail__submitted">
            提交时间：{{ formatDateTimeInTimezone(workflow?.submittedAt ?? null) }}
            <span v-if="workflow?.closedAt !== null && workflow?.closedAt !== undefined">
              · 结束时间：{{ formatDateTimeInTimezone(workflow.closedAt) }}
            </span>
          </p>
          <p class="movement-detail__gap-note">
            本页只读展示异动与审批，不内嵌部门、岗位或合同编辑器；需要修改组织或合同请前往对应模块。
          </p>
        </section>

        <MovementTimeline
          :items="timelineItems"
          :users="users"
          :application-status="applicationStatus"
          :effect-status="effectStatus"
          :current-node-key="currentNodeKey"
          :awaiting-decision="awaitingDecision"
          :loading="loading"
        />

        <section
          v-if="awaitingDecision"
          class="movement-detail__decision"
          data-testid="movement-decision-panel"
        >
          <header>
            <h2>待我审批</h2>
            <p>批准后仍按生效日期执行；驳回会结束该案例。</p>
          </header>
          <label>
            <span>审批意见</span>
            <textarea
              v-model="comment"
              data-testid="movement-decision-comment"
              rows="3"
              maxlength="4000"
              placeholder="可选：填写本次审批意见"
            />
          </label>
          <p v-if="decisionError !== ''" class="movement-detail__error" role="alert">{{ decisionError }}</p>
          <p v-if="decisionSuccess !== ''" class="movement-detail__success" role="status">{{ decisionSuccess }}</p>
          <div class="movement-detail__decision-actions">
            <button
              type="button"
              class="movement-detail__reject"
              :disabled="decisionSubmitting !== null"
              data-testid="movement-reject"
              @click="decide('REJECT')"
            >
              {{ decisionSubmitting === 'REJECT' ? '提交中…' : '驳回' }}
            </button>
            <button
              type="button"
              class="movement-detail__approve"
              :disabled="decisionSubmitting !== null"
              data-testid="movement-approve"
              @click="decide('APPROVE')"
            >
              {{ decisionSubmitting === 'APPROVE' ? '提交中…' : '批准' }}
            </button>
          </div>
        </section>

        <section v-else-if="decided" class="movement-detail__readonly" data-testid="movement-readonly">
          <strong>该案例已结束，只读</strong>
          <p>已作出审批决定的案例不能再次批准、驳回或撤回；审批记录与时间线保持不可变。</p>
        </section>

        <p v-if="decisionError !== '' && !awaitingDecision" class="movement-detail__error" role="alert">
          {{ decisionError }}
        </p>
        <p v-if="decisionSuccess !== '' && !awaitingDecision" class="movement-detail__success" role="status">
          {{ decisionSuccess }}
        </p>
      </template>
    </template>
  </div>
</template>

<style scoped>
.movement-detail {
  display: grid;
  gap: 16px;
  padding: 20px;
  color: #0f172a;
}

.movement-detail__header {
  display: flex;
  justify-content: space-between;
  gap: 16px;
  align-items: flex-start;
}

.movement-detail__back {
  padding: 0;
  color: #1d4ed8;
  font-size: 11.5px;
  background: transparent;
  border: 0;
  cursor: pointer;
}

.movement-detail__breadcrumb {
  margin: 6px 0 4px;
  color: #64748b;
  font-size: 12px;
}

.movement-detail__title {
  margin: 0;
  font-size: 21px;
}

.movement-detail__case-no {
  display: inline-block;
  margin-left: 8px;
  color: #64748b;
  font-family: ui-monospace, monospace;
  font-size: 12px;
  font-weight: 500;
}

.movement-detail__actions {
  display: flex;
  gap: 8px;
}

.movement-detail__secondary {
  padding: 7px 12px;
  color: #334155;
  font-size: 12px;
  background: #fff;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
  cursor: pointer;
}

.movement-detail__warning {
  margin: 0;
  padding: 10px 12px;
  color: #92400e;
  font-size: 12px;
  background: #fffbeb;
  border: 1px solid #fde68a;
  border-radius: 8px;
}

.movement-detail__effective {
  display: flex;
  justify-content: space-between;
  gap: 16px;
  align-items: center;
  padding: 16px 18px;
  background: #eff6ff;
  border: 1px solid #bfdbfe;
  border-radius: 10px;
}

.movement-detail__effective-label {
  display: block;
  color: #1e40af;
  font-size: 11px;
  letter-spacing: 0.04em;
}

.movement-detail__effective-date {
  display: block;
  margin-top: 4px;
  color: #1e3a8a;
  font-size: 20px;
}

.movement-detail__effective p {
  max-width: 420px;
  margin: 0;
  color: #1e40af;
  font-size: 11.5px;
  line-height: 1.6;
}

.movement-detail__facts {
  padding: 16px 18px;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
}

.movement-detail__facts header {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  align-items: center;
}

.movement-detail__facts h2 {
  margin: 0;
  font-size: 15px;
}

.movement-detail__chips {
  display: flex;
  gap: 6px;
}

.movement-chip {
  padding: 2px 8px;
  color: #334155;
  font-size: 10.5px;
  background: #f1f5f9;
  border-radius: 999px;
}

.movement-detail__facts dl {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 12px;
  margin: 14px 0 0;
}

.movement-detail__fact {
  min-width: 0;
}

.movement-detail__fact dt {
  color: #64748b;
  font-size: 10.5px;
}

.movement-detail__fact dd {
  margin: 3px 0 0;
  font-size: 12.5px;
  word-break: break-word;
}

.movement-detail__submitted {
  margin: 14px 0 0;
  color: #64748b;
  font-size: 10.5px;
}

.movement-detail__gap-note {
  margin: 8px 0 0;
  color: #64748b;
  font-size: 10.5px;
  line-height: 1.6;
}

.movement-detail__decision {
  display: grid;
  gap: 10px;
  padding: 16px 18px;
  background: #fff;
  border: 1px solid #bfdbfe;
  border-radius: 10px;
}

.movement-detail__decision h2 {
  margin: 0;
  font-size: 15px;
}

.movement-detail__decision header p {
  margin: 4px 0 0;
  color: #64748b;
  font-size: 11px;
}

.movement-detail__decision label {
  display: grid;
  gap: 4px;
  color: #334155;
  font-size: 11.5px;
}

.movement-detail__decision textarea {
  padding: 8px 10px;
  font: inherit;
  font-size: 12px;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
}

.movement-detail__decision-actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}

.movement-detail__approve,
.movement-detail__reject {
  padding: 7px 14px;
  font-size: 12px;
  border-radius: 6px;
  cursor: pointer;
}

.movement-detail__approve {
  color: #fff;
  background: #1d4ed8;
  border: 1px solid #1d4ed8;
}

.movement-detail__reject {
  color: #b91c1c;
  background: #fff;
  border: 1px solid #fecaca;
}

.movement-detail__readonly {
  padding: 14px 16px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
}

.movement-detail__readonly strong {
  font-size: 12.5px;
}

.movement-detail__readonly p {
  margin: 4px 0 0;
  color: #64748b;
  font-size: 11px;
}

.movement-detail__error {
  margin: 0;
  color: #b91c1c;
  font-size: 11.5px;
}

.movement-detail__success {
  margin: 0;
  color: #047857;
  font-size: 11.5px;
}

.movement-state {
  padding: 24px;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
}

.movement-state h2 {
  margin: 0 0 6px;
  font-size: 16px;
}

.movement-state p {
  margin: 0;
  color: #475569;
  font-size: 12px;
}
</style>
