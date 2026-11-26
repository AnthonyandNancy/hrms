<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { ApiError, errorCopy } from '@/api/errors'
import { requestIdFromError } from '@/utils/renderErrors'
import {
  formatMinorUnits,
  parseMinorUnitsInput,
  stableSubmissionKey,
  submitTravelRequest,
  type StableSubmissionKey,
  type TravelRequest,
} from '../api'

const props = withDefaults(
  defineProps<{
    modelValue: boolean
    currency?: string
  }>(),
  {
    currency: '',
  },
)

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  submitted: [request: TravelRequest]
}>()

const form = reactive({
  fromDate: '',
  toDate: '',
  destination: '',
  purpose: '',
  estimatedCost: '',
})

const submitting = ref(false)
const validationMessage = ref('')
const serverError = ref<{ message: string; requestId: string | null } | null>(null)
const submissionKey = ref<StableSubmissionKey | null>(null)

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    validationMessage.value = ''
    serverError.value = null
    submissionKey.value = null
  },
)

const estimatedCostCents = computed<number | null>(() => {
  if (form.estimatedCost.trim() === '') return null
  return parseMinorUnitsInput(form.estimatedCost)
})

const estimatedCostLabel = computed(() =>
  estimatedCostCents.value === null
    ? '—'
    : formatMinorUnits(estimatedCostCents.value, props.currency),
)

function close(): void {
  if (submitting.value) return
  emit('update:modelValue', false)
}

function validate(): boolean {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(form.fromDate)) {
    validationMessage.value = '请选择有效的出发日期。'
    return false
  }
  if (!/^\d{4}-\d{2}-\d{2}$/.test(form.toDate)) {
    validationMessage.value = '请选择有效的返回日期。'
    return false
  }
  if (form.toDate < form.fromDate) {
    validationMessage.value = '返回日期不能早于出发日期。'
    return false
  }
  if (form.destination.trim() === '') {
    validationMessage.value = '请填写出差目的地。'
    return false
  }
  if (form.purpose.trim().length < 3) {
    validationMessage.value = '出差事由至少需要 3 个字符。'
    return false
  }
  if (form.estimatedCost.trim() !== '' && estimatedCostCents.value === null) {
    validationMessage.value = '预估费用必须是整数最小货币单位（分），不支持小数。'
    return false
  }
  validationMessage.value = ''
  return true
}

function captureServerError(reason: unknown): void {
  if (reason instanceof ApiError) {
    serverError.value = { message: errorCopy(reason), requestId: reason.requestId }
    return
  }
  serverError.value = { message: errorCopy(reason), requestId: requestIdFromError(reason) }
}

