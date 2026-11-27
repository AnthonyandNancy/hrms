<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ApiError } from '@/api/errors'
import { formatBusinessDate } from '@/utils/format'
import DataTable, { type DataTableColumn } from '@/components/DataTable.vue'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import LoadingState from '@/components/LoadingState.vue'
import {
  formatMoney,
  getTravelExpenses,
  getTravelRequests,
  paymentStatusLabel,
  expenseCategoryLabel,
  parseMinorUnitsInput,
  stableSubmissionKey,
  submitTravelExpense,
  type StableSubmissionKey,
  type TravelExpense,
  type TravelRequest,
} from '../api'

const props = withDefaults(
  defineProps<{
    canSubmit?: boolean
  }>(),
  { canSubmit: false },
)

const emit = defineEmits<{ success: [message: string] }>()

const loading = ref(false)
const errorMessage = ref('')
const errorCode = ref('')
const errorRequestId = ref<string | null>(null)
const expenses = ref<TravelExpense[]>([])
const requests = ref<TravelRequest[]>([])
const total = ref(0)

const form = reactive({
  travelId: '',
  category: 'TRANSPORT',
  amountCents: '',
  note: '',
})
const submitting = ref(false)
const actionError = ref('')
const actionMessage = ref('')
const submissionKey = ref<StableSubmissionKey | null>(null)

const categories = [
  { value: 'TRANSPORT', label: '交通' },
  { value: 'LODGING', label: '住宿' },
  { value: 'MEALS', label: '餐饮' },
  { value: 'OTHER', label: '其他' },
]

const columns: DataTableColumn[] = [
  { key: 'id', label: '费用编号', width: '100px' },
  { key: 'travelId', label: '出差单', width: '100px' },
  { key: 'category', label: '类别', width: '90px' },
  { key: 'amount', label: '金额', width: '130px', align: 'right' },
  { key: 'status', label: '状态', width: '100px' },
  { key: 'paymentStatus', label: '支付状态', width: '140px' },
  { key: 'note', label: '备注', minWidth: '160px' },
]

const approvedRequests = computed(() =>
  requests.value.filter(
    (request) => request.status === 'APPROVED' && request.applicationStatus === 'APPROVED',
  ),
)

const hasExpenses = computed(() => expenses.value.length > 0)
const selectedTravelId = computed(() => {
  const parsed = Number(form.travelId)
  return Number.isSafeInteger(parsed) && parsed > 0 ? parsed : null
})
const amountCents = computed(() => parseMinorUnitsInput(form.amountCents))
const formattedAmount = computed(() =>
  amountCents.value === null ? '—' : formatMoney(amountCents.value, expenses.value[0]?.currency ?? 'CNY'),
)

function requestLabel(request: TravelRequest): string {
  return `#${request.travelId} · ${request.destination ?? '未填写目的地'} · ${formatBusinessDate(request.fromDate)} 至 ${formatBusinessDate(request.toDate)}`
}

function captureError(reason: unknown): void {
  if (reason instanceof ApiError) {
    errorMessage.value = reason.message || '费用数据暂时无法加载。'
    errorCode.value = reason.code
    errorRequestId.value = reason.requestId
    return
  }
  errorMessage.value = '费用数据暂时无法加载。'
  errorCode.value = 'UNKNOWN'
  errorRequestId.value = null
}

async function load(): Promise<void> {
  loading.value = true
  errorMessage.value = ''
  try {
    const [expenseResult, requestResult] = await Promise.all([
      getTravelExpenses(),
      getTravelRequests({ view: 'team', page: 1, pageSize: 100 }),
    ])
    expenses.value = expenseResult.items
    total.value = expenseResult.total
    requests.value = requestResult.items
  } catch (reason) {
    captureError(reason)
    expenses.value = []
    requests.value = []
    total.value = 0
  } finally {
    loading.value = false
  }
}

function resetClaim(): void {
  form.amountCents = ''
  form.note = ''
  actionError.value = ''
  submissionKey.value = null
}

