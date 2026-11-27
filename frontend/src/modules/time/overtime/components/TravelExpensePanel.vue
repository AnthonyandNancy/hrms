<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { ApiError, errorCopy } from '@/api/errors'
import { formatBusinessDate } from '@/utils/format'
import { requestIdFromError } from '@/utils/renderErrors'
import {
  EXPENSE_CATEGORY_LABELS,
  expenseCategoryLabel,
  formatMinorUnits,
  parseMinorUnitsInput,
  paymentStatusLabel,
  stableSubmissionKey,
  submitTravelExpense,
  type StableSubmissionKey,
  type TravelExpense,
  type TravelRequest,
} from '../api'

const props = withDefaults(
  defineProps<{
    requests: TravelRequest[]
    expenses: TravelExpense[]
    currency: string
    canSubmit: boolean
    loading?: boolean
    error?: string
  }>(),
  {
    loading: false,
    error: '',
  },
)

const emit = defineEmits<{
  submitted: [expense: TravelExpense]
}>()

const categories = Object.entries(EXPENSE_CATEGORY_LABELS).map(([value, label]) => ({ value, label }))

const form = reactive({
  travelId: '',
  category: 'TRANSPORT',
  amountCents: '',
  note: '',
})

const submitting = ref(false)
const validationMessage = ref('')
const serverError = ref<{ message: string; requestId: string | null; fieldErrors: Record<string, string[]> } | null>(null)
const submissionKey = ref<StableSubmissionKey | null>(null)

const claimableRequests = computed(() =>
  props.requests.filter((request) =>
    request.status === 'APPROVED' && request.applicationStatus === 'APPROVED',
  ),
)

const selectedTravelId = computed(() => {
  const parsed = Number(form.travelId)
  return Number.isSafeInteger(parsed) && parsed > 0 ? parsed : null
})

const amountCents = computed<number | null>(() => parseMinorUnitsInput(form.amountCents))

const formattedAmount = computed(() => {
  const request = props.expenses.find((expense) => expense.currency !== '')
  const currency = props.currency !== '' ? props.currency : (request?.currency ?? '')
  return amountCents.value === null ? '—' : formatMinorUnits(amountCents.value, currency)
})

const amountFieldError = computed(() => serverError.value?.fieldErrors.amount_cents?.[0] ?? '')

function requestLabel(request: TravelRequest): string {
  return `#${request.travelId} · ${request.destination ?? '未填写目的地'} · ${formatBusinessDate(request.fromDate)} 至 ${formatBusinessDate(request.toDate)}`
}

function closeClaim(): void {
  if (submitting.value) return
  form.amountCents = ''
  form.note = ''
  validationMessage.value = ''
  serverError.value = null
  submissionKey.value = null
}

function captureServerError(reason: unknown): void {
  if (reason instanceof ApiError) {
    serverError.value = {
      message: errorCopy(reason),
      requestId: reason.requestId,
      fieldErrors: { ...reason.fieldErrors },
    }
    return
  }
  serverError.value = {
    message: errorCopy(reason),
    requestId: requestIdFromError(reason),
    fieldErrors: {},
  }
}

function validate(): boolean {
  if (selectedTravelId.value === null) {
    validationMessage.value = '请选择已批准且可登记费用的出差申请。'
    return false
  }
  if (amountCents.value === null) {
    validationMessage.value = '费用金额必须是整数最小货币单位（分），不能包含小数或负号。'
    return false
  }
  validationMessage.value = ''
  return true
}

