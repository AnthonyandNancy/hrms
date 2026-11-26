<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import type { AttendanceExceptionRow } from '../api'

const props = defineProps<{
  exceptions: AttendanceExceptionRow[]
  canResolve: boolean
  resolvingId: number | null
}>()

const emit = defineEmits<{
  resolve: [exceptionId: number, note: string]
}>()

const activeId = ref<number | null>(null)
const note = ref('')
const validationMessage = ref('')

const TYPE_LABELS: Record<string, string> = {
  MISSING_PUNCH: '漏卡',
  LATE: '迟到',
  EARLY: '早退',
  EARLY_LEAVE: '早退',
  ABSENT: '缺勤',
  INCOMPLETE: '记录不完整',
  WRONG_TIME: '时间异常',
  WRONG_STATUS: '状态异常',
}

const openExceptions = computed(() =>
  props.exceptions.filter((exception) => exception.status === 'OPEN' || exception.status === 'PENDING'),
)

function typeLabel(type: string): string {
  return TYPE_LABELS[type] ?? '其他异常'
}

function isResolvable(exception: AttendanceExceptionRow): boolean {
  return props.canResolve && exception.id !== null
}

function startResolve(exception: AttendanceExceptionRow): void {
  if (exception.id === null) return
  activeId.value = exception.id
  note.value = ''
  validationMessage.value = ''
}

function cancelResolve(): void {
  activeId.value = null
  note.value = ''
  validationMessage.value = ''
}

function confirmResolve(exception: AttendanceExceptionRow): void {
  if (exception.id === null) return
  if (note.value.trim() === '') {
    validationMessage.value = '处理说明不能为空。'
    return
  }
  validationMessage.value = ''
  emit('resolve', exception.id, note.value.trim())
}

watch(
  () => props.resolvingId,
  (value) => {
    if (value === null && activeId.value !== null && !props.exceptions.some((row) => row.id === activeId.value)) {
      activeId.value = null
      note.value = ''
    }
  },
)
</script>

<template>
  <section class="attendance-exceptions" aria-labelledby="attendance-exceptions-title">
    <header class="attendance-exceptions__header">
      <div>
        <h2 id="attendance-exceptions-title">待处理异常</h2>
        <p class="muted">仅展示服务端仍为待处理状态的考勤异常；解决操作会留下处理说明。</p>
      </div>
      <span class="attendance-exceptions__count">{{ openExceptions.length }} 条</span>
    </header>

    <p v-if="openExceptions.length === 0" class="attendance-exceptions__empty" role="status">
      当前没有待处理的考勤异常。
    </p>

    <ul v-else class="attendance-exceptions__list">
      <li v-for="exception in openExceptions" :key="`${exception.id ?? exception.businessDate}-${exception.type}`" class="attendance-exception">
        <div class="attendance-exception__summary">
          <span class="attendance-exception__date">{{ exception.businessDate }}</span>
          <span class="attendance-exception__type">{{ typeLabel(exception.type) }}</span>
          <span v-if="exception.employeeName" class="attendance-exception__employee">{{ exception.employeeName }}</span>
          <span v-if="exception.label" class="attendance-exception__label">{{ exception.label }}</span>
        </div>

        <div v-if="activeId === exception.id" class="attendance-exception__resolve">
          <p class="attendance-exception__confirm-copy">确认解决该异常？处理说明将写入审计记录。</p>
          <label class="attendance-exception__field">
            <span>处理说明</span>
            <textarea
              v-model="note"
              rows="2"
              maxlength="500"
              :data-testid="`exception-note-${exception.id}`"
              placeholder="请填写核实结论或处理依据"
            />
          </label>
          <p v-if="validationMessage" class="attendance-exception__error" role="alert">
            {{ validationMessage }}
          </p>
          <div class="attendance-exception__actions">
            <button type="button" class="attendance-exception__cancel" :disabled="resolvingId !== null" @click="cancelResolve">
              取消
            </button>
            <button
              type="button"
              class="attendance-exception__confirm"
              :disabled="resolvingId !== null"
              :data-testid="`exception-confirm-${exception.id}`"
              @click="confirmResolve(exception)"
            >
              {{ resolvingId === exception.id ? '处理中…' : '确认解决' }}
            </button>
          </div>
        </div>

        <button
          v-else-if="isResolvable(exception)"
          type="button"
          class="attendance-exception__trigger"
          :data-testid="`exception-resolve-${exception.id}`"
          @click="startResolve(exception)"
        >
          处理
        </button>
      </li>
    </ul>
  </section>
</template>

<style scoped>
.attendance-exceptions {
  background: #fff;
  border: 1px solid var(--color-line);
  border-radius: var(--radius-md);
}

.attendance-exceptions__header {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  padding: 14px 16px;
  border-bottom: 1px solid var(--color-line);
}

.attendance-exceptions__header h2 {
  font-size: 15px;
}

.attendance-exceptions__header p {
  margin-top: 3px;
  font-size: 11.5px;
}

.attendance-exceptions__count {
  align-self: flex-start;
  padding: 3px 8px;
  color: #8f5b00;
  font-size: 11px;
  background: #fff5df;
  border-radius: 999px;
}

.attendance-exceptions__empty {
  padding: 18px 16px;
  color: var(--color-ink-muted);
  font-size: 12px;
}

.attendance-exceptions__list {
  margin: 0;
  padding: 0;
  list-style: none;
}

.attendance-exception {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 14px;
  padding: 12px 16px;
  border-bottom: 1px solid var(--color-line);
}

.attendance-exception:last-child {
  border-bottom: 0;
}

.attendance-exception__summary {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  font-size: 12px;
}

.attendance-exception__date {
  font-weight: 650;
}

.attendance-exception__type {
  padding: 2px 7px;
  color: #8f5b00;
  font-size: 10.5px;
  background: #fff5df;
  border-radius: 999px;
}

.attendance-exception__employee,
.attendance-exception__label {
  color: var(--color-ink-muted);
}

.attendance-exception__trigger,
.attendance-exception__cancel,
.attendance-exception__confirm {
  flex-shrink: 0;
  padding: 5px 11px;
  font-size: 11.5px;
  border-radius: 6px;
  cursor: pointer;
}

.attendance-exception__trigger {
  color: #1d4f8a;
  background: #edf4fc;
  border: 1px solid #cfe0f5;
}

.attendance-exception__resolve {
  flex: 1;
  max-width: 520px;
}

.attendance-exception__confirm-copy {
  color: #8f5b00;
  font-size: 11.5px;
}

.attendance-exception__field {
  display: flex;
  flex-direction: column;
  gap: 4px;
  margin-top: 6px;
  font-size: 11.5px;
}

.attendance-exception__field textarea {
  padding: 7px 9px;
  font: inherit;
  border: 1px solid var(--color-line-strong);
  border-radius: 6px;
}

.attendance-exception__error {
  margin-top: 4px;
  color: #9f1c12;
  font-size: 11px;
}

.attendance-exception__actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 7px;
}

.attendance-exception__cancel {
  color: var(--color-ink-muted);
  background: transparent;
  border: 1px solid var(--color-line);
}

.attendance-exception__confirm {
  color: #fff;
  background: var(--color-primary, #1f5f8b);
  border: 1px solid var(--color-primary, #1f5f8b);
}

.attendance-exception__trigger:disabled,
.attendance-exception__cancel:disabled,
.attendance-exception__confirm:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
</style>
