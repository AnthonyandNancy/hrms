<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { resourcePost } from '@/api/resourceClient'
import { usePageError } from '@/composables/usePageError'
import { useSessionStore } from '@/stores/session'
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

const session = useSessionStore()
const actionError = usePageError()
const submitting = ref(false)
const actionMessage = ref('')

const canManageRules = computed(() => session.can('time.attendance.rule.manage'))
const canManageSchedule = computed(() => session.can('time.shift.schedule.manage'))

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

const ruleForm = reactive({
  version: '',
  effective_from: todayIsoDate(),
  late_grace_source: 'SHIFT' as 'SHIFT' | 'COMPANY' | 'NONE',
  count_break: true,
  overtime_after_scheduled: true,
  incomplete_threshold_minutes: '1',
})

const scheduleForm = reactive({
  employment_id: '',
  business_date: todayIsoDate(),
  shift_template_id: '',
})

const shiftOptions = computed<Record<string, unknown>[]>(() => {
  const catalogue = props.related?.shifts
  if (catalogue === null || typeof catalogue !== 'object' || Array.isArray(catalogue)) return []
  const items = (catalogue as Record<string, unknown>).items
  return Array.isArray(items) ? items.filter(isObject) : []
})

const ruleRows = computed(() => props.rows.filter((row) => row.version !== undefined))
const scheduleRows = computed(() => props.rows.filter((row) => row.business_date !== undefined))
const closureRows = computed(() => props.rows.filter((row) => row.month !== undefined && row.status !== undefined))
const deviceRows = computed(() => props.rows.filter((row) => row.code !== undefined && row.health !== undefined))

/**
 * Panels with their own dedicated section below. The punch fallback must not
 * render on top of them, otherwise a device list would show an empty punch
 * table as well.
 */
const DEDICATED_PANELS = new Set([
  'attendance-rules',
  'attendance-schedules',
  'attendance-devices',
  'attendance-closing',
  'attendance-exceptions',
  'attendance-calendar',
  'attendance-shifts',
  'attendance-corrections',
])
const showPunchFallback = computed(() => !DEDICATED_PANELS.has(props.definition.panel))

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
  return { month: closeForm.month }
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

function positiveInteger(raw: string): number | null {
  const trimmed = raw.trim()
  if (!/^[1-9]\d*$/.test(trimmed)) return null
  const value = Number(trimmed)
  return Number.isSafeInteger(value) ? value : null
}

async function publishRule(): Promise<void> {
  actionError.clear()
  actionMessage.value = ''

  const version = ruleForm.version.trim()
  const threshold = positiveInteger(ruleForm.incomplete_threshold_minutes)

  if (!/^[A-Za-z0-9._-]{3,32}$/.test(version)) {
    actionError.reject(new Error('规则版本号需要 3 到 32 个字母、数字、点、下划线或短横线。'))
    return
  }
  if (!/^\d{4}-\d{2}-\d{2}$/.test(ruleForm.effective_from)) {
    actionError.reject(new Error('请选择有效的规则生效日期。'))
    return
  }
  if (threshold === null) {
    actionError.reject(new Error('不完整判定阈值必须是正整数分钟。'))
    return
  }

  submitting.value = true
  try {
    const result = await resourcePost<Record<string, unknown>>(
      '/time/attendance/rules',
      {
        version,
        effective_from: ruleForm.effective_from,
        config: {
          late_grace_source: ruleForm.late_grace_source,
          count_break: ruleForm.count_break,
          overtime_after_scheduled: ruleForm.overtime_after_scheduled,
          incomplete_threshold_minutes: threshold,
        },
      },
      { idempotencyKey: newIdempotencyKey('attendance-rule') },
    )
    actionMessage.value = `考勤规则版本已发布：${textValue(result.version, version)}`
    ruleForm.version = ''
    emit('action-success', actionMessage.value)
    emit('refresh')
  } catch (reason) {
    actionError.capture(reason)
  } finally {
    submitting.value = false
  }
}

