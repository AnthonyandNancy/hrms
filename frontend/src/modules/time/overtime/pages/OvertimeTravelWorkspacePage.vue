<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ApiError } from '@/api/errors'
import { useSessionStore } from '@/stores/session'
import { formatBusinessDate } from '@/utils/format'
import ErrorState from '@/components/ErrorState.vue'
import LoadingState from '@/components/LoadingState.vue'
import OvertimeRequestDrawer from '../components/OvertimeRequestDrawer.vue'
import OvertimeRecordTable from '../components/OvertimeRecordTable.vue'
import TravelRequestDrawer from '../components/TravelRequestDrawer.vue'
import TravelExpensePanel from '../components/TravelExpensePanel.vue'
import {
  confirmOvertimeRequest,
  getOvertimePolicy,
  getOvertimeRecords,
  getOvertimeRequests,
  getTimeoffBalances,
  getTravelExpenses,
  getTravelPolicies,
  getTravelRequests,
  applicationStatusLabel,
  cityTierLabel,
  overtimeNextStep,
  overtimeStatusLabel,
  stableSubmissionKey,
  travelStatusLabel,
  type OvertimePolicy,
  type OvertimeRecord,
  type OvertimeRequest,
  type StableSubmissionKey,
  type TimeoffBalancesResult,
  type TravelExpense,
  type TravelPolicy,
  type TravelRequest,
} from '../api'

defineOptions({ name: 'OvertimeTravelWorkspacePage' })

const session = useSessionStore()

const canReadOvertime = computed(() => session.can('time.overtime.read'))
const canRequestOvertime = computed(() => session.can('time.overtime.request'))
const canConfirmOvertime = computed(() => session.can('time.overtime.confirm'))
const canSettleOvertime = computed(() => session.can('time.overtime.settle'))
const canReadTimeoff = computed(() => session.can('time.timeoff.read'))

const canReadTravel = computed(() => session.can('time.travel.read'))
const canRequestTravel = computed(() => session.can('time.travel.request'))
const canReadTravelExpense = computed(() => session.can('time.travel.expense'))
const canManageTravelExpense = computed(() => session.can('time.travel.expense.manage'))
const canReadTravelPolicy = computed(() => session.can('time.travel.policy.read'))

const overtimeLoading = ref(false)
const overtimeLoaded = ref(false)
const overtimeError = ref<string>('')
const overtimeErrorRequestId = ref<string | null>(null)
const overtimeErrorCode = ref<string>('')
const overtimePartial = ref<string>('')
const overtimePolicy = ref<OvertimePolicy | null>(null)
const overtimeRequests = ref<OvertimeRequest[]>([])
const overtimeRecords = ref<OvertimeRecord[]>([])
const timeoff = ref<TimeoffBalancesResult | null>(null)

const travelLoading = ref(false)
const travelLoaded = ref(false)
const travelError = ref<string>('')
const travelErrorRequestId = ref<string | null>(null)
const travelErrorCode = ref<string>('')
const travelPartial = ref<string>('')
const travelRequests = ref<TravelRequest[]>([])
const travelExpenses = ref<TravelExpense[]>([])
const travelPolicies = ref<TravelPolicy[]>([])

const overtimeStatusFilter = ref('')
const overtimeDateFilter = ref('')
const travelStatusFilter = ref('')
const travelDateFilter = ref('')

const actionMessage = ref('')
const actionError = ref('')

const overtimeDrawerOpen = ref(false)
const travelDrawerOpen = ref(false)
const confirmingId = ref<number | null>(null)
const confirmingMinutes = ref('')
const confirming = ref(false)
const confirmError = ref('')
const confirmationKey = ref<StableSubmissionKey | null>(null)

const currency = computed(() => {
  // Currency is never assumed per row or defaulted locally. The page uses the
  // API-provided currency only when every returned expense agrees on it; a
  // mixed or empty projection stays blank until the company profile exposes
  // its configured currency.
  const currencies = [...new Set(travelExpenses.value.map((expense) => expense.currency).filter((code) => code !== ''))]
  return currencies.length === 1 ? currencies[0] ?? '' : ''
})

const filteredOvertimeRequests = computed(() =>
  overtimeRequests.value.filter((request) => {
    if (overtimeStatusFilter.value !== '' && request.status !== overtimeStatusFilter.value) return false
    if (overtimeDateFilter.value !== '' && request.businessDate !== overtimeDateFilter.value) return false
    return true
  }),
)

