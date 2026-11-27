<script setup lang="ts">
import { reactive, ref } from 'vue'
import ActionGate from '@/components/ActionGate.vue'
import EmptyState from '@/components/EmptyState.vue'
import { ApiError, errorCopy } from '@/api/errors'
import {
  createAttendanceSchedule,
  newAttendanceIdempotencyKey,
  type AttendanceSchedule,
  type ShiftTemplate,
} from '../api'

defineProps<{
  schedules: AttendanceSchedule[]
  shifts: ShiftTemplate[]
  loading: boolean
  error: string
  page: number
  pageSize: number
  total: number
}>()

const emit = defineEmits<{
  changed: [message: string]
  'update:page': [page: number]
  'update:pageSize': [pageSize: number]
}>()

const actionMessage = ref('')
const actionError = ref('')
const saving = ref(false)

const form = reactive({
  employmentId: '',
  businessDate: '',
  shiftTemplateId: '',
})

function positiveInteger(value: string): number | null {
  const trimmed = value.trim()
  if (!/^[1-9]\d*$/.test(trimmed)) return null
  const parsed = Number(trimmed)
  return Number.isSafeInteger(parsed) ? parsed : null
}

async function submit(): Promise<void> {
  actionMessage.value = ''
  actionError.value = ''

  const employmentId = positiveInteger(form.employmentId)
  const shiftTemplateId = positiveInteger(form.shiftTemplateId)

  if (employmentId === null) {
    actionError.value = '员工任职 ID 必须是正整数。'
    return
  }
  if (!/^\d{4}-\d{2}-\d{2}$/.test(form.businessDate)) {
    actionError.value = '请选择有效的排班日期。'
    return
  }
  if (shiftTemplateId === null) {
    actionError.value = '请选择班次。'
    return
  }

  saving.value = true
  try {
    const result = await createAttendanceSchedule(
      { employmentId, businessDate: form.businessDate, shiftTemplateId },
      newAttendanceIdempotencyKey('attendance-schedule'),
    )
    actionMessage.value = result.replayed ? '相同排班已存在（幂等重放）。' : `排班已保存 #${result.scheduleId}`
    form.businessDate = ''
    emit('changed', actionMessage.value)
  } catch (reason) {
    if (reason instanceof ApiError) {
      const reasonCode = reason.serverReason
      actionError.value = reasonCode === 'SCHEDULE_CONFLICT'
        ? '该员工当天已排了不同班次，服务端拒绝覆盖。'
        : reasonCode === 'PERIOD_CLOSED'
          ? '该考勤月已关账，重开并填写原因后才能排班。'
          : `${errorCopy(reason)}${reason.requestId ? ` 请求编号：${reason.requestId}` : ''}`
    } else {
      actionError.value = errorCopy(reason)
    }
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <section class="attendance-schedules" data-testid="attendance-schedules-tab" aria-labelledby="attendance-schedules-title">
    <header class="attendance-schedules__header">
      <div>
        <h2 id="attendance-schedules-title">排班</h2>
        <p class="muted">同一员工同一天只允许一个班次；冲突和已关账月份由服务端拒绝并返回稳定原因。</p>
      </div>
      <span class="attendance-schedules__count">共 {{ total }} 条</span>
    </header>

    <p v-if="error" class="attendance-schedules__error" role="alert">{{ error }}</p>
    <p v-if="actionMessage" class="attendance-schedules__success" role="status">{{ actionMessage }}</p>
    <p v-if="actionError" class="attendance-schedules__error" role="alert">{{ actionError }}</p>

    <ActionGate capability="time.shift.schedule.manage">
      <form class="attendance-schedules__form" data-testid="attendance-schedule-form" @submit.prevent="submit">
        <label>
          <span>员工任职 ID</span>
          <input v-model="form.employmentId" data-testid="attendance-schedule-employment" inputmode="numeric" placeholder="来自授权范围" />
        </label>
        <label>
          <span>业务日期</span>
          <input v-model="form.businessDate" data-testid="attendance-schedule-date" type="date" />
        </label>
        <label>
          <span>班次</span>
          <select v-model="form.shiftTemplateId" data-testid="attendance-schedule-template">
            <option value="">选择班次</option>
            <option v-for="shift in shifts" :key="shift.id" :value="String(shift.id)">
              {{ shift.code }} · {{ shift.name }}（v{{ shift.version }}）
            </option>
          </select>
        </label>
        <button type="submit" class="attendance-schedules__primary" data-testid="attendance-schedule-submit" :disabled="saving">
          {{ saving ? '保存中…' : '保存排班' }}
        </button>
      </form>
    </ActionGate>

    <p v-if="loading" class="attendance-schedules__loading" role="status">正在加载排班…</p>

    <EmptyState
      v-else-if="schedules.length === 0"
      title="当前条件下没有排班"
      description="服务端没有返回排班记录。排班只写入真实员工和有效班次，不使用占位数据。"
    />

    <div v-else class="attendance-schedules__scroller" role="region" aria-label="排班列表，可横向滚动" tabindex="0">
      <table class="attendance-schedules__table">
        <caption class="visually-hidden">排班记录</caption>
        <thead>
          <tr>
            <th scope="col">员工任职</th>
            <th scope="col">业务日期</th>
            <th scope="col">班次</th>
            <th scope="col">时段</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="schedule in schedules" :key="schedule.id" :data-testid="`attendance-schedule-${schedule.id}`">
            <td class="mono">#{{ schedule.employmentId }}</td>
            <td>{{ schedule.businessDate }}</td>
            <td>{{ schedule.shiftCode ?? `#${schedule.shiftTemplateId}` }} · {{ schedule.shiftName ?? '班次信息不可用' }}</td>
            <td>{{ schedule.startLocalTime ?? '——' }} – {{ schedule.endLocalTime ?? '——' }}<span v-if="schedule.crossesMidnight">（跨夜）</span></td>
          </tr>
        </tbody>
      </table>
    </div>

    <footer class="attendance-schedules__footer">
      <span class="muted">当前第 {{ page }} 页</span>
      <el-pagination
        :current-page="page"
        :page-size="pageSize"
        :total="total"
        :page-sizes="[10, 20, 50]"
        layout="sizes, prev, pager, next"
        background
        size="small"
        @current-change="emit('update:page', $event)"
        @size-change="emit('update:pageSize', $event)"
      />
    </footer>
  </section>
</template>

<style scoped>
.attendance-schedules {
  background: #fff;
  border: 1px solid var(--color-line);
  border-radius: var(--radius-md);
}

.attendance-schedules__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  padding: 14px 16px;
  border-bottom: 1px solid var(--color-line);
}

.attendance-schedules__header h2 {
  font-size: 15px;
}

.attendance-schedules__header p {
  margin-top: 3px;
  font-size: 11.5px;
}

.attendance-schedules__count {
  padding: 3px 8px;
  color: var(--color-ink-muted);
  font-size: 11px;
  background: #f5f8f7;
  border-radius: 999px;
}

.attendance-schedules__form {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 10px;
  padding: 14px 16px;
  border-bottom: 1px solid var(--color-line);
}

.attendance-schedules__form label {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 150px;
  color: var(--color-ink-muted);
  font-size: 11px;
}

.attendance-schedules__form input,
.attendance-schedules__form select {
  padding: 6px 8px;
  color: var(--color-ink);
  font: inherit;
  border: 1px solid var(--color-line-strong);
  border-radius: 6px;
}

.attendance-schedules__primary {
  padding: 6px 12px;
  color: #fff;
  font-size: 11.5px;
  background: var(--color-primary, #1f5f8b);
  border: 1px solid var(--color-primary, #1f5f8b);
  border-radius: 6px;
  cursor: pointer;
}

.attendance-schedules__scroller {
  overflow-x: auto;
}

.attendance-schedules__table {
  width: 100%;
  border-collapse: collapse;
  font-size: 12px;
}

.attendance-schedules__table th,
.attendance-schedules__table td {
  padding: 9px 11px;
  text-align: left;
  border-bottom: 1px solid var(--color-line);
}

.attendance-schedules__table th {
  color: var(--color-ink-muted);
  font-size: 10.5px;
  font-weight: 650;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  background: #fafcfb;
}

.attendance-schedules__footer {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 10px 16px;
  border-top: 1px solid var(--color-line);
}

.attendance-schedules__loading {
  padding: 14px 16px;
  color: var(--color-ink-muted);
  font-size: 11.5px;
}

.attendance-schedules__error {
  margin: 12px 16px;
  padding: 8px 10px;
  color: #9f1c12;
  font-size: 11.5px;
  background: #feecea;
  border: 1px solid #f5c2bd;
  border-radius: 6px;
}

.attendance-schedules__success {
  margin: 12px 16px;
  padding: 8px 10px;
  color: #1f6b3a;
  font-size: 11.5px;
  background: #eef8f1;
  border: 1px solid #bfe3ca;
  border-radius: 6px;
}
</style>
