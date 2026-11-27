<script setup lang="ts">
import DataTable, { type DataTableColumn } from '@/components/DataTable.vue'
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ApiError, errorCopy } from '@/api/errors'
import { useSessionStore } from '@/stores/session'
import { formatBusinessDate } from '@/utils/format'
import ErrorState from '@/components/ErrorState.vue'
import LoadingState from '@/components/LoadingState.vue'
import OvertimeRequestDrawer from '../components/OvertimeRequestDrawer.vue'
import OvertimePolicyPanel from '../components/OvertimePolicyPanel.vue'
import OvertimeConfirmPanel from '../components/OvertimeConfirmPanel.vue'
import OvertimeTimeoffPanel from '../components/OvertimeTimeoffPanel.vue'
import OvertimeSettlementPanel from '../components/OvertimeSettlementPanel.vue'
import {
  formatMinutes,
  getOvertimePolicies,
  getOvertimeRequests,
  getOvertimeSettlements,
  getTimeoffBalances,
  overtimeStatusLabel,
  type OvertimePoliciesResult,
  type OvertimeRequest,
  type OvertimeRequestProjection,
  type OvertimeSettlementsResult,
  type TimeoffBalancesResult,
} from '../api'

defineOptions({ name: 'OvertimeWorkspacePage' })

type TabName = 'mine' | 'requests' | 'policies' | 'confirm' | 'timeoff' | 'settlement'

const TABS: Array<{ name: TabName; label: string }> = [
  { name: 'mine', label: '我的加班' },
  { name: 'requests', label: '加班台账' },
  { name: 'policies', label: '加班规则' },
  { name: 'confirm', label: '加班确认' },
  { name: 'timeoff', label: '调休余额' },
  { name: 'settlement', label: '结算记录' },
]


const MINE_REQUEST_COLUMNS: DataTableColumn[] = [
  { key: 'businessDateLabel', label: '业务日期' }, { key: 'minutesLabel', label: '申报 / 确认' },
  { key: 'statusLabel', label: '状态' }, { key: 'reason', label: '原因' },
  { key: 'policyVersionLabel', label: '规则版本' }, { key: 'settlementLabel', label: '结算' },
]
const mineRequestRows = computed<Array<Record<string, unknown>>>(() => mineRequests.value.map((request) => ({
  ...request,
  businessDateLabel: formatBusinessDate(request.businessDate),
  minutesLabel: `${request.declaredMinutes} / ${request.confirmedMinutes ?? '待确认'} 分钟`,
  statusLabel: overtimeStatusLabel(request.status),
  policyVersionLabel: request.policyVersionId !== null ? `v${String(request.policySnapshot?.version ?? request.policyVersionId)}` : '确认时快照',
  settlementLabel: request.settlementMethod === null ? '待结算' : request.settlementMethod === 'PAY' ? '折算加班费' : '调休',
})))
const LEDGER_REQUEST_COLUMNS: DataTableColumn[] = [
  { key: 'employeeLabel', label: '员工' }, { key: 'businessDateLabel', label: '业务日期' },
  { key: 'minutesLabel', label: '申报 / 确认' }, { key: 'statusLabel', label: '状态' },
  { key: 'reason', label: '原因' },
]
const ledgerRequestRows = computed<Array<Record<string, unknown>>>(() => ledgerRequests.value.map((request) => ({
  ...request,
  employeeLabel: request.employeeName ?? request.employeeNo ?? `#${request.employmentId}`,
  businessDateLabel: formatBusinessDate(request.businessDate),
  minutesLabel: `${request.declaredMinutes} / ${request.confirmedMinutes ?? '待确认'} 分钟`,
  statusLabel: overtimeStatusLabel(request.status),
})))
const SETTLEMENT_COLUMNS: DataTableColumn[] = [
  { key: 'businessDateLabel', label: '业务日期' }, { key: 'minutesLabel', label: '加班分钟' },
  { key: 'methodLabel', label: '结算方式' }, { key: 'reasonLabel', label: '原因' },
  { key: 'freezeLabel', label: '工资期间' },
]
const settlementRows = computed<Array<Record<string, unknown>>>(() =>
  (settlements.value?.items ?? []).map((row) => ({
    ...row,
    businessDateLabel: formatBusinessDate(row.businessDate),
    minutesLabel: formatMinutes(row.minutes),
    methodLabel: row.method === 'PAY' ? '折算加班费' : '调休',
    reasonLabel: row.reason ?? '—',
    freezeLabel: row.payrollPeriodFrozen === true ? '已冻结（仅调休）' : '未冻结',
  })),
)
const session = useSessionStore()
const route = useRoute()
const router = useRouter()

