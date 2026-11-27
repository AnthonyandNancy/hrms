<script setup lang="ts">
import { reactive, ref } from 'vue'
import ActionGate from '@/components/ActionGate.vue'
import EmptyState from '@/components/EmptyState.vue'
import { ApiError, errorCopy } from '@/api/errors'
import {
  createShiftTemplate,
  disableShiftTemplate,
  newAttendanceIdempotencyKey,
  updateShiftTemplate,
  type ShiftTemplate,
  type ShiftTemplateInput,
} from '../api'

defineProps<{
  shifts: ShiftTemplate[]
  loading: boolean
  error: string
}>()

const emit = defineEmits<{
  changed: [message: string]
}>()

type Mode = 'create' | 'edit'

const mode = ref<Mode>('create')
const editingId = ref<number | null>(null)
const saving = ref(false)
const actionMessage = ref('')
const actionError = ref('')
const disableReason = ref('')
const disablingId = ref<number | null>(null)

const form = reactive<ShiftTemplateInput>({
  code: '',
  name: '',
  startLocalTime: '09:00',
  endLocalTime: '18:00',
  crossesMidnight: false,
  breakMinutes: 60,
  lateGraceMinutes: 0,
  earlyGraceMinutes: 0,
  standardHoursDecimal: '8.000000',
  validFrom: '2026-11-01',
  validTo: '9999-12-31',
})

function resetForm(): void {
  mode.value = 'create'
  editingId.value = null
  form.code = ''
  form.name = ''
  form.startLocalTime = '09:00'
  form.endLocalTime = '18:00'
  form.crossesMidnight = false
  form.breakMinutes = 60
  form.lateGraceMinutes = 0
  form.earlyGraceMinutes = 0
  form.standardHoursDecimal = '8.000000'
  form.validFrom = '2026-11-01'
  form.validTo = '9999-12-31'
}

function beginEdit(shift: ShiftTemplate): void {
  mode.value = 'edit'
  editingId.value = shift.id
  form.code = shift.code
  form.name = shift.name
  form.startLocalTime = shift.startLocalTime.slice(0, 5)
  form.endLocalTime = shift.endLocalTime.slice(0, 5)
  form.crossesMidnight = shift.crossesMidnight
  form.breakMinutes = shift.breakMinutes
  form.lateGraceMinutes = shift.lateGraceMinutes
  form.earlyGraceMinutes = shift.earlyGraceMinutes
  form.standardHoursDecimal = shift.standardHoursDecimal
  form.validFrom = shift.validFrom
  form.validTo = shift.validTo
  actionError.value = ''
  actionMessage.value = ''
}

function validate(): string {
  if (!/^[A-Za-z0-9][A-Za-z0-9._-]{0,31}$/.test(form.code.trim())) {
    return '班次编码需要 1 到 32 个字母、数字、点、下划线或短横线。'
  }
  if (form.name.trim() === '') return '班次名称不能为空。'
  if (!/^\d{2}:\d{2}$/.test(form.startLocalTime) || !/^\d{2}:\d{2}$/.test(form.endLocalTime)) {
    return '上下班时间必须使用 HH:mm 格式。'
  }
  if (!/^\d{4}-\d{2}-\d{2}$/.test(form.validFrom) || !/^\d{4}-\d{2}-\d{2}$/.test(form.validTo)) {
    return '生效区间必须使用 YYYY-MM-DD。'
  }
  if (form.validFrom >= form.validTo) return '失效日期必须晚于生效日期。'
  if (form.breakMinutes < 0 || form.breakMinutes > 1440) return '休息分钟必须在 0 到 1440 之间。'
  return ''
}

async function submit(): Promise<void> {
  actionMessage.value = ''
  actionError.value = ''
  const validation = validate()
  if (validation !== '') {
    actionError.value = validation
    return
  }

  saving.value = true
  try {
    if (mode.value === 'create') {
      const created = await createShiftTemplate({ ...form }, newAttendanceIdempotencyKey('attendance-shift-create'))
      actionMessage.value = `班次已创建：${created.code} v${created.version}`
    } else if (editingId.value !== null) {
      const updated = await updateShiftTemplate(editingId.value, { ...form }, newAttendanceIdempotencyKey('attendance-shift-update'))
      actionMessage.value = `班次新版本已发布：${updated.code} v${updated.version}`
    }
    resetForm()
    emit('changed', actionMessage.value)
  } catch (reason) {
    actionError.value = reason instanceof ApiError
      ? `${errorCopy(reason)}${reason.requestId ? ` 请求编号：${reason.requestId}` : ''}`
      : errorCopy(reason)
  } finally {
    saving.value = false
  }
}

