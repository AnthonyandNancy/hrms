<script setup lang="ts">
import { computed, ref } from 'vue'
import ActionGate from '@/components/ActionGate.vue'
import EmptyState from '@/components/EmptyState.vue'
import { ApiError, errorCopy } from '@/api/errors'
import {
  closeAttendancePeriod,
  newAttendanceIdempotencyKey,
  recalculateAttendancePeriod,
  reopenAttendancePeriod,
  type AttendanceClosure,
} from '../api'

const props = defineProps<{
  closures: AttendanceClosure[]
  loading: boolean
  error: string
}>()

const emit = defineEmits<{
  changed: [message: string]
}>()

const saving = ref(false)
const actionMessage = ref('')
const actionError = ref('')
const closeMonth = ref('')
const reopenReason = ref('')

const latestClosure = computed(() => props.closures[0] ?? null)

function statusLabel(status: string): string {
  return status === 'CLOSED' ? '已关账' : status === 'REOPENED' ? '已重开' : status
}

async function closeMonthAction(): Promise<void> {
  actionMessage.value = ''
  actionError.value = ''

  if (!/^\d{4}-\d{2}$/.test(closeMonth.value)) {
    actionError.value = '请选择有效的考勤月（YYYY-MM）。'
    return
  }

  saving.value = true
  try {
    const result = await closeAttendancePeriod(closeMonth.value, newAttendanceIdempotencyKey('attendance-close'))
    actionMessage.value = result.replayed ? `${closeMonth.value} 已关账（幂等重放）` : `${closeMonth.value} 已关账`
    emit('changed', actionMessage.value)
  } catch (reason) {
    actionError.value = formatActionError(reason)
  } finally {
    saving.value = false
  }
}

async function reopen(closure: AttendanceClosure): Promise<void> {
  actionMessage.value = ''
  actionError.value = ''

  const reason = reopenReason.value.trim()
  if (reason.length < 5) {
    actionError.value = '重开原因至少需要 5 个字符，并会记录到审计日志。'
    return
  }

  saving.value = true
  try {
    const result = await reopenAttendancePeriod(closure.id, reason, newAttendanceIdempotencyKey('attendance-reopen'))
    actionMessage.value = result.replayed
      ? `${closure.month} 已重开（幂等重放）`
      : `${closure.month} 已重开，后续重算将重新校验关账状态。`
    reopenReason.value = ''
    emit('changed', actionMessage.value)
  } catch (reasonValue) {
    actionError.value = formatActionError(reasonValue)
  } finally {
    saving.value = false
  }
}

async function recalculate(): Promise<void> {
  actionMessage.value = ''
  actionError.value = ''

  if (!/^\d{4}-\d{2}$/.test(closeMonth.value)) {
    actionError.value = '请选择有效的考勤月（YYYY-MM）。'
    return
  }

  saving.value = true
  try {
    await recalculateAttendancePeriod(closeMonth.value, null, newAttendanceIdempotencyKey('attendance-recalculate'))
    actionMessage.value = `${closeMonth.value} 重算已受理`
    emit('changed', actionMessage.value)
  } catch (reason) {
    if (reason instanceof ApiError && reason.serverReason === 'PERIOD_CLOSED') {
      actionError.value = '该考勤月已关账；普通重算被拒绝。请先按流程填写原因并重开。'
    } else {
      actionError.value = formatActionError(reason)
    }
  } finally {
    saving.value = false
  }
}

function formatActionError(reason: unknown): string {
  if (reason instanceof ApiError) {
    return `${errorCopy(reason)}${reason.requestId ? ` 请求编号：${reason.requestId}` : ''}`
  }
  return errorCopy(reason)
}
</script>