const canRead = computed(() => session.can('time.overtime.read'))
const canRequest = computed(() => session.can('time.overtime.request'))
const canConfirm = computed(() => session.can('time.overtime.confirm'))
const canSettle = computed(() => session.can('time.overtime.settle'))
const canManagePolicy = computed(() => session.can('time.overtime.manage'))
const canReadTimeoff = computed(() => session.can('time.timeoff.read'))

const tab = ref<TabName>('mine')
const loading = ref(false)
const error = ref('')
const errorCode = ref('')
const errorRequestId = ref<string | null>(null)
const partial = ref('')
const policies = ref<OvertimePoliciesResult | null>(null)
const selfRequests = ref<OvertimeRequestProjection | null>(null)
const teamRequests = ref<OvertimeRequestProjection | null>(null)
const settlements = ref<OvertimeSettlementsResult | null>(null)
const timeoff = ref<TimeoffBalancesResult | null>(null)
const drawerOpen = ref(false)
const actionMessage = ref('')
const actionError = ref('')

const currentPolicy = computed(() => policies.value?.current ?? null)
const hasPolicy = computed(() => currentPolicy.value !== null)
const unconfigured = computed(() => policies.value !== null && policies.value.items.length === 0 && policies.value.current === null && !loading.value)
const visibleTabs = computed(() =>
  TABS.filter((candidate) => {
    if (candidate.name === 'policies') return canRead.value && canManagePolicy.value
    if (candidate.name === 'confirm') return canConfirm.value
    if (candidate.name === 'settlement') return canSettle.value
    if (candidate.name === 'timeoff') return canReadTimeoff.value
    if (candidate.name === 'requests') return canRead.value
    return canRead.value
  }),
)

const teamView = computed(() => canConfirm.value || canManagePolicy.value || canSettle.value)
const mineRequests = computed(() => selfRequests.value?.items ?? [])
const ledgerRequests = computed(() => (teamView.value ? teamRequests.value?.items ?? [] : mineRequests.value))
const pendingConfirm = computed(() =>
  ledgerRequests.value.filter((request) => request.status === 'APPROVED'),
)
const confirmedRows = computed(() =>
  ledgerRequests.value.filter((request) =>
    request.status === 'CONFIRMED'
    && request.overtimeRecordId !== null
    && request.settlementMethod === null,
  ),
)
const frozenRows = computed(() => settlements.value?.items.filter((row) => row.payrollPeriodFrozen === true) ?? [])

function normaliseTab(value: unknown): TabName {
  return TABS.some((candidate) => candidate.name === value) ? (value as TabName) : 'mine'
}

function syncTab(): void {
  const next = normaliseTab(route.query.tab)
  tab.value = visibleTabs.value.some((candidate) => candidate.name === next) ? next : 'mine'
  // An invalid query must be normalised in the URL as well as in local state;
  // otherwise a copied link keeps an unsupported tab forever.
  if (route.query.tab !== tab.value) {
    void router.replace({ query: { ...route.query, tab: tab.value } }).catch(() => undefined)
  }
}

watch(
  () => route.query.tab,
  syncTab,
  { immediate: true },
)

watch(
  tab,
  (next) => {
    if (route.query.tab === next) return
    // Tab state is presentation-only: replace the query without pushing URLs
    // into history or touching any route registration.
    void router.replace({ query: { ...route.query, tab: next } }).catch(() => undefined)
  },
)

function captureError(reason: unknown): void {
  if (reason instanceof ApiError) {
    error.value = errorCopy(reason)
    errorCode.value = reason.code
    errorRequestId.value = reason.requestId
    return
  }
  error.value = errorCopy(reason)
  errorCode.value = 'UNKNOWN'
  errorRequestId.value = null
}

