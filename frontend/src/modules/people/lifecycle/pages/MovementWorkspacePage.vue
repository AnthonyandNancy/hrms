<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useSessionStore } from '@/stores/session'
import { ApiError, errorCode, errorCopy } from '@/api/errors'
import { formatBusinessDate, formatMonthLabel, todayBusinessDate } from '@/utils/format'
import LoadingState from '@/components/LoadingState.vue'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import {
  APPLICATION_STATUS_LABELS,
  MOVEMENT_TYPES,
  allowedMovementKinds,
  effectStatusLabel,
  listEmployees,
  listMovementLedger,
  listOrgUnits,
  listUsers,
  movementKindLabel,
  statusLabel,
  type EmployeeOption,
  type MovementKind,
  type MovementListRow,
  type OrgUnitOption,
  type UserOption,
} from '../api'
import MovementFormDrawer from '../components/MovementFormDrawer.vue'
import { DISPLAY_CODES, translate } from '@/shared/display/dictionary'

defineOptions({ name: 'MovementWorkspacePage' })

type MovementTab = 'all' | MovementKind
type MovementView = 'ledger' | 'calendar'

const route = useRoute()
const router = useRouter()
const session = useSessionStore()

const canReadLifecycle = computed(() => session.can('lifecycle.case.read'))
const canReadPeople = computed(() => session.can('people.employee.read'))
const canReadOrg = computed(() => session.can('org.unit.read'))
const canReadUsers = computed(() => session.can('iam.user.read'))
const readOnly = computed(() => !canReadLifecycle.value)
const createKinds = computed(() => allowedMovementKinds(session.capabilities))
const canCreate = computed(() => createKinds.value.length > 0)

const loading = ref(false)
const loaded = ref(false)
const errorMessage = ref('')
const errorRequestId = ref<string | null>(null)
const errorCodeValue = ref('')
const partialNote = ref('')
const rows = ref<MovementListRow[]>([])
const total = ref(0)
const employees = ref<EmployeeOption[]>([])
const orgUnits = ref<OrgUnitOption[]>([])
const users = ref<UserOption[]>([])
const employeeLookupError = ref('')

const typeFilter = ref<MovementTab>('all')
const statusFilter = ref('')
const employeeFilter = ref('')
const search = ref('')
const effectiveFrom = ref('')
const effectiveTo = ref('')
const page = ref(1)
const pageSize = 10
const view = ref<MovementView>('ledger')
const drawerOpen = ref(false)

const STATUS_OPTIONS = [
  { value: '', label: '全部状态' },
  { value: 'DRAFT', label: APPLICATION_STATUS_LABELS.DRAFT ?? '草稿' },
  { value: 'SUBMITTED', label: APPLICATION_STATUS_LABELS.SUBMITTED ?? '已提交' },
  { value: 'IN_REVIEW', label: APPLICATION_STATUS_LABELS.IN_REVIEW ?? '审批中' },
  { value: 'APPROVED', label: APPLICATION_STATUS_LABELS.APPROVED ?? '已批准' },
  { value: 'REJECTED', label: APPLICATION_STATUS_LABELS.REJECTED ?? '已驳回' },
  { value: 'WITHDRAWN', label: APPLICATION_STATUS_LABELS.WITHDRAWN ?? '已撤回' },
  { value: 'CANCELLED', label: APPLICATION_STATUS_LABELS.CANCELLED ?? '已取消' },
  { value: 'SCHEDULED', label: '已排期' },
  { value: DISPLAY_CODES.applicationStatus.active, label: translate('people', 'applicationStatus', DISPLAY_CODES.applicationStatus.active).label },
  { value: 'COMPLETED', label: '已结束' },
]

const typeTabs: Array<{ value: MovementTab; label: string }> = [
  { value: 'all', label: '全部异动' },
  ...MOVEMENT_TYPES.map((type) => ({ value: type.value as MovementTab, label: type.label })),
]

