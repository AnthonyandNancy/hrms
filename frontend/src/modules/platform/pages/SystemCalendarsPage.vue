<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import DataTable, { type DataTableColumn } from '@/components/DataTable.vue'
import PageShell from '@/shared/ui/PageShell.vue'
import { useSessionStore } from '@/stores/session'
import { formatDateTimeInTimezone } from '@/utils/format'
import {
  getCompanyCalendar,
  isForbidden,
  platformErrorCopy,
  publishCompanyCalendar,
  type CalendarPublishInput,
  type CompanyCalendar,
} from '../api'
import { holidaySourceLabel, holidayTypeLabel, timezoneLabel, workweekText } from '../labels'
import { nextIdempotencyKey } from '../platformRead'

const session = useSessionStore()
const canRead = computed(() => session.can('time.calendar.read'))
const canManage = computed(() => session.can('time.calendar.manage'))

const currentYear = new Date().getFullYear()
const year = ref(currentYear)
const loading = ref(true)
const loadError = ref<unknown>(null)
const calendar = ref<CompanyCalendar | null>(null)
const publishedAt = ref<string | null>(null)

const publishing = ref(false)
const publishError = ref('')
const publishMessage = ref('')
const confirmOpen = ref(false)

const form = reactive({
  timezone: 'Asia/Shanghai',
  workweek: [1, 2, 3, 4, 5] as number[],
  standardDayMinutes: 480,
  holidays: [] as Array<{ date: string; name: string; type: string }>,
})

const holidayDraft = reactive({ date: '', name: '', type: 'PUBLIC' })

const WEEKDAY_OPTIONS = [
  { value: 1, label: '周一' },
  { value: 2, label: '周二' },
  { value: 3, label: '周三' },
  { value: 4, label: '周四' },
  { value: 5, label: '周五' },
  { value: 6, label: '周六' },
  { value: 7, label: '周日' },
]

const HOLIDAY_TYPE_OPTIONS = [
  { value: 'PUBLIC', label: '法定节假日' },
  { value: 'COMPANY', label: '公司假日' },
  { value: 'MAKEUP', label: '调休补班' },
]

const TIMEZONE_OPTIONS = [
  { value: 'Asia/Shanghai', label: '中国标准时间（上海）' },
  { value: 'Asia/Hong_Kong', label: '中国香港时间' },
  { value: 'Asia/Singapore', label: '新加坡时间' },
  { value: 'UTC', label: '协调世界时' },
]

const rows = computed(() =>
  (calendar.value?.holidays ?? []).map((holiday) => ({
    ...holiday,
    typeLabel: holidayTypeLabel(holiday.type),
    sourceLabel: holidaySourceLabel(holiday.source),
  })),
)

const isCurrentSnapshotEmpty = computed(
  () => !loading.value && loadError.value === null && calendar.value === null,
)
const yearHolidays = computed(() => calendar.value?.year === year.value ? rows.value : [])

const holidayColumns: DataTableColumn[] = [
  { key: 'date', label: '日期', width: '130px' },
  { key: 'name', label: '名称', minWidth: '160px' },
  { key: 'typeLabel', label: '类型', width: '120px' },
  { key: 'sourceLabel', label: '来源', width: '130px' },
]

const conflictWarning = computed(() => {
  if (calendar.value === null) return ''
  if (calendar.value.version <= 0) return ''
  return '发布时会整体替换当前日历快照；历史版本由审计记录保留，不会在页面上回写为可编辑草稿。'
})

function syncForm(next: CompanyCalendar): void {
  form.timezone = next.timezone
  form.workweek = [...next.workweek]
  form.standardDayMinutes = next.standardDayMinutes
  form.holidays = next.holidays.map((holiday) => ({
    date: holiday.date,
    name: holiday.name,
    type: holiday.type,
  }))
}

async function loadCalendar(): Promise<void> {
  loading.value = true
  loadError.value = null
  calendar.value = null
  publishedAt.value = null
  try {
    const result = await getCompanyCalendar(year.value)
    calendar.value = result
    syncForm(result)
  } catch (error) {
    loadError.value = error
  } finally {
    loading.value = false
  }
}

