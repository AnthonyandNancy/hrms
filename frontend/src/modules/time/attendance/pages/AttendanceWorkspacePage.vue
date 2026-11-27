<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import ActionGate from '@/components/ActionGate.vue'
import LoadingState from '@/components/LoadingState.vue'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import { ApiError, errorCopy } from '@/api/errors'
import { usePageError } from '@/composables/usePageError'
import { useSessionStore } from '@/stores/session'
import AttendanceCalendar from '../components/AttendanceCalendar.vue'
import AttendanceCorrectionDrawer from '../components/AttendanceCorrectionDrawer.vue'
import AttendanceExceptionList from '../components/AttendanceExceptionList.vue'
import AttendanceRecordTable from '../components/AttendanceRecordTable.vue'
import AttendanceShiftAdmin from '../components/AttendanceShiftAdmin.vue'
import AttendanceRulePanel from '../components/AttendanceRulePanel.vue'
import AttendanceSchedulePanel from '../components/AttendanceSchedulePanel.vue'
import AttendanceClosingPanel from '../components/AttendanceClosingPanel.vue'
import AttendanceDevicePanel from '../components/AttendanceDevicePanel.vue'
import AttendanceCorrectionPanel from '../components/AttendanceCorrectionPanel.vue'
import {
  exceptionsFromPeriod,
  exportAttendanceRecords,
  getAttendanceClosures,
  getAttendanceDays,
  getAttendanceDevices,
  getAttendancePunches,
  getAttendanceRecords,
  getAttendanceRules,
  getAttendanceSchedules,
  getShiftTemplates,
  newAttendanceIdempotencyKey,
  resolveAttendanceException,
  saveAttendanceExport,
  type AttendanceClosure,
  type AttendanceDevice,
  type AttendanceExceptionPage,
  type AttendanceExceptionRow,
  type AttendancePage,
  type AttendancePeriod,
  type AttendancePunch,
  type AttendanceRecord,
  type AttendanceRuleVersion,
  type AttendanceSchedule,
  type ShiftTemplate,
} from '../api'

defineOptions({ name: 'AttendanceWorkspacePage' })

type AttendanceTab = 'my' | 'punches' | 'calendar' | 'exceptions' | 'corrections' | 'rules' | 'shifts' | 'schedules' | 'closing' | 'devices'

type TabSpec = { key: AttendanceTab; label: string }

const ALL_TABS: TabSpec[] = [
  { key: 'my', label: '我的打卡' },
  { key: 'punches', label: '明细' },
  { key: 'calendar', label: '日历' },
  { key: 'exceptions', label: '异常' },
  { key: 'corrections', label: '补卡' },
  { key: 'rules', label: '规则' },
  { key: 'shifts', label: '班次' },
  { key: 'schedules', label: '排班' },
  { key: 'closing', label: '月结' },
  { key: 'devices', label: '设备' },
]

const session = useSessionStore()
const route = useRoute()
const router = useRouter()
const pageError = usePageError()

const activeTab = ref<AttendanceTab>('my')
const loading = ref(false)
const loaded = ref(false)
const period = ref<AttendancePeriod | null>(null)
const managementPeriod = ref<AttendancePeriod | null>(null)
const exceptions = ref<AttendanceExceptionPage | null>(null)
const actionMessage = ref('')
const actionError = ref('')
const correctionOpen = ref(false)
const correctionDate = ref('')
const correctionEmploymentId = ref<number | null>(null)
const resolvingKey = ref<string | null>(null)

/**
 * Initial month is unset so the backend decides the current business month
 * from the company profile timezone. The browser clock is never used for a
 * business date or month.
 */
const selectedMonth = ref('')
const employeeFilter = ref('')
const orgUnitFilter = ref('')

const recordsPage = ref<AttendancePage<AttendanceRecord> | null>(null)
const punchesPage = ref<AttendancePage<AttendancePunch> | null>(null)
const recordsLoading = ref(false)
const punchesLoading = ref(false)
const recordsError = ref('')
const punchesError = ref('')
const recordsPageNumber = ref(1)
const recordsPageSize = ref(20)
const punchesPageNumber = ref(1)
const exporting = ref(false)