async function load(): Promise<void> {
  if (!canRead.value) {
    return
  }

  loading.value = true
  error.value = ''
  errorCode.value = ''
  errorRequestId.value = null
  partial.value = ''

  const failures: string[] = []
  const partialReasons: string[] = []
  const [policyResult, requestResult, timeoffResult, settlementResult] = await Promise.all([
    getOvertimePolicies().then((value) => ({ ok: true as const, value })).catch((reason: unknown) => ({ ok: false as const, reason })),
    Promise.all([
      getOvertimeRequests().then((value) => ({ ok: true as const, value })).catch((reason: unknown) => ({ ok: false as const, reason })),
      teamView.value
        ? getOvertimeRequests({ view: 'team' }).then((value) => ({ ok: true as const, value })).catch((reason: unknown) => ({ ok: false as const, reason }))
        : Promise.resolve({ ok: true as const, value: null }),
    ]),
    canReadTimeoff.value
      ? getTimeoffBalances().then((value) => ({ ok: true as const, value })).catch((reason: unknown) => ({ ok: false as const, reason }))
      : Promise.resolve({ ok: true as const, value: null }),
    canSettle.value
      ? getOvertimeSettlements().then((value) => ({ ok: true as const, value })).catch((reason: unknown) => ({ ok: false as const, reason }))
      : Promise.resolve({ ok: true as const, value: null }),
  ])

  if (policyResult.ok) policies.value = policyResult.value
  else {
    policies.value = null
    failures.push('加班规则')
  }

  // Promise.all above should always produce a pair, but a malformed/failed
  // transport must not crash the whole workspace. Degrade to both ledgers
  // unavailable and surface a partial/error state.
  const requestPair: unknown = requestResult
  let selfRequestFailure: unknown = null
  if (!Array.isArray(requestPair) || requestPair.length !== 2) {
    selfRequests.value = null
    teamRequests.value = null
    selfRequestFailure = new Error('Overtime request projections are unavailable.')
    failures.push('本人加班申请')
  } else {
    const [selfRequestResult, teamRequestResult] = requestPair as [
      { ok: true; value: OvertimeRequestProjection | null } | { ok: false; reason: unknown },
      { ok: true; value: OvertimeRequestProjection | null } | { ok: false; reason: unknown },
    ]
    selfRequests.value = selfRequestResult.ok ? selfRequestResult.value : null
    teamRequests.value = teamRequestResult.ok ? teamRequestResult.value : null
    if (!selfRequestResult.ok) {
      selfRequestFailure = selfRequestResult.reason
      failures.push('本人加班申请')
    } else if (selfRequestResult.value?.partial) {
      partialReasons.push('本人加班申请：' + (selfRequestResult.value.partialReason ?? '投影为部分数据'))
    }
    if (teamView.value && !teamRequestResult.ok) failures.push('团队加班台账')
    else if (teamRequestResult.ok && teamRequestResult.value?.partial) {
      partialReasons.push('团队加班台账：' + (teamRequestResult.value.partialReason ?? '投影为部分数据'))
    }
  }

  if (timeoffResult.ok) {
    timeoff.value = timeoffResult.value
    if (timeoffResult.value?.partial) partialReasons.push(timeoffResult.value.partialReason ?? '调休余额投影为部分数据')
  } else {
    timeoff.value = null
    failures.push('调休余额')
  }

  if (settlementResult.ok) {
    settlements.value = settlementResult.value
  } else {
    settlements.value = null
    failures.push('结算记录')
  }

  partial.value = [...failures, ...partialReasons].join('、')

  if (!policyResult.ok && selfRequestFailure !== null) {
    captureError(policyResult.reason)
  } else if (selfRequestFailure !== null) {
    captureError(selfRequestFailure)
  }

  loading.value = false
}

function openDrawer(): void {
  if (!canRequest.value || !hasPolicy.value) return
  actionMessage.value = ''
  actionError.value = ''
  drawerOpen.value = true
}

function onSubmitted(request: OvertimeRequest): void {
  actionMessage.value = `加班申请已提交（${overtimeStatusLabel(request.status)}），编号 #${request.id}。`
  actionError.value = ''
  void load()
}

function onPolicyChanged(message: string): void {
  actionMessage.value = message
  actionError.value = ''
  void load()
}

function onSettled(message: string): void {
  actionMessage.value = message
  actionError.value = ''
  void load()
}

function onActionError(message: string): void {
  actionError.value = message
}

onMounted(() => {
  syncTab()
  void load()
})
</script>

