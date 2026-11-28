<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessageBox } from 'element-plus'
import PageShell from '@/shared/ui/PageShell.vue'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import ActionGate from '@/components/ActionGate.vue'
import { ApiError, errorCopy } from '@/api/errors'
import { useSessionStore } from '@/stores/session'
import { requestIdFromError } from '@/utils/renderErrors'
import { todayBusinessDate } from '@/utils/format'
import {
  archiveEmployee,
  listEmployees,
  listOrgUnits,
  listPositions,
  restoreEmployee,
  type EmployeeListResult,
  type EmployeeRow,
  type OrgUnitOption,
  type PendingOnboardingCase,
  type PositionOption,
} from '../api'
import EmployeeFilter, { type EmployeeFilterValue } from '../components/EmployeeFilter.vue'
import EmployeeTable from '../components/EmployeeTable.vue'
import EmployeeCreateDrawer from '../components/EmployeeCreateDrawer.vue'
import EmployeeImportDrawer from '../components/EmployeeImportDrawer.vue'
import { exportEmployees, saveEmployeeExport } from '../listActionsApi'

type LoadState = 'idle' | 'loading' | 'ready' | 'error' | 'forbidden'

const router = useRouter()
const session = useSessionStore()

const asOf = ref(todayBusinessDate())
const q = ref('')
const orgUnitId = ref<number | undefined>(undefined)
const includeArchived = ref(false)
const page = ref(1)
const pageSize = ref(20)

const state = ref<LoadState>('idle')
const result = ref<EmployeeListResult>({
  items: [],
  page: 1,
  page_size: 20,
  total: 0,
  partial: false,
  partial_reason: null,
})
const pageError = ref<unknown>(null)
const forbiddenRequestId = ref<string | null>(null)
const createOpen = ref(false)
const importsOpen = ref(false)
const exporting = ref(false)
const orgUnits = ref<OrgUnitOption[]>([])
const positions = ref<PositionOption[]>([])
const successMessage = ref('')
const pendingCase = ref<PendingOnboardingCase | null>(null)

let requestSequence = 0

const loading = computed(() => state.value === 'loading')
const forbidden = computed(() => state.value === 'forbidden')
const isEmpty = computed(() => state.value === 'ready' && result.value.items.length === 0)
const isPartial = computed(() => state.value === 'ready' && result.value.partial)
const hasRows = computed(() => result.value.items.length > 0)

const orgUnitNames = computed(() => new Map(orgUnits.value.map((unit) => [unit.id, unit.name])))
const positionNames = computed(() => new Map(positions.value.map((position) => [position.id, position.title])))

/**
 * The employee projection carries assignment ids, not names. Resolve them
 * against the same as-of department/position reads used by the create drawer;
 * a failed lookup must not turn a real row into a blank one, so fall back to a
 * clearly labelled id rather than inventing a name.
 */
const tableRows = computed(() =>
  result.value.items.map((row) => ({
    ...row,
    department_name:
      row.department_name ??
      (row.org_unit_id === null ? null : orgUnitNames.value.get(row.org_unit_id) ?? `组织 #${row.org_unit_id}`),
    position_name:
      row.position_name ??
      (row.position_id === null ? null : positionNames.value.get(row.position_id) ?? `岗位 #${row.position_id}`),
  })),
)
const errorMessage = computed(() => (pageError.value === null ? '' : errorCopy(pageError.value)))
const errorRequestId = computed(() => (pageError.value === null ? null : requestIdFromError(pageError.value)))
const canManage = computed(() => session.can('people.employee.manage') && session.can('lifecycle.onboarding.manage'))
const canReadLifecycleCase = computed(() => session.can('lifecycle.case.read'))
const canExport = computed(() => session.can('people.employee.export'))
const canArchive = computed(() => session.can('people.employee.archive'))
const canRestore = computed(() => session.can('people.employee.restore'))

async function load(): Promise<void> {
  const sequence = ++requestSequence
  state.value = 'loading'
  pageError.value = null
  forbiddenRequestId.value = null
  try {
    const next = await listEmployees({
      as_of: asOf.value,
      org_unit_id: orgUnitId.value,
      q: q.value,
      page: page.value,
      page_size: pageSize.value,
      include_archived: includeArchived.value,
    })
    if (sequence !== requestSequence) return
    result.value = next
    state.value = 'ready'
  } catch (error) {
    if (sequence !== requestSequence) return
    result.value = { ...result.value, items: [], total: 0, partial: false, partial_reason: null }
    if (error instanceof ApiError && (error.status === 403 || error.code === 'FORBIDDEN')) {
      forbiddenRequestId.value = requestIdFromError(error)
      pageError.value = null
      state.value = 'forbidden'
      return
    }
    pageError.value = error
    state.value = 'error'
  }
}