const rules = ref<AttendanceRuleVersion[]>([])
const rulesLoading = ref(false)
const rulesError = ref('')

const shifts = ref<ShiftTemplate[]>([])
const shiftsLoading = ref(false)
const shiftsError = ref('')

const schedules = ref<AttendanceSchedule[]>([])
const schedulesLoading = ref(false)
const schedulesError = ref('')
const schedulesPage = ref<AttendancePage<AttendanceSchedule> | null>(null)
const schedulePageNumber = ref(1)
const schedulePageSize = ref(20)

const closures = ref<AttendanceClosure[]>([])
const closuresLoading = ref(false)
const closuresError = ref('')

const devices = ref<AttendanceDevice[]>([])
const devicesLoading = ref(false)

const canRead = computed(() => session.can('time.attendance.read'))
const canManage = computed(() => session.can('time.attendance.manage'))
const canRequestCorrection = computed(() => session.can('time.attendance.correct.request'))
const canResolveException = computed(() => session.can('time.attendance.manage'))
const canReadPunches = computed(() => session.can('time.punch.read'))
const canExport = computed(() => session.can('time.attendance.export'))
const canReadRules = computed(() => session.can('time.attendance.rule.read'))
const canReadShifts = computed(() => session.can('time.shift.read'))
const canReadSchedule = computed(() => session.can('time.shift.schedule.read'))
const canReadDevices = computed(() => session.can('time.attendance.device.read'))

const canManagePunches = computed(() => canReadPunches.value || canManage.value)

const visibleTabs = computed<AttendanceTab[]>(() => [
  ...(canRead.value ? (['my'] as AttendanceTab[]) : []),
  ...(canManagePunches.value ? (['punches'] as AttendanceTab[]) : []),
  ...(canRead.value ? (['calendar', 'exceptions'] as AttendanceTab[]) : []),
  ...(canRequestCorrection.value ? (['corrections'] as AttendanceTab[]) : []),
  ...(canReadRules.value ? (['rules'] as AttendanceTab[]) : []),
  ...(canReadShifts.value ? (['shifts'] as AttendanceTab[]) : []),
  ...(canReadSchedule.value ? (['schedules'] as AttendanceTab[]) : []),
  ...(canManage.value ? (['closing'] as AttendanceTab[]) : []),
  ...(canReadDevices.value && devices.value.length > 0 ? (['devices'] as AttendanceTab[]) : []),
])

const availableTabs = computed(() => ALL_TABS.filter((tab) => visibleTabs.value.includes(tab.key)))

const empty = computed(() => loaded.value && period.value !== null && period.value.days.length === 0)
const activePeriod = computed(() => managementPeriod.value ?? period.value)
const partial = computed(
  () => period.value?.partial === true || exceptions.value?.partial === true || managementPeriod.value?.partial === true,
)
const partialReason = computed(
  () => period.value?.partialReason ?? exceptions.value?.partialReason ?? managementPeriod.value?.partialReason ?? '',
)

const openExceptions = computed<AttendanceExceptionRow[]>(() =>
  (exceptions.value?.items ?? []).filter(
    (exception) => exception.status === 'OPEN' || exception.status === 'PENDING',
  ),
)

const corrections = computed(() => {
  const source = activePeriod.value
  if (source === null) return []
  const rows = exceptionsFromPeriod(source).items
  const seen = new Set<string>()
  return rows
    .filter((row) => {
      const key = `${row.businessDate}|${row.employeeId ?? ''}`
      if (seen.has(key)) return false
      seen.add(key)
      return true
    })
    .map((row) => ({
      key: `${row.businessDate}-${row.employeeId ?? 'self'}`,
      businessDate: row.businessDate,
      employeeId: row.employeeId,
      employeeName: row.employeeName,
      type: row.type,
      status: row.status,
      label: row.label,
    }))
})

