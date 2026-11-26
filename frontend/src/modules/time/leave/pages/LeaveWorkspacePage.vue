<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { usePageError } from '@/composables/usePageError'
import { useSessionStore } from '@/stores/session'
import LoadingState from '@/components/LoadingState.vue'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import LeaveBalanceCards from '../components/LeaveBalanceCards.vue'
import LeaveRequestDrawer from '../components/LeaveRequestDrawer.vue'
import LeaveRequestList from '../components/LeaveRequestList.vue'
import LeaveLedgerPanel from '../components/LeaveLedgerPanel.vue'
import {
  formatLeaveQuantity,
  getLeaveBalances,
  getLeaveRequests,
  getLeaveTypes,
  type LeaveBalance,
  type LeaveRequestItem,
  type LeaveTypeOption,
} from '../api'

defineOptions({ name: 'LeaveWorkspacePage' })

const session = useSessionStore()
const pageError = usePageError()

const loading = ref(false)
const loaded = ref(false)
const balances = ref<LeaveBalance[]>([])
const types = ref<LeaveTypeOption[]>([])
const requests = ref<LeaveRequestItem[]>([])
const drawerOpen = ref(false)
const actionMessage = ref('')
const actionError = ref('')
const partialSections = ref<string[]>([])

const canRead = computed(() => session.can('time.leave.read'))
const canRequest = computed(() => session.can('time.leave.request'))
const canCancel = computed(() => session.can('time.leave.request.cancel'))
const canReadLedger = computed(() => session.can('time.leave.ledger.read'))

const hasBalances = computed(() => balances.value.length > 0)
const hasRequests = computed(() => requests.value.length > 0)
const isEmpty = computed(
  () => loaded.value && !hasBalances.value && !hasRequests.value && partialSections.value.length === 0,
)
const partial = computed(() => partialSections.value.length > 0)

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

async function load(): Promise<void> {
  if (!canRead.value) return
  pageError.clear()
  actionError.value = ''
  loading.value = true
  loaded.value = false
  partialSections.value = []

  const failures: string[] = []

  const [balanceResult, typeResult, requestResult] = await Promise.all([
    getLeaveBalances()
      .then((value) => ({ ok: true as const, value }))
      .catch((reason: unknown) => ({ ok: false as const, reason })),
    getLeaveTypes()
      .then((value) => ({ ok: true as const, value }))
      .catch((reason: unknown) => ({ ok: false as const, reason })),
    getLeaveRequests()
      .then((value) => ({ ok: true as const, value }))
      .catch((reason: unknown) => ({ ok: false as const, reason })),
  ])

  if (balanceResult.ok) balances.value = balanceResult.value
  else {
    balances.value = []
    failures.push('假期余额')
  }

  if (typeResult.ok) types.value = typeResult.value
  else {
    types.value = []
    failures.push('假期类型')
  }

  if (requestResult.ok) requests.value = requestResult.value
  else {
    requests.value = []
    failures.push('请假申请')
  }

  partialSections.value = failures

  if (!balanceResult.ok && !typeResult.ok && !requestResult.ok) {
    // Every section failed: this is a page error, not partial data.
    pageError.capture(balanceResult.reason)
    balances.value = []
    requests.value = []
    types.value = []
  }

  loading.value = false
  loaded.value = true
}

function openRequestDrawer(): void {
  if (!canRequest.value) return
  actionMessage.value = ''
  actionError.value = ''
  drawerOpen.value = true
}

async function onSubmitted(payload: { caseNo: string }): Promise<void> {
  actionMessage.value = `请假申请已提交：${payload.caseNo || '已受理'}`
  await load()
}

function onCancelled(target: LeaveRequestItem): void {
  actionMessage.value = `请假申请已撤回：${target.caseNo}`
  actionError.value = ''
  // Cancelling releases the reservation, so both balances and the request
  // list need the server's new snapshot.
  void load()
}

onMounted(() => {
  if (canRead.value) void load()
  else loaded.value = true
})

</script>

