<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getApi } from '@/api'
import type { AttendanceCorrectionPayload, AttendanceDay, AttendanceMonth } from '@/api/contracts'
import { errorCopy } from '@/api/errors'
import { formatMonthLabel, weekdayLabel } from '@/utils/format'
import { ATTENDANCE_STATUS } from '@/utils/status'
import { correctionSchema, validate } from '@/utils/validation'
import LoadingState from '@/components/LoadingState.vue'
import ErrorState from '@/components/ErrorState.vue'

const month = ref('2026-11')
const loading = ref(true)
const error = ref('')
const data = ref<AttendanceMonth | null>(null)
const correctionOpen = ref(false)
const submitting = ref(false)
const formError = ref('')
const form = reactive<AttendanceCorrectionPayload>({
  date: '2026-11-23',
  correctionType: 'MISSING_PUNCH',
  firstIn: '08:56',
  lastOut: '18:30',
  reason: '',
})
const fieldErrors = ref<Record<string, string>>({})

const monthOptions = [
  { label: '2026 年 11 月', value: '2026-11' },
  { label: '2026 年 10 月', value: '2026-10' },
  { label: '2026 年 9 月', value: '2026-09' },
]

const calendarCells = computed(() => {
  if (!data.value) return []
  const [yearText, monthText] = data.value.month.split('-')
  const year = Number(yearText)
  const monthNumber = Number(monthText)
  const first = new Date(Date.UTC(year, monthNumber - 1, 1))
  const offset = first.getUTCDay()
  const cells: Array<AttendanceDay | null> = Array.from({ length: offset }, () => null)
  cells.push(...data.value.days)
  while (cells.length % 7 !== 0) cells.push(null)
  return cells
})

const openingDay = computed(() => data.value?.days.find((day) => day.exceptionLabel))

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    data.value = await getApi().attendance.getAttendance(month.value)
  } catch (reason) {
    error.value = errorCopy(reason)
  } finally {
    loading.value = false
  }
}

function openCorrection(day?: AttendanceDay): void {
  form.date = day?.date ?? '2026-11-23'
  form.correctionType = day?.firstIn ? 'WRONG_TIME' : 'MISSING_PUNCH'
  form.firstIn = day?.firstIn ?? '08:56'
  form.lastOut = day?.lastOut ?? '18:30'
  form.reason = ''
  fieldErrors.value = {}
  formError.value = ''
  correctionOpen.value = true
}

async function submitCorrection(): Promise<void> {
  fieldErrors.value = {}
  formError.value = ''
  const parsed = validate(correctionSchema, form)
  if (!parsed.success) {
    for (const issue of parsed.issues) fieldErrors.value[issue.path] = issue.message
    return
  }
  submitting.value = true
  try {
    const result = await getApi().attendance.submitCorrection(parsed.data!)
    ElMessage.success(`补正申请已提交：${result.requestNo}。审批通过后将更新考勤日记录。`)
    correctionOpen.value = false
    await load()
  } catch (reason) {
    formError.value = errorCopy(reason)
  } finally {
    submitting.value = false
  }
}

function statusLabel(day: AttendanceDay): string {
  return ATTENDANCE_STATUS[day.status].label
}

function statusTone(day: AttendanceDay): string {
  return ATTENDANCE_STATUS[day.status].tone
}

function workedHours(minutes: number): string {
  if (minutes <= 0) return '—'
  const hours = Math.floor(minutes / 60)
  const remainder = minutes % 60
  return `${hours} 小时 ${remainder} 分`
}

onMounted(load)
</script>