async function disable(shift: ShiftTemplate): Promise<void> {
  actionMessage.value = ''
  actionError.value = ''
  const reason = disableReason.value.trim()
  if (reason.length < 5) {
    actionError.value = '停用原因至少需要 5 个字符。'
    return
  }

  disablingId.value = shift.id
  try {
    const updated = await disableShiftTemplate(shift.id, reason, newAttendanceIdempotencyKey('attendance-shift-disable'))
    actionMessage.value = `班次 ${updated.code} 已按 ${updated.validTo} 停用`
    disableReason.value = ''
    emit('changed', actionMessage.value)
  } catch (reasonValue) {
    actionError.value = reasonValue instanceof ApiError
      ? `${errorCopy(reasonValue)}${reasonValue.requestId ? ` 请求编号：${reasonValue.requestId}` : ''}`
      : errorCopy(reasonValue)
  } finally {
    disablingId.value = null
  }
}
</script>

<template>
  <section class="attendance-shifts" data-testid="attendance-shifts-tab" aria-labelledby="attendance-shifts-title">
    <header class="attendance-shifts__header">
      <div>
        <h2 id="attendance-shifts-title">班次维护</h2>
        <p class="muted">编辑会生成新版本并保留历史；停用是生效日关窗，不删除已排班的班次。</p>
      </div>
      <span class="attendance-shifts__count">{{ shifts.length }} 个版本</span>
    </header>

    <p v-if="error" class="attendance-shifts__error" role="alert">{{ error }}</p>
    <p v-if="actionMessage" class="attendance-shifts__success" role="status">{{ actionMessage }}</p>
    <p v-if="actionError" class="attendance-shifts__error" role="alert">{{ actionError }}</p>

    <ActionGate capability="time.shift.manage">
      <form class="attendance-shifts__form" data-testid="attendance-shift-form" @submit.prevent="submit">
        <h3>{{ mode === 'create' ? '新建班次' : `编辑班次 #${editingId}` }}</h3>
        <div class="attendance-shifts__grid">
          <label>
            <span>编码</span>
            <input v-model="form.code" data-testid="attendance-shift-code" maxlength="32" required />
          </label>
          <label>
            <span>名称</span>
            <input v-model="form.name" data-testid="attendance-shift-name" maxlength="128" required />
          </label>
          <label>
            <span>上班时间</span>
            <input v-model="form.startLocalTime" data-testid="attendance-shift-start" type="time" required />
          </label>
          <label>
            <span>下班时间</span>
            <input v-model="form.endLocalTime" data-testid="attendance-shift-end" type="time" required />
          </label>
          <label>
            <span>跨夜</span>
            <label class="attendance-shifts__check">
              <input v-model="form.crossesMidnight" data-testid="attendance-shift-crosses" type="checkbox" />
              下班时刻在次日
            </label>
          </label>
          <label>
            <span>休息分钟</span>
            <input v-model.number="form.breakMinutes" data-testid="attendance-shift-break" type="number" min="0" max="1440" />
          </label>
          <label>
            <span>生效日</span>
            <input v-model="form.validFrom" data-testid="attendance-shift-valid-from" type="date" required />
          </label>
          <label>
            <span>失效日（不含）</span>
            <input v-model="form.validTo" data-testid="attendance-shift-valid-to" type="date" required />
          </label>
        </div>
        <div class="attendance-shifts__actions">
          <button type="submit" class="attendance-shifts__primary" data-testid="attendance-shift-submit" :disabled="saving">
            {{ saving ? '提交中…' : mode === 'create' ? '创建班次' : '发布新版本' }}
          </button>
          <button v-if="mode === 'edit'" type="button" class="attendance-shifts__secondary" :disabled="saving" @click="resetForm">
            取消编辑
          </button>
        </div>
      </form>
    </ActionGate>

    <EmptyState
      v-if="!loading && shifts.length === 0"
      title="还没有配置班次"
      description="服务端未返回班次模板。创建班次后，排班和考勤计算才会使用它。"
    />

    <div v-else class="attendance-shifts__scroller" role="region" aria-label="班次版本，可横向滚动" tabindex="0">
      <table class="attendance-shifts__table">
        <caption class="visually-hidden">班次模板版本</caption>
        <thead>
          <tr>
            <th scope="col">编码</th>
            <th scope="col">名称</th>
            <th scope="col">时段</th>
            <th scope="col">版本</th>
            <th scope="col">生效区间</th>
            <th scope="col">状态</th>
            <th scope="col">操作</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="shift in shifts" :key="shift.id" :data-testid="`attendance-shift-${shift.id}`">
            <td class="mono">{{ shift.code }}</td>
            <td>{{ shift.name }}</td>
            <td>{{ shift.startLocalTime }} – {{ shift.endLocalTime }}<span v-if="shift.crossesMidnight">（跨夜）</span></td>
            <td>v{{ shift.version }}</td>
            <td>{{ shift.validFrom }} – {{ shift.validTo }}</td>
            <td>{{ shift.supersededAt === null ? '当前' : '历史' }}</td>
            <td>
              <ActionGate capability="time.shift.manage">
                <div class="attendance-shifts__row-actions">
                  <button type="button" class="attendance-shifts__link" :data-testid="`attendance-shift-edit-${shift.id}`" @click="beginEdit(shift)">
                    编辑
                  </button>
                  <input
                    v-model="disableReason"
                    class="attendance-shifts__reason"
                    :data-testid="`attendance-shift-disable-reason-${shift.id}`"
                    placeholder="停用原因（≥5字）"
                    maxlength="500"
                  />
                  <button
                    type="button"
                    class="attendance-shifts__danger"
                    :data-testid="`attendance-shift-disable-${shift.id}`"
                    :disabled="disablingId !== null"
                    @click="disable(shift)"
                  >
                    停用
                  </button>
                </div>
              </ActionGate>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>

