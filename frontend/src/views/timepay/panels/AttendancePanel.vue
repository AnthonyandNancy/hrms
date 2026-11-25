<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { resourcePost } from '@/api/resourceClient'
import { usePageError } from '@/composables/usePageError'
import type { TimePayrollPageDefinition } from '../types'
import {
  formatMinutes,
  integerValue,
  newIdempotencyKey,
  statusTone,
  textValue,
  todayIsoDate,
} from '../panelUtils'

const props = defineProps<{
  definition: TimePayrollPageDefinition
  rows: Record<string, unknown>[]
  related?: Record<string, unknown>
  selectedRunId?: number | null
}>()

const emit = defineEmits<{ refresh: []; 'action-success': [message: string] }>()

const actionError = usePageError()
const submitting = ref(false)
const actionMessage = ref('')

const attendanceSummary = computed(() => {
  const found = props.rows.find((row) => row.summary !== undefined)
  const summary = found?.summary
  return summary !== null && typeof summary === 'object' && !Array.isArray(summary)
    ? (summary as Record<string, unknown>)
    : null
})

const calendarDays = computed(() => {
  const first = props.rows[0]
  if (first === undefined) return []
  return Array.isArray(first) ? [] : Array.isArray(first.days) ? first.days.filter(isObject) : []
})

const exceptionRows = computed(() => {
  const first = props.rows[0]
  if (first === undefined || !Array.isArray(first.exceptions)) return []
  const values = first.exceptions
  return values.filter(isObject)
})

const correctionDays = computed(() => calendarDays.value.slice(0, 10))

const correctionForm = reactive({
  date: todayIsoDate(),
  correctionType: 'MISSING_PUNCH' as 'MISSING_PUNCH' | 'WRONG_TIME' | 'WRONG_STATUS',
  firstIn: '09:00',
  lastOut: '18:00',
  reason: '',
})

const closeForm = reactive({ month: new Date().toISOString().slice(0, 7) })
const lastClosureId = ref<number | null>(null)

function isObject(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
}

async function submitCorrection(): Promise<void> {
  actionError.clear()
  actionMessage.value = ''
  if (correctionForm.reason.trim().length < 5) {
    actionError.reject(new Error('补卡原因至少需要 5 个字符。'))
    return
  }
  submitting.value = true
  try {
    const result = await resourcePost<Record<string, unknown>>(
      '/time/attendance/corrections',
      {
        date: correctionForm.date,
        correctionType: correctionForm.correctionType,
        firstIn: correctionForm.firstIn,
        lastOut: correctionForm.lastOut,
        reason: correctionForm.reason.trim(),
      },
      { idempotencyKey: newIdempotencyKey('attendance-correction') },
    )
    actionMessage.value = `补卡申请已提交：${textValue(result.requestNo, '已受理')}`
    correctionForm.reason = ''
    emit('action-success', actionMessage.value)
  } catch (reason) {
    actionError.capture(reason)
  } finally {
    submitting.value = false
  }
}

function monthPayload(): Record<string, unknown> {
  return { month: closeForm.month, legal_entity_id: 1 }
}

async function closeMonth(): Promise<void> {
  await postAction('/time/attendance/closings', monthPayload(), '考勤月结已提交')
}

async function reopenMonth(): Promise<void> {
  if (lastClosureId.value === null) {
    actionError.reject(new Error('请先成功提交一次月结，获得可重开的月结记录。'))
    return
  }
  await postAction(
    `/time/attendance/closings/${lastClosureId.value}/reopen`,
    { reason: '页面解锁考勤月结' },
    '考勤月结已重开',
  )
}

async function recalculate(): Promise<void> {
  await postAction('/time/attendance/recalculate', monthPayload(), '考勤重算已受理')
}

async function postAction(path: string, body: Record<string, unknown>, success: string): Promise<void> {
  actionError.clear()
  actionMessage.value = ''
  submitting.value = true
  try {
    const result = await resourcePost<Record<string, unknown>>(path, body, {
      idempotencyKey: newIdempotencyKey('attendance-closing'),
    })
    const closureId = integerValue(result.closure_id)
    if (closureId !== null) lastClosureId.value = closureId
    actionMessage.value = success
    emit('action-success', success)
  } catch (reason) {
    actionError.capture(reason)
  } finally {
    submitting.value = false
  }
}