const filteredOvertimeRecords = computed(() =>
  overtimeRecords.value.filter((record) =>
    overtimeDateFilter.value === '' ? true : record.businessDate === overtimeDateFilter.value,
  ),
)

const filteredTravelRequests = computed(() =>
  travelRequests.value.filter((request) => {
    if (travelStatusFilter.value !== '' && request.status !== travelStatusFilter.value) return false
    if (travelDateFilter.value !== '' && request.fromDate !== travelDateFilter.value) return false
    return true
  }),
)

const travelExpensesByRequest = computed<TravelExpense[]>(() => {
  const ids = new Set(travelRequests.value.map((request) => request.travelId))
  return travelExpenses.value.filter((expense) => ids.has(expense.travelId))
})

const claimableCount = computed(() =>
  travelRequests.value.filter(
    (request) => request.status === 'APPROVED' && request.applicationStatus === 'APPROVED',
  ).length,
)

const overtimeStatusOptions = [
  { value: '', label: '全部状态' },
  { value: 'SUBMITTED', label: '已提交' },
  { value: 'APPROVED', label: '已批准' },
  { value: 'CONFIRMED', label: '已确认' },
  { value: 'REJECTED', label: '已驳回' },
  { value: 'CANCELLED', label: '已取消' },
]

const travelStatusOptions = [
  { value: '', label: '全部状态' },
  { value: 'DRAFT', label: '草稿' },
  { value: 'PENDING_APPROVAL', label: '待审批' },
  { value: 'APPROVED', label: '已批准' },
  { value: 'REJECTED', label: '已驳回' },
  { value: 'CANCELLED', label: '已取消' },
  { value: 'COMPLETED', label: '已完成' },
]

function minutesLabel(minutes: number): string {
  const hours = Math.floor(minutes / 60)
  const rest = minutes % 60
  if (hours === 0) return `${rest} 分钟`
  if (rest === 0) return `${hours} 小时`
  return `${hours} 小时 ${rest} 分钟`
}

function captureLoadError(reason: unknown, target: 'overtime' | 'travel'): void {
  const requestId = reason instanceof ApiError ? reason.requestId : null
  const code = reason instanceof ApiError ? reason.code : 'UNKNOWN'
  const message =
    reason instanceof ApiError
      ? reason.message || '暂时无法加载，请稍后重试。'
      : '暂时无法加载，请稍后重试。'

  if (target === 'overtime') {
    overtimeError.value = message
    overtimeErrorRequestId.value = requestId
    overtimeErrorCode.value = code
  } else {
    travelError.value = message
    travelErrorRequestId.value = requestId
    travelErrorCode.value = code
  }
}

async function loadOvertime(): Promise<void> {
  if (!canReadOvertime.value) {
    overtimeLoaded.value = true
    return
  }
  overtimeLoading.value = true
  overtimeLoaded.value = false
  overtimeError.value = ''
  overtimeErrorRequestId.value = null
  overtimeErrorCode.value = ''

  const partialReasons: string[] = []

  const [policyResult, requestsResult, recordsResult, timeoffResult] = await Promise.allSettled([
    getOvertimePolicy(),
    getOvertimeRequests(),
    getOvertimeRecords(),
    canReadTimeoff.value ? getTimeoffBalances() : Promise.resolve(null),
  ])

  if (policyResult.status === 'fulfilled') overtimePolicy.value = policyResult.value
  else {
    overtimePolicy.value = null
    partialReasons.push('加班政策暂不可用')
  }

  if (requestsResult.status === 'fulfilled') {
    overtimeRequests.value = requestsResult.value.items
    if (requestsResult.value.partial) {
      partialReasons.push(requestsResult.value.partialReason ?? '加班申请投影为部分数据')
    }
  } else {
    overtimeRequests.value = []
    if (recordsResult.status === 'fulfilled' || policyResult.status === 'fulfilled') {
      partialReasons.push('加班申请列表暂不可用')
    }
  }

  if (recordsResult.status === 'fulfilled') {
    overtimeRecords.value = recordsResult.value.items
    if (recordsResult.value.partial) {
      partialReasons.push(recordsResult.value.partialReason ?? '加班记录投影为部分数据')
    }
  } else {
    overtimeRecords.value = []
  }

  if (timeoffResult.status === 'fulfilled') timeoff.value = timeoffResult.value
  else timeoff.value = null

  const coreFailures = [requestsResult, recordsResult].filter((result) => result.status === 'rejected')
  if (coreFailures.length > 0) {
    const first = coreFailures[0]
    if (first !== undefined && first.status === 'rejected') captureLoadError(first.reason, 'overtime')
  }

  overtimePartial.value = partialReasons.join('；')
  overtimeLoading.value = false
  overtimeLoaded.value = true
}

