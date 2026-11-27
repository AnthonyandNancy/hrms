<script setup lang="ts">
import { DISPLAY_CODES } from '@/shared/display/dictionary'
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ApiError, errorCopy } from '@/api/errors'
import { usePageError } from '@/composables/usePageError'
import { useSessionStore } from '@/stores/session'
import LoadingState from '@/components/LoadingState.vue'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import LeaveBalanceCards from '../components/LeaveBalanceCards.vue'
import LeaveRequestDrawer from '../components/LeaveRequestDrawer.vue'
import LeaveRequestList from '../components/LeaveRequestList.vue'
import LeaveLedgerPanel from '../components/LeaveLedgerPanel.vue'
import LeaveTypesPanel from '../components/LeaveTypesPanel.vue'
import LeavePoliciesPanel from '../components/LeavePoliciesPanel.vue'
import LeaveBalancePanel from '../components/LeaveBalancePanel.vue'
import LeaveCalendarPanel from '../components/LeaveCalendarPanel.vue'
import {
  formatLeaveQuantity,
  getLeaveBalances,
  getLeavePolicies,
  getLeaveRequests,
  getLeaveTypes,
  type LeaveBalance,
  type LeavePolicy,
  type LeaveRequestItem,
  type LeaveTypeOption,
} from '../api'

defineOptions({ name: 'LeaveWorkspacePage' })

type TabKey = 'mine' | 'requests' | 'types' | 'policies' | 'balances' | 'calendar' | 'reports'
type PageState = 'loading' | 'empty' | 'unconfigured' | 'forbidden' | 'error' | 'success'

const session = useSessionStore()
const pageErrorState = usePageError()

const canRead = computed(() => session.can('time.leave.read'))
const canRequest = computed(() => session.can('time.leave.request'))
const canCancel = computed(() => session.can('time.leave.request.cancel'))
const canReadLedger = computed(() => session.can('time.leave.ledger.read'))
const canManageTypes = computed(() => session.can('time.leave.type.manage'))
const canManagePolicies = computed(() => session.can('time.leave.policy.manage'))
const canReadPolicies = computed(() => session.can('time.leave.policy.read') || canManagePolicies.value)
const canOnBehalf = computed(() => session.can('time.leave.request.on-behalf'))
const canAdminRevoke = computed(() => session.can('time.leave.admin'))
const canAdmin = computed(() => session.can('time.leave.admin'))

const TABS: Array<{ key: TabKey; label: string; capability?: string }> = [
  { key: 'mine', label: '我的请假' },
  { key: 'requests', label: '请假台账', capability: 'time.leave.admin' },
  { key: 'types', label: '假期类型', capability: 'time.leave.type.manage' },
  { key: 'policies', label: '假期规则' },
  { key: 'balances', label: '假期余额', capability: 'time.leave.ledger.read' },
  { key: 'calendar', label: '假期日历', capability: 'time.leave.read' },
  { key: 'reports', label: '假期汇总', capability: 'time.leave.report.read' },
]

const validTabs = new Set(TABS.map((tab) => tab.key))
const route = useRoute()
const router = useRouter()
const activeTab = ref<TabKey>('mine')

const balances = ref<LeaveBalance[]>([])
const types = ref<LeaveTypeOption[]>([])
const requests = ref<LeaveRequestItem[]>([])
const policies = ref<LeavePolicy[]>([])
const loading = ref(false)
const loaded = ref(false)
const pageError = ref('')
const pageErrorDetails = ref<{ code: string; requestId: string | null }>({ code: '', requestId: null })
const partialSections = ref<string[]>([])
const actionMessage = ref('')
const drawerOpen = ref(false)
const drawerMode = ref<'self' | 'on-behalf'>('self')
const selectedEmploymentId = ref<number | null>(null)

const visibleTabs = computed(() =>
  TABS.filter((tab) => {
    if (tab.key === 'policies') return canReadPolicies.value
    return tab.capability === undefined || session.can(tab.capability)
  }),
)