function changeYear(delta: number): void {
  year.value = Math.min(9999, Math.max(1970, year.value + delta))
  void loadCalendar()
}

function addHoliday(): void {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(holidayDraft.date)) {
    publishError.value = '请选择有效的节假日日期。'
    return
  }
  if (holidayDraft.name.trim() === '') {
    publishError.value = '请填写节假日名称。'
    return
  }
  if (form.holidays.some((holiday) => holiday.date === holidayDraft.date)) {
    publishError.value = '同一日期只能维护一条节假日记录。'
    return
  }
  form.holidays.push({ date: holidayDraft.date, name: holidayDraft.name.trim(), type: holidayDraft.type })
  form.holidays.sort((left, right) => left.date.localeCompare(right.date))
  holidayDraft.date = ''
  holidayDraft.name = ''
  publishError.value = ''
}

function removeHoliday(date: string): void {
  form.holidays = form.holidays.filter((holiday) => holiday.date !== date)
}

function toggleWorkday(day: number): void {
  if (form.workweek.includes(day)) {
    form.workweek = form.workweek.filter((value) => value !== day)
  } else {
    form.workweek = [...form.workweek, day].sort((left, right) => left - right)
  }
}

function publishInput(): CalendarPublishInput {
  return {
    timezone: form.timezone,
    workweek: [...form.workweek].sort((left, right) => left - right),
    standardDayMinutes: Number(form.standardDayMinutes),
    holidays: form.holidays.map((holiday) => ({ ...holiday })),
  }
}

async function askPublish(): Promise<void> {
  publishError.value = ''
  if (form.workweek.length === 0) {
    publishError.value = '至少需要选择一个工作日。'
    return
  }
  if (!Number.isSafeInteger(Number(form.standardDayMinutes)) || Number(form.standardDayMinutes) < 1) {
    publishError.value = '标准工作日分钟数必须为正整数。'
    return
  }
  confirmOpen.value = true
  try {
    await ElMessageBox.confirm(
      `确认发布 ${year.value} 年工作日历吗？发布后当前版本将递增，节假日快照会被整体替换。`,
      '发布工作日历',
      { type: 'warning', confirmButtonText: '确认发布', cancelButtonText: '取消' },
    )
    await publishCalendar()
  } catch {
    // Cancelled by the operator; no write occurred.
  } finally {
    confirmOpen.value = false
  }
}

async function publishCalendar(): Promise<void> {
  publishing.value = true
  publishError.value = ''
  publishMessage.value = ''
  try {
    await publishCompanyCalendar(publishInput(), nextIdempotencyKey('calendar-publish'))
    publishMessage.value = '工作日历已发布，正在重新加载当前版本。'
    publishedAt.value = new Date().toISOString()
    ElMessage.success(publishMessage.value)
    await loadCalendar()
  } catch (error) {
    publishError.value = platformErrorCopy(error)
    ElMessage.error(publishError.value)
  } finally {
    publishing.value = false
  }
}

function retry(): void {
  void loadCalendar()
}

onMounted(() => {
  if (!canRead.value) {
    loading.value = false
    return
  }
  void loadCalendar()
})
</script>

