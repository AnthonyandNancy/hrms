<script setup lang="ts">
import { computed, ref } from 'vue'
import { ApiError, errorCopy } from '@/api/errors'
import { formatBusinessDate } from '@/utils/format'
import {
  SETTLEMENT_METHOD_LABELS,
  overtimeStatusLabel,
  recordNextStep,
  settleOvertimeRecord,
  stableSubmissionKey,
  type OvertimeRecord,
  type StableSubmissionKey,
} from '../api'

const props = withDefaults(
  defineProps<{
    records: OvertimeRecord[]
    canSettle: boolean
    loading?: boolean
    error?: string
  }>(),
  {
    loading: false,
    error: '',
  },
)

const emit = defineEmits<{
  settled: [method: 'PAY' | 'TIMEOFF']
}>()

const activeRecordId = ref<number | null>(null)
const method = ref<'PAY' | 'TIMEOFF'>('TIMEOFF')
const reason = ref('')
const submitting = ref(false)
const validationMessage = ref('')
const serverError = ref<string | null>(null)
const submissionKey = ref<StableSubmissionKey | null>(null)

const methodOptions = [
  { value: 'TIMEOFF' as const, label: SETTLEMENT_METHOD_LABELS.TIMEOFF },
  { value: 'PAY' as const, label: SETTLEMENT_METHOD_LABELS.PAY },
]

const hasRecords = computed(() => props.records.length > 0)

function minutesLabel(minutes: number): string {
  const hours = Math.floor(minutes / 60)
  const rest = minutes % 60
  if (hours === 0) return `${rest} 分钟`
  if (rest === 0) return `${hours} 小时`
  return `${hours} 小时 ${rest} 分钟`
}

function startSettle(record: OvertimeRecord): void {
  activeRecordId.value = record.id
  method.value = 'TIMEOFF'
  reason.value = ''
  validationMessage.value = ''
  serverError.value = null
  submissionKey.value = null
}

function cancelSettle(): void {
  if (submitting.value) return
  activeRecordId.value = null
  reason.value = ''
  validationMessage.value = ''
}