const currentEmployeeId = computed(() => integerFilter(employeeFilter.value))

const tabHint = computed(() => {
  if (activeTab.value === 'devices') return '仅展示服务端真实设备行；没有设备时该 Tab 不显示。'
  if (activeTab.value === 'closing') return '月结锁定后普通重算返回 409；重开需 time.attendance.reopen 和原因审计。'
  if (activeTab.value === 'rules') return '规则版本只追加；历史版本不会因发布新版本而被改写。'
  if (activeTab.value === 'shifts') return '班次编辑创建新版本；停用保留历史行和已排班引用。'
  return ''
})

function integerFilter(value: string): number | null {
  const trimmed = value.trim()
  if (!/^\d+$/.test(trimmed)) return null
  const parsed = Number(trimmed)
  return Number.isSafeInteger(parsed) && parsed > 0 ? parsed : null
}

function currentRange(): { from: string; to: string } | null {
  const month = selectedMonth.value
  if (!/^\d{4}-\d{2}$/.test(month)) return null
  const year = Number(month.slice(0, 4))
  const monthNumber = Number(month.slice(5, 7))
  // Render-only date-range derivation from the server-returned month token.
  const lastDay = new Date(Date.UTC(year, monthNumber, 0)).getUTCDate()
  return { from: `${month}-01`, to: `${month}-${String(lastDay).padStart(2, '0')}` }
}

async function ensureMonth(): Promise<boolean> {
  if (/^\d{4}-\d{2}$/.test(selectedMonth.value)) return true

  // The backend's self month endpoint requires an explicit month token. The
  // workspace must not derive one from the browser clock; the user is asked to
  // pick a server-validated business month instead.
  actionError.value = '请先选择业务月份；日期范围必须使用服务端业务月份。'
  return false
}

function tabQuery(tab: AttendanceTab): void {
  const query: Record<string, string> = { tab }
  if (selectedMonth.value !== '') query.month = selectedMonth.value
  if (employeeFilter.value !== '') query.employee = employeeFilter.value
  if (orgUnitFilter.value !== '') query.org_unit = orgUnitFilter.value
  void router.replace({ path: '/attendance', query })
}

function selectTab(tab: AttendanceTab): void {
  if (!visibleTabs.value.includes(tab)) return
  activeTab.value = tab
  tabQuery(tab)
  void loadActiveTab()
}

async function loadPeriod(): Promise<void> {
  if (!canRead.value) return
  loading.value = true
  loaded.value = false
  pageError.clear()
  actionError.value = ''

  const employeeId = activeTab.value === 'my' ? null : currentEmployeeId.value

  if (!/^\d{4}-\d{2}$/.test(selectedMonth.value)) {
    period.value = null
    managementPeriod.value = null
    exceptions.value = null
    loading.value = false
    loaded.value = true
    return
  }

  const selfQuery = {
    month: selectedMonth.value,
    employeeId,
    orgUnitId: integerFilter(orgUnitFilter.value),
  }

  try {
    if (employeeId === null) {
      const next = await getAttendanceDays(selfQuery)
      period.value = next
      managementPeriod.value = null
      exceptions.value = exceptionsFromPeriod(next)
    } else {
      const next = await getAttendanceDays(selfQuery)
      managementPeriod.value = next
      period.value = next
      exceptions.value = exceptionsFromPeriod(next)
    }
  } catch (reason) {
    period.value = null
    managementPeriod.value = null
    exceptions.value = null
    if (reason instanceof ApiError && (reason.status === 404 || reason.status === 403)) {
      actionError.value = '该员工不在当前授权范围，或服务端未返回该考勤周期。'
    } else {
      pageError.capture(reason)
    }
  } finally {
    loading.value = false
    loaded.value = true
  }
}

