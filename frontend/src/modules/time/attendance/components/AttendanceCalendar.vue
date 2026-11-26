<script setup lang="ts">
import { computed, ref } from 'vue'
import type { AttendanceDay } from '../api'

const props = defineProps<{
  month: string
  timezone: string
  days: AttendanceDay[]
  canCorrect: boolean
}>()

const emit = defineEmits<{
  correct: [businessDate: string]
  select: [businessDate: string]
}>()

const selectedDate = ref<string | null>(null)

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

const STATUS_TONES: Record<string, string> = {
  PRESENT: 'success',
  LATE: 'warning',
  EARLY: 'warning',
  ABSENT: 'danger',
  LEAVE: 'info',
  REMOTE: 'info',
  REST: 'muted',
  HOLIDAY: 'muted',
  INCOMPLETE: 'danger',
}

const LEGEND = [
  { status: 'PRESENT', label: '出勤' },
  { status: 'LATE', label: '迟到' },
  { status: 'EARLY', label: '早退' },
  { status: 'ABSENT', label: '缺勤' },
  { status: 'LEAVE', label: '休假' },
] as const

const weekdayHeaders = ['一', '二', '三', '四', '五', '六', '日']

type CalendarCell = {
  key: string
  dayNumber: number
  businessDate: string | null
  day: AttendanceDay | null
}

function statusLabel(status: string): string {
  return STATUS_LABELS[status] ?? '未知'
}

function statusTone(status: string): string {
  return STATUS_TONES[status] ?? 'muted'
}

function daysInMonth(month: string): number {
  const match = /^(\d{4})-(\d{2})$/.exec(month)
  if (match === null) return 0
  const year = Number(match[1])
  const monthNumber = Number(match[2])
  if (!Number.isInteger(year) || !Number.isInteger(monthNumber) || monthNumber < 1 || monthNumber > 12) return 0
  return new Date(Date.UTC(year, monthNumber, 0)).getUTCDate()
}

function firstWeekdayOffset(month: string): number {
  const match = /^(\d{4})-(\d{2})$/.exec(month)
  if (match === null) return 0
  const year = Number(match[1])
  const monthNumber = Number(match[2])
  return (new Date(Date.UTC(year, monthNumber - 1, 1)).getUTCDay() + 6) % 7
}

const cells = computed<CalendarCell[]>(() => {
  const total = daysInMonth(props.month)
  if (total === 0) return []
  const byDate = new Map(props.days.map((day) => [day.businessDate, day]))
  const offset = firstWeekdayOffset(props.month)
  const result: CalendarCell[] = []
  for (let index = 0; index < offset; index++) {
    result.push({ key: `blank-${index}`, dayNumber: 0, businessDate: null, day: null })
  }
  for (let dayNumber = 1; dayNumber <= total; dayNumber++) {
    const businessDate = `${props.month}-${String(dayNumber).padStart(2, '0')}`
    result.push({
      key: businessDate,
      dayNumber,
      businessDate,
      day: byDate.get(businessDate) ?? null,
    })
  }
  return result
})

const selectedDay = computed<AttendanceDay | null>(() => {
  if (selectedDate.value === null) return null
  return props.days.find((day) => day.businessDate === selectedDate.value) ?? null
})

/**
 * Only the API-provided timezone is used. The browser timezone is deliberately
 * never consulted, so a server business date cannot silently become a local
 * UTC date.
 */
function formatClock(value: string | null): string {
  if (value === null || value === '') return '——'
  const trimmed = value.trim()

  // Already a business-local wall-clock value. Reinterpreting it as UTC would
  // shift the displayed punch a second time, so keep the API clock exactly.
  const timeOnly = /^(\d{2}):(\d{2})(?::\d{2}(?:\.\d+)?)?$/.exec(trimmed)
  if (timeOnly !== null) return `${timeOnly[1]}:${timeOnly[2]}`

  const localDateTime = /^\d{4}-\d{2}-\d{2}[T ](\d{2}):(\d{2})(?::\d{2}(?:\.\d+)?)?$/.exec(trimmed)
  if (localDateTime !== null) return `${localDateTime[1]}:${localDateTime[2]}`

  const instant = Date.parse(trimmed)
  if (Number.isNaN(instant)) return '时刻数据不可用'

  try {
    return new Intl.DateTimeFormat('en-GB', {
      timeZone: props.timezone,
      hour: '2-digit',
      minute: '2-digit',
      hourCycle: 'h23',
    }).format(new Date(instant))
  } catch {
    return '时区数据不可用'
  }
}

