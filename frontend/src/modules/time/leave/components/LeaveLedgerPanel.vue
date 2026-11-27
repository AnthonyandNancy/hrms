<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { ApiError, errorCopy } from '@/api/errors'
import {
  formatLedgerMinutes,
  formatLeaveNumber,
  getLeaveLedger,
  leaveUnitLabel,
  LEDGER_ENTRY_LABEL,
  type LeaveBalance,
  type LeaveLedger,
  type LeaveLedgerEntry,
} from '../api'

const props = defineProps<{
  balances: LeaveBalance[]
}>()

interface LedgerState {
  loading: boolean
  error: { message: string; requestId: string | null } | null
  data: LeaveLedger | null
}

const selectedCode = ref('')
const states = ref<Record<string, LedgerState>>({})

const selectedBalance = computed(
  () => props.balances.find((balance) => balance.leaveTypeCode === selectedCode.value) ?? null,
)

const current = computed<LedgerState>(() => {
  const state = states.value[selectedCode.value]
  return state ?? { loading: false, error: null, data: null }
})

watch(
  () => props.balances,
  (balances) => {
    if (balances.length === 0) {
      selectedCode.value = ''
      return
    }
    if (selectedCode.value === '' || !balances.some((item) => item.leaveTypeCode === selectedCode.value)) {
      selectedCode.value = balances[0]?.leaveTypeCode ?? ''
      void load(selectedCode.value)
    }
  },
  { immediate: true, deep: true },
)

function setState(code: string, next: Partial<LedgerState>): void {
  const previous = states.value[code] ?? { loading: false, error: null, data: null }
  states.value = { ...states.value, [code]: { ...previous, ...next } }
}

async function load(code: string): Promise<void> {
  if (code === '') return
  const balance = props.balances.find((item) => item.leaveTypeCode === code) ?? null
  setState(code, { loading: true, error: null })
  try {
    const ledger = await getLeaveLedger(code, balance?.standardDayMinutes ?? null)
    setState(code, { loading: false, data: ledger })
  } catch (reason) {
    setState(code, {
      loading: false,
      data: null,
      error: {
        message: errorCopy(reason),
        requestId: reason instanceof ApiError ? reason.requestId : null,
      },
    })
  }
}

function selectType(code: string): void {
  selectedCode.value = code
  if (states.value[code]?.data === undefined || states.value[code]?.error !== null) {
    void load(code)
  }
}

const LEDGER_ENTRY_DIRECTION_POSITIVE = new Set(['GRANT', 'RELEASE'])
const LEDGER_ENTRY_DIRECTION_NEGATIVE = new Set(['RESERVE', 'CONSUME', 'EXPIRE'])

function entryLabel(entry: LeaveLedgerEntry): string {
  return LEDGER_ENTRY_LABEL[entry.entryType] ?? entry.entryType
}

function directionLabel(entry: LeaveLedgerEntry): string {
  if (LEDGER_ENTRY_DIRECTION_POSITIVE.has(entry.entryType)) return '计入'
  if (LEDGER_ENTRY_DIRECTION_NEGATIVE.has(entry.entryType)) return '扣减'
  return entry.quantityMinutes >= 0 ? '计入' : '扣减'
}

function standardDay(): number | null {
  return current.value.data?.standardDayMinutes ?? selectedBalance.value?.standardDayMinutes ?? null
}

function unitLabel(): string {
  return leaveUnitLabel(current.value.data?.unit ?? selectedBalance.value?.unit ?? 'DAY')
}

function quantityText(entry: LeaveLedgerEntry): string {
  return formatLedgerMinutes(entry.quantityMinutes, current.value.data?.unit ?? 'DAY', standardDay(), true)
}

function balanceText(entry: LeaveLedgerEntry): string {
  return formatLedgerMinutes(entry.balanceAfterMinutes, current.value.data?.unit ?? 'DAY', standardDay())
}

function closingText(): string {
  const data = current.value.data
  if (data === null) return '—'
  return formatLedgerMinutes(data.closingMinutes, data.unit, standardDay())
}

const openingText = computed(() => {
  const data = current.value.data
  if (data === null) return '—'
  return formatLedgerMinutes(data.openingMinutes, data.unit, standardDay())
})

const hasEntries = computed(() => (current.value.data?.entries.length ?? 0) > 0)
</script>