const employeeById = computed(() => {
  const map = new Map<number, EmployeeOption>()
  for (const employee of employees.value) map.set(employee.employmentId, employee)
  return map
})

const userById = computed(() => {
  const map = new Map<number, UserOption>()
  for (const user of users.value) map.set(user.id, user)
  return map
})

const pageCount = computed(() => Math.max(1, Math.ceil(total.value / pageSize)))

const calendarGroups = computed(() => {
  const groups = new Map<string, MovementListRow[]>()
  for (const row of rows.value) {
    const month = (row.effectiveDate ?? row.plannedEffectiveOn ?? '').slice(0, 7)
    if (!/^\d{4}-\d{2}$/.test(month)) continue
    const bucket = groups.get(month) ?? []
    bucket.push(row)
    groups.set(month, bucket)
  }
  return [...groups.entries()]
    .sort(([left], [right]) => right.localeCompare(left))
    .map(([month, items]) => ({ month, label: formatMonthLabel(month), items }))
})

function employeeName(row: MovementListRow): string {
  if (row.employeeName !== '') return row.employeeName
  if (row.employeeId === null) return '—'
  return employeeById.value.get(row.employeeId)?.name ?? `员工关系 #${row.employeeId}`
}

function employeeNo(row: MovementListRow): string {
  if (row.employeeNo !== '') return row.employeeNo
  if (row.employeeId === null) return ''
  return employeeById.value.get(row.employeeId)?.employeeNo ?? ''
}

function initiatorName(row: MovementListRow): string {
  if (row.initiatorDisplayName !== null && row.initiatorDisplayName !== '') return row.initiatorDisplayName
  if (row.initiatorUserId === null) return '—'
  const known = userById.value.get(row.initiatorUserId)?.displayName
  if (known !== undefined) return known
  const current = session.principal
  if (current !== null && current.userId === row.initiatorUserId) return current.displayName
  return `用户 #${row.initiatorUserId}`
}

function isOpenStatus(value: string): boolean {
  return value === DISPLAY_CODES.applicationStatus.draft
    || value === DISPLAY_CODES.applicationStatus.submitted
    || value === DISPLAY_CODES.applicationStatus.inReview
    || value === DISPLAY_CODES.applicationStatus.scheduled
    || value === DISPLAY_CODES.applicationStatus.active
}

function statusChipClass(row: MovementListRow): string {
  if (!isOpenStatus(row.applicationStatus)) return 'is-closed'
  return row.isAwaitingMe ? 'is-awaiting' : 'is-open'
}

function laneLabel(row: MovementListRow): string {
  if (row.lane === 'EFFECTIVE') return '已生效'
  if (row.lane === 'SCHEDULED') return '待生效'
  return '待审批'
}

function laneClass(row: MovementListRow): string {
  if (row.lane === 'EFFECTIVE') return 'is-effective'
  if (row.lane === 'SCHEDULED') return 'is-scheduled'
  return 'is-pending'
}

function captureError(reason: unknown): void {
  errorCodeValue.value = errorCode(reason)
  if (reason instanceof ApiError) {
    errorRequestId.value = reason.requestId
    errorMessage.value = reason.code === 'FORBIDDEN'
      ? '无权读取员工异动案例。请确认已获得 lifecycle.case.read 能力。'
      : reason.message || errorCopy(reason)
    return
  }
  errorRequestId.value = null
  errorMessage.value = reason instanceof Error ? reason.message : '员工异动工作台暂时无法加载。'
}