<style scoped>
.attendance-shifts {
  background: #fff;
  border: 1px solid var(--color-line);
  border-radius: var(--radius-md);
}

.attendance-shifts__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  padding: 14px 16px;
  border-bottom: 1px solid var(--color-line);
}

.attendance-shifts__header h2 {
  font-size: 15px;
}

.attendance-shifts__header p {
  margin-top: 3px;
  font-size: 11.5px;
}

.attendance-shifts__count {
  padding: 3px 8px;
  color: var(--color-ink-muted);
  font-size: 11px;
  background: #f5f8f7;
  border-radius: 999px;
}

.attendance-shifts__form {
  padding: 14px 16px;
  border-bottom: 1px solid var(--color-line);
}

.attendance-shifts__form h3 {
  margin-bottom: 9px;
  font-size: 13px;
}

.attendance-shifts__grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
  gap: 10px;
}

.attendance-shifts__grid label {
  display: flex;
  flex-direction: column;
  gap: 4px;
  color: var(--color-ink-muted);
  font-size: 11px;
}

.attendance-shifts__grid input {
  padding: 6px 8px;
  color: var(--color-ink);
  font: inherit;
  border: 1px solid var(--color-line-strong);
  border-radius: 6px;
}

.attendance-shifts__check {
  display: flex;
  flex-direction: row;
  gap: 5px;
  align-items: center;
  min-height: 30px;
  color: var(--color-ink);
}

.attendance-shifts__actions {
  display: flex;
  gap: 8px;
  margin-top: 11px;
}

.attendance-shifts__primary,
.attendance-shifts__secondary {
  padding: 6px 12px;
  font-size: 11.5px;
  border-radius: 6px;
  cursor: pointer;
}

.attendance-shifts__primary {
  color: #fff;
  background: var(--color-primary, #1f5f8b);
  border: 1px solid var(--color-primary, #1f5f8b);
}

.attendance-shifts__secondary {
  color: var(--color-ink);
  background: #fff;
  border: 1px solid var(--color-line-strong);
}

.attendance-shifts__scroller {
  overflow-x: auto;
}

.attendance-shifts__table {
  width: 100%;
  border-collapse: collapse;
  font-size: 12px;
}

.attendance-shifts__table th,
.attendance-shifts__table td {
  padding: 9px 11px;
  text-align: left;
  border-bottom: 1px solid var(--color-line);
}

.attendance-shifts__table th {
  color: var(--color-ink-muted);
  font-size: 10.5px;
  font-weight: 650;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  background: #fafcfb;
}

.attendance-shifts__row-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 5px;
  align-items: center;
}

.attendance-shifts__link {
  color: #1d4f8a;
  font-size: 11.5px;
  background: transparent;
  border: 0;
  cursor: pointer;
}

.attendance-shifts__danger {
  padding: 3px 8px;
  color: #9f1c12;
  font-size: 11px;
  background: #fff3f1;
  border: 1px solid #f5c2bd;
  border-radius: 5px;
  cursor: pointer;
}

.attendance-shifts__reason {
  width: 130px;
  padding: 4px 6px;
  font: inherit;
  border: 1px solid var(--color-line-strong);
  border-radius: 5px;
}

.attendance-shifts__error {
  margin: 12px 16px;
  padding: 8px 10px;
  color: #9f1c12;
  font-size: 11.5px;
  background: #feecea;
  border: 1px solid #f5c2bd;
  border-radius: 6px;
}

.attendance-shifts__success {
  margin: 12px 16px;
  padding: 8px 10px;
  color: #1f6b3a;
  font-size: 11.5px;
  background: #eef8f1;
  border: 1px solid #bfe3ca;
  border-radius: 6px;
}
</style>