async function loadTravel(): Promise<void> {
  if (!canReadTravel.value) {
    travelLoaded.value = true
    return
  }
  travelLoading.value = true
  travelLoaded.value = false
  travelError.value = ''
  travelErrorRequestId.value = null
  travelErrorCode.value = ''

  const partialReasons: string[] = []

  const [requestsResult, expensesResult, policiesResult] = await Promise.allSettled([
    getTravelRequests(),
    canReadTravelExpense.value ? getTravelExpenses() : Promise.resolve(null),
    canReadTravelPolicy.value ? getTravelPolicies() : Promise.resolve(null),
  ])

  if (requestsResult.status === 'fulfilled') {
    travelRequests.value = requestsResult.value.items
    if (requestsResult.value.partial) {
      partialReasons.push(requestsResult.value.partialReason ?? '出差申请投影为部分数据')
    }
  } else {
    travelRequests.value = []
  }

  if (expensesResult.status === 'fulfilled') {
    travelExpenses.value = expensesResult.value?.items ?? []
    if (expensesResult.value?.partial) {
      partialReasons.push(expensesResult.value.partialReason ?? '出差费用投影为部分数据')
    }
  } else {
    travelExpenses.value = []
    partialReasons.push(
      expensesResult.reason instanceof ApiError
        ? `出差费用暂不可用（请求编号 ${expensesResult.reason.requestId ?? '无'}）`
        : '出差费用暂不可用',
    )
  }

  if (policiesResult.status === 'fulfilled') travelPolicies.value = policiesResult.value?.items ?? []
  else {
    travelPolicies.value = []
    partialReasons.push('差旅政策暂不可用')
  }

  if (requestsResult.status === 'rejected') {
    captureLoadError(requestsResult.reason, 'travel')
  }

  travelPartial.value = partialReasons.join('；')
  travelLoading.value = false
  travelLoaded.value = true
}

function onOvertimeSubmitted(request: OvertimeRequest): void {
  actionError.value = ''
  actionMessage.value = `加班申请已提交（${overtimeStatusLabel(request.status)}），编号 #${request.id}。`
  void loadOvertime()
}

function onTravelSubmitted(request: TravelRequest): void {
  actionError.value = ''
  actionMessage.value = `出差申请已提交（${travelStatusLabel(request.status)}），编号 #${request.travelId}。`
  void loadTravel()
}

function onExpenseSubmitted(): void {
  actionError.value = ''
  actionMessage.value = '出差费用已登记（仅登记，不进入薪资或付款）。'
  void loadTravel()
}

function onSettled(): void {
  actionError.value = ''
  actionMessage.value = '加班记录已结算。'
  void loadOvertime()
}

function startConfirm(request: OvertimeRequest): void {
  if (!canConfirmOvertime.value) return
  confirmingId.value = request.id
  confirmingMinutes.value = String(request.declaredMinutes)
  confirmError.value = ''
  confirmationKey.value = null
}

function cancelConfirm(): void {
  if (confirming.value) return
  confirmingId.value = null
  confirmingMinutes.value = ''
  confirmError.value = ''
}

async function submitConfirm(request: OvertimeRequest): Promise<void> {
  if (!canConfirmOvertime.value) return
  const minutes = Number(confirmingMinutes.value)
  if (!Number.isSafeInteger(minutes) || minutes < 1) {
    confirmError.value = '确认分钟数必须是正整数。'
    return
  }
  const payload = {
    overtime_request_id: request.id,
    confirmed_minutes: minutes,
  }
  confirmationKey.value = stableSubmissionKey(confirmationKey.value, 'overtime-confirm', payload)

  confirming.value = true
  confirmError.value = ''
  try {
    const confirmed = await confirmOvertimeRequest(
      { requestId: request.id, confirmedMinutes: minutes },
      confirmationKey.value.key,
    )
    actionError.value = ''
    actionMessage.value = `加班已确认 ${confirmed.confirmedMinutes ?? minutes} 分钟，生成可结算记录。`
    confirmingId.value = null
    confirmationKey.value = null
    await loadOvertime()
  } catch (reason) {
    confirmError.value =
      reason instanceof ApiError
        ? `${reason.message || '确认未完成。'}${reason.requestId ? ` 请求编号：${reason.requestId}` : ''}`
        : '确认未完成，请稍后重试。'
  } finally {
    confirming.value = false
  }
}

