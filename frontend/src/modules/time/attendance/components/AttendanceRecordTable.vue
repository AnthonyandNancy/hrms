<script setup lang="ts">
import { computed } from 'vue'
import ActionGate from '@/components/ActionGate.vue'
import EmptyState from '@/components/EmptyState.vue'
import LoadingState from '@/components/LoadingState.vue'
import type { AttendancePunch, AttendanceRecord } from '../api'

const props = withDefaults(
  defineProps<{
    records: AttendanceRecord[]
    punches: AttendancePunch[]
    loading: boolean
    error: string
    page: number
    pageSize: number
    total: number
    showPunches?: boolean
    exporting?: boolean
    canCorrect?: boolean
  }>(),
  {
    showPunches: false,
    exporting: false,
    canCorrect: false,
  },
)

const emit = defineEmits<{
  'update:page': [page: number]
  'update:pageSize': [pageSize: number]
  export: []
  correct: [record: AttendanceRecord]
}>()

const pageCount = computed(() => Math.max(1, Math.ceil(props.total / Math.max(1, props.pageSize))))

const STATUS_LABELS: Record<string, string> = {
  PRESENT: '出勤',
  LATE: '迟到',
  EARLY: '早退',
  ABSENT: '缺勤',
  LEAVE: '休假',
  REMOTE: '远程',
  REST: '休息',
  HOLIDAY: '节假日',
  INCOMPLETE: '记录不完整',
  UNKNOWN: '未知',
}

function statusLabel(status: string): string {
  return STATUS_LABELS[status] ?? status
}

function clockLabel(value: string | null): string {
  if (value === null || value === '') return '——'
  const timeOnly = /^(\d{2}):(\d{2})/.exec(value.trim())
  if (timeOnly !== null) return `${timeOnly[1]}:${timeOnly[2]}`
  // This table is fed by the company-date API. The business timezone is not
  // returned by the records contract, so the raw ISO value is preserved rather
  // than silently formatting it in the browser's local timezone.
  return value
}

function punchClockLabel(value: string): string {
  // The punch contract is explicitly UC; show the canonical instant without a
  // client-side timezone conversion.
  return value
}

function sourceLabel(source: string | null): string {
  if (source === null || source === '') return '——'
  const labels: Record<string, string> = {
    DEVICE: '设备',
    MANUAL: '人工',
    IMPORT: '导入',
    MIXED: '混合来源',
  }
  return labels[source] ?? source
}
</script>