<template>
  <div class="page attendance-page">
    <header class="page-header">
      <div class="page-header__copy">
        <p class="page-header__breadcrumb">假勤</p>
        <h1 class="page-header__title">考勤日历</h1>
        <p class="page-header__description">
          打卡记录不可变；异常通过补正申请修正。日期按租户时区 Asia/Shanghai 归属，跨夜班次不会错记到次日。
        </p>
      </div>
      <div class="page-header__actions">
        <el-select v-model="month" aria-label="选择考勤月份" style="width: 150px" @change="load">
          <el-option v-for="option in monthOptions" :key="option.value" :label="option.label" :value="option.value" />
        </el-select>
        <el-button type="primary" @click="openCorrection(openingDay)">发起补正</el-button>
      </div>
    </header>

    <ErrorState v-if="error" description="考勤数据暂时无法加载。" :code="error" @retry="load" />
    <LoadingState v-else-if="loading" :rows="8" label="正在加载考勤" />

    <template v-else-if="data">
      <section class="attendance-summary" aria-label="本月考勤汇总">
        <div class="attendance-summary__item">
          <span>应出勤</span>
          <strong>{{ data.summary.expectedDays }}<small>天</small></strong>
        </div>
        <div class="attendance-summary__item">
          <span>实际出勤</span>
          <strong>{{ data.summary.presentDays }}<small>天</small></strong>
        </div>
        <div class="attendance-summary__item">
          <span>迟到</span>
          <strong>{{ data.summary.lateCount }}<small>次</small></strong>
        </div>
        <div class="attendance-summary__item">
          <span>缺勤</span>
          <strong class="text-danger">{{ data.summary.absentCount }}<small>天</small></strong>
        </div>
        <div class="attendance-summary__item">
          <span>休假</span>
          <strong>{{ data.summary.leaveDays }}<small>天</small></strong>
        </div>
        <div class="attendance-summary__item">
          <span>远程</span>
          <strong>{{ data.summary.remoteDays }}<small>天</small></strong>
        </div>
        <div class="attendance-summary__fresh">
          <p class="small">考勤月：{{ formatMonthLabel(data.month) }}</p>
          <p class="small muted">{{ data.employeeName }} · {{ data.timezone }}</p>
        </div>
      </section>

      <div class="attendance-layout">
        <section class="panel">
          <div class="panel__header">
            <div>
              <h2 class="panel__title">{{ formatMonthLabel(data.month) }}</h2>
              <p class="panel__subtitle">色块与文字同时表达状态，不使用颜色作为唯一信息。</p>
            </div>
            <div class="calendar-legend" aria-label="状态图例">
              <span v-for="(status, key) in ATTENDANCE_STATUS" :key="key" class="calendar-legend__item">
                <span class="calendar-legend__dot" :class="`calendar-legend__dot--${status.tone}`" aria-hidden="true" />
                {{ status.label }}
              </span>
            </div>
          </div>
          <div class="panel__body">
            <div class="calendar-grid" role="table" aria-label="考勤月历">
              <div class="calendar-head" role="row">
                <span v-for="dayName in ['周日', '周一', '周二', '周三', '周四', '周五', '周六']" :key="dayName" role="columnheader">
                  {{ dayName }}
                </span>
              </div>
              <div class="calendar-body" role="rowgroup">
                <div
                  v-for="(day, index) in calendarCells"
                  :key="day?.date ?? `blank-${index}`"
                  class="calendar-cell"
                  :class="day ? `calendar-cell--${statusTone(day)}` : 'calendar-cell--blank'"
                  role="cell"
                  :aria-label="day ? `${day.date} ${day.weekday}，${statusLabel(day)}${day.exceptionLabel ? `，${day.exceptionLabel}` : ''}` : undefined"
                >
                  <template v-if="day">
                    <div class="calendar-cell__top">
                      <span class="calendar-cell__date">{{ Number(day.date.slice(8)) }}</span>
                      <span class="calendar-cell__status">{{ statusLabel(day) }}</span>
                    </div>
                    <div v-if="day.firstIn || day.lastOut" class="calendar-cell__times">
                      {{ day.firstIn ?? '缺失' }} → {{ day.lastOut ?? '缺失' }}
                    </div>
                    <p v-if="day.exceptionLabel" class="calendar-cell__exception">{{ day.exceptionLabel }}</p>
                    <p v-else-if="day.workedMinutes > 0" class="calendar-cell__hours">{{ workedHours(day.workedMinutes) }}</p>
                    <button
                      v-if="day.exceptionLabel"
                      type="button"
                      class="calendar-cell__fix"
                      @click="openCorrection(day)"
                    >
                      补正
                    </button>
                  </template>
                </div>
              </div>
            </div>
          </div>
        </section>

        <div class="stack">
          <section class="panel">
            <div class="panel__header">
              <div>
                <h2 class="panel__title">本月异常</h2>
                <p class="panel__subtitle">共 {{ data.exceptions.length }} 条需要处理</p>
              </div>
            </div>
            <ul v-if="data.exceptions.length" class="exception-list">
              <li v-for="day in data.exceptions" :key="day.date" class="exception-row">
                <div>
                  <p class="exception-row__date">{{ day.date }} · {{ weekdayLabel(day.date) }}</p>
                  <p class="exception-row__label">{{ day.exceptionLabel }}</p>
                  <p class="small muted">
                    {{ day.firstIn ?? '无上班打卡' }} / {{ day.lastOut ?? '无下班打卡' }}
                  </p>
                </div>
                <el-button size="small" plain @click="openCorrection(day)">补正</el-button>
              </li>
            </ul>
            <div v-else class="panel__body">
              <p class="empty-inline">本月没有考勤异常。</p>
            </div>
          </section>

          <section class="panel">
            <div class="panel__header">
              <h2 class="panel__title">规则说明</h2>
            </div>
            <div class="panel__body stack--tight small muted">
              <p><strong>不可变打卡：</strong>原始打卡记录只追加、不更新。补正作为新的决定与考勤日投影。</p>
              <p><strong>截止时间：</strong>本月考勤确认截止 {{ formatMonthLabel(data.month) }} 28 日 18:00，逾期需走线下审批。</p>
              <p><strong>时区：</strong>记录时间为 UTC，展示时转换为 Asia/Shanghai；业务日期按当地工作日归属。</p>
            </div>
          </section>
        </div>
      </div>
    </template>

    <el-dialog v-model="correctionOpen" title="发起考勤补正" width="520px">
      <div class="stack">
        <el-alert v-if="formError" :title="formError" type="error" :closable="false" show-icon />
        <label class="field-label">
          <span>考勤日期</span>
          <el-date-picker v-model="form.date" type="date" value-format="YYYY-MM-DD" format="YYYY-MM-DD" aria-label="补正日期" />
          <em v-if="fieldErrors.date" class="field-error">{{ fieldErrors.date }}</em>
        </label>
        <label class="field-label">
          <span>补正类型</span>
          <el-select v-model="form.correctionType" aria-label="补正类型">
            <el-option label="缺少打卡" value="MISSING_PUNCH" />
            <el-option label="打卡时间错误" value="WRONG_TIME" />
            <el-option label="考勤状态错误" value="WRONG_STATUS" />
          </el-select>
        </label>
        <div class="correction-times">
          <label class="field-label">
            <span>上班时间</span>
            <el-time-picker v-model="form.firstIn" format="HH:mm" value-format="HH:mm" placeholder="08:56" aria-label="上班时间" />
            <em v-if="fieldErrors.firstIn" class="field-error">{{ fieldErrors.firstIn }}</em>
          </label>
          <label class="field-label">
            <span>下班时间</span>
            <el-time-picker v-model="form.lastOut" format="HH:mm" value-format="HH:mm" placeholder="18:30" aria-label="下班时间" />
            <em v-if="fieldErrors.lastOut" class="field-error">{{ fieldErrors.lastOut }}</em>
          </label>
        </div>
        <label class="field-label">
          <span>补正原因</span>
          <el-input
            v-model="form.reason"
            type="textarea"
            :rows="3"
            maxlength="200"
            show-word-limit
            placeholder="说明为什么原记录不正确，例如：忘记下班打卡"
          />
          <em v-if="fieldErrors.reason" class="field-error">{{ fieldErrors.reason }}</em>
        </label>
      </div>
      <template #footer>
        <el-button @click="correctionOpen = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="submitCorrection">提交补正申请</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.attendance-summary {
  display: flex;
  flex-wrap: wrap;
  gap: 0;
  align-items: stretch;
  background: #fff;
  border: 1px solid var(--color-line);
  border-radius: var(--radius-md);
  box-shadow: var(--shadow-soft);
}