onMounted(() => {
  void loadOvertime()
  void loadTravel()
})
</script>

<template>
  <div class="overtime-travel-workspace" data-page="overtime-travel">
    <header class="overtime-travel-workspace__header">
      <div>
        <p class="overtime-travel-workspace__breadcrumb">假勤与薪酬 · 加班与出差</p>
        <h1 class="overtime-travel-workspace__title">加班与出差工作台</h1>
        <p class="overtime-travel-workspace__description">
          加班与出差是两条独立流程，分别拥有自己的列表、筛选和操作权限；两个工作台互不合并表单。
        </p>
      </div>
      <div class="overtime-travel-workspace__header-actions">
        <button
          v-if="canRequestOvertime && canReadOvertime"
          type="button"
          class="overtime-travel-workspace__primary"
          data-testid="open-overtime-request"
          @click="overtimeDrawerOpen = true"
        >
          申请加班
        </button>
        <button
          v-if="canRequestTravel && canReadTravel"
          type="button"
          class="overtime-travel-workspace__secondary"
          data-testid="open-travel-request"
          @click="travelDrawerOpen = true"
        >
          申请出差
        </button>
      </div>
    </header>

    <p v-if="actionMessage" class="overtime-travel-workspace__success" role="status">{{ actionMessage }}</p>
    <p v-if="actionError" class="overtime-travel-workspace__error" role="alert">{{ actionError }}</p>

    <!-- 加班 Section -->
    <section class="overtime-travel-workspace__section" data-testid="overtime-section" aria-labelledby="overtime-section-title">
      <header class="overtime-travel-workspace__section-header">
        <div>
          <h2 id="overtime-section-title">加班</h2>
          <p class="overtime-travel-workspace__muted">
            申报加班、审批确认实际分钟，并逐条选择调休或折算加班费。
          </p>
        </div>
        <button
          type="button"
          class="overtime-travel-workspace__refresh"
          :disabled="overtimeLoading || !canReadOvertime"
          @click="loadOvertime"
        >
          刷新加班
        </button>
      </header>

      <section
        v-if="!canReadOvertime"
        class="overtime-travel-workspace__state"
        data-testid="overtime-forbidden"
        role="status"
      >
        <h3>无权查看加班</h3>
        <p>当前账号没有 <code>time.overtime.read</code> 权限。请通过角色授权申请后重试。</p>
      </section>

      <template v-else>
        <form
          class="overtime-travel-workspace__filters"
          data-testid="overtime-filters"
          aria-label="加班筛选"
          @submit.prevent="loadOvertime"
        >
          <label>
            <span>申请状态</span>
            <select v-model="overtimeStatusFilter" data-testid="overtime-status-filter">
              <option v-for="option in overtimeStatusOptions" :key="option.value" :value="option.value">
                {{ option.label }}
              </option>
            </select>
          </label>
          <label>
            <span>业务日期</span>
            <input v-model="overtimeDateFilter" data-testid="overtime-date-filter" type="date" />
          </label>
          <button type="submit" class="overtime-travel-workspace__secondary" :disabled="overtimeLoading">
            应用筛选
          </button>
        </form>

        <div v-if="overtimeLoading" data-testid="overtime-loading">
          <LoadingState :rows="4" label="正在加载加班工作台" />
        </div>

        <ErrorState
          v-else-if="overtimeError !== ''"
          data-testid="overtime-error"
          title="加班工作台暂时无法加载"
          :description="overtimeError"
          :code="overtimeErrorCode"
          :request-id="overtimeErrorRequestId"
          @retry="loadOvertime"
        />

        <template v-else>
          <p v-if="overtimePartial !== ''" class="overtime-travel-workspace__partial" data-testid="overtime-partial" role="status">
            <strong>部分数据：</strong>{{ overtimePartial }}
          </p>

          <section class="overtime-travel-workspace__card" aria-labelledby="overtime-requests-title">
            <header class="overtime-travel-workspace__card-header">
              <div>
                <h3 id="overtime-requests-title">加班申请</h3>
                <p class="overtime-travel-workspace__muted">
                  政策倍率 {{ overtimePolicy?.multiplier || '—' }}；调休余额
                  <template v-if="timeoff !== null">{{ minutesLabel(timeoff.availableMinutes) }}</template>
                  <template v-else>—</template>。
                </p>
              </div>
              <span class="overtime-travel-workspace__badge">{{ filteredOvertimeRequests.length }} 条</span>
            </header>

            <p
              v-if="overtimeLoaded && filteredOvertimeRequests.length === 0"
              class="overtime-travel-workspace__empty"
              data-testid="overtime-empty"
              role="status"
            >
              暂无加班申请。{{ canRequestOvertime ? '点击“申请加班”提交计划时段。' : '当前账号仅可查看。' }}
            </p>

            <div v-else class="overtime-travel-workspace__scroller">
              <table class="overtime-travel-workspace__table">
                <thead>
                  <tr>
                    <th scope="col">业务日期</th>
                    <th scope="col">申报 / 确认</th>
                    <th scope="col">原因</th>
                    <th scope="col">状态</th>
                    <th scope="col">下一步</th>
                    <th scope="col">操作</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="request in filteredOvertimeRequests" :key="request.id" :data-testid="`overtime-request-${request.id}`">
                    <td>{{ formatBusinessDate(request.businessDate) }}</td>
                    <td>
                      <span class="mono">{{ request.declaredMinutes }}</span>
                      <span class="overtime-travel-workspace__muted"> / {{ request.confirmedMinutes ?? '待确认' }} 分钟</span>
                    </td>
                    <td class="overtime-travel-workspace__wrap">{{ request.reason }}</td>
                    <td>{{ overtimeStatusLabel(request.status) }}</td>
                    <td>{{ overtimeNextStep(request.status) || '—' }}</td>
                    <td>
                      <div v-if="confirmingId === request.id" class="overtime-travel-workspace__confirm">
                        <label>
                          <span>确认分钟</span>
                          <input
                            v-model="confirmingMinutes"
                            :data-testid="`overtime-confirm-minutes-${request.id}`"
                            inputmode="numeric"
                            maxlength="5"
                          />
                        </label>
                        <p v-if="confirmError" class="overtime-travel-workspace__error" role="alert">{{ confirmError }}</p>
                        <div class="overtime-travel-workspace__confirm-actions">
                          <button type="button" class="overtime-travel-workspace__tertiary" :disabled="confirming" @click="cancelConfirm">
                            取消
                          </button>
                          <button
                            type="button"
                            class="overtime-travel-workspace__primary"
                            :disabled="confirming"
                            :data-testid="`overtime-confirm-submit-${request.id}`"
                            @click="submitConfirm(request)"
                          >
                            {{ confirming ? '确认中…' : '确认加班' }}
                          </button>
                        </div>
                      </div>
                      <button
                        v-else-if="canConfirmOvertime && request.status === 'APPROVED'"
                        type="button"
                        class="overtime-travel-workspace__tertiary"
                        :data-testid="`overtime-confirm-${request.id}`"
                        @click="startConfirm(request)"
                      >
                        确认实际分钟
                      </button>
                      <span v-else class="overtime-travel-workspace__muted">—</span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>

          <OvertimeRecordTable
            :records="filteredOvertimeRecords"
            :can-settle="canSettleOvertime"
            :loading="overtimeLoading"
            :error="''"
            @settled="onSettled"
          />

          <section class="overtime-travel-workspace__card" aria-label="加班政策与调休余额">
            <header class="overtime-travel-workspace__card-header">
              <div>
                <h3>适用加班政策</h3>
                <p class="overtime-travel-workspace__muted">
                  倍率来自服务端加班政策；页面不硬编码任何倍率。
                </p>
              </div>
            </header>
            <dl class="overtime-travel-workspace__policy-grid">
              <div>
                <dt>工作日倍率</dt>
                <dd>{{ overtimePolicy?.multiplier || '—' }}</dd>
              </div>
              <div>
                <dt>法定节假日倍率</dt>
                <dd>{{ overtimePolicy?.statutoryHolidayMultiplier || '—' }}</dd>
              </div>
              <div>
                <dt>加班费</dt>
                <dd>{{ overtimePolicy === null ? '—' : overtimePolicy.allowPay ? '允许' : '不允许' }}</dd>
              </div>
              <div>
                <dt>调休</dt>
                <dd>{{ overtimePolicy === null ? '—' : overtimePolicy.allowTimeoff ? '允许' : '不允许' }}</dd>
              </div>
              <div>
                <dt>政策来源</dt>
                <dd class="mono">{{ overtimePolicy?.source || '—' }}</dd>
              </div>
              <div>
                <dt>调休余额</dt>
                <dd>{{ timeoff === null ? '—' : minutesLabel(timeoff.availableMinutes) }}</dd>
              </div>
            </dl>
          </section>
        </template>
      </template>
    </section>

    <!-- 出差 Section -->
    <section class="overtime-travel-workspace__section" data-testid="travel-section" aria-labelledby="travel-section-title">
      <header class="overtime-travel-workspace__section-header">
        <div>
          <h2 id="travel-section-title">出差</h2>
          <p class="overtime-travel-workspace__muted">
            出差申请、审批案例与费用登记分开展示；费用仅登记，不进入薪资或付款。
          </p>
        </div>
        <button
          type="button"
          class="overtime-travel-workspace__refresh"
          :disabled="travelLoading || !canReadTravel"
          @click="loadTravel"
        >
          刷新出差
        </button>
      </header>

      <section
        v-if="!canReadTravel"
        class="overtime-travel-workspace__state"
        data-testid="travel-forbidden"
        role="status"
      >
        <h3>无权查看出差</h3>
        <p>当前账号没有 <code>time.travel.read</code> 权限。请通过角色授权申请后重试。</p>
      </section>

      <template v-else>
        <form
          class="overtime-travel-workspace__filters"
          data-testid="travel-filters"
          aria-label="出差筛选"
          @submit.prevent="loadTravel"
        >
          <label>
            <span>申请状态</span>
            <select v-model="travelStatusFilter" data-testid="travel-status-filter">
              <option v-for="option in travelStatusOptions" :key="option.value" :value="option.value">
                {{ option.label }}
              </option>
            </select>
          </label>
          <label>
            <span>出发日期</span>
            <input v-model="travelDateFilter" data-testid="travel-date-filter" type="date" />
          </label>
          <button type="submit" class="overtime-travel-workspace__secondary" :disabled="travelLoading">
            应用筛选
          </button>
        </form>

        <div v-if="travelLoading" data-testid="travel-loading">
          <LoadingState :rows="4" label="正在加载出差工作台" />
        </div>

        <ErrorState
          v-else-if="travelError !== ''"
          data-testid="travel-error"
          title="出差工作台暂时无法加载"
          :description="travelError"
          :code="travelErrorCode"
          :request-id="travelErrorRequestId"
          @retry="loadTravel"
        />

        <template v-else>
          <p v-if="travelPartial !== ''" class="overtime-travel-workspace__partial" data-testid="travel-partial" role="status">
            <strong>部分数据：</strong>{{ travelPartial }}
          </p>

          <section class="overtime-travel-workspace__card" aria-labelledby="travel-requests-title">
            <header class="overtime-travel-workspace__card-header">
              <div>
                <h3 id="travel-requests-title">出差申请</h3>
                <p class="overtime-travel-workspace__muted">
                  审批通过且可登记费用的出差：{{ claimableCount }} 条。
                </p>
              </div>
              <span class="overtime-travel-workspace__badge">{{ filteredTravelRequests.length }} 条</span>
            </header>

            <p
              v-if="travelLoaded && filteredTravelRequests.length === 0"
              class="overtime-travel-workspace__empty"
              data-testid="travel-empty"
              role="status"
            >
              暂无出差申请。{{ canRequestTravel ? '点击“申请出差”提交行程。' : '当前账号仅可查看。' }}
            </p>

            <div v-else class="overtime-travel-workspace__scroller">
              <table class="overtime-travel-workspace__table">
                <thead>
                  <tr>
                    <th scope="col">出差编号</th>
                    <th scope="col">目的地</th>
                    <th scope="col">日期</th>
                    <th scope="col">事由</th>
                    <th scope="col">审批状态</th>
                    <th scope="col">状态</th>
                    <th scope="col">审批案例</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="request in filteredTravelRequests" :key="request.travelId" :data-testid="`travel-request-${request.travelId}`">
                    <td class="mono">#{{ request.travelId }}</td>
                    <td>{{ request.destination ?? '—' }}</td>
                    <td>{{ formatBusinessDate(request.fromDate) }} 至 {{ formatBusinessDate(request.toDate) }}</td>
                    <td class="overtime-travel-workspace__wrap">{{ request.purpose }}</td>
                    <td>{{ applicationStatusLabel(request.applicationStatus) }}</td>
                    <td>{{ travelStatusLabel(request.status) }}</td>
                    <td>{{ request.caseNo ?? (request.caseId !== null ? `#${request.caseId}` : '—') }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>

          <TravelExpensePanel
            v-if="canReadTravelExpense"
            :requests="travelRequests"
            :expenses="travelExpensesByRequest"
            :currency="currency"
            :can-submit="canManageTravelExpense"
            :loading="travelLoading"
            :error="''"
            @submitted="onExpenseSubmitted"
          />
          <section v-else class="overtime-travel-workspace__state" data-testid="travel-expense-forbidden" role="status">
            <h3>无权查看出差费用</h3>
            <p>当前账号没有 <code>time.travel.expense</code> 权限；出差申请仍可查看。</p>
          </section>

          <section class="overtime-travel-workspace__card" aria-label="差旅政策">
            <header class="overtime-travel-workspace__card-header">
              <div>
                <h3>差旅政策</h3>
                <p class="overtime-travel-workspace__muted">
                  政策版本来自服务端；金额上限均为整数最小货币单位。
                </p>
              </div>
            </header>
            <p v-if="travelPolicies.length === 0" class="overtime-travel-workspace__empty" role="status">
              服务端尚未返回差旅政策版本。
            </p>
            <div v-else class="overtime-travel-workspace__scroller">
              <table class="overtime-travel-workspace__table">
                <thead>
                  <tr>
                    <th scope="col">城市档位</th>
                    <th scope="col">住宿上限（分）</th>
                    <th scope="col">交通上限（分）</th>
                    <th scope="col">生效日</th>
                    <th scope="col">版本</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="policy in travelPolicies" :key="policy.id">
                    <td>{{ cityTierLabel(policy.cityTier) }}</td>
                    <td class="mono">{{ policy.hotelCapCents }}</td>
                    <td class="mono">{{ policy.transportCapCents }}</td>
                    <td>{{ formatBusinessDate(policy.effectiveFrom) }}</td>
                    <td>v{{ policy.version }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>
        </template>
      </template>
    </section>

    <OvertimeRequestDrawer
      v-model="overtimeDrawerOpen"
      :policy="overtimePolicy"
      @submitted="onOvertimeSubmitted"
    />
    <TravelRequestDrawer
      v-model="travelDrawerOpen"
      :currency="currency"
      @submitted="onTravelSubmitted"
    />
  </div>
</template>

<style scoped>
.overtime-travel-workspace {
  display: grid;
  gap: 18px;
  padding: 20px;
}

.overtime-travel-workspace__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 18px;
}

.overtime-travel-workspace__breadcrumb {
  margin: 0 0 4px;
  color: #64748b;
  font-size: 11px;
}

.overtime-travel-workspace__title {
  margin: 0 0 5px;
  font-size: 22px;
}

.overtime-travel-workspace__description {
  margin: 0;
  max-width: 760px;
  color: #475569;
  font-size: 12px;
  line-height: 1.6;
}

.overtime-travel-workspace__header-actions {
  display: flex;
  gap: 8px;
}

.overtime-travel-workspace__section {
  display: grid;
  gap: 14px;
  padding: 16px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
}

.overtime-travel-workspace__section-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 14px;
}

