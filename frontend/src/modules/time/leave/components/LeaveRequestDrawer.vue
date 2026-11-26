<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { ApiError, errorCopy } from '@/api/errors'
import {
  formatLeaveNumber,
  formatLeaveQuantity,
  leaveUnitLabel,
  newLeaveIdempotencyKey,
  previewLeave,
  previewConflictText,
  submitLeaveRequest,
  type LeaveBalance,
  type LeavePreview,
  type LeaveRequestInput,
  type LeaveTypeOption,
} from '../api'

const props = withDefaults(
  defineProps<{
    modelValue: boolean
    types: LeaveTypeOption[]
    balances: LeaveBalance[]
    submitEnabled?: boolean
  }>(),
  { submitEnabled: false },
)

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  submitted: [payload: { caseNo: string }]
}>()

const REASON_MIN_LENGTH = 3

const form = reactive<LeaveRequestInput>({
  leaveTypeCode: props.types[0]?.code ?? '',
  startDate: '',
  endDate: '',
  halfDay: false,
  reason: '',
})

const preview = ref<LeavePreview | null>(null)
const previewFingerprint = ref('')
const previewLoading = ref(false)
const submitting = ref(false)
const validationMessage = ref('')
const submitError = ref<{ message: string; requestId: string | null; fieldErrors: Record<string, string[]> } | null>(null)

// The key belongs to one immutable submission. It is replaced only after a
// successful submit or when the user edits the request, so retrying the same
// failed/uncertain POST reuses the server idempotency record.
let submissionKey: string | null = null
let submissionFingerprint = ''

const fingerprint = computed(() =>
  JSON.stringify([
    form.leaveTypeCode,
    form.startDate,
    form.endDate,
    form.halfDay,
    form.reason.trim(),
  ]),
)

/**
 * Preview freshness depends only on the server-validated request inputs
 * (type, dates, half-day). The free-text reason does not change the deduction
 * or conflicts, but it is still part of the submission fingerprint used for
 * the idempotency key below.
 */
const previewKey = computed(() =>
  JSON.stringify([form.leaveTypeCode, form.startDate, form.endDate, form.halfDay]),
)

const previewFresh = computed(
  () => preview.value !== null && previewFingerprint.value === previewKey.value,
)

const selectedType = computed(
  () => props.types.find((type) => type.code === form.leaveTypeCode) ?? null,
)

const balance = computed(
  () => props.balances.find((item) => item.leaveTypeCode === form.leaveTypeCode) ?? null,
)

const blockingConflict = computed(
  () => preview.value?.conflicts.some((conflict) => conflict.severity === 'BLOCKING') ?? false,
)

const unit = computed(
  () => preview.value?.unit ?? balance.value?.unit ?? selectedType.value?.unit ?? 'DAY',
)

const previewUnitLabel = computed(() => leaveUnitLabel(unit.value))

const halfDayDisabled = computed(() => form.startDate === '' || form.endDate === '' || form.startDate !== form.endDate)

function close(): void {
  if (submitting.value) return
  emit('update:modelValue', false)
}

function resetSubmission(): void {
  submissionKey = null
  submissionFingerprint = ''
  submitError.value = null
  validationMessage.value = ''
}

/**
 * After a failed POST the user may legitimately retry the exact same payload.
 * That retry must reuse the key so the server can replay a request whose
 * response was lost. Editing any material field starts a new submission.
 */
function forgetSubmissionIfChanged(): void {
  if (submissionKey === null) return
  if (submissionFingerprint !== fingerprint.value) {
    submissionKey = null
    submissionFingerprint = ''
  }
}

function resetPreview(): void {
  preview.value = null
  previewFingerprint.value = ''
}

function canPreview(): boolean {
  return (
    form.leaveTypeCode !== '' &&
    /^\d{4}-\d{2}-\d{2}$/.test(form.startDate) &&
    /^\d{4}-\d{2}-\d{2}$/.test(form.endDate) &&
    form.endDate >= form.startDate &&
    (!form.halfDay || form.startDate === form.endDate)
  )
}