<template>
  <div class="overtime-workspace" data-page="overtime">
    <header class="overtime-workspace__header">
      <div>
        <p class="overtime-workspace__breadcrumb">假勤与薪酬 · 加班管理</p>
        <h1 class="overtime-workspace__title">加班工作台</h1>
        <p class="overtime-workspace__description">
          申请、台账、规则、确认、调休与结算统一入口。规则使用公司生效版本；确认时快照；每条加班只结算一次。
        </p>
      </div>
      <div class="overtime-workspace__actions">
        <button
          v-if="canRequest && hasPolicy"
          type="button"
          class="overtime-workspace__primary"
          data-testid="open-overtime-request"
          @click="openDrawer"
        >
          申请加班
        </button>
        <button type="button" class="overtime-workspace__secondary" :disabled="loading || !canRead" @click="load">
          刷新
        </button>
      </div>
    </header>

    <p v-if="actionMessage" class="overtime-workspace__success" role="status" data-testid="overtime-success">
      {{ actionMessage }}
    </p>
    <p v-if="actionError" class="overtime-workspace__error" role="alert" data-testid="overtime-action-error">
      {{ actionError }}
    </p>

    <section v-if="!canRead" class="state-panel overtime-workspace__forbidden" data-testid="overtime-forbidden" role="status">
      <h2 class="state-panel__title">无权查看加班</h2>
      <p class="state-panel__body">当前账号没有 <code>time.overtime.read</code> 权限。请通过角色授权申请后重试。</p>
    </section>

    <template v-else>
      <nav class="overtime-workspace__tabs" aria-label="加班工作区" data-testid="overtime-tabs">
        <button
          v-for="candidate in visibleTabs"
          :key="candidate.name"
          type="button"
          class="overtime-workspace__tab"
          :class="{ 'overtime-workspace__tab--active': tab === candidate.name }"
          :aria-current="tab === candidate.name ? 'page' : undefined"
          :data-testid="`overtime-tab-${candidate.name}`"
          @click="tab = candidate.name"
        >
          {{ candidate.label }}
        </button>
      </nav>

      <div v-if="loading" data-testid="overtime-loading">
        <LoadingState :rows="6" label="正在加载加班工作台" />
      </div>

      <div v-else-if="error !== ''" data-testid="overtime-error">
        <ErrorState
          title="加班工作台暂时无法加载"
          :description="error"
          :code="errorCode"
          :request-id="errorRequestId"
          @retry="load"
        />
      </div>

      <template v-else>
        <p v-if="partial !== ''" class="overtime-workspace__partial" role="status" data-testid="overtime-partial">
          部分数据暂不可用：{{ partial }}。其余区域仍展示服务端返回的真实数据。
        </p>

        <section
          v-if="unconfigured"
          class="state-panel overtime-workspace__unconfigured"
          data-testid="overtime-unconfigured"
          role="status"
        >
          <h2 class="state-panel__title">尚未配置公司加班规则</h2>
          <p class="state-panel__body">
            服务端没有返回任何公司加班规则版本，页面不会套用内置默认值。
            <template v-if="canManagePolicy">请由有管理权限的账号在“加班规则”页签新建版本。</template>
            <template v-else>请联系人力资源配置公司加班规则后再申请或确认。</template>
          </p>
        </section>

        <section v-if="tab === 'mine'" class="overtime-workspace__panel" data-testid="overtime-panel-mine">
          <header class="overtime-workspace__panel-header">
            <div>
              <h2>我的加班</h2>
              <p>本人申请与审批/确认进度；申报分钟和确认分钟分别保存。</p>
            </div>
            <span class="overtime-workspace__badge">{{ mineRequests.length }} 条</span>
          </header>

          <dl class="overtime-workspace__policy-summary">
            <div>
              <dt>当前规则版本</dt>
              <dd>{{ currentPolicy !== null && currentPolicy.version !== null ? `v${currentPolicy.version}` : '未配置' }}</dd>
            </div>
            <div>
              <dt>工作日倍率</dt>
              <dd class="mono">{{ currentPolicy?.multiplier || '—' }}</dd>
            </div>
            <div>
              <dt>法定节假日倍率</dt>
              <dd class="mono">{{ currentPolicy?.statutoryHolidayMultiplier || '—' }}</dd>
            </div>
            <div>
              <dt>补偿方式</dt>
              <dd>
                {{ currentPolicy === null ? '—' : currentPolicy.allowPay ? '允许加班费' : '' }}
                {{ currentPolicy === null ? '' : currentPolicy.allowTimeoff ? '允许调休' : '' }}
              </dd>
            </div>
          </dl>

          <p v-if="mineRequests.length === 0" class="overtime-workspace__empty" data-testid="overtime-mine-empty" role="status">
            暂无本人加班申请。{{ canRequest && hasPolicy ? '点击“申请加班”提交计划时段。' : '当前账号仅可查看。' }}
          </p>

          <DataTable
            v-else
            :columns="MINE_REQUEST_COLUMNS"
            :rows="mineRequestRows"
            :total="mineRequestRows.length"
            :page="1"
            :page-size="Math.max(1, mineRequestRows.length)"
            row-key="id"
            data-testid="overtime-mine-table"
            empty-title="暂无本人加班申请"
          />
        </section>

        <section v-if="tab === 'requests'" class="overtime-workspace__panel" data-testid="overtime-panel-requests">
          <header class="overtime-workspace__panel-header">
            <div>
              <h2>加班台账</h2>
              <p>按授权范围查看加班申请；SELF 仅本人，MANAGED 仅直属，COMPANY 全量。</p>
            </div>
            <span class="overtime-workspace__badge">{{ ledgerRequests.length }} 条</span>
          </header>

          <p v-if="ledgerRequests.length === 0" class="overtime-workspace__empty" role="status">
            当前授权范围内暂无可显示的加班申请。
          </p>
          <DataTable
            v-else
            :columns="LEDGER_REQUEST_COLUMNS"
            :rows="ledgerRequestRows"
            :total="ledgerRequestRows.length"
            :page="1"
            :page-size="Math.max(1, ledgerRequestRows.length)"
            row-key="id"
            data-testid="overtime-ledger-table"
            empty-title="当前授权范围内暂无可显示的加班申请"
          />
        </section>

        <OvertimePolicyPanel
          v-if="tab === 'policies' && canManagePolicy"
          :policies="policies"
          :can-manage="canManagePolicy"
          @changed="onPolicyChanged"
          @error="onActionError"
        />

        <OvertimeConfirmPanel
          v-if="tab === 'confirm'"
          :requests="pendingConfirm"
          :can-confirm="canConfirm"
          :policy-configured="hasPolicy"
          @confirmed="onPolicyChanged"
          @error="onActionError"
        />

        <OvertimeTimeoffPanel
          v-if="tab === 'timeoff'"
          :timeoff="timeoff"
          :can-read="canReadTimeoff"
        />

        <section v-if="tab === 'settlement'" class="overtime-workspace__panel" data-testid="overtime-panel-settlement">
          <header class="overtime-workspace__panel-header">
            <div>
              <h2>结算记录</h2>
              <p>每条加班只能结算一次：折算加班费或调休。冻结工资期间仅可选择调休。</p>
            </div>
            <span class="overtime-workspace__badge">{{ settlements?.total ?? 0 }} 条</span>
          </header>

          <p v-if="!canSettle" class="overtime-workspace__empty" role="status">当前账号没有加班结算权限。</p>
          <p v-else-if="frozenRows.length > 0" class="overtime-workspace__freeze" data-testid="overtime-frozen-notice" role="status">
            该工资期间已冻结：仅可调休，不再计薪。折算加班费动作由服务端返回 409。
          </p>

          <p v-if="canSettle && (settlements?.items.length ?? 0) === 0" class="overtime-workspace__empty" role="status">
            暂无已结算记录。
          </p>
          <DataTable
            v-else-if="canSettle"
            :columns="SETTLEMENT_COLUMNS"
            :rows="settlementRows"
            :total="settlementRows.length"
            :page="1"
            :page-size="Math.max(1, settlementRows.length)"
            row-key="settlementId"
            data-testid="overtime-settlement-table"
            empty-title="暂无已结算记录"
          />

          <OvertimeSettlementPanel
            v-if="canSettle"
            :requests="confirmedRows"
            :can-settle="canSettle"
            @settled="onSettled"
            @error="onActionError"
          />
        </section>
      </template>
    </template>

    <OvertimeRequestDrawer
      v-model="drawerOpen"
      :policy="currentPolicy"
      @submitted="onSubmitted"
    />
  </div>