.overtime-travel-workspace__section-header h2 {
  margin: 0;
  font-size: 18px;
}

.overtime-travel-workspace__muted {
  margin: 3px 0 0;
  color: #64748b;
  font-size: 11.5px;
  line-height: 1.5;
}

.overtime-travel-workspace__filters {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  align-items: flex-end;
  padding: 12px;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
}

.overtime-travel-workspace__filters label {
  display: flex;
  flex-direction: column;
  gap: 3px;
  font-size: 11px;
}

.overtime-travel-workspace__filters select,
.overtime-travel-workspace__filters input,
.overtime-travel-workspace__confirm input {
  min-width: 150px;
  padding: 6px 8px;
  font: inherit;
  font-size: 11.5px;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
}

.overtime-travel-workspace__card {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
}

.overtime-travel-workspace__card-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  padding: 12px 14px;
  border-bottom: 1px solid #e2e8f0;
}

.overtime-travel-workspace__card-header h3 {
  margin: 0;
  font-size: 13.5px;
}

.overtime-travel-workspace__badge {
  padding: 3px 8px;
  color: #1d4ed8;
  font-size: 10.5px;
  background: #eff6ff;
  border-radius: 999px;
}

.overtime-travel-workspace__scroller {
  overflow-x: auto;
}

.overtime-travel-workspace__table {
  width: 100%;
  font-size: 11.5px;
  border-collapse: collapse;
}

