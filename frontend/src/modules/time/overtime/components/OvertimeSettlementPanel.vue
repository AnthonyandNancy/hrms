<script setup lang="ts">
import { computed, ref } from 'vue'
import { ApiError, errorCopy } from '@/api/errors'
import {
  formatMinutes,
  settleOvertimeRecord,
  stableSubmissionKey,
  type OvertimeRequest,
  type StableSubmissionKey,
} from '../api'

/**
 * Per-record settlement action. The backend's unique overtime_record_id is the
 * authority for single settlement; this UI only offers a method once and
 * surfaces ALREADY_SETTLED / PAYROLL_PERIOD_FROZEN as server errors.
 */
const props = defineProps<{
  requests: OvertimeRequest[]
  canSettle: boolean
}>()

const emit = defineEmits<{
  settled: [message: string]
  error: [message: string]
}>()

const activeRecordId = ref<number | null>(null)
const method = ref<'PAY' | 'TIMEOFF'>('TIMEOFF')
const reason = ref('')
const submitting = ref(false)
const validation = ref('')
const serverError = ref<{ message: string; frozen: boolean } | null>(null)
const submissionKey = ref<StableSubmissionKey | null>(null)

const candidates = computed(() =>
  props.requests.filter((request) =>
    request.overtimeRecordId !== null
    && request.settlementMethod === null
    && request.status === 'CONFIRMED',
  ),
)

function start(request: OvertimeRequest): void {
  if (!props.canSettle || request.overtimeRecordId === null) return
  activeRecordId.value = request.overtimeRecordId
  // TIMEOFF is the safe default; PAY remains an explicit choice and is
  // disabled when the server marked the target payroll period frozen.
  method.value = 'TIMEOFF'
  reason.value = ''
  validation.value = ''
  serverError.value = null
  submissionKey.value = null
}

function cancel(): void {
  if (submitting.value) return
  activeRecordId.value = null
  reason.value = ''
  validation.value = ''
  serverError.value = null
}

