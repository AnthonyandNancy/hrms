<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { ApiError, errorCopy } from '@/api/errors'
import { requestIdFromError } from '@/utils/renderErrors'
import {
  COMPENSATION_PREFERENCE_LABELS,
  stableSubmissionKey,
  submitOvertimeRequest,
  type OvertimePolicy,
  type OvertimeRequest,
  type StableSubmissionKey,
} from '../api'

const props = withDefaults(
  defineProps<{
    modelValue: boolean
    policy?: OvertimePolicy | null
  }>(),
  {
    policy: null,
  },
)

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  submitted: [request: OvertimeRequest]
}>()

const preferenceOptions = [
  { value: 'TIMEOFF' as const, label: COMPENSATION_PREFERENCE_LABELS.TIMEOFF },
  { value: 'PAY' as const, label: COMPENSATION_PREFERENCE_LABELS.PAY },
]

const form = reactive({
  businessDate: '',
  startTime: '18:00',
  endTime: '20:00',
  reason: '',
  compensation: 'TIMEOFF' as 'PAY' | 'TIMEOFF',
})

const submitting = ref(false)
const validationMessage = ref('')
const serverError = ref<{ message: string; requestId: string | null; fieldErrors: Record<string, string[]> } | null>(null)
const submissionKey = ref<StableSubmissionKey | null>(null)

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    serverError.value = null
    validationMessage.value = ''
    submissionKey.value = null
  },
)

watch(
  () => props.policy,
  (policy) => {
    if (policy === null) return
    if (policy.allowTimeoff && !policy.allowPay) form.compensation = 'TIMEOFF'
    if (policy.allowPay && !policy.allowTimeoff) form.compensation = 'PAY'
  },
  { immediate: true },
)

const plannedMinutes = computed<number | null>(() => {
  const start = clockMinutes(form.startTime)
  const end = clockMinutes(form.endTime)
  if (start === null || end === null || start === end) return null
  const raw = end > start ? end - start : 1_440 - start + end
  return raw > 0 && raw <= 10_080 ? raw : null
})

const declaredFieldError = computed(() => {
  const message = serverError.value?.fieldErrors.business_date?.[0] ?? ''
  return message
})

const reasonFieldError = computed(() => serverError.value?.fieldErrors.reason?.[0] ?? '')

function clockMinutes(value: string): number | null {
  const match = /^(\d{2}):(\d{2})$/.exec(value)
  if (match === null) return null
  const hours = Number(match[1])
  const minutes = Number(match[2])
  if (!Number.isInteger(hours) || !Number.isInteger(minutes)) return null
  if (hours < 0 || hours > 23 || minutes < 0 || minutes > 59) return null
  return hours * 60 + minutes
}

function minutesLabel(minutes: number | null): string {
  if (minutes === null) return '请填写有效的开始与结束时间'
  const hours = Math.floor(minutes / 60)
  const rest = minutes % 60
  if (hours === 0) return `${rest} 分钟`
  if (rest === 0) return `${hours} 小时`
  return `${hours} 小时 ${rest} 分钟`
}

function close(): void {
  if (submitting.value) return
  emit('update:modelValue', false)
}

function validate(): boolean {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(form.businessDate)) {
    validationMessage.value = '请选择有效的加班日期。'
    return false
  }
  if (plannedMinutes.value === null) {
    validationMessage.value = '计划开始与结束时间必须有效，且申报分钟数在 1 至 10080 之间。'
    return false
  }
  if (form.reason.trim().length < 3) {
    validationMessage.value = '加班原因至少需要 3 个字符。'
    return false
  }
  if (props.policy !== null) {
    if (form.compensation === 'PAY' && !props.policy.allowPay) {
      validationMessage.value = '当前政策不允许将加班折算为加班费。'
      return false
    }
    if (form.compensation === 'TIMEOFF' && !props.policy.allowTimeoff) {
      validationMessage.value = '当前政策不允许调休补偿。'
      return false
    }
  }
  validationMessage.value = ''
  return true
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