function validate(): boolean {
  validationMessage.value = ''
  if (form.leaveTypeCode === '') {
    validationMessage.value = '请选择假期类型。'
    return false
  }
  if (!/^\d{4}-\d{2}-\d{2}$/.test(form.startDate) || !/^\d{4}-\d{2}-\d{2}$/.test(form.endDate)) {
    validationMessage.value = '请选择开始日期与结束日期。'
    return false
  }
  if (form.endDate < form.startDate) {
    validationMessage.value = '结束日期不能早于开始日期。'
    return false
  }
  if (form.halfDay && form.startDate !== form.endDate) {
    validationMessage.value = '半天假必须开始与结束于同一天。'
    return false
  }
  if (form.reason.trim().length < REASON_MIN_LENGTH) {
    validationMessage.value = `请假事由至少需要 ${REASON_MIN_LENGTH} 个字。`
    return false
  }
  return true
}

function captureSubmitError(reason: unknown): void {
  if (reason instanceof ApiError) {
    submitError.value = {
      message: errorCopy(reason),
      requestId: reason.requestId,
      fieldErrors: { ...reason.fieldErrors },
    }
    return
  }
  submitError.value = { message: errorCopy(reason), requestId: null, fieldErrors: {} }
}

let previewRequest = 0

async function runPreview(): Promise<void> {
  if (!canPreview()) {
    resetPreview()
    return
  }

  const requestedFingerprint = previewKey.value
  const requestId = ++previewRequest
  previewLoading.value = true
  // Any preview from a previous form value is stale immediately.
  preview.value = null
  previewFingerprint.value = ''
  submitError.value = null

  try {
    const result = await previewLeave({ ...form, reason: form.reason.trim() })
    if (requestId !== previewRequest) return
    preview.value = result
    previewFingerprint.value = requestedFingerprint
  } catch (reason) {
    if (requestId !== previewRequest) return
    resetPreview()
    if (reason instanceof ApiError) {
      submitError.value = {
        message: errorCopy(reason),
        requestId: reason.requestId,
        fieldErrors: { ...reason.fieldErrors },
      }
    } else {
      submitError.value = { message: errorCopy(reason), requestId: null, fieldErrors: {} }
    }
  } finally {
    if (requestId === previewRequest) previewLoading.value = false
  }
}

function schedulePreview(): void {
  resetPreview()
  forgetSubmissionIfChanged()
  validationMessage.value = ''
  previewLoading.value = false
  previewRequest += 1
  void runPreview()
}

async function submit(): Promise<void> {
  if (submitting.value) return
  submitError.value = null
  validationMessage.value = ''

  if (!validate()) return
  if (preview.value === null) {
    validationMessage.value = '预览已过期：请重新检查余额与冲突后再提交。'
    return
  }
  if (!previewFresh.value) {
    validationMessage.value = '预览已过期：请重新检查余额与冲突后再提交。'
    return
  }
  if (blockingConflict.value) {
    validationMessage.value = '存在阻断性冲突，无法提交。请调整日期或余额后重试。'
    return
  }
  if (!props.submitEnabled) return

  submitting.value = true
  try {
    if (submissionKey === null || submissionFingerprint !== fingerprint.value) {
      submissionKey = newLeaveIdempotencyKey('leave-request')
      submissionFingerprint = fingerprint.value
    }
    const result = await submitLeaveRequest({ ...form, reason: form.reason.trim() }, submissionKey)
    emit('submitted', { caseNo: result.caseNo })
    submissionKey = null
    submissionFingerprint = ''
    form.reason = ''
    resetPreview()
    emit('update:modelValue', false)
  } catch (reason) {
    captureSubmitError(reason)
  } finally {
    submitting.value = false
  }
}

watch(
  () => [form.leaveTypeCode, form.startDate, form.endDate, form.halfDay] as const,
  () => {
    if (form.halfDay && form.startDate !== form.endDate) form.halfDay = false
    schedulePreview()
  },
)

watch(
  () => form.reason,
  () => {
    forgetSubmissionIfChanged()
  },
)