async function submit(): Promise<void> {
  serverError.value = null
  if (!validate()) return

  // The server contract currently accepts only destination, dates and purpose.
  // The estimate is intentionally held in this UI and disclosed as unpersisted.
  const payload = {
    from_date: form.fromDate,
    to_date: form.toDate,
    destination: form.destination.trim(),
    purpose: form.purpose.trim(),
  }
  submissionKey.value = stableSubmissionKey(submissionKey.value, 'travel-request', payload)

  submitting.value = true
  try {
    const created = await submitTravelRequest(
      {
        fromDate: form.fromDate,
        toDate: form.toDate,
        destination: payload.destination,
        purpose: payload.purpose,
      },
      submissionKey.value.key,
    )
    emit('submitted', created)
    emit('update:modelValue', false)
  } catch (reason) {
    captureServerError(reason)
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div v-if="props.modelValue" class="travel-drawer" role="presentation" @click.self="close">
    <section class="travel-drawer__panel" role="dialog" aria-modal="true" aria-label="申请出差">
      <header class="travel-drawer__header">
        <div>
          <p class="travel-drawer__eyebrow">出差</p>
          <h2>申请出差</h2>
          <p class="travel-drawer__muted">提交后生成审批案例；审批通过后方可登记费用。</p>
        </div>
        <button type="button" class="travel-drawer__close" aria-label="关闭出差申请" @click="close">关闭</button>
      </header>

      <form class="travel-drawer__form" data-testid="travel-request-form" @submit.prevent="submit">
        <div class="travel-drawer__field-row">
          <label class="travel-drawer__field">
            <span>出发日期</span>
            <input v-model="form.fromDate" data-testid="travel-from-date" type="date" required />
          </label>
          <label class="travel-drawer__field">
            <span>返回日期</span>
            <input v-model="form.toDate" data-testid="travel-to-date" type="date" required />
          </label>
        </div>

        <label class="travel-drawer__field">
          <span>目的地</span>
          <input v-model="form.destination" data-testid="travel-destination" maxlength="160" required />
        </label>

        <label class="travel-drawer__field">
          <span>出差事由</span>
          <textarea
            v-model="form.purpose"
            data-testid="travel-purpose"
            rows="3"
            maxlength="500"
            placeholder="请说明出差事项"
            required
          />
        </label>

        <label class="travel-drawer__field">
          <span>预估费用（整数最小货币单位）</span>
          <input
            v-model="form.estimatedCost"
            data-testid="travel-estimated-cost-cents"
            inputmode="numeric"
            placeholder="例如 88000"
          />
          <small class="travel-drawer__muted">
            预估：{{ estimatedCostLabel }}
            <template v-if="props.currency === ''">（币种尚未由公司档案或费用接口返回，暂不展示货币符号）</template>
          </small>
        </label>

        <p class="travel-drawer__disclosure" data-testid="travel-estimated-disclosure">
          申请接口当前仅持久化目的地、日期与事由；预估费用不会随申请提交，需在审批通过后按实际费用登记。
        </p>

        <p v-if="validationMessage" class="travel-drawer__error" role="alert" data-testid="travel-request-validation">
          {{ validationMessage }}
        </p>

        <p v-if="serverError" class="travel-drawer__error" role="alert" data-testid="travel-request-error">
          {{ serverError.message }}
          <template v-if="serverError.requestId"> 请求编号：<span class="mono">{{ serverError.requestId }}</span></template>
        </p>

        <footer class="travel-drawer__footer">
          <button type="button" class="travel-drawer__secondary" :disabled="submitting" @click="close">取消</button>
          <button type="submit" class="travel-drawer__primary" :disabled="submitting" data-testid="travel-request-submit">
            {{ submitting ? '提交中…' : '提交' }}
          </button>
        </footer>
      </form>
    </section>
  </div>
</template>

<style scoped>
.travel-drawer {
  position: fixed;
  inset: 0;
  z-index: 40;
  display: flex;
  justify-content: flex-end;
  background: rgb(15 23 42 / 38%);
}

.travel-drawer__panel {
  display: flex;
  flex-direction: column;
  width: min(520px, 100vw);
  height: 100%;
  overflow-y: auto;
  background: #fff;
  box-shadow: -8px 0 24px rgb(15 23 42 / 18%);
}

.travel-drawer__header {
  display: flex;
  justify-content: space-between;
  gap: 16px;
  padding: 20px 22px 16px;
  border-bottom: 1px solid #e2e8f0;
}

.travel-drawer__eyebrow {
  color: #64748b;
  font-size: 11px;
  letter-spacing: 0.12em;
}

.travel-drawer__header h2 {
  margin: 4px 0 6px;
  font-size: 18px;
}

.travel-drawer__muted {
  color: #64748b;
  font-size: 11.5px;
  line-height: 1.6;
}

.travel-drawer__close,
.travel-drawer__secondary {
  color: #475569;
  font-size: 12px;
  background: transparent;
  border: 0;
  cursor: pointer;
}

.travel-drawer__form {
  display: grid;
  gap: 14px;
  padding: 18px 22px 24px;
}

.travel-drawer__field,
.travel-drawer__field-row {
  display: flex;
  flex-direction: column;
  gap: 4px;
  font-size: 12px;
}

.travel-drawer__field-row {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}

.travel-drawer__field input,
.travel-drawer__field textarea {
  padding: 8px 10px;
  font: inherit;
  color: #0f172a;
  border: 1px solid #cbd5e1;
  border-radius: 7px;
}

.travel-drawer__disclosure {
  margin: 0;
  padding: 10px 12px;
  color: #92400e;
  font-size: 11px;
  line-height: 1.6;
  background: #fffbeb;
  border: 1px solid #fcd34d;
  border-radius: 8px;
}

.travel-drawer__error {
  margin: 0;
  padding: 10px 12px;
  color: #991b1b;
  font-size: 12px;
  line-height: 1.5;
  background: #fef2f2;
  border: 1px solid #fecaca;
  border-radius: 8px;
}

.travel-drawer__footer {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
}

.travel-drawer__primary {
  padding: 8px 18px;
  color: #fff;
  font-size: 12px;
  background: #1d4ed8;
  border: 0;
  border-radius: 7px;
  cursor: pointer;
}

.travel-drawer__primary:disabled,
.travel-drawer__secondary:disabled {
  cursor: not-allowed;
  opacity: 0.55;
}

.mono {
  font-family: ui-monospace, monospace;
}
</style>