<template>
  <div class="page leave-workspace" data-page="leave">
    <header class="page-header">
      <div class="page-header__copy">
        <p class="page-header__breadcrumb">假勤与薪酬 · 请假管理</p>
        <h1 class="page-header__title">请假工作台</h1>
        <p class="page-header__description">
          查看本人假期余额、申请请假并跟踪审批状态；余额、日期和冲突均由服务端按公司时区计算。
        </p>
      </div>
      <div class="page-header__actions">
        <button
          v-if="canRequest"
          type="button"
          class="leave-workspace__primary"
          data-testid="open-leave-request"
          @click="openRequestDrawer"
        >
          申请请假
        </button>
        <button type="button" class="leave-workspace__secondary" :disabled="loading || !canRead" @click="load">
          刷新
        </button>
      </div>
    </header>

    <section v-if="!canRead" class="state-panel empty-state" role="status">
      <div class="state-panel__mark" aria-hidden="true">—</div>
      <div class="state-panel__content">
        <h2 class="state-panel__title">无权查看请假</h2>
        <p class="state-panel__body">当前账号没有请假查看权限。请通过角色授权申请后重试。</p>
      </div>
    </section>

    <template v-else>
      <p v-if="actionMessage" class="leave-workspace__success" role="status" data-testid="leave-success">
        {{ actionMessage }}
      </p>
      <p v-if="actionError" class="leave-workspace__error" role="alert">{{ actionError }}</p>

      <ErrorState
        v-if="pageError.message.value"
        title="请假工作台暂时无法加载"
        :description="pageError.message.value"
        :code="pageError.code.value"
        :request-id="pageError.requestId.value"
        @retry="load"
      />
      <LoadingState v-else-if="loading" :rows="6" label="正在加载请假工作台" />

      <template v-else>
        <section v-if="partial" class="leave-workspace__partial" role="status" data-testid="leave-partial">
          <strong>部分数据</strong>
          <span>
            {{ partialSections.join('、') }}暂时无法加载；其余服务端数据仍按返回结果展示。
          </span>
        </section>

        <EmptyState
          v-if="isEmpty"
          title="暂无请假数据"
          description="服务端未返回假期余额或请假申请。若已入职，请联系人力资源确认假期账户。"
        />

        <template v-else>
          <section class="leave-workspace__meta" aria-label="请假数据业务日期">
            <span data-testid="leave-as-of">余额截至：{{ asOfLabel }}</span>
            <span v-if="balanceSummary">摘要：{{ balanceSummary }}</span>
            <span>业务日期由服务端公司时区计算，页面不读取浏览器“今天”。</span>
          </section>

          <LeaveBalanceCards :balances="balances" />

          <div class="leave-workspace__layout">
            <LeaveRequestList
              :requests="requests"
              :types="types"
              :balances="balances"
              :can-cancel="canCancel"
              @cancel="onCancelled"
            />

            <LeaveLedgerPanel v-if="canReadLedger" :balances="balances" />
            <section v-else class="leave-workspace__ledger-locked" aria-label="账本权限提示">
              <h2>假期账本</h2>
              <p>当前账号没有账本查看权限。余额卡片仍展示服务端汇总值。</p>
            </section>
          </div>
        </template>
      </template>
    </template>

    <LeaveRequestDrawer
      v-model="drawerOpen"
      :types="types"
      :balances="balances"
      :submit-enabled="canRequest"
      @submitted="onSubmitted"
    />
  </div>
</template>

<style scoped>
.leave-workspace {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.leave-workspace__primary,
.leave-workspace__secondary {
  padding: 6px 12px;
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

.leave-workspace__meta {
  display: flex;
  flex-wrap: wrap;
  gap: 6px 16px;
  padding: 8px 12px;
  color: var(--color-ink-muted);
  font-size: 10.5px;
  background: var(--color-canvas);
  border: 1px solid var(--color-line);
  border-radius: 6px;
}

.leave-workspace__layout {
  display: grid;
  grid-template-columns: minmax(0, 1.15fr) minmax(340px, 1fr);
  gap: 14px;
  align-items: start;
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

.leave-workspace__success {
  margin: 0;
  padding: 8px 10px;
  color: #1f6b3a;
  font-size: 12px;
  background: #eef8f1;
  border: 1px solid #bfe3ca;
  border-radius: 6px;
}

.leave-workspace__error {
  margin: 0;
  padding: 8px 10px;
  color: #9f1c12;
  font-size: 12px;
  background: #feecea;
  border: 1px solid #f5c2bd;
  border-radius: 6px;
}

.leave-workspace__partial {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  padding: 9px 12px;
  color: #6b4a00;
  font-size: 11.5px;
  background: #fffaf0;
  border: 1px dashed #e6c98d;
  border-radius: 6px;
}

@media (max-width: 1080px) {
  .leave-workspace__layout {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