async function loadLookups(): Promise<void> {
  const [unitResult, positionResult] = await Promise.allSettled([
    listOrgUnits(asOf.value),
    listPositions(asOf.value),
  ])
  orgUnits.value = unitResult.status === 'fulfilled' ? unitResult.value : []
  positions.value = positionResult.status === 'fulfilled' ? positionResult.value : []
}

/**
 * A filter change always resets to page 1. Pagination keeps the filters and
 * only moves the page, matching the server-side contract.
 */
function onFilterChange(value: EmployeeFilterValue): void {
  asOf.value = value.as_of
  q.value = value.q
  orgUnitId.value = value.org_unit_id
  includeArchived.value = value.include_archived
  page.value = 1
  void load()
  void loadLookups()
}

function onPageChange(next: number): void {
  if (next === page.value) return
  page.value = next
  void load()
}

function onPageSizeChange(next: number): void {
  pageSize.value = next
  page.value = 1
  void load()
}

function view(row: EmployeeRow): void {
  void router.push(`/hr/employees/${row.employment_id}`)
}

function openCreate(): void {
  createOpen.value = true
}

function onCreated(created: PendingOnboardingCase): void {
  pendingCase.value = created
  successMessage.value = created.replayed
    ? `入职审批 Case ${created.case_no} 已存在（幂等重放，未重复提交）。`
    : '已提交入职审批。'
}

async function onArchive(row: EmployeeRow): Promise<void> {
  if (!canArchive.value) return
  let reason: string
  try {
    const result = await ElMessageBox.prompt(
      `确认归档员工 ${row.person_name}（${row.employee_no}）？归档只逻辑停用员工，历史、合同与审批记录都会保留。请输入归档原因（至少 3 个字符）。`,
      '归档员工',
      {
        confirmButtonText: '确认归档',
        cancelButtonText: '取消',
        inputType: 'textarea',
        inputPlaceholder: '例如：员工离职，档案归档',
        inputValidator: (value: string) => (value.trim().length >= 3 ? true : '归档原因至少需要 3 个字符'),
        type: 'warning',
      },
    )
    reason = String(result.value ?? '').trim()
  } catch {
    return
  }
  if (reason.length < 3) return
  try {
    await archiveEmployee(row.employment_id, reason)
    pendingCase.value = null
    successMessage.value = `员工 ${row.employee_no} 已归档。`
    page.value = 1
    await load()
  } catch (error) {
    pageError.value = error
    state.value = 'error'
  }
}

async function onRestore(row: EmployeeRow): Promise<void> {
  if (!canRestore.value) return
  try {
    await ElMessageBox.confirm(
      `确认恢复员工 ${row.person_name}（${row.employee_no}）？恢复后该员工会重新出现在默认列表中。`,
      '恢复员工',
      { confirmButtonText: '确认恢复', cancelButtonText: '取消', type: 'warning' },
    )
  } catch {
    return
  }
  try {
    await restoreEmployee(row.employment_id)
    pendingCase.value = null
    successMessage.value = `员工 ${row.employee_no} 已恢复。`
    page.value = 1
    await load()
  } catch (error) {
    pageError.value = error
    state.value = 'error'
  }
}

function resetFilters(): void {
  q.value = ''
  orgUnitId.value = undefined
  includeArchived.value = false
  page.value = 1
  void load()
}

function openImports(): void {
  importsOpen.value = true
}

/**
 * Export reuses the exact current list filters. The button is capability
 * guarded; the server still re-authorizes and applies its own row cap and
 * fixed non-sensitive column whitelist.
 */
async function onExport(): Promise<void> {
  if (!canExport.value || exporting.value) return
  exporting.value = true
  try {
    const file = await exportEmployees({
      as_of: asOf.value,
      org_unit_id: orgUnitId.value,
      q: q.value,
      include_archived: includeArchived.value,
    })
    saveEmployeeExport(file)
    pendingCase.value = null
    successMessage.value = `员工导出已生成（${file.filename}）。`
  } catch (error) {
    ElMessageBox.alert(errorCopy(error), '导出未完成', { confirmButtonText: '知道了' }).catch(() => undefined)
  } finally {
    exporting.value = false
  }
}