function formatMinutes(minutes: number): string {
  if (!Number.isFinite(minutes) || minutes < 0) return '——'
  const hours = Math.floor(minutes / 60)
  const rest = minutes % 60
  if (hours === 0) return `${rest} 分钟`
  return rest === 0 ? `${hours} 小时` : `${hours} 小时 ${rest} 分钟`
}

function openDay(cell: CalendarCell): void {
  if (cell.businessDate === null) return
  selectedDate.value = cell.businessDate
  emit('select', cell.businessDate)
}

function closeDetail(): void {
  selectedDate.value = null
}
</script>

<template>
  <section class="attendance-calendar" data-testid="attendance-calendar">
    <header class="attendance-calendar__header">
      <div>
        <h2>月历</h2>
        <p class="muted">
          业务月份：{{ props.month || '——' }} · 业务时区：{{ props.timezone || '——' }}
        </p>
      </div>
      <ul class="attendance-legend" data-testid="attendance-legend" aria-label="考勤图例">
        <li v-for="item in LEGEND" :key="item.status" class="attendance-legend__item">
          <span class="attendance-dot" :class="`attendance-dot--${item.status.toLowerCase()}`" aria-hidden="true" />
          {{ item.label }}
        </li>
      </ul>
    </header>

    <div class="attendance-calendar__grid" role="grid" :aria-label="`${props.month} 考勤日历`">
      <div v-for="header in weekdayHeaders" :key="header" class="attendance-calendar__weekday" role="columnheader">
        {{ header }}
      </div>
      <button
        v-for="cell in cells"
        :key="cell.key"
        type="button"
        class="attendance-day"
        :class="[
          cell.day === null ? 'attendance-day--empty' : `attendance-day--${statusTone(cell.day.status)}`,
          cell.businessDate === null ? 'attendance-day--blank' : '',
        ]"
        :data-business-date="cell.businessDate ?? undefined"
        :disabled="cell.businessDate === null"
        :aria-label="cell.businessDate === null ? '' : `${cell.businessDate} ${cell.day ? statusLabel(cell.day.status) : '没有考勤记录'}`"
        @click="openDay(cell)"
      >
        <span class="attendance-day__number">{{ cell.dayNumber || '' }}</span>
        <span v-if="cell.day" class="attendance-day__status" :class="`attendance-day__status--${statusTone(cell.day.status)}`">
          {{ statusLabel(cell.day.status) }}
        </span>
        <span v-else-if="cell.businessDate !== null" class="attendance-day__status attendance-day__status--muted">无记录</span>
      </button>
    </div>

    <p v-if="cells.length === 0" class="muted" role="status">
      服务端尚未返回可展示的业务月份。
    </p>

    <section
      v-if="selectedDay"
      class="attendance-day-detail"
      role="dialog"
      aria-label="当日详情"
      aria-modal="false"
    >
      <header class="attendance-day-detail__header">
        <h3>{{ selectedDay.businessDate }} · {{ statusLabel(selectedDay.status) }}</h3>
        <button type="button" class="attendance-day-detail__close" aria-label="关闭当日详情" @click="closeDetail">关闭</button>
      </header>
      <dl class="attendance-day-detail__facts">
        <div>
          <dt>上班</dt>
          <dd>{{ formatClock(selectedDay.firstIn) }}</dd>
        </div>
        <div>
          <dt>下班</dt>
          <dd>{{ formatClock(selectedDay.lastOut) }}</dd>
        </div>
        <div>
          <dt>计入工时</dt>
          <dd>{{ formatMinutes(selectedDay.workedMinutes) }}</dd>
        </div>
        <div>
          <dt>应出勤</dt>
          <dd>{{ formatMinutes(selectedDay.scheduledMinutes) }}</dd>
        </div>
        <div>
          <dt>迟到 / 早退</dt>
          <dd>{{ formatMinutes(selectedDay.lateMinutes) }} / {{ formatMinutes(selectedDay.earlyLeaveMinutes) }}</dd>
        </div>
        <div v-if="selectedDay.exceptionLabel">
          <dt>异常</dt>
          <dd>{{ selectedDay.exceptionLabel }}</dd>
        </div>
      </dl>
      <p class="attendance-day-detail__note">
        打卡时刻按公司业务时区 {{ props.timezone || '——' }} 显示；工时由服务端计算，页面不重新计算。
      </p>
      <button
        v-if="props.canCorrect"
        type="button"
        class="attendance-day-detail__correct"
        data-testid="calendar-correct"
        @click="emit('correct', selectedDay.businessDate)"
      >
        补卡
      </button>
    </section>
  </section>