watch(
  () => props.types,
  (types) => {
    if (form.leaveTypeCode === '' && types.length > 0) form.leaveTypeCode = types[0]?.code ?? ''
  },
  { deep: true },
)

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    previewLoading.value = false
    submitError.value = null
    validationMessage.value = ''
    resetPreview()
    resetSubmission()
    if (form.leaveTypeCode === '') form.leaveTypeCode = props.types[0]?.code ?? ''
    if (canPreview()) void runPreview()
  },
)
</script>

<template>
  <div v-if="props.modelValue" class="leave-drawer-overlay" role="presentation" @click.self="close">
    <section class="leave-drawer" role="dialog" aria-modal="true" aria-label="申请请假">
      <header class="leave-drawer__header">
        <div>
          <h2 class="leave-drawer__title">申请请假</h2>
          <p class="leave-drawer__subtitle">
            先由服务端预览扣减与日期冲突，确认后再提交。业务日期与余额均以服务端公司时区为准。
          </p>
        </div>
        <button type="button" class="leave-drawer__close" aria-label="关闭请假申请抽屉" @click="close">关闭</button>
      </header>

      <form class="leave-drawer__form" data-testid="leave-form" @submit.prevent="submit">
        <div class="leave-drawer__row">
          <label class="leave-drawer__field">
            <span>假期类型</span>
            <select v-model="form.leaveTypeCode" data-testid="leave-type" required>
              <option v-if="props.types.length === 0" value="" disabled>暂无可用假期类型</option>
              <option v-for="type in props.types" :key="type.code" :value="type.code">
                {{ type.name }}（{{ leaveUnitLabel(type.unit) }}）
              </option>
            </select>
            <small v-if="selectedType?.requiresAttachment" class="leave-drawer__hint">该假期类型需要证明材料，审批时补充。</small>
            <small v-else-if="selectedType?.allowNegative === false" class="leave-drawer__hint">不允许负余额；提交前必须满足可用余额。</small>
          </label>

          <div class="leave-drawer__field">
            <span>当前余额</span>
            <p class="leave-drawer__balance">
              <template v-if="balance">
                {{ formatLeaveQuantity(balance.available, balance.unit) }}
                <small>截至 {{ balance.asOf || '服务端未返回日期' }}</small>
              </template>
              <template v-else>服务端未返回该类型的余额</template>
            </p>
          </div>
        </div>

        <div class="leave-drawer__row">
          <label class="leave-drawer__field">
            <span>开始日期</span>
            <input v-model="form.startDate" data-testid="leave-start" type="date" required />
          </label>
          <label class="leave-drawer__field">
            <span>结束日期</span>
            <input v-model="form.endDate" data-testid="leave-end" type="date" required />
          </label>
        </div>

        <label class="leave-drawer__check">
          <input
            v-model="form.halfDay"
            data-testid="leave-half-day"
            type="checkbox"
            :disabled="halfDayDisabled"
          />
          <span>仅请半天（开始与结束必须为同一天）</span>
        </label>

        <label class="leave-drawer__field">
          <span>请假事由</span>
          <textarea
            v-model="form.reason"
            data-testid="leave-reason"
            rows="3"
            maxlength="500"
            placeholder="请填写审批人可见的具体事由"
          />
          <small class="leave-drawer__hint">必填，至少 {{ REASON_MIN_LENGTH }} 个字（与接口校验一致）。</small>
        </label>

        <section class="leave-drawer__preview" aria-live="polite" aria-label="请假预览" data-testid="leave-preview">
          <header class="leave-drawer__preview-head">
            <h3>服务端预览</h3>
            <span v-if="previewLoading" data-testid="leave-preview-loading">正在检查余额与冲突…</span>
            <span v-else-if="previewFresh" data-testid="leave-preview-fresh">预览已更新</span>
            <span v-else data-testid="leave-preview-stale">等待预览</span>
          </header>

          <template v-if="previewFresh && preview">
            <dl class="leave-drawer__deduction">
              <div>
                <dt>本次扣减</dt>
                <dd>{{ formatLeaveNumber(preview.requestedDays) }} {{ previewUnitLabel }}</dd>
              </div>
              <div>
                <dt>当前可用</dt>
                <dd>{{ formatLeaveNumber(preview.availableBefore) }} {{ previewUnitLabel }}</dd>
              </div>
              <div>
                <dt>提交后可用</dt>
                <dd :class="{ 'leave-drawer__negative': preview.availableAfter < 0 }">
                  {{ formatLeaveNumber(preview.availableAfter) }} {{ previewUnitLabel }}
                </dd>
              </div>
              <div>
                <dt>服务端计算天数</dt>
                <dd>{{ preview.workingDays }} 天</dd>
              </div>
            </dl>

            <p class="leave-drawer__preview-note">
              余额在提交时预占，审批通过后结算；预览不能替代服务端最终校验。
            </p>

            <ul v-if="preview.conflicts.length" class="leave-drawer__conflicts">
              <li
                v-for="(conflict, index) in preview.conflicts"
                :key="`${conflict.code}-${index}`"
                :class="conflict.severity === 'BLOCKING' ? 'leave-drawer__conflict--blocking' : 'leave-drawer__conflict--warning'"
              >
                <span class="leave-drawer__conflict-tag">
                  {{ conflict.severity === 'BLOCKING' ? '阻断' : '提醒' }}
                </span>
                {{ previewConflictText(conflict) }}
              </li>
            </ul>
          </template>

          <p v-else class="leave-drawer__preview-wait">
            调整类型或日期后，服务端会重新检查扣减与冲突；预览完成前不能提交。
          </p>
        </section>

        <div
          v-if="validationMessage || submitError"
          class="leave-drawer__error"
          role="alert"
          data-testid="leave-submit-error"
        >
          <p v-if="validationMessage">{{ validationMessage }}</p>
          <template v-if="submitError">
            <p>{{ submitError.message }}</p>
            <p v-for="(messages, field) in submitError.fieldErrors" :key="field" class="leave-drawer__field-error">
              {{ messages.join('；') }}
            </p>
            <p v-if="submitError.requestId" class="leave-drawer__request-id">
              请求编号：{{ submitError.requestId }}
            </p>
          </template>
        </div>

        <footer class="leave-drawer__actions">
          <button type="button" class="leave-drawer__secondary" :disabled="submitting" @click="close">取消</button>
          <button
            type="submit"
            class="leave-drawer__primary"
            data-testid="leave-submit"
            :disabled="submitting || !previewFresh || previewLoading"
          >
            {{ submitting ? '正在提交…' : '提交请假申请' }}
          </button>
        </footer>
      </form>
    </section>
  </div>