.overtime-travel-workspace__table th,
.overtime-travel-workspace__table td {
  padding: 9px 12px;
  text-align: left;
  vertical-align: top;
  border-bottom: 1px solid #f1f5f9;
}

.overtime-travel-workspace__table th {
  color: #64748b;
  font-weight: 500;
  background: #f8fafc;
}

.overtime-travel-workspace__wrap {
  max-width: 280px;
  white-space: normal;
  word-break: break-word;
}

.overtime-travel-workspace__empty {
  margin: 0;
  padding: 16px;
  color: #64748b;
  font-size: 11.5px;
}

.overtime-travel-workspace__state {
  padding: 18px;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
}

.overtime-travel-workspace__state h3 {
  margin: 0 0 4px;
  font-size: 14px;
}

.overtime-travel-workspace__state p {
  margin: 0;
  color: #64748b;
  font-size: 11.5px;
}

.overtime-travel-workspace__partial {
  margin: 0;
  padding: 9px 12px;
  color: #92400e;
  font-size: 11px;
  background: #fffbeb;
  border: 1px solid #fcd34d;
  border-radius: 8px;
}

.overtime-travel-workspace__success {
  margin: 0;
  padding: 9px 12px;
  color: #065f46;
  font-size: 11.5px;
  background: #ecfdf5;
  border: 1px solid #a7f3d0;
  border-radius: 8px;
}