const activeTypes = computed(() => types.value.filter((type) => type.status === DISPLAY_CODES.userStatus.active))
const asOfDates = computed(() =>
  [...new Set(balances.value.map((balance) => balance.asOf).filter((value) => value !== ''))],
)
const asOfLabel = computed(() => {
  if (asOfDates.value.length === 0) return '服务端未返回截至日期'
  if (asOfDates.value.length === 1) return asOfDates.value[0] ?? ''
  return `多个截至日期：${asOfDates.value.join(' / ')}`
})
const balanceSummary = computed(() => {
  const first = balances.value[0]
  if (first === undefined) return ''
  return `${first.leaveTypeName} ${formatLeaveQuantity(first.available, first.unit)} 可用`
})

const showUnconfigured = computed(
  () => loaded.value && !loading.value && pageError.value === '' && types.value.length === 0,
)

const mineEmpty = computed(
  () => loaded.value
    && !loading.value
    && pageError.value === ''
    && !showUnconfigured.value
    && balances.value.length === 0
    && requests.value.length === 0,
)

const pageState = computed<PageState>(() => {
  if (!canRead.value) return 'forbidden'
  if (loading.value) return 'loading'
  if (pageError.value !== '') return 'error'
  if (showUnconfigured.value) return 'unconfigured'
  if (loaded.value && !loading.value && partialSections.value.length === 3
    && requests.value.length === 0 && balances.value.length === 0) return 'empty'
  return 'success'
})

function selectTab(tab: TabKey): void {
  if (!validTabs.has(tab) || !visibleTabs.value.some((item) => item.key === tab)) tab = 'mine'
  activeTab.value = tab
  void router.replace({ query: { ...route.query, tab } })
  if (!loaded.value) void load()
}

function openSelfDrawer(): void {
  drawerMode.value = 'self'
  selectedEmploymentId.value = null
  drawerOpen.value = true
}

function onBehalfSubjectValue(): number | null {
  const parsed = Number(selectedEmploymentId.value)
  return Number.isSafeInteger(parsed) && parsed > 0 ? parsed : null
}

function openOnBehalfDrawer(): void {
  const employmentId = onBehalfSubjectValue()
  if (employmentId === null) return
  selectedEmploymentId.value = employmentId
  drawerMode.value = 'on-behalf'
  drawerOpen.value = true
}

async function load(): Promise<void> {
  if (!canRead.value) {
    loaded.value = true
    return
  }

  pageError.value = ''
  pageErrorDetails.value = { code: '', requestId: null }
  pageErrorState.clear()
  actionMessage.value = ''
  loading.value = true
  loaded.value = false
  partialSections.value = []

  const calls: Array<Promise<{ key: string; ok: boolean; value?: unknown; reason?: unknown }>> = [
    getLeaveBalances().then((value) => ({ key: 'balances', ok: true as const, value })).catch((reason: unknown) => ({ key: 'balances', ok: false as const, reason })),
    getLeaveTypes().then((value) => ({ key: 'types', ok: true as const, value })).catch((reason: unknown) => ({ key: 'types', ok: false as const, reason })),
    getLeaveRequests(canAdmin.value ? { scope: 'all', page_size: 100 } : {}).then((value) => ({ key: 'requests', ok: true as const, value })).catch((reason: unknown) => ({ key: 'requests', ok: false as const, reason })),
  ]

  if (canReadPolicies.value) {
    calls.push(getLeavePolicies().then((value) => ({ key: 'policies', ok: true as const, value })).catch((reason: unknown) => ({ key: 'policies', ok: false as const, reason })))
  }

  const outcomes = await Promise.all(calls)

  for (const outcome of outcomes) {
    if (outcome.ok) {
      if (outcome.key === 'balances') balances.value = outcome.value as LeaveBalance[]
      if (outcome.key === 'types') types.value = outcome.value as LeaveTypeOption[]
      if (outcome.key === 'requests') requests.value = outcome.value as LeaveRequestItem[]
      if (outcome.key === 'policies') policies.value = outcome.value as LeavePolicy[]
    } else {
      partialSections.value = [...partialSections.value, outcome.key]
      if (outcome.key === 'balances') balances.value = []
      if (outcome.key === 'types') types.value = []
      if (outcome.key === 'requests') requests.value = []
      if (outcome.key === 'policies') policies.value = []
    }
  }

  if (outcomes.every((outcome) => !outcome.ok)) {
    const first = outcomes[0]
    pageError.value = first !== undefined && !first.ok ? errorCopy(first.reason) : '请假工作台暂时无法加载。'
    if (first !== undefined && !first.ok) {
      pageErrorState.capture(first.reason)
      pageErrorDetails.value = {
        code: first.reason instanceof ApiError ? first.reason.code : '',
        requestId: first.reason instanceof ApiError ? first.reason.requestId : null,
      }
    }
  }

  loading.value = false
  loaded.value = true
}