<template>
  <section class="attendance-closing" data-testid="attendance-closing-tab" aria-labelledby="attendance-closing-title">
    <header class="attendance-closing__header">
      <div>
        <h2 id="attendance-closing-title">考勤月结</h2>
        <p class="muted">关账后普通重算返回 409 PERIOD_CLOSED；重开必须填写原因并留下审计记录。</p>
      </div>
      <span class="attendance-closing__count">{{ closures.length }} 条记录</span>
    </header>

    <p v-if="error" class="attendance-closing__error" role="alert">{{ error }}</p>
    <p v-if="actionMessage" class="attendance-closing__success" role="status">{{ actionMessage }}</p>
    <p v-if="actionError" class="attendance-closing__error" role="alert">{{ actionError }}</p>

    <ActionGate capability="time.attendance.manage">
      <form class="attendance-closing__form" data-testid="attendance-closing-form" @submit.prevent="closeMonthAction">
        <h3>关账 / 重算</h3>
        <label>
          <span>考勤月</span>
          <input v-model="closeMonth" data-testid="attendance-close-month" type="month" />
        </label>
        <button type="submit" class="attendance-closing__primary" data-testid="attendance-close" :disabled="saving">
          {{ saving ? '提交中…' : '关账' }}
        </button>
        <button type="button" class="attendance-closing__secondary" data-testid="attendance-recalculate" :disabled="saving" @click="recalculate">
          重算
        </button>
      </form>
    </ActionGate>

    <EmptyState
      v-if="!loading && closures.length === 0"
      title="尚无月结记录"
      description="服务端未返回关账记录。未关账月份保持 OPEN，可直接按真实考勤数据重算。"
    />

    <div v-else class="attendance-closing__scroller" role="region" aria-label="月结记录，可横向滚动" tabindex="0">
      <table class="attendance-closing__table">
        <caption class="visually-hidden">考勤月结记录</caption>
        <thead>
          <tr>
            <th scope="col">月份</th>
            <th scope="col">状态</th>
            <th scope="col">关账时间</th>
            <th scope="col">重开原因</th>
            <th scope="col">重开</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="closure in closures" :key="closure.id" :data-testid="`attendance-closure-${closure.id}`">
            <td>{{ closure.month }}</td>
            <td>{{ statusLabel(closure.status) }}</td>
            <td>{{ closure.closedAt ?? '——' }}</td>
            <td>{{ closure.reopenReason ?? '——' }}</td>
            <td>
              <ActionGate v-if="closure.status === 'CLOSED'" capability="time.attendance.reopen">
                <div class="attendance-closing__reopen">
                  <input
                    v-model="reopenReason"
                    class="attendance-closing__reason"
                    :data-testid="`attendance-reopen-reason-${closure.id}`"
                    placeholder="重开原因（≥5字，会审计）"
                    maxlength="500"
                  />
                  <button
                    type="button"
                    class="attendance-closing__danger"
                    :data-testid="`attendance-reopen-${closure.id}`"
                    :disabled="saving"
                    @click="reopen(closure)"
                  >
                    重开
                  </button>
                </div>
              </ActionGate>
              <span v-else class="muted">——</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <p v-if="latestClosure !== null" class="attendance-closing__hint">
      最近一条服务端记录：{{ latestClosure.month }} · {{ statusLabel(latestClosure.status) }}
      <template v-if="latestClosure.reopenReason"> · 重开原因：{{ latestClosure.reopenReason }}</template>
    </p>
  </section>
</template>

<style scoped>
.attendance-closing {
  background: #fff;
  border: 1px solid var(--color-line);
  border-radius: var(--radius-md);
}

.attendance-closing__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  padding: 14px 16px;
  border-bottom: 1px solid var(--color-line);
}

.attendance-closing__header h2 {
  font-size: 15px;
}

.attendance-closing__header p {
  margin-top: 3px;
  font-size: 11.5px;
}

.attendance-closing__count {
  padding: 3px 8px;
  color: var(--color-ink-muted);
  font-size: 11px;
  background: #f5f8f7;
  border-radius: 999px;
}

.attendance-closing__form {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 10px;
  padding: 14px 16px;
  border-bottom: 1px solid var(--color-line);
}

.attendance-closing__form label {
  display: flex;
  flex-direction: column;
  gap: 4px;
  color: var(--color-ink-muted);
  font-size: 11px;
}

.attendance-closing__form input {
  padding: 6px 8px;
  color: var(--color-ink);
  font: inherit;
  border: 1px solid var(--color-line-strong);
  border-radius: 6px;
}

.attendance-closing__primary,
.attendance-closing__secondary,
.attendance-closing__danger {
  padding: 6px 12px;
  font-size: 11.5px;
  border-radius: 6px;
  cursor: pointer;
}

.attendance-closing__primary {
  color: #fff;
  background: var(--color-primary, #1f5f8b);
  border: 1px solid var(--color-primary, #1f5f8b);
}

.attendance-closing__secondary {
  color: var(--color-ink);
  background: #fff;
  border: 1px solid var(--color-line-strong);
}

.attendance-closing__scroller {
  overflow-x: auto;
}

.attendance-closing__table {
  width: 100%;
  border-collapse: collapse;
  font-size: 12px;
}

.attendance-closing__table th,
.attendance-closing__table td {
  padding: 9px 11px;
  text-align: left;
  border-bottom: 1px solid var(--color-line);
}

.attendance-closing__table th {
  color: var(--color-ink-muted);
  font-size: 10.5px;
  font-weight: 650;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  background: #fafcfb;
}

.attendance-closing__reopen {
  display: flex;
  flex-wrap: wrap;
  gap: 5px;
  align-items: center;
}

.attendance-closing__reason {
  width: 160px;
  padding: 4px 6px;
  font: inherit;
  border: 1px solid var(--color-line-strong);
  border-radius: 5px;
}

.attendance-closing__danger {
  color: #9f1c12;
  background: #fff3f1;
  border: 1px solid #f5c2bd;
}

.attendance-closing__hint {
  padding: 9px 16px;
  color: var(--color-ink-muted);
  font-size: 11px;
}

.attendance-closing__error {
  margin: 12px 16px;
  padding: 8px 10px;
  color: #9f1c12;
  font-size: 11.5px;
  background: #feecea;
  border: 1px solid #f5c2bd;
  border-radius: 6px;
}

.attendance-closing__success {
  margin: 12px 16px;
  padding: 8px 10px;
  color: #1f6b3a;
  font-size: 11.5px;
  background: #eef8f1;
  border: 1px solid #bfe3ca;
  border-radius: 6px;
}
</style>
