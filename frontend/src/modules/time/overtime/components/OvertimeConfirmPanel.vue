<script setup lang="ts">
import { computed, ref } from 'vue'
import { errorCopy } from '@/api/errors'
import { formatBusinessDate } from '@/utils/format'
import {
  confirmOvertimeRequest,
  formatMinutes,
  overtimeStatusLabel,
  stableSubmissionKey,
  type OvertimeRequest,
  type StableSubmissionKey,
} from '../api'

/**
 * Confirmation queue. Actual minutes are a distinct command from the employee
 * declaration; confirmation writes the policy snapshot server-side.
 */
const props = defineProps<{
  requests: OvertimeRequest[]
  canConfirm: boolean
  policyConfigured: boolean
}>()

const emit = defineEmits<{
  confirmed: [message: string]
  error: [message: string]
}>()

const activeId = ref<number | null>(null)
const minutes = ref('')
const submitting = ref(false)
const validation = ref('')
const submissionKey = ref<StableSubmissionKey | null>(null)

const pending = computed(() => props.requests.filter((request) => request.status === 'APPROVED'))

function start(request: OvertimeRequest): void {
  if (!props.canConfirm) return
  activeId.value = request.id
  minutes.value = String(request.declaredMinutes)
  validation.value = ''
  submissionKey.value = null
}

function cancel(): void {
  if (submitting.value) return
  activeId.value = null
  minutes.value = ''
  validation.value = ''
}