.attendance-summary__item {
  display: flex;
  min-width: 100px;
  flex: 1;
  flex-direction: column;
  gap: 2px;
  padding: 12px 16px;
  border-right: 1px solid var(--color-line);
}

.attendance-summary__item span {
  color: var(--color-ink-muted);
  font-size: 10.5px;
}

.attendance-summary__item strong {
  font-size: 24px;
  font-weight: 680;
  font-variant-numeric: tabular-nums;
}

.attendance-summary__item small {
  margin-left: 2px;
  color: var(--color-ink-muted);
  font-size: 10px;
  font-weight: 500;
}

.attendance-summary__fresh {
  display: flex;
  flex-direction: column;
  justify-content: center;
  gap: 3px;
  padding: 12px 16px;
  margin-left: auto;
}

.attendance-layout {
  display: grid;
  grid-template-columns: minmax(0, 1.7fr) minmax(300px, 1fr);
  gap: 16px;
  align-items: start;
}

.calendar-legend {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  justify-content: flex-end;
}

.calendar-legend__item {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  color: var(--color-ink-muted);
  font-size: 10px;
}

.calendar-legend__dot {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: var(--color-line-strong);
}

.calendar-legend__dot--success {
  background: var(--color-success);
}

.calendar-legend__dot--warning {
  background: var(--color-warning);
}

