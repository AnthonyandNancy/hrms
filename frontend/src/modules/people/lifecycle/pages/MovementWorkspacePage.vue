<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useSessionStore } from '@/stores/session'
import {
  ApiError,
  errorCode,
  errorCopy,
} from '@/api/errors'
import { formatBusinessDate } from '@/utils/format'
import LoadingState from '@/components/LoadingState.vue'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import {
  APPLICATION_STATUS_LABELS,
  MOVEMENT_TYPES,
  allowedMovementKinds,
  effectStatusLabel,
  listEmployees,
  listLifecycleMovementCases,
  listMovementCases,
  listOrgUnits,
  listSecondments,
  listUsers,
  movementKindLabel,
  secondmentToRow,
  statusLabel,
  type EmployeeOption,
  type MovementKind,
  type MovementListRow,
  type OrgUnitOption,
  type UserOption,
} from '../api'
import MovementFormDrawer from '../components/MovementFormDrawer.vue'

defineOptions({ name: 'MovementWorkspacePage' })

type MovementTab = 'all' | MovementKind

const router = useRouter()
const session = useSessionStore()

const canReadLifecycle = computed(() => session.can('lifecycle.case.read'))
const canReadWorkflow = computed(() => session.can('workflow.case.read'))
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
const employees = ref<EmployeeOption[]>([])
const orgUnits = ref<OrgUnitOption[]>([])
const users = ref<UserOption[]>([])
const employeeLookupError = ref('')

const typeFilter = ref<MovementTab>('all')
const statusFilter = ref('')
const search = ref('')
const page = ref(1)
const pageSize = 10

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
  { value: 'ACTIVE', label: '借调中' },
  { value: 'COMPLETED', label: '已结束' },
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

function employeeName(row: MovementListRow): string {
  if (row.employeeName !== '') return row.employeeName
  if (row.employeeId === null) return '—'
  const employee = employeeById.value.get(row.employeeId)
  return employee?.name ?? `员工关系 #${row.employeeId}`
}

function employeeNo(row: MovementListRow): string {
  if (row.employeeNo !== '') return row.employeeNo
  if (row.employeeId === null) return ''
  return employeeById.value.get(row.employeeId)?.employeeNo ?? ''
}

function initiatorName(row: MovementListRow): string {
  if (row.initiatorUserId === null) return '—'
  const known = userById.value.get(row.initiatorUserId)?.displayName
  if (known !== undefined) return known
  const current = session.principal
  if (current !== null && current.userId === row.initiatorUserId) return current.displayName
  return `用户 #${row.initiatorUserId}`
}

function statusChipClass(row: MovementListRow): string {
  if (!isOpenStatus(row.applicationStatus)) return 'is-closed'
  return row.isAwaitingMe ? 'is-awaiting' : 'is-open'
}

function isOpenStatus(value: string): boolean {
  return value === 'DRAFT' || value === 'SUBMITTED' || value === 'IN_REVIEW' || value === 'SCHEDULED' || value === 'ACTIVE'
}

const typeTabs: Array<{ value: MovementTab; label: string }> = [
  { value: 'all', label: '全部异动' },
  ...MOVEMENT_TYPES.map((type) => ({ value: type.value as MovementTab, label: type.label })),
]

const filteredRows = computed(() => {
  const term = search.value.trim().toLowerCase()
  return rows.value.filter((row) => {
    if (typeFilter.value !== 'all' && row.kind !== typeFilter.value) return false
    if (statusFilter.value !== '' && row.applicationStatus !== statusFilter.value) return false
    if (term === '') return true
    return [row.caseNo, row.title, employeeName(row), employeeNo(row), initiatorName(row)]
      .join(' ')
      .toLowerCase()
      .includes(term)
  })
})

const pageCount = computed(() => Math.max(1, Math.ceil(filteredRows.value.length / pageSize)))
const pagedRows = computed(() => {
  const start = (page.value - 1) * pageSize
  return filteredRows.value.slice(start, start + pageSize)
})

function resetPage(): void {
  page.value = 1
}

function nextPage(): void {
  if (page.value < pageCount.value) page.value += 1
}

function previousPage(): void {
  if (page.value > 1) page.value -= 1
}

function setTypeFilter(kind: MovementTab): void {
  typeFilter.value = kind
  resetPage()
}

function captureError(reason: unknown): void {
  errorCodeValue.value = errorCode(reason)
  if (reason instanceof ApiError) {
    errorRequestId.value = reason.requestId
    errorMessage.value = reason.code === 'FORBIDDEN'
      ? '无权读取员工异动案例。请确认已获得 lifecycle.case.read 能力。'
      : reason.message || errorCopy(reason)
  } else {
    errorRequestId.value = null
    errorMessage.value = reason instanceof Error ? reason.message : '员工异动工作台暂时无法加载。'
  }
}

