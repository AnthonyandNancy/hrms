<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { ApiError, errorCopy } from '@/api/errors'
import {
  newAttendanceIdempotencyKey,
  requestAttendanceCorrection,
  type AttendanceCorrectionType,
} from '../api'

const props = withDefaults(
  defineProps<{
    modelValue: boolean
    businessDate: string
    timezone: string
    employmentId?: number | null
  }>(),
  {
    employmentId: null,
  },
)

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  submitted: [requestNo: string]
}>()

const REASON_MIN_LENGTH = 5

const CORRECTION_TYPES: Array<{ value: AttendanceCorrectionType; label: string }> = [
  { value: 'MISSING_PUNCH', label: '补卡' },
  { value: 'WRONG_TIME', label: '漏卡' },
  { value: 'WRONG_STATUS', label: '异常修正' },
]

const form = reactive({
  date: props.businessDate,
  correctionType: 'MISSING_PUNCH' as AttendanceCorrectionType,
  reason: '',
  firstIn: '',
  lastOut: '',
})

const submitting = ref(false)
const validationMessage = ref('')
const serverError = ref<{ message: string; requestId: string | null; fieldErrors: Record<string, string[]> } | null>(null)

watch(
  () => props.businessDate,
  (value) => {
    form.date = value
  },
)

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    form.date = props.businessDate
    serverError.value = null
    validationMessage.value = ''
  },
)

const reasonError = computed(() => {
  if (serverError.value === null) return ''
  return (serverError.value.fieldErrors.reason ?? []).join('；')
})

function close(): void {
  if (submitting.value) return
  emit('update:modelValue', false)
}

function validate(): boolean {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(form.date)) {
    validationMessage.value = '请选择有效的考勤日期。'
    return false
  }
  if (form.reason.trim().length < REASON_MIN_LENGTH) {
    validationMessage.value = `补卡原因至少需要 ${REASON_MIN_LENGTH} 个字符。`
    return false
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
  serverError.value = { message: errorCopy(reason), requestId: null, fieldErrors: {} }
}