.calendar-legend__dot--danger {
  background: var(--color-danger);
}

.calendar-legend__dot--info {
  background: var(--color-info);
}

.calendar-grid {
  overflow-x: auto;
}

.calendar-head,
.calendar-body {
  display: grid;
  grid-template-columns: repeat(7, minmax(92px, 1fr));
  gap: 1px;
  min-width: 660px;
}

.calendar-head span {
  padding: 4px 6px 7px;
  color: var(--color-ink-muted);
  font-size: 10px;
  font-weight: 650;
  text-align: center;
}

.calendar-body {
  background: var(--color-line);
  border: 1px solid var(--color-line);
  border-radius: 5px;
  overflow: hidden;
}

.calendar-cell {
  position: relative;
  display: flex;
  flex-direction: column;
  min-height: 108px;
  padding: 7px 8px;
  background: #fff;
}

.calendar-cell--blank {
  background: #f8faf9;
}

.calendar-cell--success {
  background: #fbfefc;
}

.calendar-cell--warning {
  background: #fffbf2;
}

.calendar-cell--danger {
  background: #fffafa;
}

.calendar-cell--info {
  background: #f7fbfd;
}

.calendar-cell__top {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 4px;
}

.calendar-cell__date {
  font-size: 14px;
  font-weight: 650;
  font-variant-numeric: tabular-nums;
}

.calendar-cell__status {
  color: var(--color-ink-muted);
  font-size: 9px;
}

.calendar-cell__times {
  margin-top: 5px;
  color: var(--color-ink-secondary);
  font-family: "Cascadia Code", Consolas, monospace;
  font-size: 10px;
}

.calendar-cell__hours {
  margin-top: 3px;
  color: var(--color-ink-muted);
  font-size: 9.5px;
}

.calendar-cell__exception {
  margin-top: 3px;
  color: var(--color-warning);
  font-size: 9.5px;
  line-height: 1.35;
}

.calendar-cell__fix {
  align-self: flex-start;
  margin-top: auto;
  padding: 1px 0;
  color: var(--color-brand);
  font-size: 9.5px;
  text-decoration: underline;
  background: transparent;
  border: 0;
  cursor: pointer;
}

.exception-list {
  margin: 0;
  padding: 0;
  list-style: none;
}

.exception-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 11px 16px;
  border-bottom: 1px solid #edf1ef;
}

.exception-row:last-child {
  border-bottom: 0;
}

.exception-row__date {
  font-size: 12px;
  font-weight: 650;
}

.exception-row__label {
  color: var(--color-warning);
  font-size: 11px;
}

.field-label {
  display: flex;
  flex-direction: column;
  gap: 5px;
  color: var(--color-ink-muted);
  font-size: 11px;
  font-weight: 600;
}

.field-label :deep(.el-date-editor) {
  width: 100%;
}

.field-error {
  color: var(--color-danger);
  font-size: 11px;
  font-style: normal;
  font-weight: 400;
}

.correction-times {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}

.text-danger {
  color: var(--color-danger) !important;
}

@media (max-width: 1100px) {
  .attendance-layout {
    grid-template-columns: minmax(0, 1fr);
  }
}

@media (max-width: 700px) {
  .attendance-summary__item {
    min-width: 33%;
    border-bottom: 1px solid var(--color-line);
  }

  .attendance-summary__fresh {
    width: 100%;
    margin-left: 0;
  }
}
</style>