</template>

<style scoped>
.attendance-calendar {
  background: #fff;
  border: 1px solid var(--color-line);
  border-radius: var(--radius-md);
}

.attendance-calendar__header {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  gap: 12px;
  padding: 14px 16px;
  border-bottom: 1px solid var(--color-line);
}

.attendance-calendar__header h2 {
  font-size: 15px;
}

.attendance-calendar__header p {
  margin-top: 3px;
  font-size: 11.5px;
}

.attendance-legend {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  align-items: center;
  margin: 0;
  padding: 0;
  list-style: none;
  font-size: 11.5px;
}

.attendance-legend__item {
  display: inline-flex;
  align-items: center;
  gap: 4px;
}

.attendance-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: #8b95a5;
}

.attendance-dot--present { background: #1f8a4c; }
.attendance-dot--late { background: #c47b00; }
.attendance-dot--early { background: #b45f06; }
.attendance-dot--absent { background: #b42318; }
.attendance-dot--leave { background: #2563a8; }

.attendance-calendar__grid {
  display: grid;
  grid-template-columns: repeat(7, minmax(0, 1fr));
  gap: 1px;
  padding: 1px;
  background: var(--color-line);
}

.attendance-calendar__weekday {
  padding: 6px;
  text-align: center;
  color: var(--color-ink-muted);
  font-size: 11px;
  background: var(--color-canvas);
}

.attendance-day {
  min-height: 76px;
  padding: 6px;
  text-align: left;
  background: #fff;
  border: 0;
  cursor: pointer;
}

.attendance-day:hover {
  background: #f7f9fc;
}

.attendance-day--blank {
  background: var(--color-canvas);
  cursor: default;
}

.attendance-day__number {
  display: block;
  color: var(--color-ink-muted);
  font-size: 11px;
}

.attendance-day__status {
  display: inline-block;
  margin-top: 8px;
  padding: 2px 6px;
  font-size: 11px;
  border-radius: 999px;
  background: #eef1f5;
}

.attendance-day__status--success { color: #1f6b3a; background: #eef8f1; }
.attendance-day__status--warning { color: #8f5b00; background: #fff5df; }
.attendance-day__status--danger { color: #9f1c12; background: #feecea; }
.attendance-day__status--info { color: #1d4f8a; background: #edf4fc; }
.attendance-day__status--muted { color: #6b7482; background: #f1f3f6; }

.attendance-day-detail {
  position: relative;
  margin: 14px 16px 16px;
  padding: 14px;
  background: var(--color-canvas);
  border: 1px solid var(--color-line);
  border-radius: var(--radius-md);
}

.attendance-day-detail__header {
  display: flex;
  justify-content: space-between;
  gap: 12px;
}

.attendance-day-detail__header h3 {
  font-size: 13.5px;
}

.attendance-day-detail__close {
  color: var(--color-ink-muted);
  font-size: 11.5px;
  background: transparent;
  border: 0;
  cursor: pointer;
}

.attendance-day-detail__facts {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
  gap: 10px;
  margin: 12px 0 0;
}

.attendance-day-detail__facts dt {
  color: var(--color-ink-muted);
  font-size: 10.5px;
}

.attendance-day-detail__facts dd {
  margin: 2px 0 0;
  font-size: 13px;
  font-weight: 650;
}

.attendance-day-detail__note {
  margin-top: 12px;
  color: var(--color-ink-muted);
  font-size: 11px;
}

.attendance-day-detail__correct {
  margin-top: 10px;
  padding: 6px 12px;
  color: #fff;
  font-size: 12px;
  background: var(--color-primary, #1f5f8b);
  border: 0;
  border-radius: 6px;
  cursor: pointer;
}
</style>