async function loadRecords(): Promise<void> {
  if (!canManagePunches.value) return
  const range = currentRange()
  if (range === null && !(await ensureMonth())) return
  const nextRange = currentRange()
  if (nextRange === null) return

  recordsLoading.value = true
  recordsError.value = ''
  try {
    recordsPage.value = await getAttendanceRecords({
      ...nextRange,
      employmentId: currentEmployeeId.value,
      page: recordsPageNumber.value,
      pageSize: recordsPageSize.value,
    })
  } catch (reason) {
    recordsPage.value = null
    recordsError.value = `考勤明细加载失败：${errorCopy(reason)}`
  } finally {
    recordsLoading.value = false
  }
}

async function loadPunches(): Promise<void> {
  if (!canReadPunches.value) {
    punchesPage.value = null
    punchesError.value = ''
    return
  }
  const range = currentRange()
  if (range === null && !(await ensureMonth())) return
  const nextRange = currentRange()
  if (nextRange === null) return

  punchesLoading.value = true
  punchesError.value = ''
  try {
    punchesPage.value = await getAttendancePunches({
      ...nextRange,
      employmentId: currentEmployeeId.value,
      page: punchesPageNumber.value,
      pageSize: recordsPageSize.value,
    })
  } catch (reason) {
    punchesPage.value = null
    punchesError.value = reason instanceof ApiError && reason.status === 403
      ? '缺少 time.punch.read，原始打卡明细不可见。'
      : `原始打卡加载失败：${errorCopy(reason)}`
  } finally {
    punchesLoading.value = false
  }
}

async function loadRules(): Promise<void> {
  if (!canReadRules.value) return
  rulesLoading.value = true
  rulesError.value = ''
  try {
    rules.value = await getAttendanceRules(activePeriod.value?.month ?? selectedMonth.value)
  } catch (reason) {
    rules.value = []
    rulesError.value = `考勤规则加载失败：${errorCopy(reason)}`
  } finally {
    rulesLoading.value = false
  }
}

async function loadShifts(): Promise<void> {
  if (!canReadShifts.value) return
  shiftsLoading.value = true
  shiftsError.value = ''
  try {
    shifts.value = await getShiftTemplates(selectedMonth.value === '' ? '' : `${selectedMonth.value}-01`)
  } catch (reason) {
    shifts.value = []
    shiftsError.value = `班次目录加载失败：${errorCopy(reason)}`
  } finally {
    shiftsLoading.value = false
  }
}

async function loadSchedules(): Promise<void> {
  if (!canReadSchedule.value) return
  const range = currentRange()
  if (range === null && !(await ensureMonth())) return
  const nextRange = currentRange()
  if (nextRange === null) return

  schedulesLoading.value = true
  schedulesError.value = ''
  try {
    const page = await getAttendanceSchedules(
      nextRange.from,
      nextRange.to,
      schedulePageNumber.value,
      schedulePageSize.value,
    )
    schedulesPage.value = page
    schedules.value = page.items
  } catch (reason) {
    schedulesPage.value = null
    schedules.value = []
    schedulesError.value = `排班加载失败：${errorCopy(reason)}`
  } finally {
    schedulesLoading.value = false
  }
}

async function loadClosures(): Promise<void> {
  if (!canManage.value) return
  closuresLoading.value = true
  closuresError.value = ''
  try {
    const page = await getAttendanceClosures(selectedMonth.value)
    closures.value = page.items
  } catch (reason) {
    closures.value = []
    closuresError.value = `月结记录加载失败：${errorCopy(reason)}`
  } finally {
    closuresLoading.value = false
  }
}

async function loadDevices(): Promise<void> {
  if (!canReadDevices.value) return
  devicesLoading.value = true
  try {
    devices.value = await getAttendanceDevices()
  } catch {
    devices.value = []
  } finally {
    devicesLoading.value = false
    if (activeTab.value === 'devices' && !visibleTabs.value.includes('devices')) {
      activeTab.value = 'my'
      tabQuery('my')
    }
  }
}