async function submit(): Promise<void> {
  actionError.value = ''
  actionMessage.value = ''
  if (selectedTravelId.value === null) {
    actionError.value = '请选择已批准且可登记费用的出差申请。'
    return
  }
  if (amountCents.value === null) {
    actionError.value = '费用金额必须是非负整数分，不能包含小数或负号。'
    return
  }

  const payload: Record<string, unknown> = {
    travel_id: selectedTravelId.value,
    amount_cents: amountCents.value,
    category: form.category,
  }
  if (form.note.trim() !== '') payload.note = form.note.trim()
  submissionKey.value = stableSubmissionKey(submissionKey.value, 'travel-expense', payload)

  submitting.value = true
  try {
    const expense = await submitTravelExpense(
      {
        travelId: selectedTravelId.value,
        amountCents: amountCents.value,
        category: form.category as 'TRANSPORT' | 'LODGING' | 'MEALS' | 'OTHER',
        note: form.note,
      },
      submissionKey.value.key,
    )
    actionMessage.value = `费用已登记（#${expense.id}，仅登记，不触发付款）。`
    emit('success', actionMessage.value)
    resetClaim()
    await load()
  } catch (reason) {
    actionError.value =
      reason instanceof ApiError
        ? `${reason.message || '费用登记未完成。'}${reason.requestId ? ` 请求编号：${reason.requestId}` : ''}`
        : '费用登记未完成，请稍后重试。'
  } finally {
    submitting.value = false
  }
}

onMounted(() => {
  void load()
})
</script>

<template>
  <section class="travel-expenses" data-testid="travel-expenses-panel">
    <header class="travel-expenses__header">
      <div>
        <h2>费用交接</h2>
        <p class="travel-expenses__muted">
          仅审批通过的出差可登记费用；金额以整数分保存，状态始终为仅登记（CLAIM_ONLY），不进入薪资或付款。
        </p>
      </div>
      <div class="travel-expenses__header-actions">
        <span class="travel-expenses__badge">{{ total }} 条</span>
        <button type="button" class="travel-expenses__secondary" data-testid="travel-expenses-refresh" :disabled="loading" @click="load">
          刷新
        </button>
      </div>
    </header>

    <p v-if="actionMessage" class="travel-expenses__success" role="status" data-testid="travel-expenses-success">{{ actionMessage }}</p>
    <p v-if="actionError" class="travel-expenses__error" role="alert" data-testid="travel-expenses-error">{{ actionError }}</p>

    <div v-if="loading" data-testid="travel-expenses-loading">
      <LoadingState :rows="4" label="正在加载出差费用" />
    </div>
    <ErrorState
      v-else-if="errorMessage !== ''"
      data-testid="travel-expenses-load-error"
      title="出差费用暂时无法加载"
      :description="errorMessage"
      :code="errorCode"
      :request-id="errorRequestId"
      @retry="load"
    />
    <template v-else>
      <EmptyState
        v-if="!hasExpenses"
        data-testid="travel-expenses-empty"
        title="暂无出差费用"
        description="当前授权范围内没有已登记的出差费用。"
      />
      <DataTable
        v-else
        :columns="columns"
        :rows="expenses as unknown as Array<Record<string, unknown>>"
        :total="total"
        :page="1"
        :page-size="Math.max(1, expenses.length)"
        row-key="id"
        data-testid="travel-expense-table"
        empty-title="暂无出差费用"
      >
        <template #cell-id="{ row }"><span class="mono">#{{ row.id }}</span></template>
        <template #cell-travelId="{ row }"><span class="mono">#{{ row.travelId }}</span></template>
        <template #cell-category="{ row }">{{ expenseCategoryLabel(String(row.category)) }}</template>
        <template #cell-amount="{ row }">
          <span class="mono">{{ formatMoney(Number(row.amountCents), String(row.currency)) }}</span>
          <small class="travel-expenses__muted">（{{ row.amountCents }} 分）</small>
        </template>
        <template #cell-status="{ row }">{{ String(row.status) }}</template>
        <template #cell-paymentStatus="{ row }">{{ paymentStatusLabel(String(row.paymentStatus)) }}</template>
        <template #cell-note="{ row }">{{ row.note ?? '—' }}</template>
      </DataTable>

      <form v-if="props.canSubmit" class="travel-expenses__form" data-testid="travel-expense-form" @submit.prevent="submit">
        <h3>登记费用</h3>
        <label>
          <span>出差申请</span>
          <select v-model="form.travelId" data-testid="travel-expense-request" required>
            <option value="" disabled>请选择已批准的出差</option>
            <option v-for="request in approvedRequests" :key="request.travelId" :value="String(request.travelId)">
              {{ requestLabel(request) }}
            </option>
          </select>
          <small v-if="approvedRequests.length === 0" class="travel-expenses__muted">
            当前没有审批通过的出差申请，费用登记在审批通过后开放。
          </small>
        </label>
        <div class="travel-expenses__field-row">
          <label>
            <span>费用类别</span>
            <select v-model="form.category" data-testid="travel-expense-category">
              <option v-for="category in categories" :key="category.value" :value="category.value">{{ category.label }}</option>
            </select>
          </label>
          <label>
            <span>金额（整数分）</span>
            <input v-model="form.amountCents" data-testid="travel-expense-amount-cents" inputmode="numeric" placeholder="例如 5000" />
            <small class="travel-expenses__muted">显示：{{ formattedAmount }}</small>
          </label>
        </div>
        <label>
          <span>备注</span>
          <input v-model="form.note" data-testid="travel-expense-note" maxlength="500" placeholder="可填写票据说明" />
        </label>
        <footer class="travel-expenses__form-actions">
          <button type="button" class="travel-expenses__secondary" :disabled="submitting" @click="resetClaim">清空</button>
          <button type="submit" class="travel-expenses__primary" :disabled="submitting" data-testid="travel-expense-submit">
            {{ submitting ? '提交中…' : '登记费用' }}
          </button>
        </footer>
      </form>
      <p v-else class="travel-expenses__readonly" data-testid="travel-expense-forbidden">
        当前账号可查看费用，但没有费用登记权限。
      </p>
    </template>
  </section>