</template>

<style scoped>
.leave-drawer-overlay {
  position: fixed;
  inset: 0;
  z-index: 60;
  display: flex;
  justify-content: flex-end;
  background: rgb(15 23 32 / 36%);
}

.leave-drawer {
  display: flex;
  flex-direction: column;
  width: min(560px, 100vw);
  height: 100%;
  overflow-y: auto;
  background: #fff;
  box-shadow: -18px 0 48px rgb(15 23 32 / 18%);
}

.leave-drawer__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  padding: 20px 22px 14px;
  border-bottom: 1px solid var(--color-line);
}

.leave-drawer__title {
  margin: 0;
  font-size: 18px;
  font-weight: 700;
}

.leave-drawer__subtitle {
  max-width: 430px;
  margin: 5px 0 0;
  color: var(--color-ink-muted);
  font-size: 11.5px;
  line-height: 1.55;
}

.leave-drawer__close {
  padding: 5px 10px;
  font-size: 11px;
  background: transparent;
  border: 1px solid var(--color-line-strong);
  border-radius: 6px;
  cursor: pointer;
}

.leave-drawer__form {
  display: flex;
  flex-direction: column;
  gap: 13px;
  padding: 18px 22px 26px;
}

.leave-drawer__row {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}

.leave-drawer__field {
  display: flex;
  flex-direction: column;
  gap: 5px;
  min-width: 0;
  color: var(--color-ink-muted);
  font-size: 11px;
  font-weight: 600;
}