function onSubmitted(payload: { caseNo: string; onBehalf?: boolean }): void {
  const message = `${payload.onBehalf ? '代申请' : '请假申请'}已提交：${payload.caseNo || '已受理'}`
  // load() clears transient messages at its start; restore the command result
  // after the server-backed refresh so the operator can see the case number.
  void load().finally(() => {
    actionMessage.value = message
  })
}

onMounted(() => {
  const requested = typeof route.query.tab === 'string' ? route.query.tab : ''
  const allowed = validTabs.has(requested as TabKey)
    && visibleTabs.value.some((item) => item.key === requested)
  activeTab.value = allowed ? requested as TabKey : 'mine'
  if (canRead.value) void load()
  else loaded.value = true
})

function onCancelled(target: LeaveRequestItem, revoked: boolean): void {
  const message = `请假申请已${revoked ? '销假' : '撤回'}：${target.caseNo}`
  void load().finally(() => {
    actionMessage.value = message
  })
}

function sectionLabel(key: string): string {
  if (key === 'balances') return '假期余额'
  if (key === 'types') return '假期类型'
  if (key === 'requests') return '请假申请'
  if (key === 'policies') return '假期规则'
  return key
}
</script>

<template>
  <div class="leave-workspace" data-page="leave">
    <header class="leave-workspace__header">
      <div>
        <p class="leave-workspace__breadcrumb">假勤管理</p>
        <h1 class="leave-workspace__title">请假与假期余额</h1>
        <p class="leave-workspace__description">
          配置假期类型与规则、提交本人或代员工申请、核对余额流水和假期日历；所有写操作由服务端进行权限、规则、冲突与工资冻结校验。
        </p>
      </div>
      <div class="leave-workspace__header-actions">
        <button
          v-if="canRequest && activeTypes.length > 0"
          type="button"
          class="leave-workspace__primary"
          data-testid="open-leave-request"
          @click="openSelfDrawer"
        >
          发起请假
        </button>
        <button type="button" class="leave-workspace__secondary" :disabled="loading" @click="load">
          刷新
        </button>
      </div>
    </header>

    <nav class="leave-workspace__tabs" role="tablist" aria-label="请假工作区页签">
      <button
        v-for="tab in visibleTabs"
        :key="tab.key"
        type="button"
        role="tab"
        class="leave-workspace__tab"
        :class="{ 'leave-workspace__tab--active': activeTab === tab.key }"
        :aria-selected="activeTab === tab.key"
        :data-testid="`leave-tab-${tab.key}`"
        @click="selectTab(tab.key)"
      >
        {{ tab.label }}
      </button>
    </nav>

    <p v-if="actionMessage" class="leave-workspace__success" role="status" data-testid="leave-success">
      {{ actionMessage }}
    </p>

    <section v-if="pageState === 'forbidden'" class="state-panel empty-state" role="status">
      <div class="state-panel__mark" aria-hidden="true">—</div>
      <div class="state-panel__content">
        <h2 class="state-panel__title">无权查看请假</h2>
        <p class="state-panel__body">当前账号没有请假查看权限。请通过角色授权申请后重试。</p>
      </div>
    </section>

    <ErrorState
      v-else-if="pageState === 'error'"
      title="请假工作台暂时无法加载"
      :description="pageError"
      :code="pageErrorDetails.code"
      :request-id="pageErrorDetails.requestId"
      @retry="load"
    />

    <LoadingState v-else-if="pageState === 'loading'" :rows="6" label="正在加载请假工作台" />

    <section v-else-if="pageState === 'unconfigured'" class="leave-workspace__state" role="status" data-testid="leave-unconfigured">
      <h2>尚未配置假期类型</h2>
      <p>管理员需先在“假期类型”和“假期规则”中完成配置，员工才可以选择假别发起申请。</p>
      <button v-if="canManageTypes" type="button" class="leave-workspace__primary" @click="selectTab('types')">前往假期类型</button>
    </section>

    <template v-else>
      <div v-if="partialSections.length > 0" class="leave-workspace__partial" role="status" data-testid="leave-partial">
        部分数据暂时无法加载：{{ partialSections.map(sectionLabel).join('、') }}。其余模块仍按服务端返回结果展示。
      </div>

      <section v-show="activeTab === 'mine'" role="tabpanel" aria-label="我的请假" class="leave-workspace__panel">
        <section class="leave-workspace__meta" aria-label="请假数据业务日期">
          <span data-testid="leave-as-of">余额截至：{{ asOfLabel }}</span>
          <span v-if="balanceSummary">摘要：{{ balanceSummary }}</span>
          <span>业务日期由服务端公司时区计算，页面不读取浏览器“今天”。</span>
        </section>
        <EmptyState
          v-if="mineEmpty"
          title="暂无请假数据"
          description="服务端未返回假期余额或请假申请。若已入职，请联系人力资源确认假期账户。"
        />
        <template v-else>
          <LeaveBalanceCards :balances="balances" :loading="loading" />
          <div class="leave-workspace__split">
            <LeaveRequestList
              :requests="requests"
              :types="types"
              :balances="balances"
              :can-cancel="canCancel"
              :can-revoke="true"
              title="我的请假申请"
              @cancel="onCancelled($event, false)"
              @revoke="onCancelled($event, true)"
            />
            <LeaveLedgerPanel v-if="canReadLedger" :balances="balances" />
          </div>
        </template>
      </section>

      <section v-show="activeTab === 'requests'" role="tabpanel" aria-label="请假台账" class="leave-workspace__panel">
        <div v-if="canOnBehalf" class="leave-workspace__subject">
          <label>
            <span>代申请员工任职编号</span>
            <el-input
              v-model.number="selectedEmploymentId"
              type="number"
              placeholder="先选择或输入员工任职编号"
              style="width: 220px"
              data-testid="leave-on-behalf-subject"
            />
          </label>
          <button
            type="button"
            class="leave-workspace__primary"
            :disabled="onBehalfSubjectValue() === null || activeTypes.length === 0"
            data-testid="leave-on-behalf-open"
            @click="openOnBehalfDrawer"
          >
            代员工申请
          </button>
          <span class="leave-workspace__hint">操作者与申请员工分别记录；员工必须在你的授权范围内。</span>
        </div>
        <LeaveRequestList
          :requests="requests"
          :types="types"
          :balances="balances"
          :can-cancel="canCancel"
          :can-revoke="false"
          :can-admin-revoke="canAdminRevoke"
          :can-on-behalf="canOnBehalf"
          title="请假台账"
          show-employee
          @cancel="onCancelled($event, false)"
          @revoke="onCancelled($event, true)"
        />
      </section>

      <section v-show="activeTab === 'types'" role="tabpanel" aria-label="假期类型" class="leave-workspace__panel">
        <LeaveTypesPanel
          :types="types"
          :loading="loading"
          :can-manage="canManageTypes"
          :error="partialSections.includes('types') ? '假期类型暂时无法加载。' : ''"
          @refresh="load"
          @saved="actionMessage = $event"
        />
      </section>

      <section v-show="activeTab === 'policies'" role="tabpanel" aria-label="假期规则" class="leave-workspace__panel">
        <LeavePoliciesPanel
          :policies="policies"
          :types="types"
          :loading="loading"
          :can-manage="canManagePolicies"
          :error="partialSections.includes('policies') ? '假期规则暂时无法加载。' : ''"
          @refresh="load"
          @saved="actionMessage = $event"
        />
      </section>

      <section v-show="activeTab === 'balances'" role="tabpanel" aria-label="假期余额" class="leave-workspace__panel">
        <LeaveBalancePanel :types="types" :balances="balances" :can-read-ledger="canReadLedger" />
      </section>

      <section v-show="activeTab === 'calendar'" role="tabpanel" aria-label="假期日历" class="leave-workspace__panel">
        <LeaveCalendarPanel :requests="requests" :types="types" />
      </section>

      <section v-show="activeTab === 'reports'" role="tabpanel" aria-label="假期汇总" class="leave-workspace__panel">
        <LeaveBalancePanel
          :types="types"
          :balances="balances"
          :can-read-ledger="canReadLedger"
          initial-mode="summary"
        />
      </section>
    </template>

    <LeaveRequestDrawer
      v-model="drawerOpen"
      :types="activeTypes"
      :balances="balances"
      :submit-enabled="drawerMode === 'self' ? canRequest : canOnBehalf"
      :mode="drawerMode"
      :subject-employment-id="onBehalfSubjectValue()"
      @submitted="onSubmitted"
    />
  </div>