async function loadActiveTab(): Promise<void> {
  switch (activeTab.value) {
    case 'my':
      await loadPeriod()
      break
    case 'punches':
      await loadPeriod()
      await Promise.all([loadRecords(), loadPunches()])
      break
    case 'calendar':
    case 'exceptions':
    case 'corrections':
      await loadPeriod()
      break
    case 'rules':
      await loadRules()
      break
    case 'shifts':
      await loadShifts()
      break
    case 'schedules':
      await Promise.all([loadShifts(), loadSchedules()])
      break
    case 'closing':
      await loadClosures()
      break
    case 'devices':
      await loadDevices()
      break
  }
}

function applyFilters(): void {
  recordsPageNumber.value = 1
  schedulePageNumber.value = 1
  tabQuery(activeTab.value)
  void loadActiveTab()
}

function openCorrection(businessDate = '', employmentId: number | null = null): void {
  if (!canRequestCorrection.value) return
  correctionDate.value = businessDate !== '' ? businessDate : (period.value?.days[0]?.businessDate ?? '')
  correctionEmploymentId.value = employmentId ?? period.value?.employmentId ?? null
  actionError.value = ''
  actionMessage.value = ''
  correctionOpen.value = true
}

function onCorrectionSubmitted(requestNo: string): void {
  actionMessage.value = `补卡申请已提交：${requestNo || '已受理'}`
  void loadActiveTab()
}

async function resolveException(businessDate: string, note: string): Promise<void> {
  if (!canResolveException.value) return
  actionError.value = ''
  actionMessage.value = ''
  resolvingKey.value = businessDate
  try {
    await resolveAttendanceException(
      businessDate,
      note,
      newAttendanceIdempotencyKey('attendance-exception'),
      activePeriod.value?.employmentId ?? null,
    )
    actionMessage.value = '异常修正申请已提交，审批通过后由服务端重算。'
    await loadActiveTab()
  } catch (reason) {
    actionError.value = reason instanceof ApiError ? (reason.message || '异常处理未完成。') : '异常处理未完成。'
  } finally {
    resolvingKey.value = null
  }
}

async function exportRecords(): Promise<void> {
  if (!canExport.value) return
  const range = currentRange()
  if (range === null) {
    actionError.value = '请先加载服务端业务月份后再导出。'
    return
  }

  exporting.value = true
  actionError.value = ''
  actionMessage.value = ''
  try {
    const file = await exportAttendanceRecords({
      ...range,
      employmentId: currentEmployeeId.value,
    })
    saveAttendanceExport(file)
    actionMessage.value = '考勤明细 CSV 已生成并下载。'
  } catch (reason) {
    actionError.value = `导出失败：${errorCopy(reason)}`
  } finally {
    exporting.value = false
  }
}

function onPanelChanged(message: string): void {
  actionMessage.value = message
  void loadActiveTab()
}

function onRecordsPageChange(page: number): void {
  recordsPageNumber.value = page
  punchesPageNumber.value = page
  void Promise.all([loadRecords(), loadPunches()])
}

function onRecordsPageSizeChange(pageSize: number): void {
  recordsPageSize.value = pageSize
  recordsPageNumber.value = 1
  punchesPageNumber.value = 1
  void Promise.all([loadRecords(), loadPunches()])
}

function onSchedulesPageChange(page: number): void {
  schedulePageNumber.value = page
  void loadSchedules()
}

function onSchedulesPageSizeChange(pageSize: number): void {
  schedulePageSize.value = pageSize
  schedulePageNumber.value = 1
  void loadSchedules()
}

function initialTabFromQuery(): AttendanceTab {
  const requested = String(route.query.tab ?? '')
  const candidate = ALL_TABS.find((tab) => tab.key === requested)?.key
  return candidate !== undefined && visibleTabs.value.includes(candidate) ? candidate : 'my'
}

function adoptQueryFilters(): void {
  const month = String(route.query.month ?? '')
  if (/^\d{4}-\d{2}$/.test(month)) selectedMonth.value = month
  const employee = String(route.query.employee ?? '')
  if (/^\d+$/.test(employee)) employeeFilter.value = employee
  const orgUnit = String(route.query.org_unit ?? '')
  if (/^\d+$/.test(orgUnit)) orgUnitFilter.value = orgUnit
}