async function confirmSettle(record: OvertimeRecord): Promise<void> {
  serverError.value = null
  if (reason.value.trim().length < 3) {
    validationMessage.value = '结算原因至少需要 3 个字符。'
    return
  }
  validationMessage.value = ''
  const payload = {
    overtime_record_id: record.id,
    method: method.value,
    reason: reason.value.trim(),
  }
  submissionKey.value = stableSubmissionKey(submissionKey.value, 'overtime-settlement', payload)

  submitting.value = true
  try {
    await settleOvertimeRecord(
      { recordId: record.id, method: method.value, reason: reason.value.trim() },
      submissionKey.value.key,
    )
    emit('settled', method.value)
    activeRecordId.value = null
    reason.value = ''
    submissionKey.value = null
  } catch (reasonValue) {
    serverError.value =
      reasonValue instanceof ApiError
        ? `${errorCopy(reasonValue)}${reasonValue.requestId ? ` 请求编号：${reasonValue.requestId}` : ''}`
        : errorCopy(reasonValue)
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <section class="overtime-records" aria-labelledby="overtime-records-title" data-testid="overtime-records">
    <header class="overtime-records__header">
      <div>
        <h3 id="overtime-records-title">已确认加班记录</h3>
        <p class="overtime-records__muted">每条记录只能结算一次：调休或折算加班费，二选一。</p>
      </div>
      <span class="overtime-records__count">{{ props.records.length }} 条</span>
    </header>

    <p v-if="props.error" class="overtime-records__error" role="alert">{{ props.error }}</p>

    <p v-if="!hasRecords && !props.loading" class="overtime-records__empty" role="status">
      暂无加班记录。确认加班申请后，服务端会生成可结算记录。
    </p>

    <div v-else class="overtime-records__scroller">
      <table class="overtime-records__table">
        <thead>
          <tr>
            <th scope="col">业务日期</th>
            <th scope="col">确认分钟</th>
            <th scope="col">状态</th>
            <th scope="col">结算</th>
            <th scope="col">下一步</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="record in props.records" :key="record.id" :data-testid="`overtime-record-${record.id}`">
            <td>{{ formatBusinessDate(record.businessDate) }}</td>
            <td>
              <span class="mono">{{ record.minutes }}</span>
              <small class="overtime-records__muted">（{{ minutesLabel(record.minutes) }}）</small>
            </td>
            <td>{{ overtimeStatusLabel(record.status) }}</td>
            <td>
              <span v-if="record.settlementMethod !== null" class="overtime-records__settled">
                {{ SETTLEMENT_METHOD_LABELS[record.settlementMethod] }}
              </span>
              <span v-else class="overtime-records__pending">未结算</span>
            </td>
            <td>
              <div v-if="activeRecordId === record.id" class="overtime-records__form">
                <label>
                  <span>结算方式</span>
                  <select v-model="method" :data-testid="`overtime-settlement-method-${record.id}`">
                    <option v-for="option in methodOptions" :key="option.value" :value="option.value">
                      {{ option.label }}
                    </option>
                  </select>
                </label>
                <label>
                  <span>结算原因</span>
                  <input
                    v-model="reason"
                    :data-testid="`overtime-settlement-reason-${record.id}`"
                    maxlength="255"
                    placeholder="例如：本月选择调休"
                  />
                </label>
                <p v-if="validationMessage" class="overtime-records__error" role="alert">
                  {{ validationMessage }}
                </p>
                <p v-if="serverError" class="overtime-records__error" role="alert">
                  {{ serverError }}
                </p>
                <div class="overtime-records__actions">
                  <button type="button" class="overtime-records__cancel" :disabled="submitting" @click="cancelSettle">
                    取消
                  </button>
                  <button
                    type="button"
                    class="overtime-records__confirm"
                    :disabled="submitting"
                    :data-testid="`overtime-settlement-confirm-${record.id}`"
                    @click="confirmSettle(record)"
                  >
                    {{ submitting ? '结算中…' : '确认结算' }}
                  </button>
                </div>
              </div>
              <template v-else-if="props.canSettle && record.settlementMethod === null">
                <span class="overtime-records__pending">{{ recordNextStep(record) }}</span>
                <button
                  type="button"
                  class="overtime-records__trigger"
                  :data-testid="`overtime-settle-${record.id}`"
                  @click="startSettle(record)"
                >
                  结算
                </button>
              </template>
              <span v-else class="overtime-records__muted">{{ recordNextStep(record) }}</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>

<style scoped>
.overtime-records {
  margin-top: 16px;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
}

.overtime-records__header {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  padding: 14px 16px;
  border-bottom: 1px solid #e2e8f0;
}

.overtime-records__header h3 {
  margin: 0;
  font-size: 14px;
}

.overtime-records__muted {
  margin: 3px 0 0;
  color: #64748b;
  font-size: 11px;
}

.overtime-records__count {
  align-self: flex-start;
  padding: 3px 8px;
  color: #1d4ed8;
  font-size: 11px;
  background: #eff6ff;
  border-radius: 999px;
}

.overtime-records__empty {
  margin: 0;
  padding: 18px 16px;
  color: #64748b;
  font-size: 12px;
}

.overtime-records__scroller {
  overflow-x: auto;
}

.overtime-records__table {
  width: 100%;
  font-size: 12px;
  border-collapse: collapse;
}

.overtime-records__table th,
.overtime-records__table td {
  padding: 10px 12px;
  text-align: left;
  border-bottom: 1px solid #f1f5f9;
  vertical-align: top;
}

.overtime-records__table th {
  color: #64748b;
  font-weight: 500;
  background: #f8fafc;
}

.overtime-records__settled {
  color: #047857;
}

.overtime-records__pending {
  color: #b45309;
}

.overtime-records__trigger,
.overtime-records__confirm {
  padding: 5px 10px;
  color: #fff;
  font-size: 11px;
  background: #1d4ed8;
  border: 0;
  border-radius: 6px;
  cursor: pointer;
}

.overtime-records__cancel {
  padding: 5px 10px;
  color: #475569;
  font-size: 11px;
  background: transparent;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
  cursor: pointer;
}

.overtime-records__form {
  display: grid;
  gap: 6px;
  min-width: 220px;
}

.overtime-records__form label {
  display: flex;
  flex-direction: column;
  gap: 3px;
  font-size: 10.5px;
}

.overtime-records__form select,
.overtime-records__form input {
  padding: 5px 7px;
  font: inherit;
  font-size: 11px;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
}

.overtime-records__actions {
  display: flex;
  gap: 6px;
}

.overtime-records__error {
  margin: 0;
  color: #b91c1c;
  font-size: 11px;
  line-height: 1.5;
}

.mono {
  font-family: ui-monospace, monospace;
}
</style>
