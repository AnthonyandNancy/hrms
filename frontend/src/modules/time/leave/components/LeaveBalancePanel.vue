<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { errorCopy } from '@/api/errors'
import {
  formatLeaveNumber,
  formatLedgerMinutes,
  getLeaveBalances,
  getLeaveLedger,
  getLeaveSummary,
  LEDGER_ENTRY_LABEL,
  leaveUnitLabel,
  type LeaveBalance,
  type LeaveLedger,
  type LeaveSummary,
  type LeaveTypeOption,
} from '../api'

const props = withDefaults(
  defineProps<{
    types: LeaveTypeOption[]
    balances: LeaveBalance[]
    asOf?: string
    canReadLedger?: boolean
    initialMode?: 'balance' | 'ledger' | 'summary'
  }>(),
  { asOf: '', canReadLedger: false, initialMode: 'balance' },
)

const mode = ref<'balance' | 'ledger' | 'summary'>(props.initialMode)
const employmentInput = ref('')
const selectedCode = ref('')
const ledger = ref<LeaveLedger | null>(null)
const ledgerLoading = ref(false)
const ledgerError = ref('')
const summary = ref<LeaveSummary | null>(null)
const summaryLoading = ref(false)
const summaryError = ref('')
const summaryFrom = ref('')
const summaryTo = ref('')
const scopedBalances = ref<LeaveBalance[]>([])
const scopedLoading = ref(false)
const scopedError = ref('')

const currentBalance = computed(
  () => scopedBalances.value.find((balance) => balance.leaveTypeCode === selectedCode.value)
    ?? props.balances.find((balance) => balance.leaveTypeCode === selectedCode.value)
    ?? null,
)
const visibleBalances = computed(() => (scopedBalances.value.length > 0 ? scopedBalances.value : props.balances))

watch(
  () => props.balances,
  (value) => {
    if (selectedCode.value === '' && value.length > 0) selectedCode.value = value[0]?.leaveTypeCode ?? ''
  },
  { immediate: true, deep: true },
)

async function loadScoped(): Promise<void> {
  const parsed = Number(employmentInput.value)
  if (!Number.isSafeInteger(parsed) || parsed <= 0) {
    scopedError.value = '请输入正整数员工任职编号。'
    scopedBalances.value = []
    return
  }

  scopedLoading.value = true
  scopedError.value = ''
  try {
    const result = await getLeaveBalances(parsed)
    scopedBalances.value = result
    if (!result.some((balance) => balance.leaveTypeCode === selectedCode.value)) {
      selectedCode.value = result[0]?.leaveTypeCode ?? selectedCode.value
    }
  } catch (reason) {
    scopedError.value = errorCopy(reason)
    scopedBalances.value = []
  } finally {
    scopedLoading.value = false
  }
}

async function loadLedger(): Promise<void> {
  if (selectedCode.value === '') return
  ledgerLoading.value = true
  ledgerError.value = ''
  try {
    ledger.value = await getLeaveLedger(
      selectedCode.value,
      currentBalance.value?.standardDayMinutes ?? null,
      Number.isSafeInteger(Number(employmentInput.value)) && Number(employmentInput.value) > 0
        ? Number(employmentInput.value)
        : null,
    )
  } catch (reason) {
    ledger.value = null
    ledgerError.value = errorCopy(reason)
  } finally {
    ledgerLoading.value = false
  }
}

async function loadSummary(): Promise<void> {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(summaryFrom.value) || !/^\d{4}-\d{2}-\d{2}$/.test(summaryTo.value)) {
    summaryError.value = '请填写汇总开始日期与结束日期。'
    return
  }

  summaryLoading.value = true
  summaryError.value = ''
  try {
    const params: Record<string, unknown> = { from: summaryFrom.value, to: summaryTo.value }
    if (props.asOf) params.as_of = props.asOf
    if (Number.isSafeInteger(Number(employmentInput.value)) && Number(employmentInput.value) > 0) {
      params.employment_id = Number(employmentInput.value)
    }
    summary.value = await getLeaveSummary(params)
  } catch (reason) {
    summary.value = null
    summaryError.value = errorCopy(reason)
  } finally {
    summaryLoading.value = false
  }
}

function openLedger(): void {
  mode.value = 'ledger'
  void loadLedger()
}

function openSummary(): void {
  mode.value = 'summary'
  if (summary.value === null && !summaryLoading.value) void loadSummary()
}