function hydrateFromRoute(): void {
  const tab = typeof route.query.tab === 'string' ? route.query.tab : 'all'
  if (tab === 'all' || MOVEMENT_TYPES.some((type) => type.value === tab)) typeFilter.value = tab as MovementTab
  view.value = route.query.view === 'calendar' ? 'calendar' : 'ledger'
  statusFilter.value = typeof route.query.status === 'string' ? route.query.status : ''
  employeeFilter.value = typeof route.query.employee_id === 'string' ? route.query.employee_id : ''
  search.value = typeof route.query.q === 'string' ? route.query.q : ''
  effectiveFrom.value = typeof route.query.effective_from === 'string' ? route.query.effective_from : ''
  effectiveTo.value = typeof route.query.effective_to === 'string' ? route.query.effective_to : ''
  const rawPage = typeof route.query.page === 'string' ? Number(route.query.page) : 1
  page.value = Number.isSafeInteger(rawPage) && rawPage > 0 ? rawPage : 1
}

function syncRoute(): void {
  const query: Record<string, string> = {}
  if (typeFilter.value !== 'all') query.tab = typeFilter.value
  if (view.value === 'calendar') query.view = 'calendar'
  if (statusFilter.value !== '') query.status = statusFilter.value
  if (employeeFilter.value !== '') query.employee_id = employeeFilter.value
  if (search.value.trim() !== '') query.q = search.value.trim()
  if (effectiveFrom.value !== '') query.effective_from = effectiveFrom.value
  if (effectiveTo.value !== '') query.effective_to = effectiveTo.value
  if (page.value > 1) query.page = String(page.value)
  void router.replace({ path: '/hr/movements', query })
}

async function loadLookups(): Promise<void> {
  employeeLookupError.value = ''
  const [employeeResult, orgResult, userResult] = await Promise.allSettled([
    canReadPeople.value ? listEmployees() : Promise.resolve([]),
    canReadOrg.value ? listOrgUnits() : Promise.resolve([]),
    canReadUsers.value ? listUsers() : Promise.resolve([]),
  ])

  if (employeeResult.status === 'fulfilled') employees.value = employeeResult.value
  else {
    employees.value = []
    employeeLookupError.value = '员工姓名暂不可用'
  }
  orgUnits.value = orgResult.status === 'fulfilled' ? orgResult.value : []
  users.value = userResult.status === 'fulfilled' ? userResult.value : []
}

async function loadRows(): Promise<void> {
  if (readOnly.value) return
  loading.value = true
  errorMessage.value = ''
  errorRequestId.value = null
  errorCodeValue.value = ''
  partialNote.value = ''

  try {
    const result = await listMovementLedger({
      kind: typeFilter.value === 'all' ? undefined : typeFilter.value,
      status: statusFilter.value,
      employmentId: employeeFilter.value === '' ? null : Number(employeeFilter.value),
      effectiveFrom: effectiveFrom.value,
      effectiveTo: effectiveTo.value,
      q: search.value,
      page: page.value,
      pageSize: view.value === 'calendar' ? 100 : pageSize,
      asOf: todayBusinessDate(),
    })
    rows.value = result.items
    total.value = result.total
    const reasons: string[] = []
    if (result.partial && result.partialReason !== null) reasons.push(result.partialReason)
    if (result.items.some((row) => row.partial)) reasons.push('部分案例的工作流投影暂不可用')
    partialNote.value = reasons.join('；')
    if (page.value > pageCount.value) {
      page.value = pageCount.value
      syncRoute()
      await loadRows()
    }
  } catch (reason) {
    captureError(reason)
  } finally {
    loading.value = false
    loaded.value = true
  }
}

async function load(): Promise<void> {
  if (readOnly.value) {
    loaded.value = true
    return
  }
  loading.value = true
  loaded.value = false
  await loadLookups()
  await loadRows()
}

function resetPageAndLoad(): void {
  page.value = 1
  syncRoute()
  void loadRows()
}

function setTypeFilter(kind: MovementTab): void {
  typeFilter.value = kind
  resetPageAndLoad()
}

function setView(next: MovementView): void {
  if (view.value === next) return
  view.value = next
  page.value = 1
  syncRoute()
  void loadRows()
}

function nextPage(): void {
  if (page.value >= pageCount.value) return
  page.value += 1
  syncRoute()
  void loadRows()
}

