<script setup lang="ts">
import EmptyState from '@/components/EmptyState.vue'
import LoadingState from '@/components/LoadingState.vue'
import type { AttendanceCorrectionType } from '../api'

const props = withDefaults(
  defineProps<{
    corrections: Array<{
      key: string
      businessDate: string
      employeeId: number | null
      employeeName: string
      type: string
      status: string
      label: string | null
    }>
    loading: boolean
    canRequest: boolean
    actionMessage?: string
    actionError?: string
  }>(),
  { actionMessage: '', actionError: '' },
)

const emit = defineEmits<{
  'request-correction': [payload: { businessDate: string; employeeId: number | null }]
}>()

const TYPE_LABELS: Record<string, string> = {
  MISSING_PUNCH: '漏卡',
  WRONG_TIME: '时间异常',
  WRONG_STATUS: '状态异常',
  LATE: '迟到',
  EARLY: '早退',
  ABSENT: '缺勤',
  INCOMPLETE: '记录不完整',
}

function typeLabel(type: string): string {
  return TYPE_LABELS[type] ?? type
}

function statusLabel(status: string): string {
  const labels: Record<string, string> = {
    OPEN: '待处理',
    PENDING: '待审批',
    SUBMITTED: '已提交',
    APPLIED: '已生效',
    RESOLVED: '已处理',
    CLOSED: '已关闭',
  }
  return labels[status] ?? status
}

// AttendanceCorrectionType is re-exported through the drawer API contract.
const correctionTypes: AttendanceCorrectionType[] = ['MISSING_PUNCH', 'WRONG_TIME', 'WRONG_STATUS']
</script>

<template>
  <section class="attendance-corrections" data-testid="attendance-corrections-tab" aria-labelledby="attendance-corrections-title">
    <header class="attendance-corrections__header">
      <div>
        <h2 id="attendance-corrections-title">补卡申请</h2>
        <p class="muted">原始打卡不可修改；补卡提交生成审批案例，审批通过后由 AttendanceCorrectionService 重算。</p>
      </div>
      <span class="attendance-corrections__count">{{ props.corrections.length }} 条</span>
    </header>

    <p v-if="props.actionMessage" class="attendance-corrections__success" role="status">{{ props.actionMessage }}</p>
    <p v-if="props.actionError" class="attendance-corrections__error" role="alert">{{ props.actionError }}</p>

    <LoadingState v-if="props.loading" :rows="4" label="正在加载可补正考勤日" />

    <EmptyState
      v-else-if="props.corrections.length === 0"
      title="当前月份没有可补正考勤日"
      description="服务端未返回考勤日。补卡只能针对真实考勤日期发起，页面不会生成虚假申请。"
    />

    <div v-else class="attendance-corrections__scroller" role="region" aria-label="可补正考勤日，可横向滚动" tabindex="0">
      <table class="attendance-corrections__table">
        <caption class="visually-hidden">可补正考勤日</caption>
        <thead>
          <tr>
            <th scope="col">业务日期</th>
            <th scope="col">员工</th>
            <th scope="col">异常类型</th>
            <th scope="col">状态</th>
            <th scope="col">说明</th>
            <th scope="col">操作</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="correction in props.corrections" :key="correction.key" :data-testid="`attendance-correction-${correction.key}`">
            <td>{{ correction.businessDate }}</td>
            <td>{{ correction.employeeName || (correction.employeeId === null ? '未提供' : `#${correction.employeeId}`) }}</td>
            <td>{{ typeLabel(correction.type) }}</td>
            <td>{{ statusLabel(correction.status) }}</td>
            <td>{{ correction.label ?? '——' }}</td>
            <td>
              <button
                v-if="props.canRequest"
                type="button"
                class="attendance-corrections__link"
                :data-testid="`attendance-correction-request-${correction.key}`"
                @click="emit('request-correction', { businessDate: correction.businessDate, employeeId: correction.employeeId })"
              >
                发起补卡
              </button>
              <span v-else class="muted">仅查看</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <p class="attendance-corrections__note">
      支持类型：{{ correctionTypes.join(' / ') }}。页面不提供直接改写原始打卡的能力。
    </p>
  </section>
</template>

<style scoped>
.attendance-corrections {
  background: #fff;
  border: 1px solid var(--color-line);
  border-radius: var(--radius-md);
}

.attendance-corrections__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  padding: 14px 16px;
  border-bottom: 1px solid var(--color-line);
}

.attendance-corrections__header h2 {
  font-size: 15px;
}

.attendance-corrections__header p {
  margin-top: 3px;
  font-size: 11.5px;
}

.attendance-corrections__count {
  padding: 3px 8px;
  color: var(--color-ink-muted);
  font-size: 11px;
  background: #f5f8f7;
  border-radius: 999px;
}

.attendance-corrections__scroller {
  overflow-x: auto;
}

.attendance-corrections__table {
  width: 100%;
  border-collapse: collapse;
  font-size: 12px;
}

.attendance-corrections__table th,
.attendance-corrections__table td {
  padding: 9px 11px;
  text-align: left;
  border-bottom: 1px solid var(--color-line);
}

.attendance-corrections__table th {
  color: var(--color-ink-muted);
  font-size: 10.5px;
  font-weight: 650;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  background: #fafcfb;
}

.attendance-corrections__link {
  color: #1d4f8a;
  font-size: 11.5px;
  background: transparent;
  border: 0;
  cursor: pointer;
}

.attendance-corrections__note {
  padding: 9px 16px;
  color: var(--color-ink-muted);
  font-size: 11px;
  border-top: 1px solid var(--color-line);
}

.attendance-corrections__error {
  margin: 12px 16px;
  padding: 8px 10px;
  color: #9f1c12;
  font-size: 11.5px;
  background: #feecea;
  border: 1px solid #f5c2bd;
  border-radius: 6px;
}

.attendance-corrections__success {
  margin: 12px 16px;
  padding: 8px 10px;
  color: #1f6b3a;
  font-size: 11.5px;
  background: #eef8f1;
  border: 1px solid #bfe3ca;
  border-radius: 6px;
}
</style>