function mergeRows(primary: MovementListRow[], secondments: MovementListRow[]): MovementListRow[] {
  const byId = new Map<string, MovementListRow>()
  for (const row of primary) byId.set(row.rowId, row)
  for (const row of secondments) byId.set(row.rowId, row)
  return [...byId.values()].sort((left, right) => {
    const leftDate = left.effectiveDate ?? ''
    const rightDate = right.effectiveDate ?? ''
    return rightDate.localeCompare(leftDate) || left.rowId.localeCompare(right.rowId)
  })
}

async function loadLookups(): Promise<void> {
  employeeLookupError.value = ''
  const employeePromise = canReadPeople.value ? listEmployees() : Promise.resolve([])
  const orgPromise = canReadOrg.value ? listOrgUnits() : Promise.resolve([])
  const userPromise = canReadUsers.value ? listUsers() : Promise.resolve([])

  const [employeeResult, orgResult, userResult] = await Promise.allSettled([
    employeePromise,
    orgPromise,
    userPromise,
  ])

  if (employeeResult.status === 'fulfilled') employees.value = employeeResult.value
  else {
    employees.value = []
    employeeLookupError.value = '员工姓名暂不可用'
  }
  if (orgResult.status === 'fulfilled') orgUnits.value = orgResult.value
  else orgUnits.value = []
  if (userResult.status === 'fulfilled') users.value = userResult.value
  else users.value = []
}

async function loadRows(): Promise<void> {
  const partialReasons: string[] = []

  let movementResult: Awaited<ReturnType<typeof listMovementCases>> | null = null
  const secondmentPromise =
    session.can('lifecycle.secondment.manage')
      ? listSecondments().catch((reason: unknown) => {
          partialReasons.push(
            reason instanceof ApiError && reason.code === 'FORBIDDEN'
              ? '借调任职暂不可读（缺少 lifecycle.secondment.manage）'
              : '借调任职列表暂不可用',
          )
          return null
        })
      : Promise.resolve(null)

  try {
    if (canReadWorkflow.value) {
      movementResult = await listMovementCases()
    } else {
      movementResult = await listLifecycleMovementCases()
      partialReasons.push('当前能力只能读取生命周期投影：姓名、申请人和生效日期部分为空白')
    }
  } catch (reason) {
    if (!canReadWorkflow.value) {
      captureError(reason)
      return
    }
    // The workflow read is the primary seam; without it there is no honest
    // list to show. A lifecycle fallback is only used when it was intended.
    captureError(reason)
    return
  }

  if (movementResult !== null) {
    rows.value = movementResult.items
    if (movementResult.partial && movementResult.partialReason !== null) {
      partialReasons.push(movementResult.partialReason)
    }
  }

  const secondments = await secondmentPromise
  if (secondments !== null) {
    if (secondments.partial && secondments.partialReason !== null) {
      partialReasons.push(secondments.partialReason)
    }
    rows.value = mergeRows(rows.value, secondments.items.map((item) => secondmentToRow(item)))
  }

  partialNote.value = partialReasons.join('；')
}

async function load(): Promise<void> {
  if (readOnly.value) {
    loaded.value = true
    return
  }
  loading.value = true
  loaded.value = false
  errorMessage.value = ''
  errorRequestId.value = null
  errorCodeValue.value = ''
  partialNote.value = ''

  await loadLookups()
  await loadRows()

  loading.value = false
  loaded.value = true
}

function onDrawerCreated(): void {
  drawerOpen.value = false
  resetPage()
  void loadRows()
}

function openCase(row: MovementListRow): void {
  if (row.secondmentAssignmentId !== null) {
    void router.push({ path: `/hr/movements/${row.secondmentAssignmentId}`, query: { type: 'secondment' } })
    return
  }
  if (row.caseId === null) return
  void router.push(`/hr/movements/${row.caseId}`)
}

function openDrawer(): void {
  if (!canCreate.value) return
  drawerOpen.value = true
}