function previousPage(): void {
  if (page.value <= 1) return
  page.value -= 1
  syncRoute()
  void loadRows()
}

function onDrawerCreated(): void {
  drawerOpen.value = false
  resetPageAndLoad()
}

function openCase(row: MovementListRow): void {
  if (row.secondmentAssignmentId !== null) {
    void router.push({ path: `/hr/movements/${row.secondmentAssignmentId}`, query: { type: 'secondment' } })
    return
  }
  if (row.caseId === null) return
  void router.push(`/hr/movements/${row.caseId}`)
}

onMounted(() => {
  hydrateFromRoute()
  void load()
})
</script>

<template>
  <div class="movement-workspace" data-page="movement-workspace">
    <header class="movement-workspace__header">
      <div>
        <p class="movement-workspace__breadcrumb">员工与组织 · 员工异动</p>
        <h1 class="movement-workspace__title">员工异动工作台</h1>
        <p class="movement-workspace__description">
          统一查看入职、转正、调岗、调薪、借调与离职案例。异动是生效日导向的审批流程；批准不等于立即变更。
        </p>
      </div>
      <div class="movement-workspace__header-actions">
        <button type="button" class="movement-workspace__secondary" :disabled="loading" @click="load">刷新</button>
        <button
          v-if="canCreate"
          type="button"
          class="movement-workspace__primary"
          data-testid="open-movement-drawer"
          @click="drawerOpen = true"
        >
          发起异动
        </button>
      </div>
    </header>

    <section v-if="readOnly" class="movement-state" role="alert">
      <h2>无权访问员工异动</h2>
      <p>当前账号缺少 lifecycle.case.read 能力，页面不会发起任何读取请求。</p>
    </section>

    <template v-else>
      <ErrorState
        v-if="errorMessage !== ''"
        title="员工异动工作台暂时无法加载"
        :description="errorMessage"
        :code="errorCodeValue"
        :request-id="errorRequestId"
        @retry="load"
      />
      <LoadingState v-else-if="loading" :rows="6" label="正在加载员工异动" />
      <template v-else>
        <p v-if="partialNote !== ''" class="movement-workspace__warning" role="status" data-testid="movement-partial">
          部分数据：{{ partialNote }}
        </p>
        <p v-if="employeeLookupError !== ''" class="movement-workspace__warning" role="status">
          {{ employeeLookupError }}；表格中保留员工关系编号，不伪造姓名。
        </p>

        <nav class="movement-tabs" aria-label="异动类型">
          <button
            v-for="tab in typeTabs"
            :key="tab.value"
            type="button"
            class="movement-tabs__button"
            :class="{ 'is-active': typeFilter === tab.value }"
            :data-testid="`movement-tab-${tab.value}`"
            @click="setTypeFilter(tab.value)"
          >
            {{ tab.label }}
          </button>
        </nav>

        <div class="movement-view-toggle" role="group" aria-label="台账视图">
          <button
            type="button"
            class="movement-view-toggle__button"
            :class="{ 'is-active': view === 'ledger' }"
            data-testid="movement-view-ledger"
            @click="setView('ledger')"
          >
            台账
          </button>
          <button
            type="button"
            class="movement-view-toggle__button"
            :class="{ 'is-active': view === 'calendar' }"
            data-testid="movement-view-calendar"
            @click="setView('calendar')"
          >
            日历
          </button>
        </div>

        <section class="movement-filters" aria-label="异动筛选">
          <label>
            <span>异动类型</span>
            <el-select v-model="typeFilter" data-testid="movement-type-filter" @change="resetPageAndLoad">
              <el-option value="all" label="全部类型" />
              <el-option
                v-for="type in MOVEMENT_TYPES"
                :key="type.value"
                :value="type.value"
                :label="type.label"
              />
            </el-select>
          </label>
          <label>
            <span>案例状态</span>
            <el-select v-model="statusFilter" data-testid="movement-status-filter" @change="resetPageAndLoad">
              <el-option
                v-for="option in STATUS_OPTIONS"
                :key="option.value"
                :value="option.value"
                :label="option.label"
              />
            </el-select>
          </label>
          <label>
            <span>员工</span>
            <el-select v-model="employeeFilter" data-testid="movement-employee-filter" clearable @change="resetPageAndLoad">
              <el-option value="" label="全部员工" />
              <el-option
                v-for="employee in employees"
                :key="employee.employmentId"
                :value="String(employee.employmentId)"
                :label="`${employee.name}（${employee.employeeNo}）`"
              />
            </el-select>
          </label>
          <label>
            <span>生效日期从</span>
            <el-input v-model="effectiveFrom" type="date" data-testid="movement-effective-from" @change="resetPageAndLoad" />
          </label>
          <label>
            <span>生效日期至</span>
            <el-input v-model="effectiveTo" type="date" data-testid="movement-effective-to" @change="resetPageAndLoad" />
          </label>
          <label class="movement-filters__search">
            <span>搜索</span>
            <el-input
              v-model="search"
              data-testid="movement-search"
              placeholder="案例编号、员工姓名或工号"
              @input="resetPageAndLoad"
            />
          </label>
        </section>

        <EmptyState
          v-if="total === 0"
          title="暂无员工异动"
          description="当前筛选条件下没有异动案例。可以通过发起异动创建真实案例，或清除筛选条件。"
        />

        <section v-else-if="view === 'ledger'" class="movement-panel">
          <div class="movement-panel__meta">
            <span>共 {{ total }} 条</span>
            <span>第 {{ page }} / {{ pageCount }} 页</span>
          </div>
          <div class="movement-table-scroll">
            <el-table
              :data="rows"
              row-key="rowId"
              class="movement-table"
              data-testid="movement-table"
              @row-click="openCase"
            >
              <el-table-column label="异动类型" min-width="110">
                <template #default="{ row }">{{ movementKindLabel(row.kind) }}</template>
              </el-table-column>
              <el-table-column label="案例" min-width="180">
                <template #default="{ row }">
                  <span class="movement-table__case-no">{{ row.caseNo || row.title }}</span>
                  <small v-if="row.secondmentAssignmentId !== null" class="movement-table__muted">
                    并行任职（主任职历史保持不变）
                  </small>
                  <small v-else-if="row.title !== ''" class="movement-table__muted">{{ row.title }}</small>
                </template>
              </el-table-column>
              <el-table-column label="员工" min-width="140">
                <template #default="{ row }">
                  <span>{{ employeeName(row) }}</span>
                  <small v-if="employeeNo(row) !== ''" class="movement-table__muted">{{ employeeNo(row) }}</small>
                </template>
              </el-table-column>
              <el-table-column label="生效日期" min-width="120">
                <template #default="{ row }">
                  <strong class="movement-table__effective">{{ formatBusinessDate(row.effectiveDate) }}</strong>
                </template>
              </el-table-column>
              <el-table-column label="发起人" min-width="110">
                <template #default="{ row }">{{ initiatorName(row) }}</template>
              </el-table-column>
              <el-table-column label="申请状态" min-width="150">
                <template #default="{ row }">
                  <span class="movement-chip" :class="statusChipClass(row)">{{ statusLabel(row.applicationStatus) }}</span>
                  <span v-if="row.isAwaitingMe" class="movement-chip is-awaiting">待我审批</span>
                </template>
              </el-table-column>
              <el-table-column label="生效状态" min-width="110">
                <template #default="{ row }">
                  <span>{{ effectStatusLabel(row.effectStatus) }}</span>
                  <small class="movement-table__muted" :class="laneClass(row)">{{ laneLabel(row) }}</small>
                </template>
              </el-table-column>
              <el-table-column label="操作" min-width="130">
                <template #default="{ row }">
                  <button
                    v-if="row.caseId !== null || row.secondmentAssignmentId !== null"
                    type="button"
                    class="movement-table__link"
                    :data-testid="`movement-open-${row.rowId}`"
                    @click.stop="openCase(row)"
                  >
                    {{ row.caseId === null ? '查看借调任职' : '查看详情' }}
                  </button>
                </template>
              </el-table-column>
            </el-table>
          </div>
          <footer class="movement-pagination">
            <button type="button" :disabled="page <= 1" data-testid="movement-prev-page" @click="previousPage">上一页</button>
            <button type="button" :disabled="page >= pageCount" data-testid="movement-next-page" @click="nextPage">下一页</button>
          </footer>
        </section>

        <section v-else class="movement-calendar" data-testid="movement-calendar">
          <article v-for="group in calendarGroups" :key="group.month" class="movement-calendar__month">
            <header>
              <h2>{{ group.label }}</h2>
              <span>{{ group.items.length }} 条</span>
            </header>
            <ul>
              <li
                v-for="row in group.items"
                :key="row.rowId"
                class="movement-calendar__item"
                :class="laneClass(row)"
                :data-testid="`movement-calendar-${row.rowId}`"
                @click="openCase(row)"
              >
                <time :datetime="row.effectiveDate ?? undefined">{{ formatBusinessDate(row.effectiveDate) }}</time>
                <strong>{{ movementKindLabel(row.kind) }} · {{ employeeName(row) }}</strong>
                <span class="movement-calendar__lane">{{ laneLabel(row) }} · {{ statusLabel(row.applicationStatus) }}</span>
              </li>
            </ul>
          </article>
        </section>
      </template>
    </template>

    <MovementFormDrawer
      v-if="canCreate"
      v-model="drawerOpen"
      :allowed-kinds="createKinds"
      :employees="employees"
      :org-units="orgUnits"
      :can-read-users="canReadUsers"
      @created="onDrawerCreated"
    />
  </div>