watch(
  () => session.capabilities,
  () => {
    if (!visibleTabs.value.includes(activeTab.value)) {
      activeTab.value = 'my'
    }
  },
)

watch(devices, () => {
  if (activeTab.value === 'devices' && !visibleTabs.value.includes('devices')) {
    activeTab.value = 'my'
    tabQuery('my')
  }
})

watch(
  () => route.query.tab,
  (value) => {
    const requested = String(value ?? '')
    const candidate = ALL_TABS.find((tab) => tab.key === requested)?.key
    if (candidate !== undefined && candidate !== activeTab.value && visibleTabs.value.includes(candidate)) {
      activeTab.value = candidate
      void loadActiveTab()
    }
  },
)

onMounted(async () => {
  adoptQueryFilters()

  if (!canRead.value) {
    loaded.value = true
    return
  }

  if (canReadDevices.value) {
    void loadDevices()
  }

  await loadPeriod()
  activeTab.value = initialTabFromQuery()

  if (activeTab.value !== 'my') {
    tabQuery(activeTab.value)
    if (activeTab.value === 'punches') {
      await Promise.all([loadRecords(), loadPunches()])
    } else {
      await loadActiveTab()
    }
  }
})
</script>

<template>
  <div class="page attendance-workspace" data-page="attendance">
    <header class="page-header">
      <div class="page-header__copy">
        <p class="page-header__breadcrumb">假勤与薪酬 · 考勤打卡</p>
        <h1 class="page-header__title">考勤工作台</h1>
        <p class="page-header__description">
          本人打卡与管理动作收敛在同一个角色化工作区；日期、范围、关账状态和计算结果均由服务端判定。
        </p>
      </div>
      <div class="page-header__actions">
        <ActionGate capability="time.attendance.correct.request">
          <button
            type="button"
            class="attendance-workspace__primary"
            data-testid="open-correction"
            @click="openCorrection()"
          >
            补卡
          </button>
        </ActionGate>
        <button type="button" class="attendance-workspace__secondary" :disabled="loading || !canRead" @click="loadActiveTab">
          刷新
        </button>
      </div>
    </header>

    <section v-if="!canRead" class="state-panel empty-state" role="status">
      <div class="state-panel__mark" aria-hidden="true">—</div>
      <div class="state-panel__content">
        <h2 class="state-panel__title">无权查看考勤</h2>
        <p class="state-panel__body">当前账号没有考勤查看权限。请通过角色授权申请后重试。</p>
      </div>
    </section>

    <template v-else>
      <nav class="attendance-workspace__tabs" aria-label="考勤工作区视图" data-testid="attendance-tabs">
        <button
          v-for="tab in availableTabs"
          :key="tab.key"
          type="button"
          class="attendance-workspace__tab"
          :class="{ 'attendance-workspace__tab--active': activeTab === tab.key }"
          :aria-current="activeTab === tab.key ? 'page' : undefined"
          :data-testid="`attendance-tab-${tab.key}`"
          @click="selectTab(tab.key)"
        >
          {{ tab.label }}
        </button>
      </nav>

      <form class="attendance-workspace__filters" aria-label="考勤筛选" @submit.prevent="applyFilters">
        <label>
          <span>业务月份</span>
          <el-input v-model="selectedMonth" data-testid="attendance-month" type="month" />
        </label>
        <label>
          <span>员工任职 ID</span>
          <el-input v-model="employeeFilter" data-testid="attendance-employee" inputmode="numeric" placeholder="管理视角按授权范围筛选" />
        </label>
        <label>
          <span>组织单元编号</span>
          <el-input v-model="orgUnitFilter" data-testid="attendance-org-unit" inputmode="numeric" placeholder="按授权范围筛选" />
        </label>
        <button type="submit" class="attendance-workspace__secondary" :disabled="loading">应用筛选</button>
      </form>

      <p v-if="tabHint" class="attendance-workspace__hint" role="status">{{ tabHint }}</p>
      <p v-if="actionMessage" class="attendance-workspace__success" role="status">{{ actionMessage }}</p>
      <p v-if="actionError" class="attendance-workspace__error" role="alert">{{ actionError }}</p>

      <ErrorState
        v-if="pageError.message.value && activeTab !== 'punches'"
        title="考勤工作台暂时无法加载"
        :description="pageError.message.value"
        :code="pageError.code.value"
        :request-id="pageError.requestId.value"
        @retry="loadActiveTab"
      />
      <LoadingState v-else-if="loading || devicesLoading" :rows="6" label="正在加载考勤工作台" />

      <template v-else>
        <section v-if="partial" class="attendance-workspace__partial" role="status" data-testid="attendance-partial">
          <strong>部分数据</strong>
          <span>{{ partialReason || '部分服务端投影暂不可用；其余数据仍按真实返回值展示。' }}</span>
        </section>

        <template v-if="activeTab === 'my' || activeTab === 'calendar'">
          <EmptyState
            v-if="selectedMonth === ''"
            title="请选择业务月份"
            description="考勤月份由服务端业务日期校验。页面不会使用浏览器本地月份替代，也不会展示未请求月份的数据。"
          />

          <EmptyState
            v-else-if="empty"
            title="本月暂无考勤记录"
            description="服务端尚未返回该业务月份的考勤日。可调整月份或等待考勤计算完成。"
          />

          <template v-else-if="period !== null">
            <AttendanceCalendar
              :month="period.month"
              :timezone="period.timezone"
              :days="period.days"
              :can-correct="canRequestCorrection && activeTab === 'my'"
              @correct="openCorrection"
            />
          </template>
        </template>

        <template v-else-if="activeTab === 'exceptions'">
          <EmptyState
            v-if="openExceptions.length === 0"
            title="当前没有待处理考勤异常"
            description="异常来自服务端考勤日投影，不在浏览器中推算。"
          />
          <AttendanceExceptionList
            v-else
            :exceptions="openExceptions"
            :can-resolve="canResolveException"
            :resolving-key="resolvingKey"
            @resolve="resolveException"
          />
        </template>

        <template v-else-if="activeTab === 'punches'">
          <AttendanceRecordTable
            :records="recordsPage?.items ?? []"
            :punches="punchesPage?.items ?? []"
            :loading="recordsLoading || punchesLoading"
            :error="recordsError || punchesError"
            :page="recordsPage?.page ?? recordsPageNumber"
            :page-size="recordsPage?.pageSize ?? recordsPageSize"
            :total="recordsPage?.total ?? 0"
            :show-punches="canReadPunches"
            :exporting="exporting"
            :can-correct="canRequestCorrection"
            @update:page="onRecordsPageChange"
            @update:page-size="onRecordsPageSizeChange"
            @export="exportRecords"
            @correct="(record) => openCorrection(record.businessDate, record.employmentId)"
          />
        </template>

        <template v-else-if="activeTab === 'corrections'">
          <EmptyState
            v-if="currentEmployeeId === null"
            title="请选择员工任职 ID"
            description="管理视角的补卡清单需要按授权范围内的员工查看；页面不会默认展示公司全员。"
          />
          <AttendanceCorrectionPanel
            v-else
            :corrections="corrections"
            :loading="loading"
            :can-request="canRequestCorrection"
            :action-message="actionMessage"
            :action-error="actionError"
            @request-correction="(payload) => openCorrection(payload.businessDate, payload.employeeId)"
          />
        </template>

        <template v-else-if="activeTab === 'rules'">
          <LoadingState v-if="rulesLoading" :rows="4" label="正在加载考勤规则" />
          <AttendanceRulePanel v-else :rules="rules" :loading="rulesLoading" :error="rulesError" @changed="onPanelChanged" />
        </template>

        <template v-else-if="activeTab === 'shifts'">
          <LoadingState v-if="shiftsLoading" :rows="4" label="正在加载班次" />
          <AttendanceShiftAdmin
            v-else
            :shifts="shifts"
            :loading="shiftsLoading"
            :error="shiftsError"
            @changed="onPanelChanged"
          />
        </template>

        <template v-else-if="activeTab === 'schedules'">
          <AttendanceSchedulePanel
            :schedules="schedules"
            :shifts="shifts"
            :loading="schedulesLoading || shiftsLoading"
            :error="schedulesError || shiftsError"
            :page="schedulesPage?.page ?? schedulePageNumber"
            :page-size="schedulesPage?.pageSize ?? schedulePageSize"
            :total="schedulesPage?.total ?? 0"
            @changed="onPanelChanged"
            @update:page="onSchedulesPageChange"
            @update:page-size="onSchedulesPageSizeChange"
          />
        </template>

        <template v-else-if="activeTab === 'closing'">
          <LoadingState v-if="closuresLoading" :rows="4" label="正在加载考勤月结" />
          <AttendanceClosingPanel
            v-else
            :closures="closures"
            :loading="closuresLoading"
            :error="closuresError"
            @changed="onPanelChanged"
          />
        </template>

        <template v-else-if="activeTab === 'devices'">
          <AttendanceDevicePanel :devices="devices" />
        </template>
      </template>
    </template>

    <AttendanceCorrectionDrawer
      v-model="correctionOpen"
      :business-date="correctionDate"
      :timezone="period?.timezone ?? ''"
      :employment-id="correctionEmploymentId"
      @submitted="onCorrectionSubmitted"
    />
  </div>