<template>
  <section class="attendance-records" data-testid="attendance-records-tab" aria-labelledby="attendance-records-title">
    <header class="attendance-records__header">
      <div>
        <h2 id="attendance-records-title">考勤明细</h2>
        <p class="muted">
          服务端先按当前账号授权范围过滤，再分页。原始打卡只读，补卡必须经审批案例后由服务端重算。
        </p>
      </div>
      <div class="attendance-records__actions">
        <ActionGate capability="time.attendance.export">
          <button
            type="button"
            class="attendance-records__secondary"
            data-testid="attendance-export"
            :disabled="exporting || loading"
            @click="emit('export')"
          >
            {{ exporting ? '导出中…' : '导出 CSV' }}
          </button>
        </ActionGate>
        <span class="attendance-records__count">共 {{ total }} 条</span>
      </div>
    </header>

    <p v-if="error" class="attendance-records__error" role="alert">{{ error }}</p>
    <LoadingState v-if="loading" :rows="5" label="正在加载考勤明细" />

    <template v-else>
      <EmptyState
        v-if="records.length === 0"
        title="当前条件下没有考勤明细"
        description="服务端没有返回考勤日记录。请调整月份、员工编号或组织单元后重试；页面不会补造打卡数据。"
      />

      <div v-else class="attendance-records__scroller" role="region" aria-label="考勤明细，可横向滚动" tabindex="0">
        <table class="attendance-records__table">
          <caption class="visually-hidden">
            考勤明细，共 {{ total }} 条，第 {{ page }} 页，共 {{ pageCount }} 页
          </caption>
          <thead>
            <tr>
              <th scope="col">员工编号</th>
              <th scope="col">员工</th>
              <th scope="col">业务日期</th>
              <th scope="col">状态</th>
              <th scope="col">首次打卡</th>
              <th scope="col">末次打卡</th>
              <th scope="col">来源</th>
              <th scope="col">迟到 / 早退 / 缺勤</th>
              <th scope="col">异常</th>
              <th v-if="canCorrect" scope="col">操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="record in records" :key="`${record.employmentId}-${record.businessDate}`" :data-testid="`attendance-record-${record.employmentId}-${record.businessDate}`">
              <td class="mono">{{ record.employeeNo || `#${record.employmentId}` }}</td>
              <td>{{ record.employeeName || '未提供姓名' }}</td>
              <td>{{ record.businessDate }}</td>
              <td>{{ statusLabel(record.status) }}</td>
              <td>{{ clockLabel(record.firstIn) }}</td>
              <td>{{ clockLabel(record.lastOut) }}</td>
              <td>{{ sourceLabel(record.source) }}</td>
              <td class="tabular">
                {{ record.lateMinutes }} / {{ record.earlyLeaveMinutes }} / {{ record.absenceMinutes }} 分钟
              </td>
              <td>{{ record.exceptionLabel ?? '——' }}</td>
              <td v-if="canCorrect">
                <button
                  type="button"
                  class="attendance-records__link"
                  :data-testid="`attendance-record-correct-${record.employmentId}-${record.businessDate}`"
                  @click="emit('correct', record)"
                >
                  发起补卡
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <footer class="attendance-records__footer">
        <span class="muted">当前第 {{ page }} / {{ pageCount }} 页</span>
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

      <section v-if="showPunches" class="attendance-punches" aria-labelledby="attendance-punches-title">
        <header class="attendance-punches__header">
          <div>
            <h3 id="attendance-punches-title">原始打卡</h3>
            <p class="muted">原始设备/导入打卡不可编辑或删除；此处仅展示服务端返回的真实证据行。</p>
          </div>
          <span class="attendance-records__count">{{ punches.length }} 条</span>
        </header>

        <EmptyState
          v-if="punches.length === 0"
          title="当前条件下没有原始打卡"
          description="未返回打卡证据行。页面不会用考勤日投影推算原始打卡时刻。"
        />

        <div v-else class="attendance-records__scroller" role="region" aria-label="原始打卡，可横向滚动" tabindex="0">
          <table class="attendance-records__table" data-testid="attendance-punch-table">
            <caption class="visually-hidden">原始打卡证据，共 {{ punches.length }} 条</caption>
            <thead>
              <tr>
                <th scope="col">打卡记录</th>
                <th scope="col">员工</th>
                <th scope="col">打卡时刻（UTC）</th>
                <th scope="col">来源</th>
                <th scope="col">设备</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="punch in punches" :key="punch.id" :data-testid="`attendance-punch-${punch.id}`">
                <td class="mono">#{{ punch.id }}</td>
                <td>#{{ punch.employmentId }}</td>
                <td>{{ punchClockLabel(punch.punchedAtUtc) }}</td>
                <td>{{ sourceLabel(punch.source) }}</td>
                <td>{{ punch.deviceId ?? '——' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>
  </section>
</template>

<style scoped>
.attendance-records,
.attendance-punches {
  background: #fff;
  border: 1px solid var(--color-line);
  border-radius: var(--radius-md);
}

.attendance-records__header,
.attendance-punches__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  padding: 14px 16px;
  border-bottom: 1px solid var(--color-line);
}

.attendance-records__header h2,
.attendance-punches__header h3 {
  font-size: 15px;
}

.attendance-records__header p,
.attendance-punches__header p {
  margin-top: 3px;
  font-size: 11.5px;
}

.attendance-records__actions {
  display: flex;
  align-items: center;
  gap: 10px;
}

.attendance-records__count {
  padding: 3px 8px;
  color: var(--color-ink-muted);
  font-size: 11px;
  background: #f5f8f7;
  border-radius: 999px;
}

.attendance-records__scroller {
  overflow-x: auto;
}

.attendance-records__table {
  width: 100%;
  border-collapse: collapse;
  font-size: 12px;
}

.attendance-records__table th,
.attendance-records__table td {
  padding: 9px 11px;
  text-align: left;
  border-bottom: 1px solid var(--color-line);
}

.attendance-records__table th {
  color: var(--color-ink-muted);
  font-size: 10.5px;
  font-weight: 650;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  background: #fafcfb;
}

.attendance-records__footer {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 10px 16px;
  border-top: 1px solid var(--color-line);
}

.attendance-records__error {
  margin: 12px 16px;
  padding: 8px 10px;
  color: #9f1c12;
  font-size: 11.5px;
  background: #feecea;
  border: 1px solid #f5c2bd;
  border-radius: 6px;
}

.attendance-records__secondary {
  padding: 5px 11px;
  color: var(--color-ink);
  font-size: 11.5px;
  background: #fff;
  border: 1px solid var(--color-line-strong);
  border-radius: 6px;
  cursor: pointer;
}

.attendance-records__link {
  color: #1d4f8a;
  font-size: 11.5px;
  background: transparent;
  border: 0;
  cursor: pointer;
}

.attendance-punches {
  border-top: 0;
  border-radius: 0 0 var(--radius-md) var(--radius-md);
}
</style>