</template>

<style scoped>
.movement-workspace {
  display: grid;
  gap: 16px;
  padding: 20px;
  color: #0f172a;
}

.movement-workspace__header {
  display: flex;
  justify-content: space-between;
  gap: 16px;
  align-items: flex-start;
}

.movement-workspace__breadcrumb {
  margin: 0 0 4px;
  color: #64748b;
  font-size: 12px;
}

.movement-workspace__title {
  margin: 0;
  font-size: 22px;
}

.movement-workspace__description {
  max-width: 720px;
  margin: 6px 0 0;
  color: #475569;
  font-size: 12px;
  line-height: 1.6;
}

.movement-workspace__header-actions {
  display: flex;
  gap: 8px;
}

.movement-workspace__primary,
.movement-workspace__secondary,
.movement-pagination button,
.movement-table__link {
  padding: 7px 12px;
  font-size: 12px;
  border: 1px solid transparent;
  border-radius: 6px;
  cursor: pointer;
}

.movement-workspace__primary {
  color: #fff;
  background: #1d4ed8;
}

.movement-workspace__secondary {
  color: #334155;
  background: #fff;
  border-color: #cbd5e1;
}

.movement-workspace__warning {
  margin: 0;
  padding: 10px 12px;
  color: #92400e;
  font-size: 12px;
  line-height: 1.6;
  background: #fffbeb;
  border: 1px solid #fde68a;
  border-radius: 8px;
}