</template>

<style scoped>
.attendance-workspace {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.attendance-workspace__tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 5px;
  padding: 7px;
  background: #fff;
  border: 1px solid var(--color-line);
  border-radius: var(--radius-md);
}

.attendance-workspace__tab {
  padding: 6px 12px;
  color: var(--color-ink-muted);
  font-size: 11.5px;
  background: transparent;
  border: 1px solid transparent;
  border-radius: 6px;
  cursor: pointer;
}

.attendance-workspace__tab--active {
  color: #1d4f8a;
  font-weight: 650;
  background: #edf4fc;
  border-color: #cfe0f5;
}

.attendance-workspace__filters {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 10px;
  padding: 12px 14px;
  background: #fff;
  border: 1px solid var(--color-line);
  border-radius: var(--radius-md);
}

.attendance-workspace__filters label {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 150px;
  color: var(--color-ink-muted);
  font-size: 11px;
}

.attendance-workspace__filters input {
  padding: 6px 8px;
  color: var(--color-ink);
  font: inherit;
  border: 1px solid var(--color-line-strong);
  border-radius: 6px;
}

.attendance-workspace__primary,
.attendance-workspace__secondary {
  padding: 6px 12px;
  font-size: 12px;
  border-radius: 6px;
  cursor: pointer;
}

.attendance-workspace__primary {
  color: #fff;
  background: var(--color-primary, #1f5f8b);
  border: 1px solid var(--color-primary, #1f5f8b);
}

.attendance-workspace__secondary {
  color: var(--color-ink);
  background: #fff;
  border: 1px solid var(--color-line-strong);
}

.attendance-workspace__hint {
  padding: 8px 10px;
  color: var(--color-ink-muted);
  font-size: 11.5px;
  background: #f7faf9;
  border: 1px solid var(--color-line);
  border-radius: 6px;
}

.attendance-workspace__success {
  padding: 8px 10px;
  color: #1f6b3a;
  font-size: 12px;
  background: #eef8f1;
  border: 1px solid #bfe3ca;
  border-radius: 6px;
}

.attendance-workspace__error {
  padding: 8px 10px;
  color: #9f1c12;
  font-size: 12px;
  background: #feecea;
  border: 1px solid #f5c2bd;
  border-radius: 6px;
}

.attendance-workspace__partial {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  padding: 9px 12px;
  color: #6b4a00;
  font-size: 11.5px;
  background: #fffaf0;
  border: 1px dashed #e6c98d;
  border-radius: 6px;
}
</style>