async function submit(): Promise<void> {
  serverError.value = null
  if (!validate()) return

  submitting.value = true
  try {
    const result = await requestAttendanceCorrection(
      {
        date: form.date,
        correctionType: form.correctionType,
        reason: form.reason.trim(),
        firstIn: form.firstIn === '' ? undefined : form.firstIn,
        lastOut: form.lastOut === '' ? undefined : form.lastOut,
        employmentId: props.employmentId,
      },
      newAttendanceIdempotencyKey('attendance-correction'),
    )
    emit('submitted', result.requestNo)
    emit('update:modelValue', false)
    form.reason = ''
  } catch (reason) {
    captureServerError(reason)
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div v-if="props.modelValue" class="attendance-correction-overlay" role="presentation" @click.self="close">
    <section class="attendance-correction" role="dialog" aria-modal="true" aria-label="考勤补卡">
      <header class="attendance-correction__header">
        <div>
          <h2>发起补卡申请</h2>
          <p class="muted">打卡原始记录不可修改；提交后生成审批案例，审批通过后由服务端重算。</p>
        </div>
        <button type="button" class="attendance-correction__close" aria-label="关闭补卡抽屉" @click="close">关闭</button>
      </header>

      <form class="attendance-correction__form" data-testid="correction-form" @submit.prevent="submit">
        <label class="attendance-correction__field">
          <span>考勤日</span>
          <input v-model="form.date" data-testid="correction-date" type="date" required />
        </label>

        <label class="attendance-correction__field">
          <span>更正类型</span>
          <select v-model="form.correctionType" data-testid="correction-type">
            <option v-for="item in CORRECTION_TYPES" :key="item.value" :value="item.value">
              {{ item.label }}
            </option>
          </select>
        </label>

        <label class="attendance-correction__field">
          <span>上班时间</span>
          <input v-model="form.firstIn" data-testid="correction-first-in" type="time" />
        </label>

        <label class="attendance-correction__field">
          <span>下班时间</span>
          <input v-model="form.lastOut" data-testid="correction-last-out" type="time" />
        </label>

        <label class="attendance-correction__field attendance-correction__field--wide">
          <span>原因</span>
          <textarea
            v-model="form.reason"
            data-testid="correction-reason"
            rows="3"
            maxlength="500"
            aria-describedby="attendance-correction-reason-help"
          />
          <small id="attendance-correction-reason-help" class="muted">必填，至少 5 个字符。</small>
        </label>

        <p v-if="validationMessage" class="attendance-correction__error" role="alert" data-testid="correction-validation">
          {{ validationMessage }}
        </p>

        <p v-if="serverError" class="attendance-correction__error" role="alert" data-testid="correction-error">
          {{ serverError.message }}
          <template v-if="reasonError">（原因：{{ reasonError }}）</template>
          <template v-if="serverError.requestId"> 请求编号：<span class="mono">{{ serverError.requestId }}</span></template>
        </p>

        <p class="attendance-correction__note">
          时间按公司业务时区 {{ props.timezone || '——' }} 解释；页面不会以浏览器本地时区换算业务日期。
        </p>

        <footer class="attendance-correction__footer">
          <button type="button" class="attendance-correction__cancel" :disabled="submitting" @click="close">取消</button>
          <button type="submit" class="attendance-correction__submit" :disabled="submitting" data-testid="correction-submit">
            {{ submitting ? '提交中…' : '提交' }}
          </button>
        </footer>
      </form>
    </section>
  </div>
</template>

<style scoped>
.attendance-correction-overlay {
  position: fixed;
  inset: 0;
  z-index: 40;
  display: flex;
  justify-content: flex-end;
  background: rgb(15 23 42 / 35%);
}

.attendance-correction {
  display: flex;
  flex-direction: column;
  width: min(480px, 100vw);
  height: 100%;
  overflow-y: auto;
  background: #fff;
  box-shadow: -8px 0 24px rgb(15 23 42 / 18%);
}

.attendance-correction__header {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  padding: 18px 20px;
  border-bottom: 1px solid var(--color-line);
}

.attendance-correction__header h2 {
  font-size: 16px;
}

.attendance-correction__header p {
  margin-top: 4px;
  font-size: 11.5px;
}

.attendance-correction__close,
.attendance-correction__cancel {
  color: var(--color-ink-muted);
  font-size: 12px;
  background: transparent;
  border: 0;
  cursor: pointer;
}

.attendance-correction__form {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
  padding: 18px 20px;
}

.attendance-correction__field {
  display: flex;
  flex-direction: column;
  gap: 4px;
  font-size: 12px;
}

.attendance-correction__field--wide,
.attendance-correction__error,
.attendance-correction__note,
.attendance-correction__footer {
  grid-column: 1 / -1;
}

.attendance-correction__field input,
.attendance-correction__field select,
.attendance-correction__field textarea {
  padding: 7px 9px;
  font: inherit;
  background: #fff;
  border: 1px solid var(--color-line-strong);
  border-radius: 6px;
}

.attendance-correction__field small {
  font-size: 10.5px;
}

.attendance-correction__error {
  padding: 8px 10px;
  color: #9f1c12;
  font-size: 11.5px;
  background: #feecea;
  border: 1px solid #f5c2bd;
  border-radius: 6px;
}

.attendance-correction__note {
  color: var(--color-ink-muted);
  font-size: 11px;
}

.attendance-correction__footer {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 4px;
}

.attendance-correction__submit {
  padding: 7px 16px;
  color: #fff;
  font-size: 12px;
  background: var(--color-primary, #1f5f8b);
  border: 0;
  border-radius: 6px;
  cursor: pointer;
}

.attendance-correction__submit:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
</style>