async function settle(request: OvertimeRequest): Promise<void> {
  if (!props.canSettle || request.overtimeRecordId === null) return
  if (reason.value.trim().length < 3) {
    validation.value = '结算原因至少需要 3 个字符。'
    return
  }
  validation.value = ''
  serverError.value = null
  const payload = {
    overtime_record_id: request.overtimeRecordId,
    method: method.value,
    reason: reason.value.trim(),
  }
  submissionKey.value = stableSubmissionKey(submissionKey.value, 'overtime-settlement', payload)
  submitting.value = true
  try {
    const settlement = await settleOvertimeRecord(
      { recordId: request.overtimeRecordId, method: method.value, reason: reason.value.trim() },
      submissionKey.value.key,
    )
    emit('settled', `加班记录已结算为${settlement.method === 'PAY' ? '折算加班费' : '调休'}。`)
    activeRecordId.value = null
    submissionKey.value = null
  } catch (caught) {
    const frozen = caught instanceof ApiError
    && (caught.serverReason === 'PAYROLL_PERIOD_FROZEN' || caught.details.reason === 'PAYROLL_PERIOD_FROZEN')
    serverError.value = {
      message: frozen
        ? '该工资期间已冻结，折算加班费被拒绝；当前仅可选择调休。'
        : errorCopy(caught),
      frozen,
    }
    emit('error', serverError.value.message)
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <section class="overtime-settlement-panel" data-testid="overtime-settlement-actions" aria-labelledby="overtime-settlement-title">
    <header class="overtime-settlement-panel__header">
      <div>
        <h2 id="overtime-settlement-title">待结算加班</h2>
        <p>每条加班记录只能结算一次；不同方式再次结算由服务端返回 409 ALREADY_SETTLED。</p>
      </div>
      <span class="overtime-settlement-panel__badge">{{ candidates.length }} 条</span>
    </header>

    <p v-if="!props.canSettle" class="overtime-settlement-panel__empty" role="status">当前账号没有加班结算权限。</p>
    <p v-else-if="candidates.length === 0" class="overtime-settlement-panel__empty" data-testid="overtime-settlement-empty" role="status">
      暂无待结算加班记录。
    </p>
    <div v-else class="overtime-settlement-panel__table-wrap">
      <table class="overtime-settlement-panel__table">
        <thead>
          <tr>
            <th scope="col">员工</th>
            <th scope="col">业务日期</th>
            <th scope="col">确认分钟</th>
            <th scope="col">工资期间</th>
            <th scope="col">结算</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="request in candidates" :key="String(request.overtimeRecordId)" :data-testid="`overtime-record-row-${String(request.overtimeRecordId)}`">
            <td>{{ request.employeeName ?? request.employeeNo ?? `#${request.employmentId}` }}</td>
            <td>{{ request.businessDate }}</td>
            <td>{{ formatMinutes(request.confirmedMinutes) }}</td>
            <td>{{ request.payrollPeriodFrozen === true ? '已冻结（仅调休）' : '未冻结' }}</td>
            <td>
              <div v-if="activeRecordId === request.overtimeRecordId" class="overtime-settlement-panel__form">
                <label>
                  <span>结算方式</span>
                  <select v-model="method" :data-testid="`overtime-settlement-method-${String(request.overtimeRecordId)}`">
                    <option value="TIMEOFF">调休</option>
                    <option value="PAY" :disabled="request.payrollPeriodFrozen === true">折算加班费</option>
                  </select>
                </label>
                <label>
                  <span>结算原因</span>
                  <input
                    v-model="reason"
                    :data-testid="`overtime-settlement-reason-${String(request.overtimeRecordId)}`"
                    maxlength="255"
                    placeholder="例如：本月选择调休"
                  />
                </label>
                <p v-if="validation" class="overtime-settlement-panel__error" role="alert">{{ validation }}</p>
                <p v-if="serverError" class="overtime-settlement-panel__error" role="alert">
                  {{ serverError.message }}
                  <span v-if="serverError.frozen">（仅调休，不再计薪）</span>
                </p>
                <div class="overtime-settlement-panel__actions">
                  <button type="button" class="overtime-settlement-panel__secondary" :disabled="submitting" @click="cancel">取消</button>
                  <button
                    type="button"
                    class="overtime-settlement-panel__primary"
                    :disabled="submitting"
                    :data-testid="`overtime-settlement-confirm-${String(request.overtimeRecordId)}`"
                    @click="settle(request)"
                  >
                    {{ submitting ? '结算中…' : '确认结算' }}
                  </button>
                </div>
              </div>
              <button
                v-else
                type="button"
                class="overtime-settlement-panel__secondary"
                :data-testid="`overtime-settle-${String(request.overtimeRecordId)}`"
                @click="start(request)"
              >
                结算
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>

<style scoped>
.overtime-settlement-panel {
  display: grid;
  gap: 12px;
  padding: 16px;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
}

.overtime-settlement-panel__header {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  align-items: flex-start;
}

.overtime-settlement-panel__header h2 {
  margin: 0;
  font-size: 16px;
}

.overtime-settlement-panel__header p {
  margin: 4px 0 0;
  color: #64748b;
  font-size: 11.5px;
}

.overtime-settlement-panel__badge {
  padding: 3px 8px;
  color: #1d4ed8;
  font-size: 11px;
  background: #eff6ff;
  border-radius: 999px;
}

.overtime-settlement-panel__empty {
  margin: 0;
  padding: 14px;
  color: #64748b;
  font-size: 12px;
  background: #f8fafc;
  border-radius: 7px;
}

.overtime-settlement-panel__table-wrap {
  overflow-x: auto;
}

.overtime-settlement-panel__table {
  width: 100%;
  font-size: 11.5px;
  border-collapse: collapse;
}

.overtime-settlement-panel__table th,
.overtime-settlement-panel__table td {
  padding: 8px 9px;
  text-align: left;
  vertical-align: top;
  border-bottom: 1px solid #f1f5f9;
}

.overtime-settlement-panel__table th {
  color: #64748b;
  font-weight: 500;
  background: #f8fafc;
}

.overtime-settlement-panel__form {
  display: grid;
  gap: 5px;
  min-width: 190px;
}

.overtime-settlement-panel__form label {
  display: flex;
  flex-direction: column;
  gap: 3px;
  font-size: 10.5px;
}

.overtime-settlement-panel__form input,
.overtime-settlement-panel__form select {
  padding: 5px 7px;
  font: inherit;
  font-size: 11px;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
}

.overtime-settlement-panel__actions {
  display: flex;
  gap: 6px;
}

.overtime-settlement-panel__primary,
.overtime-settlement-panel__secondary {
  padding: 6px 10px;
  font-size: 11px;
  border-radius: 6px;
  cursor: pointer;
}

.overtime-settlement-panel__primary {
  color: #fff;
  background: #1d4ed8;
  border: 1px solid #1d4ed8;
}

.overtime-settlement-panel__secondary {
  color: #334155;
  background: #fff;
  border: 1px solid #cbd5e1;
}

.overtime-settlement-panel__error {
  margin: 0;
  color: #b91c1c;
  font-size: 11px;
  line-height: 1.5;
}
</style>