onMounted(() => {
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
        <button type="button" class="movement-workspace__secondary" :disabled="loading" @click="load">
          刷新
        </button>
        <button
          v-if="canCreate"
          type="button"
          class="movement-workspace__primary"
          data-testid="open-movement-drawer"
          @click="openDrawer"
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

        <section class="movement-filters" aria-label="异动筛选">
          <label>
            <span>异动类型</span>
            <select v-model="typeFilter" data-testid="movement-type-filter" @change="resetPage">
              <option value="all">全部类型</option>
              <option v-for="type in MOVEMENT_TYPES" :key="type.value" :value="type.value">
                {{ type.label }}
              </option>
            </select>
          </label>
          <label>
            <span>案例状态</span>
            <select v-model="statusFilter" data-testid="movement-status-filter" @change="resetPage">
              <option v-for="option in STATUS_OPTIONS" :key="option.value" :value="option.value">
                {{ option.label }}
              </option>
            </select>
          </label>
          <label class="movement-filters__search">
            <span>搜索</span>
            <input
              v-model="search"
              data-testid="movement-search"
              placeholder="案例编号、员工姓名或工号"
              @input="resetPage"
            />
          </label>
        </section>

        <EmptyState
          v-if="filteredRows.length === 0"
          title="暂无员工异动"
          description="当前筛选条件下没有异动案例。可以通过发起异动创建真实案例，或清除筛选条件。"
        />

        <section v-else class="movement-panel">
          <div class="movement-panel__meta">
            <span>共 {{ filteredRows.length }} 条</span>
            <span>第 {{ page }} / {{ pageCount }} 页</span>
          </div>
          <div class="movement-table-scroll">
            <table class="movement-table" data-testid="movement-table">
              <caption class="visually-hidden">员工异动案例列表</caption>
              <thead>
                <tr>
                  <th scope="col">异动类型</th>
                  <th scope="col">案例</th>
                  <th scope="col">员工</th>
                  <th scope="col">生效日期</th>
                  <th scope="col">发起人</th>
                  <th scope="col">申请状态</th>
                  <th scope="col">生效状态</th>
                  <th scope="col">操作</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="row in pagedRows"
                  :key="row.rowId"
                  :data-testid="`movement-row-${row.rowId}`"
                  @click="openCase(row)"
                >
                  <td>{{ movementKindLabel(row.kind) }}</td>
                  <td>
                    <span class="movement-table__case-no">{{ row.caseNo || row.title }}</span>
                    <small v-if="row.secondmentAssignmentId !== null" class="movement-table__muted">
                      并行任职（主任职历史保持不变）
                    </small>
                    <small v-else-if="row.title !== ''" class="movement-table__muted">{{ row.title }}</small>
                  </td>
                  <td>
                    <span>{{ employeeName(row) }}</span>
                    <small v-if="employeeNo(row) !== ''" class="movement-table__muted">{{ employeeNo(row) }}</small>
                  </td>
                  <td>
                    <strong class="movement-table__effective">{{ formatBusinessDate(row.effectiveDate) }}</strong>
                  </td>
                  <td>{{ initiatorName(row) }}</td>
                  <td>
                    <span class="movement-chip" :class="statusChipClass(row)">{{ statusLabel(row.applicationStatus) }}</span>
                    <span v-if="row.isAwaitingMe" class="movement-chip is-awaiting">待我审批</span>
                  </td>
                  <td>{{ effectStatusLabel(row.effectStatus) }}</td>
                  <td>
                    <button
                      v-if="row.caseId !== null"
                      type="button"
                      class="movement-table__link"
                      :data-testid="`movement-open-${row.rowId}`"
                      @click.stop="openCase(row)"
                    >
                      查看详情
                    </button>
                    <button
                      v-else-if="row.secondmentAssignmentId !== null"
                      type="button"
                      class="movement-table__link"
                      :data-testid="`movement-open-${row.rowId}`"
                      @click.stop="openCase(row)"
                    >
                      查看借调任职
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <footer class="movement-pagination">
            <button
              type="button"
              :disabled="page <= 1"
              data-testid="movement-prev-page"
              @click="previousPage"
            >
              上一页
            </button>
            <button
              type="button"
              :disabled="page >= pageCount"
              data-testid="movement-next-page"
              @click="nextPage"
            >
              下一页
            </button>
          </footer>
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

.movement-tabs__button {
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

.movement-panel {
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
  color: #94a3b8;
  font-size: 10.5px;
}

.movement-table__effective {
  color: #1d4ed8;
}

.movement-table__link {
  padding: 4px 8px;
  color: #1d4ed8;
  background: transparent;
  border-color: #bfdbfe;
}

.movement-chip {
  display: inline-block;
  padding: 2px 8px;
  font-size: 10.5px;
  border-radius: 999px;
}

.movement-chip.is-open {
  color: #92400e;
  background: #fef3c7;
}

.movement-chip.is-awaiting {
  color: #1d4ed8;
  background: #dbeafe;
}

.movement-chip.is-closed {
  color: #475569;
  background: #e2e8f0;
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
  color: #94a3b8;
  cursor: not-allowed;
}

.movement-state {
  padding: 24px;
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