function entryLabel(entry: LeaveLedger['entries'][number]): string {
  return LEDGER_ENTRY_LABEL[entry.entryType] ?? entry.entryType
}

function quantityText(entry: LeaveLedger['entries'][number]): string {
  return formatLedgerMinutes(entry.quantityMinutes, ledger.value?.unit ?? 'DAY', ledger.value?.standardDayMinutes ?? null, true)
}

function balanceText(entry: LeaveLedger['entries'][number]): string {
  return formatLedgerMinutes(entry.balanceAfterMinutes, ledger.value?.unit ?? 'DAY', ledger.value?.standardDayMinutes ?? null)
}
</script>

<template>
  <section class="leave-account" aria-label="假期余额与流水">
    <header class="leave-account__header">
      <div>
        <h2 class="leave-account__title">假期余额</h2>
        <p class="leave-account__subtitle">
          余额只能由发放、占用、结算、释放与冲正流水变化。代查看员工余额需具备对应数据范围权限。
        </p>
      </div>
      <div class="leave-account__toolbar">
        <el-input v-model="employmentInput" placeholder="员工任职编号" aria-label="员工任职编号" style="width: 150px" />
        <el-button :loading="scopedLoading" @click="loadScoped">查看员工</el-button>
        <el-button :disabled="selectedCode === ''" @click="openLedger">查看流水</el-button>
        <el-button @click="openSummary">假期汇总</el-button>
      </div>
    </header>

    <el-alert v-if="scopedError" type="error" :closable="false" show-icon :title="scopedError" />

    <div class="leave-account__cards">
      <button
        v-for="balance in visibleBalances"
        :key="`${balance.leaveTypeCode}-${balance.leaveTypeId}`"
        type="button"
        class="leave-account__card"
        :class="{ 'leave-account__card--active': selectedCode === balance.leaveTypeCode }"
        :data-testid="`leave-balance-${balance.leaveTypeCode}`"
        @click="selectedCode = balance.leaveTypeCode"
      >
        <span class="leave-account__code">{{ balance.leaveTypeName }}</span>
        <strong>{{ formatLeaveNumber(balance.available) }} {{ leaveUnitLabel(balance.unit) }} 可用</strong>
        <span class="leave-account__meta">
          应享 {{ formatLeaveNumber(balance.entitled) }} · 已用 {{ formatLeaveNumber(balance.used) }} · 待占用 {{ formatLeaveNumber(balance.pending) }}
        </span>
        <span class="leave-account__asof">截至 {{ balance.asOf || asOf || '服务端日期' }}</span>
      </button>
    </div>

    <p v-if="visibleBalances.length === 0 && !scopedLoading" class="leave-account__empty">
      服务端未返回假期余额。若已入职，请联系人力资源确认假期账户。
    </p>

    <section v-if="mode === 'ledger'" class="leave-account__panel" aria-label="假期流水">
      <header class="leave-account__panel-header">
        <div>
          <h3>假期流水</h3>
          <p>{{ currentBalance?.leaveTypeName || '所选假别' }} · 截至 {{ ledger?.asOf || asOf || '服务端日期' }}</p>
        </div>
        <el-select v-model="selectedCode" style="width: 160px" @change="loadLedger">
          <el-option v-for="balance in visibleBalances" :key="balance.leaveTypeCode" :label="balance.leaveTypeName" :value="balance.leaveTypeCode" />
        </el-select>
      </header>

      <el-alert v-if="ledgerError" type="error" :closable="false" show-icon :title="ledgerError" />
      <div v-if="ledgerLoading" class="leave-account__state">正在加载流水…</div>
      <p v-else-if="ledger && ledger.entries.length === 0" class="leave-account__state" data-testid="leave-ledger-empty">
        该假别暂无流水记录。
      </p>
      <el-table v-else-if="ledger" :data="ledger.entries" row-key="id" data-testid="leave-ledger-table">
        <el-table-column label="业务日期" prop="businessDate" width="120" />
        <el-table-column label="类型" width="100">
          <template #default="{ row }">{{ entryLabel(row) }}</template>
        </el-table-column>
        <el-table-column label="数量" width="130">
          <template #default="{ row }">{{ quantityText(row) }}</template>
        </el-table-column>
        <el-table-column label="累计余额" width="130">
          <template #default="{ row }">{{ balanceText(row) }}</template>
        </el-table-column>
        <el-table-column label="原因 / 来源" min-width="200">
          <template #default="{ row }">
            {{ row.reason }}
            <span v-if="row.sourceCaseNo" class="mono">{{ row.sourceCaseNo }}</span>
          </template>
        </el-table-column>
      </el-table>
    </section>

    <section v-if="mode === 'summary'" class="leave-account__panel" aria-label="假期汇总">
      <header class="leave-account__panel-header">
        <div>
          <h3>假期汇总</h3>
          <p>按假别、部门与月份汇总真实申请与流水；个人只返回本人数据。</p>
        </div>
        <div class="leave-account__toolbar">
          <el-date-picker v-model="summaryFrom" type="date" value-format="YYYY-MM-DD" placeholder="开始日期" style="width: 145px" />
          <el-date-picker v-model="summaryTo" type="date" value-format="YYYY-MM-DD" placeholder="结束日期" style="width: 145px" />
          <el-button :loading="summaryLoading" @click="loadSummary">汇总</el-button>
        </div>
      </header>

      <el-alert v-if="summaryError" type="error" :closable="false" show-icon :title="summaryError" />
      <div v-if="summaryLoading" class="leave-account__state">正在汇总…</div>
      <p v-else-if="summary && summary.rows.length === 0" class="leave-account__state">
        所选范围内没有请假记录或流水。
      </p>
      <el-table v-else-if="summary" :data="summary.rows" row-key="month" data-testid="leave-summary-table">
        <el-table-column label="月份" prop="month" width="100" />
        <el-table-column label="假别" prop="leaveTypeName" min-width="120" />
        <el-table-column label="部门编号" width="100">
          <template #default="{ row }">{{ row.orgUnitId ?? '全公司' }}</template>
        </el-table-column>
        <el-table-column label="申请额度" width="120">
          <template #default="{ row }">{{ formatLeaveNumber(row.requestedMinutes / 480) }} 天</template>
        </el-table-column>
        <el-table-column label="已结算" width="120">
          <template #default="{ row }">{{ formatLeaveNumber(row.consumedMinutes / 480) }} 天</template>
        </el-table-column>
        <el-table-column label="余额" width="120">
          <template #default="{ row }">{{ formatLeaveNumber(row.balanceMinutes / 480) }} 天</template>
        </el-table-column>
        <el-table-column label="真实单据" min-width="180">
          <template #default="{ row }">
            <span class="mono">{{ (row.caseIds as number[]).slice(0, 3).map((id) => `CASE-${id}`).join('、') }}</span>
          </template>
        </el-table-column>
      </el-table>
    </section>
  </section>