</template>

<style scoped>
.leave-workspace {
  display: flex;
  flex-direction: column;
  gap: 14px;
  padding: 20px;
}

.leave-workspace__header {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
}

.leave-workspace__breadcrumb {
  margin: 0 0 4px;
  color: #64748b;
  font-size: 11px;
}

.leave-workspace__title {
  margin: 0;
  font-size: 22px;
  font-weight: 720;
}

.leave-workspace__description {
  max-width: 860px;
  margin: 5px 0 0;
  color: var(--color-ink-muted);
  font-size: 12px;
  line-height: 1.6;
}

.leave-workspace__header-actions,
.leave-workspace__tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.leave-workspace__tabs {
  padding: 4px;
  background: #fff;
  border: 1px solid var(--color-line);
  border-radius: 8px;
}

.leave-workspace__tab {
  padding: 7px 12px;
  color: var(--color-ink-muted);
  font-size: 12px;
  background: transparent;
  border: 0;
  border-radius: 6px;
  cursor: pointer;
}

.leave-workspace__tab--active {
  color: #fff;
  font-weight: 620;
  background: var(--color-primary, #1f5f8b);
}

.leave-workspace__primary,
.leave-workspace__secondary {
  padding: 7px 13px;
  font-size: 12px;
  border-radius: 6px;
  cursor: pointer;
}

.leave-workspace__primary {
  color: #fff;
  background: var(--color-primary, #1f5f8b);
  border: 1px solid var(--color-primary, #1f5f8b);
}

.leave-workspace__secondary {
  color: var(--color-ink);
  background: #fff;
  border: 1px solid var(--color-line-strong);
}

.leave-workspace__state {
  padding: 28px 20px;
  color: var(--color-ink-muted);
  font-size: 12.5px;
  text-align: center;
  background: #fff;
  border: 1px dashed var(--color-line-strong);
  border-radius: var(--radius-md);
}

.leave-workspace__state--error {
  color: #9f1c12;
  background: #feecea;
  border-color: #f5c2bd;
}

.leave-workspace__state h2 {
  margin: 0 0 6px;
  color: var(--color-ink);
  font-size: 15px;
}

.leave-workspace__state p {
  margin: 0 0 12px;
}

.leave-workspace__panel {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.leave-workspace__split {
  display: grid;
  grid-template-columns: minmax(0, 1.1fr) minmax(340px, 1fr);
  gap: 14px;
  align-items: start;
}

.leave-workspace__success {
  margin: 0;
  padding: 9px 12px;
  color: #1f6b3a;
  font-size: 12px;
  background: #eef8f1;
  border: 1px solid #bfe3ca;
  border-radius: 6px;
}

.leave-workspace__subject {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 10px;
  padding: 12px 14px;
  background: #fff;
  border: 1px solid var(--color-line);
  border-radius: var(--radius-md);
}

.leave-workspace__subject label {
  display: flex;
  flex-direction: column;
  gap: 5px;
  color: var(--color-ink-muted);
  font-size: 11px;
  font-weight: 600;
}

.leave-workspace__hint {
  color: var(--color-ink-muted);
  font-size: 10.5px;
}

.leave-workspace__meta {
  display: flex;
  flex-wrap: wrap;
  gap: 8px 18px;
  padding: 9px 12px;
  color: var(--color-ink-muted);
  font-size: 11px;
  background: #fff;
  border: 1px solid var(--color-line);
  border-radius: 6px;
}

.leave-workspace__ledger-locked {
  padding: 16px;
  background: #fff;
  border: 1px dashed var(--color-line-strong);
  border-radius: var(--radius-md);
}

.leave-workspace__ledger-locked h2 {
  margin: 0 0 6px;
  font-size: 14px;
  font-weight: 680;
}

.leave-workspace__ledger-locked p {
  margin: 0;
  color: var(--color-ink-muted);
  font-size: 11px;
  line-height: 1.55;
}

.leave-workspace__partial {
  padding: 9px 12px;
  color: #6b4a00;
  font-size: 11.5px;
  background: #fffaf0;
  border: 1px dashed #e6c98d;
  border-radius: 6px;
}

@media (max-width: 1080px) {
  .leave-workspace__split {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
