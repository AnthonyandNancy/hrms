<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { ApiError, errorCopy } from '@/api/errors'
import {
  stableSubmissionKey,
  submitTravelChange,
  submitTravelRequest,
  type StableSubmissionKey,
  type TravelRequest,
} from '../api'

const props = withDefaults(
  defineProps<{
    modelValue: boolean
    mode?: 'request' | 'change'
    request?: TravelRequest | null
  }>(),
  { mode: 'request', request: null },
)

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  submitted: [request: TravelRequest | { changeId: number; travelId: number; caseId: number; changeStatus: string; reason: string }]
}>()

const form = reactive({
  fromDate: '',
  toDate: '',
  destination: '',
  purpose: '',
  reason: '',
})

const submitting = ref(false)
const validationMessage = ref('')
const serverError = ref<{ message: string; requestId: string | null } | null>(null)
const submissionKey = ref<StableSubmissionKey | null>(null)

const isChange = computed(() => props.mode === 'change')
const title = computed(() => (isChange.value ? '申请出差变更' : '申请出差'))
const claimable = computed(
  () => props.request !== null && ['PENDING_APPROVAL', 'APPROVED'].includes(props.request.status),
)

watch(
  () => [props.modelValue, props.mode, props.request?.travelId, props.request?.fromDate, props.request?.toDate, props.request?.destination, props.request?.purpose] as const,
  ([open]) => {
    if (!open) return
    validationMessage.value = ''
    serverError.value = null
    submissionKey.value = null

    if (isChange.value && props.request !== null) {
      form.fromDate = props.request.fromDate
      form.toDate = props.request.toDate
      form.destination = props.request.destination ?? ''
      form.purpose = props.request.purpose
    } else {
      form.fromDate = ''
      form.toDate = ''
      form.destination = ''
      form.purpose = ''
    }
    form.reason = ''
  },
  { immediate: true },
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
  if (isChange.value && form.reason.trim().length < 3) {
    validationMessage.value = '变更原因至少需要 3 个字符。'
    return false
  }
  if (isChange.value && (props.request === null || !claimable.value)) {
    validationMessage.value = '当前出差申请状态不允许变更。'
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
  serverError.value = { message: errorCopy(reason), requestId: null }
}

async function submit(): Promise<void> {
  serverError.value = null
  if (!validate()) return

  const payload: Record<string, unknown> = {
    mode: props.mode,
    travel_id: props.request?.travelId ?? null,
    from_date: form.fromDate,
    to_date: form.toDate,
    destination: form.destination.trim(),
    purpose: form.purpose.trim(),
  }
  if (isChange.value) payload.reason = form.reason.trim()

  submissionKey.value = stableSubmissionKey(
    submissionKey.value,
    isChange.value ? 'travel-change' : 'travel-request',
    payload,
  )
  submitting.value = true

  try {
    if (isChange.value && props.request !== null) {
      const change = await submitTravelChange(
        {
          travelId: props.request.travelId,
          fromDate: form.fromDate,
          toDate: form.toDate,
          destination: form.destination.trim(),
          purpose: form.purpose.trim(),
          reason: form.reason.trim(),
        },
        submissionKey.value.key,
      )
      emit('submitted', change)
    } else {
      const created = await submitTravelRequest(
        {
          fromDate: form.fromDate,
          toDate: form.toDate,
          destination: form.destination.trim(),
          purpose: form.purpose.trim(),
        },
        submissionKey.value.key,
      )
      emit('submitted', created)
    }
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
    <section class="travel-drawer__panel" role="dialog" aria-modal="true" :aria-label="title">
      <header class="travel-drawer__header">
        <div>
          <p class="travel-drawer__eyebrow">出差管理</p>
          <h2>{{ title }}</h2>
          <p class="travel-drawer__muted">
            {{ isChange ? '变更提交后生成独立审批案例；批准前原行程保持不变。' : '提交后生成审批案例；审批通过后方可登记费用。' }}
          </p>
        </div>
        <button type="button" class="travel-drawer__close" aria-label="关闭" @click="close">关闭</button>
      </header>

      <form class="travel-drawer__form" data-testid="travel-request-form" @submit.prevent="submit">
        <p v-if="isChange && props.request" class="travel-drawer__reference" data-testid="travel-change-reference">
          变更对象：#{{ props.request.travelId }} · {{ props.request.destination ?? '未填写目的地' }}
        </p>

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

        <label v-if="isChange" class="travel-drawer__field">
          <span>变更原因</span>
          <textarea
            v-model="form.reason"
            data-testid="travel-change-reason"
            rows="2"
            maxlength="500"
            placeholder="请说明变更原因，将与审批案例一起留痕"
            required
          />
        </label>

        <p v-if="!isChange" class="travel-drawer__disclosure" data-testid="travel-expense-disclosure">
          申请接口仅持久化目的地、日期与事由；费用需在审批通过后按实际金额登记，金额以整数分保存。
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
            {{ submitting ? '提交中…' : isChange ? '提交变更' : '提交申请' }}
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
  width: min(540px, 100vw);
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
  margin: 0;
  color: #64748b;
  font-size: 11px;
  letter-spacing: 0.12em;
}

.travel-drawer__header h2 {
  margin: 4px 0 6px;
  font-size: 18px;
}

.travel-drawer__muted {
  margin: 0;
  color: #64748b;
  font-size: 11.5px;
  line-height: 1.6;
}

.travel-drawer__close,
.travel-drawer__secondary {
  align-self: flex-start;
  padding: 6px 10px;
  color: #475569;
  font-size: 12px;
  background: transparent;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
  cursor: pointer;
}

.travel-drawer__form {
  display: grid;
  gap: 14px;
  padding: 18px 22px 24px;
}

.travel-drawer__reference {
  margin: 0;
  padding: 8px 10px;
  color: #1e3a8a;
  font-size: 11.5px;
  background: #eff6ff;
  border: 1px solid #bfdbfe;
  border-radius: 7px;
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