async function submit(): Promise<void> {
  serverError.value = null
  if (!validate() || selectedTravelId.value === null || amountCents.value === null) return

  const payload: Record<string, unknown> = {
    travel_id: selectedTravelId.value,
    amount_cents: amountCents.value,
    category: form.category,
  }
  if (form.note.trim() !== '') payload.note = form.note.trim()

  submissionKey.value = stableSubmissionKey(submissionKey.value, 'travel-expense', payload)

  submitting.value = true
  try {
    const created = await submitTravelExpense(
      {
        travelId: selectedTravelId.value,
        amountCents: amountCents.value,
        category: form.category as 'TRANSPORT' | 'LODGING' | 'MEALS' | 'OTHER',
        note: form.note,
      },
      submissionKey.value.key,
    )
    emit('submitted', created)
    closeClaim()
  } catch (reason) {
    captureServerError(reason)
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <section class="travel-expenses" aria-labelledby="travel-expenses-title" data-testid="travel-expenses">
    <header class="travel-expenses__header">
      <div>
        <h3 id="travel-expenses-title">出差费用</h3>
        <p class="travel-expenses__muted">仅可对审批通过的出差登记费用；金额以整数最小货币单位存储，不进入薪资或付款。</p>
      </div>
      <span class="travel-expenses__count">{{ props.expenses.length }} 条</span>
    </header>

    <p v-if="props.error" class="travel-expenses__error" role="alert">{{ props.error }}</p>

    <div class="travel-expenses__grid">
      <section class="travel-expenses__list" aria-label="已登记费用">
        <p v-if="props.loading" class="travel-expenses__empty" role="status">正在加载费用…</p>
        <p v-else-if="props.expenses.length === 0" class="travel-expenses__empty" role="status">
          暂无出差费用记录。
        </p>
        <div v-else class="travel-expenses__scroller">
          <table class="travel-expenses__table">
            <thead>
              <tr>
                <th scope="col">出差申请</th>
                <th scope="col">类别</th>
                <th scope="col" class="travel-expenses__amount-col">金额</th>
                <th scope="col">状态</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="expense in props.expenses" :key="expense.id" :data-testid="`travel-expense-${expense.id}`">
                <td>#{{ expense.travelId }}</td>
                <td>{{ expenseCategoryLabel(expense.category) }}</td>
                <td class="travel-expenses__amount-col">
                  <span class="mono">{{ formatMinorUnits(expense.amountCents, expense.currency) }}</span>
                  <small class="travel-expenses__muted">（{{ expense.amountCents }} 分）</small>
                </td>
                <td>{{ paymentStatusLabel(expense.paymentStatus) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <form
        v-if="props.canSubmit"
        class="travel-expenses__form"
        data-testid="travel-expense-form"
        @submit.prevent="submit"
      >
        <h4>登记费用</h4>

        <label class="travel-expenses__field">
          <span>出差申请</span>
          <select v-model="form.travelId" data-testid="travel-expense-request" required>
            <option value="" disabled>请选择已批准的出差</option>
            <option v-for="request in claimableRequests" :key="request.travelId" :value="String(request.travelId)">
              {{ requestLabel(request) }}
            </option>
          </select>
          <small v-if="claimableRequests.length === 0" class="travel-expenses__muted">
            当前没有审批通过的出差申请，费用登记在审批通过后开放。
          </small>
        </label>

        <div class="travel-expenses__field-row">
          <label class="travel-expenses__field">
            <span>费用类别</span>
            <select v-model="form.category" data-testid="travel-expense-category">
              <option v-for="category in categories" :key="category.value" :value="category.value">
                {{ category.label }}
              </option>
            </select>
          </label>

          <label class="travel-expenses__field">
            <span>金额（整数分）</span>
            <input
              v-model="form.amountCents"
              data-testid="travel-expense-amount-cents"
              inputmode="numeric"
              placeholder="例如 5000"
              :aria-invalid="amountFieldError !== '' ? 'true' : undefined"
            />
            <small class="travel-expenses__muted">显示：{{ formattedAmount }}</small>
            <small v-if="amountFieldError" class="travel-expenses__field-error">{{ amountFieldError }}</small>
          </label>
        </div>

        <label class="travel-expenses__field">
          <span>备注</span>
          <input v-model="form.note" data-testid="travel-expense-note" maxlength="500" placeholder="可填写票据说明" />
        </label>

        <p v-if="validationMessage" class="travel-expenses__error" role="alert" data-testid="travel-expense-validation">
          {{ validationMessage }}
        </p>
        <p v-if="serverError" class="travel-expenses__error" role="alert" data-testid="travel-expense-error">
          {{ serverError.message }}
          <template v-if="serverError.requestId"> 请求编号：<span class="mono">{{ serverError.requestId }}</span></template>
        </p>

        <footer class="travel-expenses__actions">
          <button type="button" class="travel-expenses__secondary" :disabled="submitting" @click="closeClaim">清空</button>
          <button type="submit" class="travel-expenses__primary" :disabled="submitting" data-testid="travel-expense-submit">
            {{ submitting ? '提交中…' : '登记费用' }}
          </button>
        </footer>
      </form>

      <p v-else class="travel-expenses__readonly">
        当前账号可查看费用，但没有费用登记权限。
      </p>
    </div>
  </section>
</template>

<style scoped>
.travel-expenses {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
}

.travel-expenses__header {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  padding: 14px 16px;
  border-bottom: 1px solid #e2e8f0;
}

.travel-expenses__header h3 {
  margin: 0;
  font-size: 14px;
}

.travel-expenses__muted {
  margin: 3px 0 0;
  color: #64748b;
  font-size: 11px;
}

.travel-expenses__count {
  align-self: flex-start;
  padding: 3px 8px;
  color: #0369a1;
  font-size: 11px;
  background: #f0f9ff;
  border-radius: 999px;
}

.travel-expenses__grid {
  display: grid;
  grid-template-columns: minmax(0, 1.4fr) minmax(260px, 0.8fr);
  gap: 0;
}

.travel-expenses__list {
  min-width: 0;
  border-right: 1px solid #e2e8f0;
}

.travel-expenses__scroller {
  overflow-x: auto;
}

.travel-expenses__table {
  width: 100%;
  font-size: 11.5px;
  border-collapse: collapse;
}

.travel-expenses__table th,
.travel-expenses__table td {
  padding: 9px 12px;
  text-align: left;
  border-bottom: 1px solid #f1f5f9;
}

.travel-expenses__table th {
  color: #64748b;
  font-weight: 500;
  background: #f8fafc;
}

.travel-expenses__amount-col {
  text-align: right;
}

.travel-expenses__empty {
  margin: 0;
  padding: 16px;
  color: #64748b;
  font-size: 11.5px;
}

.travel-expenses__form {
  display: grid;
  gap: 10px;
  padding: 14px 16px 18px;
}

.travel-expenses__form h4 {
  margin: 0;
  font-size: 12.5px;
}

.travel-expenses__field,
.travel-expenses__field-row {
  display: flex;
  flex-direction: column;
  gap: 3px;
  font-size: 11.5px;
}

.travel-expenses__field-row {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
}

.travel-expenses__field select,
.travel-expenses__field input {
  padding: 7px 9px;
  font: inherit;
  font-size: 11.5px;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
}

.travel-expenses__field-error {
  color: #b91c1c;
}

.travel-expenses__error {
  margin: 0;
  padding: 8px 10px;
  color: #991b1b;
  font-size: 11px;
  line-height: 1.5;
  background: #fef2f2;
  border: 1px solid #fecaca;
  border-radius: 7px;
}

.travel-expenses__actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}

.travel-expenses__primary {
  padding: 6px 12px;
  color: #fff;
  font-size: 11.5px;
  background: #1d4ed8;
  border: 0;
  border-radius: 6px;
  cursor: pointer;
}

.travel-expenses__secondary {
  padding: 6px 12px;
  color: #475569;
  font-size: 11.5px;
  background: transparent;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
  cursor: pointer;
}

.travel-expenses__readonly {
  margin: 0;
  padding: 16px;
  color: #64748b;
  font-size: 11.5px;
}

.travel-expenses__primary:disabled,
.travel-expenses__secondary:disabled {
  cursor: not-allowed;
  opacity: 0.55;
}

.mono {
  font-family: ui-monospace, monospace;
}

@media (max-width: 900px) {
  .travel-expenses__grid {
    grid-template-columns: minmax(0, 1fr);
  }

  .travel-expenses__list {
    border-right: 0;
    border-bottom: 1px solid #e2e8f0;
  }
}
</style>