<template>
  <section class="leave-ledger" aria-label="假期账本" data-testid="leave-ledger">
    <header class="leave-ledger__header">
      <div>
        <h2 class="leave-ledger__title">假期账本</h2>
        <p class="leave-ledger__subtitle">
          只读的不可变分录：发放计入、占用、结算与冲正逐笔记录，余额为服务端计算的累计值。
        </p>
      </div>
      <span class="leave-ledger__readonly">只读</span>
    </header>

    <div class="leave-ledger__toolbar">
      <label class="leave-ledger__field">
        <span>假期类型</span>
        <select v-model="selectedCode" data-testid="leave-ledger-type" @change="selectType(selectedCode)">
          <option v-for="balance in props.balances" :key="balance.leaveTypeCode" :value="balance.leaveTypeCode">
            {{ balance.leaveTypeName }}
          </option>
        </select>
      </label>
      <button
        type="button"
        class="leave-ledger__refresh"
        data-testid="leave-ledger-refresh"
        :disabled="selectedCode === '' || current.loading"
        @click="load(selectedCode)"
      >
        刷新账本
      </button>
    </div>

    <p v-if="selectedCode === ''" class="leave-ledger__empty">暂无可查看的假期账户。</p>

    <template v-else>
      <div class="leave-ledger__summary">
        <div>
          <span class="leave-ledger__summary-label">截至业务日期</span>
          <strong data-testid="leave-ledger-as-of">
            {{ current.data?.asOf || '服务端未返回截至日期' }}
          </strong>
        </div>
        <div>
          <span class="leave-ledger__summary-label">期初余额</span>
          <strong>{{ openingText }}</strong>
        </div>
        <div>
          <span class="leave-ledger__summary-label">期末余额</span>
          <strong data-testid="leave-ledger-closing">{{ closingText() }}</strong>
        </div>
      </div>

      <p v-if="current.loading" class="leave-ledger__state" role="status">正在加载账本明细…</p>

      <div v-else-if="current.error" class="leave-ledger__error" role="alert">
        <p>账本暂时无法加载：{{ current.error.message }}</p>
        <p v-if="current.error.requestId" class="leave-ledger__request-id">
          请求编号：{{ current.error.requestId }}
        </p>
        <button type="button" class="leave-ledger__refresh" @click="load(selectedCode)">重试</button>
      </div>

      <p v-else-if="!hasEntries" class="leave-ledger__empty" data-testid="leave-ledger-empty">
        该假期类型暂无账本分录。服务端未返回记录时不会生成占位行。
      </p>

      <div v-else class="leave-ledger__table-wrap">
        <table class="leave-ledger__table">
          <caption class="visually-hidden">假期账本分录，按业务日期与分录顺序排列</caption>
          <thead>
            <tr>
              <th scope="col">业务日期</th>
              <th scope="col">类型</th>
              <th scope="col">方向</th>
              <th scope="col" class="leave-ledger__align-right">数量（{{ unitLabel() }}）</th>
              <th scope="col" class="leave-ledger__align-right">累计余额</th>
              <th scope="col">原因 / 来源</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="entry in current.data?.entries ?? []"
              :key="entry.id"
              :data-testid="`leave-ledger-entry-${entry.id}`"
            >
              <td class="leave-ledger__date">{{ entry.businessDate || '—' }}</td>
              <td>{{ entryLabel(entry) }}</td>
              <td>
                <span
                  class="leave-ledger__direction"
                  :class="entry.quantityMinutes >= 0 ? 'leave-ledger__direction--credit' : 'leave-ledger__direction--debit'"
                >
                  {{ directionLabel(entry) }}
                </span>
              </td>
              <td
                class="leave-ledger__align-right leave-ledger__quantity"
                :class="{ 'leave-ledger__quantity--debit': entry.quantityMinutes < 0 }"
              >
                {{ quantityText(entry) }}
              </td>
              <td class="leave-ledger__align-right leave-ledger__running">{{ balanceText(entry) }}</td>
              <td class="leave-ledger__reason">
                <span>{{ entry.reason || '—' }}</span>
                <small v-if="entry.sourceCaseNo" class="leave-ledger__case mono">{{ entry.sourceCaseNo }}</small>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <footer class="leave-ledger__footer">
        <span>余额 = 服务端分录累计；本页面不重新计算余额。</span>
        <span v-if="current.data">共 {{ formatLeaveNumber(current.data.entries.length) }} 条分录</span>
      </footer>
    </template>
  </section>
</template>