</template>

<style scoped>
.overtime-workspace {
  display: flex;
  flex-direction: column;
  gap: 14px;
  padding: 20px;
}

.overtime-workspace__header {
  display: flex;
  justify-content: space-between;
  gap: 16px;
  align-items: flex-start;
}

.overtime-workspace__breadcrumb {
  margin: 0 0 4px;
  color: #64748b;
  font-size: 11px;
}

.overtime-workspace__title {
  margin: 0 0 5px;
  font-size: 22px;
}

.overtime-workspace__description {
  max-width: 780px;
  margin: 0;
  color: #475569;
  font-size: 12px;
  line-height: 1.6;
}

.overtime-workspace__actions {
  display: flex;
  gap: 8px;
}

.overtime-workspace__primary,
.overtime-workspace__secondary {
  padding: 6px 12px;
  font-size: 12px;
  border-radius: 6px;
  cursor: pointer;
}

.overtime-workspace__primary {
  color: #fff;
  background: #1d4ed8;
  border: 1px solid #1d4ed8;
}

.overtime-workspace__secondary {
  color: #334155;
  background: #fff;
  border: 1px solid #cbd5e1;
}

.overtime-workspace__primary:disabled,
.overtime-workspace__secondary:disabled {
  cursor: not-allowed;
  opacity: 0.55;
}