async function scheduleShift(): Promise<void> {
  actionError.clear()
  actionMessage.value = ''

  const employmentId = positiveInteger(scheduleForm.employment_id)
  const templateId = positiveInteger(scheduleForm.shift_template_id)

  if (employmentId === null) {
    actionError.reject(new Error('员工 ID 必须是正整数。'))
    return
  }
  if (!/^\d{4}-\d{2}-\d{2}$/.test(scheduleForm.business_date)) {
    actionError.reject(new Error('请选择有效的排班日期。'))
    return
  }
  if (templateId === null) {
    actionError.reject(new Error('请选择要排的班次。'))
    return
  }

  submitting.value = true
  try {
    const result = await resourcePost<Record<string, unknown>>(
      '/time/shift-schedules',
      {
        employment_id: employmentId,
        business_date: scheduleForm.business_date,
        shift_template_id: templateId,
      },
      { idempotencyKey: newIdempotencyKey('attendance-schedule') },
    )
    actionMessage.value = `排班已保存：${textValue(result.business_date, scheduleForm.business_date)}`
    emit('action-success', actionMessage.value)
    emit('refresh')
  } catch (reason) {
    actionError.capture(reason)
  } finally {
    submitting.value = false
  }
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

function ruleConfigValue(row: Record<string, unknown>, key: string): string {
  const config = row.config
  if (config === null || typeof config !== 'object' || Array.isArray(config)) return '—'
  const value = (config as Record<string, unknown>)[key]
  if (value === true) return '是'
  if (value === false) return '否'
  return textValue(value)
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

    <section v-if="definition.panel === 'attendance-rules'" class="panel">
      <h2>规则版本</h2>
      <p class="small muted">每个版本带生效日期；发布新版本只追加历史，历史版本永不被改写。</p>
      <table v-if="ruleRows.length > 0" class="timepay-table">
        <caption class="sr-only">考勤规则版本</caption>
        <thead><tr><th scope="col">版本</th><th scope="col">生效日期</th><th scope="col">当前生效</th><th scope="col">休息扣除</th><th scope="col">加班起算</th><th scope="col">校验和</th></tr></thead>
        <tbody>
          <tr v-for="row in ruleRows" :key="textValue(row.id)">
            <td class="mono">{{ textValue(row.version) }}</td>
            <td>{{ textValue(row.effective_from) }}</td>
            <td>{{ row.is_effective === true ? '是' : '否' }}</td>
            <td>{{ ruleConfigValue(row, 'count_break') }}</td>
            <td>{{ ruleConfigValue(row, 'overtime_after_scheduled') }}</td>
            <td class="mono">{{ textValue(row.checksum).slice(0, 12) }}</td>
          </tr>
        </tbody>
      </table>
      <p v-else class="small muted">后端还没有返回任何规则版本；页面不会本地生成规则。</p>

      <div v-if="canManageRules" class="timepay-form panel">
        <h3>发布新版本</h3>
        <div class="timepay-form__grid">
          <label>版本号
            <input v-model="ruleForm.version" data-testid="attendance-rule-version" maxlength="32" />
          </label>
          <label>生效日期
            <input v-model="ruleForm.effective_from" data-testid="attendance-rule-effective-from" type="date" />
          </label>
          <label>迟到宽限来源
            <select v-model="ruleForm.late_grace_source" data-testid="attendance-rule-late-grace-source">
              <option value="SHIFT">班次</option>
              <option value="COMPANY">公司</option>
              <option value="NONE">不宽限</option>
            </select>
          </label>
          <label>不完整判定阈值（分钟）
            <input v-model="ruleForm.incomplete_threshold_minutes" data-testid="attendance-rule-threshold" inputmode="numeric" />
          </label>
        </div>
        <label class="timepay-form__wide">
          <input v-model="ruleForm.count_break" data-testid="attendance-rule-count-break" type="checkbox" />
          休息时间计入工时
        </label>
        <label class="timepay-form__wide">
          <input v-model="ruleForm.overtime_after_scheduled" data-testid="attendance-rule-overtime" type="checkbox" />
          超过计划工时后记加班
        </label>
        <el-button type="primary" :loading="submitting" :disabled="submitting" data-testid="attendance-rule-publish" @click="publishRule">
          发布规则版本
        </el-button>
      </div>
      <p v-else class="small muted">当前账号没有 time.attendance.rule.manage，只能查看规则版本。</p>
    </section>

    <section v-if="definition.panel === 'attendance-schedules'" class="panel">
      <h2>排班记录</h2>
      <p class="small muted">同一员工同一天只允许一个班次；重复排不同班次由服务端返回 SCHEDULE_CONFLICT。</p>
      <table v-if="scheduleRows.length > 0" class="timepay-table">
        <caption class="sr-only">排班记录</caption>
        <thead><tr><th scope="col">考勤日</th><th scope="col">员工</th><th scope="col">班次</th><th scope="col">开始</th><th scope="col">结束</th><th scope="col">跨夜</th></tr></thead>
        <tbody>
          <tr v-for="row in scheduleRows" :key="textValue(row.id)">
            <td>{{ textValue(row.business_date) }}</td>
            <td>{{ textValue(row.employment_id) }}</td>
            <td class="mono">{{ textValue(row.shift_code) }}</td>
            <td>{{ textValue(row.start_local_time) }}</td>
            <td>{{ textValue(row.end_local_time) }}</td>
            <td>{{ row.crosses_midnight === true ? '是' : '否' }}</td>
          </tr>
        </tbody>
      </table>
      <p v-else class="small muted">当前筛选条件下没有排班记录；页面不会合成排班。</p>

      <div v-if="canManageSchedule" class="timepay-form panel">
        <h3>新增排班</h3>
        <div class="timepay-form__grid">
          <label>员工 ID
            <input v-model="scheduleForm.employment_id" data-testid="attendance-schedule-employment" inputmode="numeric" />
          </label>
          <label>考勤日
            <input v-model="scheduleForm.business_date" data-testid="attendance-schedule-date" type="date" />
          </label>
          <label>班次
            <select v-model="scheduleForm.shift_template_id" data-testid="attendance-schedule-template">
              <option value="">请选择班次</option>
              <option v-for="shift in shiftOptions" :key="textValue(shift.id)" :value="textValue(shift.id)">
                {{ textValue(shift.code) }} · {{ textValue(shift.start_local_time) }}–{{ textValue(shift.end_local_time) }}
              </option>
            </select>
          </label>
        </div>
        <el-button type="primary" :loading="submitting" :disabled="submitting" data-testid="attendance-schedule-submit" @click="scheduleShift">
          保存排班
        </el-button>
      </div>
      <p v-else class="small muted">当前账号没有 time.shift.schedule.manage，只能查看排班。</p>
    </section>

    <section v-if="definition.panel === 'attendance-devices'" class="panel">
      <h2>设备健康</h2>
      <p class="small muted">健康状态由适配器回报，接口只读；页面不提供任何写入设备健康的入口。</p>
      <table v-if="deviceRows.length > 0" class="timepay-table">
        <caption class="sr-only">考勤设备健康</caption>
        <thead><tr><th scope="col">编码</th><th scope="col">名称</th><th scope="col">适配器</th><th scope="col">健康</th><th scope="col">最近心跳</th><th scope="col">启用</th></tr></thead>
        <tbody>
          <tr v-for="row in deviceRows" :key="textValue(row.id)">
            <td class="mono">{{ textValue(row.code) }}</td>
            <td>{{ textValue(row.name) }}</td>
            <td>{{ textValue(row.adapter) }}</td>
            <td><span class="timepay-status" :class="`timepay-status--${statusTone(textValue(row.health))}`">{{ textValue(row.health) }}</span></td>
            <td>{{ textValue(row.last_seen_at) }}</td>
            <td>{{ row.active === true ? '是' : '否' }}</td>
          </tr>
        </tbody>
      </table>
      <p v-else class="small muted">尚未登记任何考勤设备；页面不会展示虚构设备状态。</p>
    </section>

    <section v-if="definition.panel === 'attendance-closing'" class="panel">
      <h2>月结记录</h2>
      <table v-if="closureRows.length > 0" class="timepay-table">
        <caption class="sr-only">考勤月结记录</caption>
        <thead><tr><th scope="col">月份</th><th scope="col">状态</th><th scope="col">关账时间</th><th scope="col">重开时间</th><th scope="col">重开原因</th></tr></thead>
        <tbody>
          <tr v-for="row in closureRows" :key="textValue(row.id)">
            <td>{{ textValue(row.month) }}</td>
            <td><span class="timepay-status" :class="`timepay-status--${statusTone(textValue(row.status))}`">{{ textValue(row.status) }}</span></td>
            <td>{{ textValue(row.closed_at) }}</td>
            <td>{{ textValue(row.reopened_at) }}</td>
            <td>{{ textValue(row.reopen_reason) }}</td>
          </tr>
        </tbody>
      </table>
      <p v-else class="small muted">尚无月结记录；关账后这里会出现带审计原因的锁定记录。</p>
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

    <section v-else-if="showPunchFallback" class="panel">
      <h2>{{ definition.title }}</h2>
      <table v-if="calendarDays.length > 0" class="timepay-table">
        <caption class="sr-only">{{ definition.title }}记录</caption>
        <thead><tr><th scope="col">日期</th><th scope="col">星期</th><th scope="col">状态</th><th scope="col">首次打卡</th><th scope="col">末次打卡</th><th scope="col">工时</th></tr></thead>
        <tbody>
          <tr v-for="day in definition.panel === 'attendance-punches' ? correctionDays : calendarDays" :key="textValue(day.date)">
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