.leave-drawer__field select,
.leave-drawer__field input,
.leave-drawer__field textarea {
  padding: 7px 8px;
  font: inherit;
  font-weight: 400;
  color: var(--color-ink);
  background: #fff;
  border: 1px solid var(--color-line-strong);
  border-radius: 6px;
}

.leave-drawer__field textarea {
  resize: vertical;
}

.leave-drawer__balance {
  margin: 0;
  padding: 7px 9px;
  color: var(--color-ink);
  font-size: 13px;
  font-weight: 650;
  background: var(--color-canvas);
  border: 1px solid var(--color-line);
  border-radius: 6px;
  font-variant-numeric: tabular-nums;
}

.leave-drawer__balance small {
  display: block;
  color: var(--color-ink-muted);
  font-size: 9.5px;
  font-weight: 400;
}

.leave-drawer__hint {
  color: var(--color-ink-muted);
  font-size: 9.5px;
  font-weight: 400;
}

.leave-drawer__check {
  display: flex;
  align-items: center;
  gap: 7px;
  color: var(--color-ink-muted);
  font-size: 11.5px;
}

.leave-drawer__preview {
  padding: 12px 13px;
  background: var(--color-canvas);
  border: 1px solid var(--color-line);
  border-radius: 7px;
}

.leave-drawer__preview-head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 8px;
}

.leave-drawer__preview-head h3 {
  margin: 0;
  font-size: 12.5px;
  font-weight: 680;
}

.leave-drawer__preview-head span {
  color: var(--color-ink-muted);
  font-size: 10px;
}

.leave-drawer__deduction {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 6px;
  margin: 9px 0 7px;
}

.leave-drawer__deduction div {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
  padding: 7px 8px;
  background: #fff;
  border: 1px solid var(--color-line);
  border-radius: 5px;
}

.leave-drawer__deduction dt {
  color: var(--color-ink-muted);
  font-size: 9.5px;
}

.leave-drawer__deduction dd {
  margin: 0;
  font-size: 13px;
  font-weight: 650;
  font-variant-numeric: tabular-nums;
}

.leave-drawer__negative {
  color: var(--color-danger);
}

.leave-drawer__preview-note,
.leave-drawer__preview-wait {
  margin: 0;
  color: var(--color-ink-muted);
  font-size: 10px;
  line-height: 1.5;
}

.leave-drawer__conflicts {
  display: flex;
  flex-direction: column;
  gap: 5px;
  margin: 8px 0 0;
  padding: 0;
  list-style: none;
  font-size: 11px;
}

.leave-drawer__conflict--blocking {
  color: var(--color-danger);
}

.leave-drawer__conflict--warning {
  color: var(--color-warning);
}

.leave-drawer__conflict-tag {
  margin-right: 5px;
  padding: 1px 5px;
  font-size: 9px;
  border: 1px solid currentcolor;
  border-radius: 999px;
}

.leave-drawer__error {
  margin: 0;
  padding: 8px 10px;
  color: #9f1c12;
  font-size: 11.5px;
  background: #feecea;
  border: 1px solid #f5c2bd;
  border-radius: 6px;
}

.leave-drawer__error p {
  margin: 0;
}

.leave-drawer__field-error {
  margin-top: 4px;
  font-size: 10.5px;
}

.leave-drawer__request-id {
  margin-top: 4px;
  color: var(--color-ink-muted);
  font-family: var(--font-mono, monospace);
  font-size: 9.5px;
}

.leave-drawer__actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  padding-top: 4px;
}

.leave-drawer__primary,
.leave-drawer__secondary {
  padding: 7px 14px;
  font-size: 12px;
  border-radius: 6px;
  cursor: pointer;
}

.leave-drawer__primary {
  color: #fff;
  background: var(--color-primary, #1f5f8b);
  border: 1px solid var(--color-primary, #1f5f8b);
}

.leave-drawer__primary:disabled,
.leave-drawer__secondary:disabled {
  cursor: not-allowed;
  opacity: 0.55;
}

.leave-drawer__secondary {
  color: var(--color-ink);
  background: #fff;
  border: 1px solid var(--color-line-strong);
}

@media (max-width: 560px) {
  .leave-drawer__row,
  .leave-drawer__deduction {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