</template>

<style scoped>
.travel-expenses { display: grid; gap: 14px; }
.travel-expenses__header { display: flex; justify-content: space-between; gap: 16px; align-items: flex-start; }
.travel-expenses__header h2 { margin: 0; font-size: 18px; }
.travel-expenses__muted { margin: 3px 0 0; color: #64748b; font-size: 11px; }
.travel-expenses__header-actions { display: flex; gap: 8px; align-items: center; }
.travel-expenses__badge { padding: 3px 8px; color: #0369a1; font-size: 10.5px; background: #f0f9ff; border-radius: 999px; }
.travel-expenses__form { display: grid; gap: 11px; padding: 14px; background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; }
.travel-expenses__form h3 { margin: 0; font-size: 14px; }
.travel-expenses__form label { display: flex; flex-direction: column; gap: 3px; font-size: 11.5px; }
.travel-expenses__form input, .travel-expenses__form select { padding: 7px 9px; font: inherit; font-size: 11.5px; border: 1px solid #cbd5e1; border-radius: 6px; }
.travel-expenses__field-row { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
.travel-expenses__form-actions { display: flex; justify-content: flex-end; gap: 8px; }
.travel-expenses__success { margin: 0; padding: 9px 12px; color: #065f46; font-size: 11.5px; background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 8px; }
.travel-expenses__error { margin: 0; padding: 9px 12px; color: #991b1b; font-size: 11.5px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; }
.travel-expenses__readonly { margin: 0; padding: 16px; color: #64748b; font-size: 11.5px; background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; }
.travel-expenses__primary, .travel-expenses__secondary { padding: 6px 11px; font-size: 11.5px; border-radius: 6px; cursor: pointer; }
.travel-expenses__primary { color: #fff; background: #1d4ed8; border: 1px solid #1d4ed8; }
.travel-expenses__secondary { color: #334155; background: #fff; border: 1px solid #cbd5e1; }
.mono { font-family: ui-monospace, monospace; }
@media (max-width: 720px) { .travel-expenses__field-row { grid-template-columns: minmax(0, 1fr); } }
</style>