function dayStatusLabel(status: unknown): string {
  const value = textValue(status)
  const labels: Record<string, string> = {
    PRESENT: '出勤',
    LATE: '迟到',
    EARLY: '早退',
    ABSENT: '缺勤',
    LEAVE: '休假',
    REMOTE: '远程',
    REST: '休息',
    HOLIDAY: '节假日',
    INCOMPLETE: '不完整',
  }
  return labels[value] ?? value
}
</script>

<template>
  <div class="timepay-panel" :data-testid="definition.testId">
    <section
      v-if="attendanceSummary && definition.panel === 'attendance-calendar'"
      class="timepay-summary"
      aria-label="考勤汇总"
    >
      <div><span>应出勤</span><strong>{{ textValue(attendanceSummary.expectedDays) }} 天</strong></div>
      <div><span>实际出勤</span><strong>{{ textValue(attendanceSummary.presentDays) }} 天</strong></div>
      <div><span>迟到</span><strong>{{ textValue(attendanceSummary.lateCount) }} 次</strong></div>
      <div><span>缺勤</span><strong>{{ textValue(attendanceSummary.absentCount) }} 天</strong></div>
      <div><span>休假</span><strong>{{ textValue(attendanceSummary.leaveDays) }} 天</strong></div>
    </section>

    <section v-if="definition.panel === 'attendance-corrections'" class="timepay-form panel">
      <h2>发起补卡申请</h2>
      <p class="small muted">原始打卡不可修改；补卡会生成审批案例，审批通过后由服务端重算。</p>
      <div class="timepay-form__grid">
        <label>考勤日
          <input v-model="correctionForm.date" data-testid="correction-date" type="date" />
        </label>
        <label>补卡类型
          <select v-model="correctionForm.correctionType" data-testid="correction-type">
            <option value="MISSING_PUNCH">漏卡</option>
            <option value="WRONG_TIME">时刻错误</option>
            <option value="WRONG_STATUS">状态错误</option>
          </select>
        </label>
        <label>上班时间
          <input v-model="correctionForm.firstIn" data-testid="correction-first-in" type="time" />
        </label>
        <label>下班时间
          <input v-model="correctionForm.lastOut" data-testid="correction-last-out" type="time" />
        </label>
      </div>
      <label class="timepay-form__wide">补卡原因
        <textarea v-model="correctionForm.reason" maxlength="500" data-testid="correction-reason" rows="3" />
      </label>
      <el-button type="primary" :loading="submitting" :disabled="submitting" data-testid="correction-submit" @click="submitCorrection">
        提交补卡申请
      </el-button>
    </section>

    <section v-if="definition.panel === 'attendance-closing'" class="timepay-form panel">
      <h2>月结操作</h2>
      <p class="small muted">月结后普通用户不能重算；重开需要填写原因并留下审计记录。</p>
      <div class="timepay-form__grid">
        <label>考勤月
          <input v-model="closeForm.month" data-testid="attendance-close-month" type="month" />
        </label>
      </div>
      <div class="timepay-form__actions">
        <el-button type="primary" :loading="submitting" data-testid="attendance-close" @click="closeMonth">关账</el-button>
        <el-button :loading="submitting" data-testid="attendance-reopen" @click="reopenMonth">重开</el-button>
        <el-button :loading="submitting" data-testid="attendance-recalculate" @click="recalculate">重算</el-button>
      </div>
      <p class="small muted">最近一次关账记录：{{ lastClosureId === null ? '—' : `#${lastClosureId}` }}</p>
    </section>

    <section v-if="definition.panel === 'attendance-exceptions'" class="panel">
      <h2>异常清单</h2>
      <table v-if="exceptionRows.length > 0" class="timepay-table">
        <caption class="sr-only">考勤异常清单</caption>
        <thead><tr><th scope="col">日期</th><th scope="col">星期</th><th scope="col">状态</th><th scope="col">异常</th><th scope="col">已记录工时</th></tr></thead>
        <tbody>
          <tr v-for="day in exceptionRows" :key="textValue(day.date)">
            <td>{{ textValue(day.date) }}</td>
            <td>{{ textValue(day.weekday) }}</td>
            <td>{{ dayStatusLabel(day.status) }}</td>
            <td>{{ textValue(day.exceptionLabel) }}</td>
            <td>{{ formatMinutes(day.workedMinutes) }}</td>
          </tr>
        </tbody>
      </table>
      <p v-else class="small muted">后端投影未返回异常行；不以本地推算补足。</p>
    </section>

    <section v-else-if="definition.panel === 'attendance-calendar'" class="panel" data-calendar>
      <h2>考勤日历</h2>
      <table class="timepay-table">
        <caption class="sr-only">考勤日历</caption>
        <thead><tr><th scope="col">日期</th><th scope="col">星期</th><th scope="col">状态</th><th scope="col">上班</th><th scope="col">下班</th><th scope="col">工时</th><th scope="col">异常</th></tr></thead>
        <tbody>
          <tr v-for="day in calendarDays" :key="textValue(day.date)">
            <td>{{ textValue(day.date) }}</td>
            <td>{{ textValue(day.weekday) }}</td>
            <td><span class="timepay-status" :class="`timepay-status--${statusTone(textValue(day.status))}`">{{ dayStatusLabel(day.status) }}</span></td>
            <td>{{ textValue(day.firstIn) }}</td>
            <td>{{ textValue(day.lastOut) }}</td>
            <td>{{ formatMinutes(day.workedMinutes) }}</td>
            <td>{{ textValue(day.exceptionLabel) }}</td>
          </tr>
        </tbody>
      </table>
    </section>

    <section v-else-if="definition.panel === 'attendance-shifts'" class="panel">
      <h2>班次目录</h2>
      <table class="timepay-table">
        <caption class="sr-only">班次目录</caption>
        <thead><tr><th scope="col">编码</th><th scope="col">名称</th><th scope="col">开始</th><th scope="col">结束</th><th scope="col">跨夜</th><th scope="col">休息</th><th scope="col">版本</th></tr></thead>
        <tbody>
          <tr v-for="row in rows" :key="`${textValue(row.id)}-${textValue(row.version)}`">
            <td class="mono">{{ textValue(row.code) }}</td>
            <td>{{ textValue(row.name) }}</td>
            <td>{{ textValue(row.start_local_time) }}</td>
            <td>{{ textValue(row.end_local_time) }}</td>
            <td>{{ row.crosses_midnight === true ? '是' : '否' }}</td>
            <td>{{ textValue(row.break_minutes) }} 分钟</td>
            <td>{{ textValue(row.version) }}</td>
          </tr>
        </tbody>
      </table>
    </section>

    <section v-else class="panel">
      <h2>{{ definition.title }}</h2>
      <table v-if="calendarDays.length > 0" class="timepay-table">
        <caption class="sr-only">{{ definition.title }}记录</caption>
        <thead><tr><th scope="col">日期</th><th scope="col">星期</th><th scope="col">状态</th><th scope="col">首次打卡</th><th scope="col">末次打卡</th><th scope="col">工时</th></tr></thead>
        <tbody>
          <tr v-for="day in definition.panel === 'attendance-punches' || definition.panel === 'attendance-devices' ? correctionDays : calendarDays" :key="textValue(day.date)">
            <td>{{ textValue(day.date) }}</td>
            <td>{{ textValue(day.weekday) }}</td>
            <td>{{ dayStatusLabel(day.status) }}</td>
            <td>{{ textValue(day.firstIn) }}</td>
            <td>{{ textValue(day.lastOut) }}</td>
            <td>{{ formatMinutes(day.workedMinutes) }}</td>
          </tr>
        </tbody>
      </table>
      <p v-else class="small muted">本页主数据已加载；没有可用的结构化行，未展示任何虚构打卡或设备记录。</p>
    </section>

    <p v-if="actionMessage" class="timepay-action-success" role="status">{{ actionMessage }}</p>
    <p v-if="actionError.message.value" class="timepay-action-error" role="alert">
      {{ actionError.message.value }}
      <span v-if="actionError.requestId.value">（请求编号：{{ actionError.requestId.value }}）</span>
    </p>
  </div>
</template>