<template>
  <PageShell
    data-testid="system-calendars-page"
    title="节假日与工作日历"
    description="查看当前公司日历版本、工作日规则与年度节假日快照；发布时整表替换，并由服务端递增版本与记录审计。"
  >
    <template #actions>
      <el-button plain :disabled="loading || !canRead" @click="retry">刷新</el-button>
      <el-button
        v-if="canRead && canManage && calendar !== null"
        type="primary"
        :loading="publishing"
        data-testid="calendar-publish"
        @click="askPublish"
      >
        发布工作日历
      </el-button>
    </template>

    <section v-if="!canRead" class="panel" data-testid="calendar-forbidden">
      <div class="panel__body">
        <h2 class="panel__title">无权查看工作日历</h2>
        <p class="muted">当前账号没有工作日历查看权限。请联系系统管理员授予日历查看能力。</p>
      </div>
    </section>

    <template v-else>
      <p v-if="publishMessage" class="inline-success" data-testid="calendar-publish-message">{{ publishMessage }}</p>
      <p v-if="publishError" class="inline-error" role="alert" data-testid="calendar-publish-error">{{ publishError }}</p>

      <section v-if="loading" class="panel" data-testid="calendar-loading">
        <div class="panel__body">
          <el-skeleton :rows="6" animated />
          <span class="visually-hidden">正在加载工作日历</span>
        </div>
      </section>

      <section v-else-if="loadError !== null && isForbidden(loadError)" class="panel" data-testid="calendar-forbidden">
        <div class="panel__body">
          <h2 class="panel__title">工作日历不可查看</h2>
          <p class="muted">公司日历可能尚未配置，或你的数据范围不包含该日历。请联系系统管理员确认。</p>
        </div>
      </section>

      <section v-else-if="loadError !== null" class="panel" data-testid="calendar-error">
        <div class="panel__body">
          <h2 class="panel__title">工作日历加载失败</h2>
          <p class="muted">{{ platformErrorCopy(loadError) }}</p>
          <el-button type="primary" plain @click="retry">重试</el-button>
        </div>
      </section>

      <section v-else-if="isCurrentSnapshotEmpty" class="panel" data-testid="calendar-empty">
        <div class="panel__body">
          <h2 class="panel__title">尚未发布工作日历</h2>
          <p class="muted">公司还没有可用的日历快照。具备日历管理能力时可以发布第一个版本。</p>
        </div>
      </section>

      <template v-else-if="calendar !== null">
        <section class="calendar-toolbar panel">
          <div class="panel__body calendar-toolbar__body">
            <div class="calendar-year">
              <el-button text :disabled="year <= 1970" aria-label="上一年" @click="changeYear(-1)">←</el-button>
              <strong data-testid="calendar-year">{{ year }} 年</strong>
              <el-button text :disabled="year >= 9999" aria-label="下一年" @click="changeYear(1)">→</el-button>
            </div>
            <span class="plain-chip plain-chip--info" data-testid="calendar-version">当前版本 v{{ calendar.version }}</span>
            <span class="muted small" data-testid="calendar-timezone">{{ timezoneLabel(calendar.timezone) }}</span>
            <span class="muted small">标准工作日 {{ Math.floor(calendar.standardDayMinutes / 60) }} 小时 {{ calendar.standardDayMinutes % 60 }} 分钟</span>
          </div>
        </section>

        <section class="panel">
          <div class="panel__header">
            <div>
              <h2 class="panel__title">日历版本</h2>
              <p class="panel__subtitle">接口仅暴露当前有效快照；历史版本由平台审计记录保留，不在此页面伪造版本行。</p>
            </div>
          </div>
          <div class="panel__body">
            <div class="version-row" data-testid="calendar-version-row">
              <span class="plain-chip plain-chip--success">已发布</span>
              <strong>第 {{ calendar.version }} 版</strong>
              <span class="muted">{{ workweekText(calendar.workweek) }}</span>
              <span class="muted">{{ calendar.holidays.length }} 条节假日</span>
              <span v-if="publishedAt" class="muted small">本次发布于 {{ formatDateTimeInTimezone(publishedAt) }}</span>
            </div>
            <p v-if="conflictWarning" class="muted small version-note">{{ conflictWarning }}</p>
          </div>
        </section>

        <section class="panel">
          <div class="panel__header">
            <div>
              <h2 class="panel__title">工作日规则</h2>
              <p class="panel__subtitle">
                <span data-testid="calendar-workweek">{{ workweekText(calendar.workweek) }}</span>
              </p>
            </div>
          </div>
          <div class="panel__body">
            <p class="muted">工作日按公司统一配置执行；调休补班通过节假日类型 MAKEUP 覆盖周末规则。</p>
          </div>
        </section>

        <section class="panel">
          <div class="panel__header">
            <div>
              <h2 class="panel__title">{{ year }} 年节假日与调休</h2>
              <p class="panel__subtitle">数据来自当前已发布日历快照，按日期排序。</p>
            </div>
          </div>
          <div class="panel__body">
            <DataTable
              v-if="yearHolidays.length > 0"
              :columns="holidayColumns"
              :rows="yearHolidays as unknown as Array<Record<string, unknown>>"
              :total="yearHolidays.length"
              :page="1"
              :page-size="100"
              row-key="date"
              data-testid="calendar-holiday-table"
              empty-title="当前版本没有维护该年份的节假日"
            >
              <template #cell-date="{ row }">
                <span class="mono">{{ row.date }}</span>
              </template>
              <template #cell-typeLabel="{ row }">
                <span class="plain-chip plain-chip--info">{{ row.typeLabel }}</span>
              </template>
            </DataTable>
            <div v-else class="empty-inline" data-testid="calendar-holidays-empty">
              当前版本没有维护 {{ year }} 年的节假日。切换到其他年份或具备管理能力时补录后发布。
            </div>
          </div>
        </section>

        <section v-if="canManage" class="panel" data-testid="calendar-editor">
          <div class="panel__header">
            <div>
              <h2 class="panel__title">下一版本配置</h2>
              <p class="panel__subtitle">编辑后点击“发布工作日历”；发布动作需要二次确认并写入幂等键。</p>
            </div>
          </div>
          <div class="panel__body calendar-editor">
            <div class="calendar-editor__grid">
              <label class="calendar-field">
                <span>时区</span>
                <div class="calendar-chip-field" role="group" aria-label="公司时区">
                  <button
                    v-for="zone in TIMEZONE_OPTIONS"
                    :key="zone.value"
                    type="button"
                    class="calendar-chip"
                    :class="{ 'calendar-chip--active': form.timezone === zone.value }"
                    :aria-pressed="form.timezone === zone.value"
                    :data-testid="`calendar-timezone-${zone.value}`"
                    @click="form.timezone = zone.value"
                  >
                    {{ zone.label }}
                  </button>
                </div>
              </label>
              <label class="calendar-field">
                <span>标准工作日分钟</span>
                <el-input-number v-model="form.standardDayMinutes" :min="1" :max="1440" :step="30" data-testid="calendar-standard-minutes" />
              </label>
            </div>

            <fieldset class="calendar-workweek">
              <legend>工作日</legend>
              <el-checkbox
                v-for="day in WEEKDAY_OPTIONS"
                :key="day.value"
                :model-value="form.workweek.includes(day.value)"
                :data-testid="`calendar-workday-${day.value}`"
                @change="toggleWorkday(day.value)"
              >
                {{ day.label }}
              </el-checkbox>
            </fieldset>

            <div class="holiday-editor">
              <h3 class="panel__title">节假日</h3>
              <div class="holiday-editor__row">
                <el-date-picker
                  v-model="holidayDraft.date"
                  data-testid="calendar-holiday-date"
                  type="date"
                  value-format="YYYY-MM-DD"
                  aria-label="节假日日期"
                  placeholder="选择日期"
                />
                <el-input v-model="holidayDraft.name" data-testid="calendar-holiday-name" aria-label="节假日名称" placeholder="例如 国庆节" />
                <div class="calendar-chip-field" role="group" aria-label="节假日类型" data-testid="calendar-holiday-type">
                  <button
                    v-for="type in HOLIDAY_TYPE_OPTIONS"
                    :key="type.value"
                    type="button"
                    class="calendar-chip"
                    :class="{ 'calendar-chip--active': holidayDraft.type === type.value }"
                    :aria-pressed="holidayDraft.type === type.value"
                    :data-testid="`calendar-holiday-type-${type.value}`"
                    @click="holidayDraft.type = type.value"
                  >
                    {{ type.label }}
                  </button>
                </div>
                <el-button plain data-testid="calendar-holiday-add" @click="addHoliday">添加</el-button>
              </div>

              <ul v-if="form.holidays.length > 0" class="holiday-draft-list" data-testid="calendar-holiday-draft-list">
                <li v-for="holiday in form.holidays" :key="holiday.date">
                  <span class="mono">{{ holiday.date }}</span>
                  <span>{{ holiday.name }}</span>
                  <span class="plain-chip plain-chip--info">{{ holidayTypeLabel(holiday.type) }}</span>
                  <el-button text type="danger" size="small" @click="removeHoliday(holiday.date)">移除</el-button>
                </li>
              </ul>
              <p v-else class="muted">当前版本没有节假日记录。</p>
            </div>
          </div>
        </section>

        <el-dialog v-model="confirmOpen" title="发布工作日历" width="480px" data-testid="calendar-publish-confirm">
          <p>将发布 {{ year }} 年工作日历第 {{ calendar.version + 1 }} 版；节假日快照会整体替换。</p>
          <p class="muted small">{{ workweekText(form.workweek) }} · 标准工作日 {{ form.standardDayMinutes }} 分钟 · {{ form.holidays.length }} 条节假日</p>
          <template #footer>
            <el-button @click="confirmOpen = false">取消</el-button>
            <el-button type="primary" :loading="publishing" data-testid="calendar-publish-confirm-ok" @click="publishCalendar">
              确认发布
            </el-button>
          </template>
        </el-dialog>
      </template>
    </template>
  </PageShell>