.movement-tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.movement-tabs__button,
.movement-view-toggle__button {
  padding: 6px 12px;
  color: #475569;
  font-size: 12px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 999px;
  cursor: pointer;
}

.movement-tabs__button.is-active {
  color: #fff;
  background: #1e293b;
  border-color: #1e293b;
}

.movement-view-toggle {
  display: inline-flex;
  gap: 4px;
  justify-self: start;
}

.movement-view-toggle__button.is-active {
  color: #fff;
  background: #1d4ed8;
  border-color: #1d4ed8;
}

.movement-filters {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  padding: 12px;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
}

.movement-filters label {
  display: grid;
  gap: 4px;
  color: #475569;
  font-size: 11px;
}

.movement-filters select,
.movement-filters input {
  min-width: 160px;
  padding: 6px 8px;
  font: inherit;
  font-size: 12px;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
}

.movement-filters__search input {
  min-width: 240px;
}

.movement-panel,
.movement-calendar__month {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  overflow: hidden;
}

.movement-panel__meta {
  display: flex;
  justify-content: space-between;
  padding: 10px 14px;
  color: #64748b;
  font-size: 11px;
  border-bottom: 1px solid #f1f5f9;
}

.movement-table-scroll {
  overflow-x: auto;
}

.movement-table {
  width: 100%;
  font-size: 12px;
  border-collapse: collapse;
}