.overtime-workspace__tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  padding: 4px;
  background: #f1f5f9;
  border-radius: 9px;
}

.overtime-workspace__tab {
  padding: 7px 12px;
  color: #475569;
  font-size: 12px;
  background: transparent;
  border: 0;
  border-radius: 6px;
  cursor: pointer;
}

.overtime-workspace__tab--active {
  color: #1e3a8a;
  font-weight: 600;
  background: #fff;
  box-shadow: 0 1px 3px rgb(15 23 42 / 12%);
}

.overtime-workspace__panel {
  display: grid;
  gap: 12px;
  padding: 16px;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
}

.overtime-workspace__panel-header {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  align-items: flex-start;
}

.overtime-workspace__panel-header h2 {
  margin: 0;
  font-size: 16px;
}

.overtime-workspace__panel-header p {
  margin: 4px 0 0;
  color: #64748b;
  font-size: 11.5px;
}

.overtime-workspace__badge {
  align-self: flex-start;
  padding: 3px 8px;
  color: #1d4ed8;
  font-size: 11px;
  background: #eff6ff;
  border-radius: 999px;
}

.overtime-workspace__policy-summary {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: 10px;
  margin: 0;
  padding: 12px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
}

.overtime-workspace__policy-summary dt {
  color: #64748b;
  font-size: 10.5px;
}

.overtime-workspace__policy-summary dd {
  margin: 3px 0 0;
  color: #0f172a;
  font-size: 13px;
}

.overtime-workspace__table-wrap {
  overflow-x: auto;
}

.overtime-workspace__table {
  width: 100%;
  font-size: 12px;
  border-collapse: collapse;
}

.overtime-workspace__table th,
.overtime-workspace__table td {
  padding: 9px 10px;
  text-align: left;
  vertical-align: top;
  border-bottom: 1px solid #f1f5f9;
}

.overtime-workspace__table th {
  color: #64748b;
  font-weight: 500;
  background: #f8fafc;
}

.overtime-workspace__empty {
  margin: 0;
  padding: 16px;
  color: #64748b;
  font-size: 12px;
  background: #f8fafc;
  border-radius: 8px;
}

.overtime-workspace__muted {
  color: #64748b;
  font-size: 11px;
}

.overtime-workspace__success,
.overtime-workspace__error,
.overtime-workspace__partial,
.overtime-workspace__freeze {
  margin: 0;
  padding: 9px 12px;
  font-size: 12px;
  border-radius: 8px;
}

.overtime-workspace__success {
  color: #065f46;
  background: #ecfdf5;
  border: 1px solid #a7f3d0;
}

.overtime-workspace__error {
  color: #991b1b;
  background: #fef2f2;
  border: 1px solid #fecaca;
}

.overtime-workspace__partial {
  color: #92400e;
  background: #fffbeb;
  border: 1px solid #fde68a;
}

.overtime-workspace__freeze {
  color: #92400e;
  background: #fffbeb;
  border: 1px solid #fde68a;
}

.overtime-workspace__forbidden,
.overtime-workspace__unconfigured {
  padding: 18px;
  background: #fff;
  border: 1px dashed #cbd5e1;
  border-radius: 10px;
}

.overtime-workspace__inline-form {
  display: grid;
  gap: 6px;
  min-width: 200px;
}

.overtime-workspace__inline-form label {
  display: flex;
  flex-direction: column;
  gap: 3px;
  font-size: 10.5px;
}

.overtime-workspace__inline-form input {
  padding: 5px 7px;
  font: inherit;
  font-size: 11px;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
}

.mono {
  font-family: ui-monospace, monospace;
}
</style>