watch(
  () => asOf.value,
  () => {
    void loadLookups()
  },
)

onMounted(() => {
  void load()
  void loadLookups()
})

onBeforeUnmount(() => {
  requestSequence += 1
})
</script>

<template>
  <PageShell title="员工列表" description="按业务日期、部门与关键词查询员工主数据；归档记录可显式纳入。">
    <template #actions>
      <el-button :disabled="loading" @click="load">刷新</el-button>
      <ActionGate capability="people.employee.manage">
        <el-button data-testid="employee-imports" @click="openImports">导入结果</el-button>
        <el-button v-if="canManage" type="primary" data-testid="employee-create" @click="openCreate">新建员工</el-button>
      </ActionGate>
      <ActionGate capability="people.employee.export">
        <el-button
          data-testid="employee-export"
          :loading="exporting"
          :disabled="loading || exporting"
          @click="onExport"
        >
          导出
        </el-button>
      </ActionGate>
    </template>

    <EmployeeFilter
      :as-of="asOf"
      :q="q"
      :org-unit-id="orgUnitId"
      :include-archived="includeArchived"
      :org-units="orgUnits"
      :disabled="loading"
      @change="onFilterChange"
    />

    <el-alert v-if="successMessage" type="success" :closable="true" show-icon data-testid="employee-created-message" @close="successMessage = ''; pendingCase = null">
      <template #title>
        {{ successMessage }}
        <RouterLink
          v-if="pendingCase && canReadLifecycleCase"
          :to="`/hr/movements/${pendingCase.case_id}`"
          data-testid="onboarding-case-link"
        >
          查看 Case {{ pendingCase.case_no }}
        </RouterLink>
      </template>
    </el-alert>

    <el-alert v-if="isPartial" type="warning" :closable="false" show-icon data-testid="employee-partial">
      <template #title>
        部分数据：{{ result.partial_reason || '服务端只返回了部分结果，请缩小筛选范围或稍后重试。' }}
      </template>
    </el-alert>

    <div v-if="forbidden" class="state-panel error-state" role="alert" data-testid="employee-forbidden">
      <div class="state-panel__mark" aria-hidden="true">!</div>
      <div class="state-panel__content">
        <h2 class="state-panel__title">你没有查看员工列表的权限</h2>
        <p class="state-panel__body">当前账号未被授予员工档案读取能力。如需访问，请向权限管理员提交带业务目的和到期日的授权申请。</p>
        <p v-if="forbiddenRequestId" class="state-panel__request" data-testid="request-id">
          请求编号：<span class="mono">{{ forbiddenRequestId }}</span>
        </p>
      </div>
      <el-button type="primary" plain @click="router.push('/workspace')">返回我的工作台</el-button>
    </div>

    <ErrorState
      v-else-if="state === 'error'"
      title="员工列表暂时无法加载"
      :description="errorMessage"
      :request-id="errorRequestId"
      @retry="load"
    />

    <template v-else>
      <EmptyState
        v-if="isEmpty"
        title="暂无员工"
        description="当前筛选条件下没有员工记录。可以调整筛选，或直接新建一名员工。"
        :action-label="canManage ? '新建员工' : '清空筛选'"
        @action="canManage ? openCreate() : resetFilters()"
      />

      <EmployeeTable
        v-if="loading || hasRows"
        :rows="tableRows"
        :total="result.total"
        :page="page"
        :page-size="pageSize"
        :loading="loading"
        :archived-visible="includeArchived"
        :can-archive="canArchive"
        :can-restore="canRestore"
        @update:page="onPageChange"
        @update:page-size="onPageSizeChange"
        @view="view"
        @archive="onArchive"
        @restore="onRestore"
      />
    </template>

    <EmployeeCreateDrawer
      v-model="createOpen"
      :org-units="orgUnits"
      :positions="positions"
      @created="onCreated"
    />

    <EmployeeImportDrawer
      v-if="canManage"
      v-model="importsOpen"
    />
  </PageShell>
</template>

<style scoped>
.page-shell__actions {
  display: flex;
  gap: 8px;
}
</style>