.movement-table th,
.movement-table td {
  padding: 10px 12px;
  text-align: left;
  border-bottom: 1px solid #f1f5f9;
  vertical-align: top;
}

.movement-table th {
  color: #64748b;
  font-weight: 500;
  background: #f8fafc;
}

.movement-table tbody tr {
  cursor: pointer;
}

.movement-table__case-no {
  display: block;
  font-family: ui-monospace, monospace;
}

.movement-table__muted {
  display: block;
  margin-top: 2px;
  color: #64748b;
}

.movement-table__muted.is-effective {
  color: #047857;
}

.movement-table__muted.is-scheduled {
  color: #b45309;
}

.movement-table__muted.is-pending {
  color: #1d4ed8;
}

.movement-table__effective {
  color: #0f172a;
}

.movement-table__link {
  padding: 4px 8px;
  color: #1d4ed8;
  background: #eff6ff;
  border-color: #bfdbfe;
}

.movement-pagination {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  padding: 10px 14px;
}

.movement-pagination button {
  color: #334155;
  background: #fff;
  border-color: #cbd5e1;
}

.movement-pagination button:disabled {
  cursor: not-allowed;
  opacity: 0.5;
}

.movement-chip {
  display: inline-flex;
  margin-right: 4px;
  padding: 2px 8px;
  font-size: 11px;
  border-radius: 999px;
}

.movement-chip.is-open {
  color: #1e40af;
  background: #dbeafe;
}

.movement-chip.is-awaiting {
  color: #92400e;
  background: #fef3c7;
}

.movement-chip.is-closed {
  color: #475569;
  background: #f1f5f9;
}

.movement-calendar {
  display: grid;
  gap: 12px;
}

.movement-calendar__month > header {
  display: flex;
  justify-content: space-between;
  padding: 10px 14px;
  color: #475569;
  font-size: 12px;
  background: #f8fafc;
  border-bottom: 1px solid #e2e8f0;
}

.movement-calendar__month h2 {
  margin: 0;
  color: #0f172a;
  font-size: 14px;
}

.movement-calendar__month ul {
  display: grid;
  gap: 1px;
  margin: 0;
  padding: 0;
  list-style: none;
  background: #f1f5f9;
}

.movement-calendar__item {
  display: grid;
  gap: 2px;
  padding: 10px 14px;
  background: #fff;
  cursor: pointer;
}

.movement-calendar__item time {
  color: #64748b;
  font-size: 11px;
}

.movement-calendar__item strong {
  font-size: 12px;
}

.movement-calendar__lane {
  font-size: 11px;
}

.movement-calendar__item.is-effective .movement-calendar__lane {
  color: #047857;
}

.movement-calendar__item.is-scheduled .movement-calendar__lane {
  color: #b45309;
}

.movement-calendar__item.is-pending .movement-calendar__lane {
  color: #1d4ed8;
}

.movement-state {
  padding: 24px;
  text-align: center;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
}

.movement-state h2 {
  margin: 0 0 6px;
  font-size: 16px;
}

.movement-state p {
  margin: 0;
  color: #475569;
  font-size: 12px;
}
</style>