async function submit(): Promise<void> {
  serverError.value = null
  if (!validate() || plannedMinutes.value === null) return

  const payload = {
    business_date: form.businessDate,
    declared_minutes: plannedMinutes.value,
    reason: form.reason.trim(),
  }

  // The preference is a request-time intent only. The backend settles through
  // the explicit PAY/TIMEOFF settlement command, so it is deliberately not
  // smuggled into the declared-minutes payload.
  const fingerprint = JSON.stringify({ ...payload, preference: form.compensation })
  submissionKey.value = stableSubmissionKey(
    submissionKey.value !== null && submissionKey.value.fingerprint === fingerprint
      ? submissionKey.value
      : null,
    'overtime-request',
    { ...payload, preference: form.compensation },
  )

  submitting.value = true
  try {
    const created = await submitOvertimeRequest(
      {
        businessDate: form.businessDate,
        declaredMinutes: plannedMinutes.value,
        reason: form.reason.trim(),
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
  <div v-if="props.modelValue" class="overtime-drawer" role="presentation" @click.self="close">
    <section class="overtime-drawer__panel" role="dialog" aria-modal="true" aria-label="申请加班">
      <header class="overtime-drawer__header">
        <div>
          <p class="overtime-drawer__eyebrow">加班</p>
          <h2>申请加班</h2>
          <p class="overtime-drawer__muted">申报计划时段与原因；实际分钟由有确认权限的审批人单独确认。</p>
        </div>
        <button type="button" class="overtime-drawer__close" aria-label="关闭加班申请" @click="close">关闭</button>
      </header>

      <form class="overtime-drawer__form" data-testid="overtime-request-form" @submit.prevent="submit">
        <section class="overtime-drawer__policy" data-testid="overtime-policy" aria-label="当前适用加班政策">
          <div class="overtime-drawer__policy-head">
            <span>当前适用加班政策</span>
            <span class="overtime-drawer__source">{{ props.policy?.source || '未返回来源' }}</span>
          </div>
          <dl class="overtime-drawer__policy-grid">
            <div>
              <dt>工作日倍率</dt>
              <dd>{{ props.policy?.multiplier || '—' }}</dd>
            </div>
            <div>
              <dt>法定节假日倍率</dt>
              <dd>{{ props.policy?.statutoryHolidayMultiplier || '—' }}</dd>
            </div>
            <div>
              <dt>允许加班费</dt>
              <dd>{{ props.policy === null ? '—' : props.policy.allowPay ? '允许' : '不允许' }}</dd>
            </div>
            <div>
              <dt>允许调休</dt>
              <dd>{{ props.policy === null ? '—' : props.policy.allowTimeoff ? '允许' : '不允许' }}</dd>
            </div>
          </dl>
          <p class="overtime-drawer__policy-note">
            {{ props.policy?.note || '政策由服务端返回；页面不会在本地编造倍率。' }}
          </p>
        </section>

        <label class="overtime-drawer__field">
          <span>加班日期</span>
          <input v-model="form.businessDate" data-testid="overtime-date" type="date" required />
          <small v-if="declaredFieldError">{{ declaredFieldError }}</small>
        </label>

        <div class="overtime-drawer__field-row">
          <label class="overtime-drawer__field">
            <span>计划开始</span>
            <input v-model="form.startTime" data-testid="overtime-start" type="time" required />
          </label>
          <label class="overtime-drawer__field">
            <span>计划结束</span>
            <input v-model="form.endTime" data-testid="overtime-end" type="time" required />
          </label>
        </div>

        <p class="overtime-drawer__minutes" data-testid="overtime-planned-minutes">
          申报分钟数：<strong>{{ plannedMinutes ?? '—' }}</strong>
          <span class="overtime-drawer__muted">（{{ minutesLabel(plannedMinutes) }}，整数分钟）</span>
        </p>

        <label class="overtime-drawer__field">
          <span>补偿偏好</span>
          <select v-model="form.compensation" data-testid="overtime-compensation">
            <option
              v-for="option in preferenceOptions"
              :key="option.value"
              :value="option.value"
              :disabled="option.value === 'PAY' ? props.policy?.allowPay === false : props.policy?.allowTimeoff === false"
            >
              {{ option.label }}
            </option>
          </select>
          <small class="overtime-drawer__muted">
            偏好仅供申报参考；实际补偿方式在加班记录结算时明确选择“调休”或“折算加班费”。
          </small>
        </label>

        <label class="overtime-drawer__field">
          <span>加班原因</span>
          <textarea
            v-model="form.reason"
            data-testid="overtime-reason"
            rows="3"
            maxlength="500"
            placeholder="请说明加班事项与必要性"
            required
          />
          <small v-if="reasonFieldError">{{ reasonFieldError }}</small>
        </label>

        <p v-if="validationMessage" class="overtime-drawer__error" role="alert" data-testid="overtime-request-validation">
          {{ validationMessage }}
        </p>

        <p v-if="serverError" class="overtime-drawer__error" role="alert" data-testid="overtime-request-error">
          {{ serverError.message }}
          <template v-if="serverError.requestId"> 请求编号：<span class="mono">{{ serverError.requestId }}</span></template>
        </p>

        <footer class="overtime-drawer__footer">
          <button type="button" class="overtime-drawer__secondary" :disabled="submitting" @click="close">取消</button>
          <button type="submit" class="overtime-drawer__primary" :disabled="submitting" data-testid="overtime-request-submit">
            {{ submitting ? '提交中…' : '提交' }}
          </button>
        </footer>
      </form>
    </section>
  </div>
</template>

<style scoped>
.overtime-drawer {
  position: fixed;
  inset: 0;
  z-index: 40;
  display: flex;
  justify-content: flex-end;
  background: rgb(15 23 42 / 38%);
}

.overtime-drawer__panel {
  display: flex;
  flex-direction: column;
  width: min(540px, 100vw);
  height: 100%;
  overflow-y: auto;
  background: #fff;
  box-shadow: -8px 0 24px rgb(15 23 42 / 18%);
}

.overtime-drawer__header {
  display: flex;
  justify-content: space-between;
  gap: 16px;
  padding: 20px 22px 16px;
  border-bottom: 1px solid var(--color-line, #e2e8f0);
}

.overtime-drawer__eyebrow {
  color: #64748b;
  font-size: 11px;
  letter-spacing: 0.12em;
}

.overtime-drawer__header h2 {
  margin: 4px 0 6px;
  font-size: 18px;
}

.overtime-drawer__muted {
  color: #64748b;
  font-size: 12px;
  line-height: 1.6;
}

.overtime-drawer__close,
.overtime-drawer__secondary {
  color: #475569;
  font-size: 12px;
  background: transparent;
  border: 0;
  cursor: pointer;
}

.overtime-drawer__form {
  display: grid;
  gap: 14px;
  padding: 18px 22px 24px;
}

.overtime-drawer__policy {
  padding: 14px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
}

.overtime-drawer__policy-head {
  display: flex;
  justify-content: space-between;
  gap: 8px;
  font-size: 12px;
  font-weight: 600;
}

.overtime-drawer__source {
  color: #64748b;
  font-family: ui-monospace, monospace;
  font-size: 10.5px;
  font-weight: 400;
}

.overtime-drawer__policy-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px 14px;
  margin: 12px 0 8px;
}

.overtime-drawer__policy-grid dt {
  color: #64748b;
  font-size: 10.5px;
}

.overtime-drawer__policy-grid dd {
  margin: 2px 0 0;
  font-family: ui-monospace, monospace;
  font-size: 13px;
}

.overtime-drawer__policy-note {
  margin: 0;
  color: #64748b;
  font-size: 10.5px;
  line-height: 1.5;
}

.overtime-drawer__field,
.overtime-drawer__field-row {
  display: flex;
  flex-direction: column;
  gap: 4px;
  font-size: 12px;
}

.overtime-drawer__field-row {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}

.overtime-drawer__field input,
.overtime-drawer__field select,
.overtime-drawer__field textarea {
  padding: 8px 10px;
  font: inherit;
  color: #0f172a;
  background: #fff;
  border: 1px solid #cbd5e1;
  border-radius: 7px;
}

.overtime-drawer__field small {
  color: #b91c1c;
}

.overtime-drawer__minutes {
  margin: 0;
  color: #334155;
  font-size: 12px;
}

.overtime-drawer__minutes strong {
  font-family: ui-monospace, monospace;
  font-size: 14px;
}

.overtime-drawer__error {
  margin: 0;
  padding: 10px 12px;
  color: #991b1b;
  font-size: 12px;
  line-height: 1.5;
  background: #fef2f2;
  border: 1px solid #fecaca;
  border-radius: 8px;
}

.overtime-drawer__footer {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  padding-top: 4px;
}

.overtime-drawer__primary {
  padding: 8px 18px;
  color: #fff;
  font-size: 12px;
  background: #1d4ed8;
  border: 0;
  border-radius: 7px;
  cursor: pointer;
}

.overtime-drawer__primary:disabled,
.overtime-drawer__secondary:disabled {
  cursor: not-allowed;
  opacity: 0.55;
}
</style>