<style scoped>
.leave-ledger {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 14px 16px;
  background: #fff;
  border: 1px solid var(--color-line);
  border-radius: var(--radius-md);
  box-shadow: var(--shadow-soft);
}

.leave-ledger__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}

.leave-ledger__title {
  margin: 0;
  font-size: 14px;
  font-weight: 680;
}

.leave-ledger__subtitle {
  margin: 4px 0 0;
  color: var(--color-ink-muted);
  font-size: 11px;
  line-height: 1.5;
}

.leave-ledger__readonly {
  flex: none;
  padding: 2px 8px;
  color: var(--color-ink-muted);
  font-size: 9.5px;
  background: var(--color-canvas);
  border: 1px solid var(--color-line);
  border-radius: 999px;
}

.leave-ledger__toolbar {
  display: flex;
  align-items: flex-end;
  gap: 8px;
}

.leave-ledger__field {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 180px;
  color: var(--color-ink-muted);
  font-size: 10px;
  font-weight: 600;
}

.leave-ledger__field select {
  padding: 6px 8px;
  font: inherit;
  font-weight: 400;
  color: var(--color-ink);
  background: #fff;
  border: 1px solid var(--color-line-strong);
  border-radius: 6px;
}

.leave-ledger__refresh {
  padding: 6px 11px;
  color: var(--color-ink);
  font-size: 10.5px;
  background: #fff;
  border: 1px solid var(--color-line-strong);
  border-radius: 6px;
  cursor: pointer;
}

.leave-ledger__refresh:disabled {
  cursor: not-allowed;
  opacity: 0.55;
}

.leave-ledger__summary {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.leave-ledger__summary div {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 150px;
  padding: 7px 10px;
  background: var(--color-canvas);
  border: 1px solid var(--color-line);
  border-radius: 6px;
}

.leave-ledger__summary-label {
  color: var(--color-ink-muted);
  font-size: 9.5px;
}

.leave-ledger__summary strong {
  font-size: 13px;
  font-variant-numeric: tabular-nums;
}

.leave-ledger__state,
.leave-ledger__empty {
  margin: 0;
  padding: 16px 12px;
  color: var(--color-ink-muted);
  font-size: 11.5px;
  text-align: center;
  background: var(--color-canvas);
  border: 1px dashed var(--color-line-strong);
  border-radius: 6px;
}

.leave-ledger__error {
  padding: 10px 12px;
  color: #9f1c12;
  font-size: 11.5px;
  background: #feecea;
  border: 1px solid #f5c2bd;
  border-radius: 6px;
}

.leave-ledger__error p {
  margin: 0 0 5px;
}

.leave-ledger__request-id {
  font-family: var(--font-mono, monospace);
  font-size: 9.5px;
}

.leave-ledger__table-wrap {
  overflow-x: auto;
  border: 1px solid var(--color-line);
  border-radius: 6px;
}

.leave-ledger__table {
  width: 100%;
  min-width: 720px;
  border-collapse: collapse;
  font-size: 11px;
}

.leave-ledger__table th {
  padding: 7px 10px;
  color: var(--color-ink-muted);
  font-size: 9.5px;
  font-weight: 650;
  text-align: left;
  background: #f8faf9;
  border-bottom: 1px solid var(--color-line);
}

.leave-ledger__table td {
  padding: 8px 10px;
  border-bottom: 1px solid #edf1ef;
}

.leave-ledger__table tr:last-child td {
  border-bottom: 0;
}

.leave-ledger__date {
  white-space: nowrap;
  font-variant-numeric: tabular-nums;
}

.leave-ledger__align-right {
  text-align: right !important;
}

.leave-ledger__quantity,
.leave-ledger__running {
  white-space: nowrap;
  font-variant-numeric: tabular-nums;
}

.leave-ledger__quantity--debit {
  color: #9f1c12;
}

.leave-ledger__running {
  font-weight: 650;
}

.leave-ledger__direction {
  padding: 1px 6px;
  font-size: 9px;
  border-radius: 999px;
}

.leave-ledger__direction--credit {
  color: #1f6b3a;
  background: #eef8f1;
}

.leave-ledger__direction--debit {
  color: #9f1c12;
  background: #feecea;
}

.leave-ledger__reason {
  color: var(--color-ink-muted);
}

.leave-ledger__case {
  display: block;
  font-size: 9px;
}

.leave-ledger__footer {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  gap: 8px;
  color: var(--color-ink-muted);
  font-size: 9.5px;
}
</style>