.overtime-travel-workspace__error {
  margin: 0;
  padding: 9px 12px;
  color: #991b1b;
  font-size: 11.5px;
  background: #fef2f2;
  border: 1px solid #fecaca;
  border-radius: 8px;
}

.overtime-travel-workspace__policy-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 10px 16px;
  padding: 14px;
}

.overtime-travel-workspace__policy-grid dt {
  color: #64748b;
  font-size: 10.5px;
}

.overtime-travel-workspace__policy-grid dd {
  margin: 2px 0 0;
  font-size: 12.5px;
}

.overtime-travel-workspace__confirm {
  display: grid;
  gap: 6px;
}

.overtime-travel-workspace__confirm label {
  display: flex;
  flex-direction: column;
  gap: 3px;
  font-size: 10.5px;
}

.overtime-travel-workspace__confirm-actions {
  display: flex;
  gap: 6px;
}

.overtime-travel-workspace__primary,
.overtime-travel-workspace__secondary,
.overtime-travel-workspace__tertiary,
.overtime-travel-workspace__refresh {
  padding: 7px 12px;
  font-size: 11.5px;
  border-radius: 6px;
  cursor: pointer;
}

.overtime-travel-workspace__primary {
  color: #fff;
  background: #1d4ed8;
  border: 1px solid #1d4ed8;
}

.overtime-travel-workspace__secondary,
.overtime-travel-workspace__refresh {
  color: #334155;
  background: #fff;
  border: 1px solid #cbd5e1;
}

.overtime-travel-workspace__tertiary {
  color: #1d4ed8;
  background: transparent;
  border: 1px solid #bfdbfe;
}

.overtime-travel-workspace__primary:disabled,
.overtime-travel-workspace__secondary:disabled,
.overtime-travel-workspace__tertiary:disabled,
.overtime-travel-workspace__refresh:disabled {
  cursor: not-allowed;
  opacity: 0.55;
}

.mono {
  font-family: ui-monospace, monospace;
}

@media (max-width: 720px) {
  .overtime-travel-workspace__header,
  .overtime-travel-workspace__section-header {
    flex-direction: column;
  }

  .overtime-travel-workspace__policy-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
</style>