async function submit(request: OvertimeRequest): Promise<void> {
  if (!props.canConfirm) return
  const confirmedMinutes = Number(minutes.value)
  if (!Number.isSafeInteger(confirmedMinutes) || confirmedMinutes < 1) {
    validation.value = '确认分钟数必须是正整数。'
    return
  }
  const payload = {
    overtime_request_id: request.id,
    confirmed_minutes: confirmedMinutes,
  }
  submissionKey.value = stableSubmissionKey(submissionKey.value, 'overtime-confirm', payload)
  submitting.value = true
  validation.value = ''
  try {
    const confirmed = await confirmOvertimeRequest(
      { requestId: request.id, confirmedMinutes },
      submissionKey.value.key,
    )
    emit('confirmed', `加班已确认 ${formatMinutes(confirmed.confirmedMinutes ?? confirmedMinutes)}，已按确认时公司规则快照。`)
    activeId.value = null
    submissionKey.value = null
  } catch (reason) {
    emit('error', `确认未完成：${errorCopy(reason)}`)
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <section class="overtime-confirm-panel" data-testid="overtime-panel-confirm" aria-labelledby="overtime-confirm-title">
    <header class="overtime-confirm-panel__header">
      <div>
        <h2 id="overtime-confirm-title">加班确认</h2>
        <p>核对已批准申请与打卡事实；确认实际分钟并在同一事务内快照公司规则。</p>
      </div>
      <span class="overtime-confirm-panel__badge">{{ pending.length }} 条待确认</span>
    </header>

    <p v-if="!canConfirm" class="overtime-confirm-panel__empty" role="status">
      当前账号没有加班确认权限。
    </p>
    <p v-else-if="!policyConfigured" class="overtime-confirm-panel__empty" data-testid="overtime-confirm-unconfigured" role="status">
      公司尚未配置加班规则，服务端将以 OVERTIME_POLICY_UNCONFIGURED 拒绝确认。
    </p>
    <p v-else-if="pending.length === 0" class="overtime-confirm-panel__empty" data-testid="overtime-confirm-empty" role="status">
      暂无待确认的加班申请。
    </p>

    <div v-else class="overtime-confirm-panel__table-wrap">
      <table class="overtime-confirm-panel__table">
        <thead>
          <tr>
            <th scope="col">员工</th>
            <th scope="col">业务日期</th>
            <th scope="col">申报分钟</th>
            <th scope="col">打卡事实</th>
            <th scope="col">状态</th>
            <th scope="col">确认</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="request in pending" :key="request.id" :data-testid="`overtime-confirm-row-${request.id}`">
            <td>{{ request.employeeName ?? request.employeeNo ?? `#${request.employmentId}` }}</td>
            <td>{{ formatBusinessDate(request.businessDate) }}</td>
            <td>{{ formatMinutes(request.declaredMinutes) }}</td>
            <td>
              <template v-if="request.attendanceStatus !== null">
                {{ request.attendanceStatus }}；工作 {{ formatMinutes(request.attendanceWorkedMinutes) }}；
                加班 {{ formatMinutes(request.attendanceOvertimeMinutes) }}
              </template>
              <template v-else>尚无打卡事实</template>
            </td>
            <td>{{ overtimeStatusLabel(request.status) }}</td>
            <td>
              <div v-if="activeId === request.id" class="overtime-confirm-panel__form">
                <label>
                  <span>确认分钟</span>
                  <input
                    v-model="minutes"
                    :data-testid="`overtime-confirm-minutes-${request.id}`"
                    inputmode="numeric"
                    maxlength="5"
                  />
                </label>
                <p v-if="validation" class="overtime-confirm-panel__error" role="alert">{{ validation }}</p>
                <div class="overtime-confirm-panel__actions">
                  <button type="button" class="overtime-confirm-panel__secondary" :disabled="submitting" @click="cancel">取消</button>
                  <button
                    type="button"
                    class="overtime-confirm-panel__primary"
                    :disabled="submitting"
                    :data-testid="`overtime-confirm-submit-${request.id}`"
                    @click="submit(request)"
                  >
                    {{ submitting ? '确认中…' : '确认加班' }}
                  </button>
                </div>
              </div>
              <button
                v-else
                type="button"
                class="overtime-confirm-panel__secondary"
                :data-testid="`overtime-confirm-${request.id}`"
                @click="start(request)"
              >
                确认实际分钟
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>

<style scoped>
.overtime-confirm-panel {
  display: grid;
  gap: 12px;
  padding: 16px;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
}

.overtime-confirm-panel__header {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  align-items: flex-start;
}

.overtime-confirm-panel__header h2 {
  margin: 0;
  font-size: 16px;
}

.overtime-confirm-panel__header p {
  margin: 4px 0 0;
  color: #64748b;
  font-size: 11.5px;
}

.overtime-confirm-panel__badge {
  padding: 3px 8px;
  color: #1d4ed8;
  font-size: 11px;
  background: #eff6ff;
  border-radius: 999px;
}

.overtime-confirm-panel__table-wrap {
  overflow-x: auto;
}

.overtime-confirm-panel__table {
  width: 100%;
  font-size: 11.5px;
  border-collapse: collapse;
}

.overtime-confirm-panel__table th,
.overtime-confirm-panel__table td {
  padding: 8px 9px;
  text-align: left;
  vertical-align: top;
  border-bottom: 1px solid #f1f5f9;
}

.overtime-confirm-panel__table th {
  color: #64748b;
  font-weight: 500;
  background: #f8fafc;
}

.overtime-confirm-panel__empty {
  margin: 0;
  padding: 14px;
  color: #64748b;
  font-size: 12px;
  background: #f8fafc;
  border-radius: 7px;
}

.overtime-confirm-panel__form {
  display: grid;
  gap: 5px;
  min-width: 190px;
}

.overtime-confirm-panel__form label {
  display: flex;
  flex-direction: column;
  gap: 3px;
  font-size: 10.5px;
}

.overtime-confirm-panel__form input {
  padding: 5px 7px;
  font: inherit;
  font-size: 11px;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
}

.overtime-confirm-panel__actions {
  display: flex;
  gap: 6px;
}

.overtime-confirm-panel__primary,
.overtime-confirm-panel__secondary {
  padding: 6px 10px;
  font-size: 11px;
  border-radius: 6px;
  cursor: pointer;
}

.overtime-confirm-panel__primary {
  color: #fff;
  background: #1d4ed8;
  border: 1px solid #1d4ed8;
}

.overtime-confirm-panel__secondary {
  color: #334155;
  background: #fff;
  border: 1px solid #cbd5e1;
}

.overtime-confirm-panel__error {
  margin: 0;
  color: #b91c1c;
  font-size: 11px;
}
</style>