</template>

<style scoped>
.leave-account {
  display: flex;
  flex-direction: column;
  gap: 14px;
  padding: 16px;
  background: #fff;
  border: 1px solid var(--color-line);
  border-radius: var(--radius-md);
}

.leave-account__header,
.leave-account__panel-header {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}

.leave-account__title,
.leave-account__panel-header h3 {
  margin: 0;
  font-size: 15px;
  font-weight: 680;
}

.leave-account__subtitle,
.leave-account__panel-header p {
  max-width: 720px;
  margin: 4px 0 0;
  color: var(--color-ink-muted);
  font-size: 11.5px;
  line-height: 1.55;
}

.leave-account__toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px;
}

.leave-account__cards {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
  gap: 10px;
}

.leave-account__card {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 12px 14px;
  text-align: left;
  background: #fff;
  border: 1px solid var(--color-line);
  border-radius: 8px;
  cursor: pointer;
}

.leave-account__card--active {
  border-color: var(--color-primary, #1f5f8b);
  box-shadow: 0 0 0 1px var(--color-primary, #1f5f8b);
}

.leave-account__code {
  font-size: 12px;
  font-weight: 620;
}

.leave-account__card strong {
  font-size: 18px;
  font-variant-numeric: tabular-nums;
}

.leave-account__meta,
.leave-account__asof {
  color: var(--color-ink-muted);
  font-size: 10.5px;
}

.leave-account__panel {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding-top: 12px;
  border-top: 1px solid var(--color-line);
}

.leave-account__state,
.leave-account__empty {
  margin: 0;
  padding: 20px 14px;
  color: var(--color-ink-muted);
  font-size: 12px;
  text-align: center;
  background: var(--color-canvas);
  border: 1px dashed var(--color-line-strong);
  border-radius: 6px;
}
</style>