</template>

<style scoped>
.calendar-toolbar__body {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px;
}
.calendar-year {
  display: flex;
  align-items: center;
  gap: 6px;
}
.version-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px;
  padding: 10px 12px;
  background: var(--color-canvas);
  border: 1px solid var(--color-line);
  border-radius: var(--radius-md);
}
.version-note {
  margin: 9px 0 0;
}
.calendar-editor {
  display: flex;
  flex-direction: column;
  gap: 16px;
}
.calendar-editor__grid {
  display: flex;
  flex-wrap: wrap;
  gap: 14px;
}
.calendar-chip-field {
  display: flex;
  flex-wrap: wrap;
  gap: 5px;
}
.calendar-chip {
  padding: 4px 9px;
  color: var(--color-ink-secondary);
  font: inherit;
  font-size: 11px;
  cursor: pointer;
  background: var(--color-canvas);
  border: 1px solid var(--color-line);
  border-radius: 999px;
}
.calendar-chip--active {
  color: var(--color-brand-deep, #0f4956);
  background: #eaf4f7;
  border-color: #a8d2dc;
}
.calendar-field {
  display: flex;
  flex: 1 1 220px;
  flex-direction: column;
  gap: 6px;
  font-size: 12px;
  font-weight: 600;
}
.calendar-workweek {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  padding: 10px 12px;
  border: 1px solid var(--color-line);
  border-radius: var(--radius-md);
}
.calendar-workweek legend {
  padding: 0 4px;
  font-size: 12px;
  font-weight: 600;
}
.calendar-workday {
  display: inline-flex;
  gap: 5px;
  align-items: center;
  font-size: 12px;
}
.holiday-editor {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.holiday-editor__row {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
}
.holiday-draft-list {
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding: 0;
  margin: 0;
  list-style: none;
}
.holiday-draft-list li {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  align-items: center;
  padding: 7px 10px;
  font-size: 12px;
  background: var(--color-canvas);
  border-radius: 6px;
}
.inline-warning {
  padding: 9px 12px;
  color: #8a5a00;
  font-size: 12px;
  background: #fff6e5;
  border: 1px solid #ead49f;
  border-radius: 7px;
}
.plain-chip--success {
  color: #146c43;
  background: #e8f6ee;
  border-color: #b7dcc7;
}
.plain-chip--info {
  color: #0f5466;
  background: #eaf4f7;
  border-color: #c4dde4;
}
</style>
