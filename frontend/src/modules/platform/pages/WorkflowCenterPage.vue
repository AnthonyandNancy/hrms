<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { RouterLink } from 'vue-router'
import DataTable, { type DataTableColumn } from '@/components/DataTable.vue'
import PageShell from '@/shared/ui/PageShell.vue'
import { useSessionStore } from '@/stores/session'
import { formatDateTimeInTimezone } from '@/utils/format'
import {
  isForbidden,
  listMyWorkflowTasks,
  listWorkflowCases,
  platformErrorCopy,
  type WorkflowCaseStatus,
  type WorkflowCaseSummary,
  type WorkflowTask,
} from '../api'
import {
  caseTypeLabel,
  effectStatusLabel,
  slaStateLabel,
  slaStateTone,
  workflowStatusLabel,
  workflowStatusTone,
} from '../labels'

type ViewKey = 'my_approvals' | 'my_requests' | 'all'


const session = useSessionStore()
const canRead = computed(() => session.can('workflow.case.read'))

const loading = ref(true)
const loadError = ref<unknown>(null)
const cases = ref<WorkflowCaseSummary[]>([])
const tasks = ref<WorkflowTask[]>([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(20)
const partial = ref(false)
const partialReason = ref('')

const filters = reactive<{ view: ViewKey; status: WorkflowCaseStatus | ''; type: string }>({
  view: 'all',
  status: '',
  type: '',
})

const STATUS_FILTERS: Array<{ value: WorkflowCaseStatus | ''; label: string }> = [
  { value: '', label: '全部状态' },
  { value: 'DRAFT', label: '草稿' },
  { value: 'SUBMITTED', label: '已提交' },
  { value: 'IN_REVIEW', label: '审批中' },
  { value: 'APPROVED', label: '已通过' },
  { value: 'REJECTED', label: '已驳回' },
  { value: 'WITHDRAWN', label: '已撤回' },
  { value: 'CANCELLED', label: '已取消' },
]

const CASE_TYPES = [
  { value: 'LEAVE_REQUEST', label: '请假申请' },
  { value: 'ATTENDANCE_CORRECTION', label: '考勤更正' },
  { value: 'TRAVEL_REQUEST', label: '出差申请' },
  { value: 'TRANSFER_REQUEST', label: '调动申请' },
  { value: 'CONTRACT_RENEWAL', label: '合同续签' },
  { value: 'NOTICE_APPROVAL', label: '公告审批' },
  { value: 'PAYROLL_APPROVAL', label: '薪资审批' },
  { value: 'ONBOARDING', label: '入职办理' },
  { value: 'OFFBOARD', label: '离职办理' },
  { value: 'PROBATION_CONFIRM', label: '转正办理' },
  { value: 'TERMS_CHANGE', label: '雇佣条款变更' },
  { value: 'REHIRE', label: '返聘办理' },
]

const columns = computed<DataTableColumn[]>(() => [
  { key: 'caseNo', label: '案例编号', minWidth: '170px' },
  { key: 'type', label: '事项类型', width: '120px' },
  { key: 'title', label: '申请主题', minWidth: '190px' },
  { key: 'requester', label: '发起人', width: '110px' },
  { key: 'status', label: '申请状态', width: '100px' },
  { key: 'effect', label: '生效状态', width: '110px' },
  { key: 'currentNode', label: '当前环节', width: '130px' },
  { key: 'sla', label: '时限', width: '110px' },
  { key: 'dueAt', label: '截止时间', width: '150px' },
  { key: 'actions', label: '操作', width: '90px' },
])

const taskByCaseId = computed(() => {
  const map = new Map<number, WorkflowTask>()
  for (const task of tasks.value) map.set(task.caseId, task)
  return map
})

const rows = computed(() =>
  cases.value.map((item) => {
    const task = taskByCaseId.value.get(item.id)
    return {
      ...item,
      caseNo: item.caseNo,
      type: item.type,
      title: item.title,
      requester: `用户 #${item.requesterUserId}`,
      duty: task ?? null,
    }
  }),
)

const isEmpty = computed(() => !loading.value && loadError.value === null && cases.value.length === 0)

function statusTone(code: string): string {
  return `plain-chip--${workflowStatusTone(code)}`
}

function effectTone(code: string): string {
  if (code === 'APPLIED') return 'plain-chip--success'
  if (code === 'FAILED') return 'plain-chip--danger'
  if (code === 'NONE') return 'plain-chip--muted'
  return 'plain-chip--info'
}

function taskFor(row: Record<string, unknown>): WorkflowTask | null {
  const task = row.duty
  return task !== null && typeof task === 'object' ? (task as WorkflowTask) : null
}

function slaFor(row: Record<string, unknown>): { label: string; tone: string } {
  const task = taskFor(row)
  if (task === null) return { label: '非当前待办', tone: 'plain-chip--muted' }
  return { label: slaStateLabel(task.slaState), tone: `plain-chip--${slaStateTone(task.slaState)}` }
}

function dueFor(row: Record<string, unknown>): string {
  const task = taskFor(row)
  return task?.dueAt ? formatDateTimeInTimezone(task.dueAt) : '—'
}

function currentNode(row: Record<string, unknown>): string {
  const key = typeof row.currentNodeKey === 'string' ? row.currentNodeKey : ''
  if (key === '') return '已结束'
  if (key === 'manager_approval') return '直属经理审批'
  if (key === 'hr_review') return '人事复核'
  if (key === 'start') return '发起环节'
  return '审批处理中'
}

async function loadCases(): Promise<void> {
  loading.value = true
  loadError.value = null
  partial.value = false
  partialReason.value = ''
  try {
    const result = await listWorkflowCases({
      page: page.value,
      pageSize: pageSize.value,
      view: filters.view === 'all' ? undefined : filters.view,
      status: filters.status,
      type: filters.type,
    })
    cases.value = result.items
    total.value = result.total
    // The list projection never claims partial; detail pagination does not add
    // evidence. Keep the five-state flag false unless a task read degrades.
    await loadTasks()
  } catch (error) {
    if (isForbidden(error)) {
      cases.value = []
      total.value = 0
    }
    loadError.value = error
    cases.value = []
    total.value = 0
  } finally {
    loading.value = false
  }
}

async function loadTasks(): Promise<void> {
  try {
    tasks.value = await listMyWorkflowTasks()
  } catch (error) {
    // The case list is still useful when the personal task projection is
    // unavailable; disclose the partial state instead of inventing SLA values.
    tasks.value = []
    partial.value = true
    partialReason.value = isForbidden(error)
      ? '个人待办时限暂不可见，列表中的时限列仅显示已获取的数据。'
      : '个人待办时限加载失败，列表中的时限列可能不完整。'
  }
}

function retry(): void {
  void loadCases()
}

function applyFilters(): void {
  page.value = 1
  void loadCases()
}

function changePage(next: number): void {
  page.value = next
  void loadCases()
}

function changePageSize(next: number): void {
  pageSize.value = next
  page.value = 1
  void loadCases()
}

function changeView(view: ViewKey): void {
  filters.view = view
  applyFilters()
}

function setStatus(status: WorkflowCaseStatus | ''): void {
  filters.status = status
  applyFilters()
}

function setType(type: string): void {
  filters.type = type
  applyFilters()
}

onMounted(() => {
  if (!canRead.value) {
    loading.value = false
    loadError.value = null
    return
  }
  void loadCases()
})
</script>

<template>
  <PageShell
    data-testid="workflow-center-page"
    title="审批流程中心"
    description="按待办、我发起与全部可见范围查看审批案例；时限来自当前审批任务，状态与决策由流程服务端判定。"
  >
    <section v-if="!canRead" class="panel" data-testid="workflow-forbidden">
      <div class="panel__body">
        <h2 class="panel__title">无权查看审批流程</h2>
        <p class="muted">当前账号没有审批流程查看权限。如需处理审批，请联系系统管理员授予流程查看能力。</p>
      </div>
    </section>

    <template v-else>
      <section class="panel" data-testid="workflow-filters">
        <div class="panel__header">
          <div>
            <h2 class="panel__title">案例筛选</h2>
            <p class="panel__subtitle">筛选条件由流程服务端执行；“待我审批”只返回当前指派给我的待办案例。</p>
          </div>
          <el-button plain :loading="loading" @click="retry">刷新</el-button>
        </div>
        <div class="workspace-filters">
          <div class="workspace-filter-tabs" role="group" aria-label="案例范围">
            <button
              v-for="view in ([
                { key: 'my_approvals', label: '待我审批' },
                { key: 'my_requests', label: '我发起的' },
                { key: 'all', label: '全部可见' },
              ] as const)"
              :key="view.key"
              type="button"
              class="workspace-filter-tab"
              :class="{ 'workspace-filter-tab--active': filters.view === view.key }"
              :aria-pressed="filters.view === view.key"
              :data-testid="`workflow-view-${view.key}`"
              @click="changeView(view.key)"
            >
              {{ view.label }}
            </button>
          </div>
          <div class="workspace-filter-group" data-testid="workflow-status-filter" role="group" aria-label="按申请状态筛选">
            <span class="workspace-filter-label">状态</span>
            <button
              v-for="option in STATUS_FILTERS"
              :key="option.value"
              type="button"
              class="workspace-filter-chip"
              :class="{ 'workspace-filter-chip--active': filters.status === option.value }"
              :aria-pressed="filters.status === option.value"
              :data-testid="`workflow-status-${option.value || 'all'}`"
              @click="setStatus(option.value)"
            >
              {{ option.label }}
            </button>
          </div>
          <div class="workspace-filter-group" data-testid="workflow-type-filter" role="group" aria-label="按事项类型筛选">
            <span class="workspace-filter-label">类型</span>
            <button
              type="button"
              class="workspace-filter-chip"
              :class="{ 'workspace-filter-chip--active': filters.type === '' }"
              :aria-pressed="filters.type === ''"
              data-testid="workflow-type-all"
              @click="setType('')"
            >
              全部
            </button>
            <button
              v-for="type in CASE_TYPES"
              :key="type.value"
              type="button"
              class="workspace-filter-chip"
              :class="{ 'workspace-filter-chip--active': filters.type === type.value }"
              :aria-pressed="filters.type === type.value"
              :data-testid="`workflow-type-${type.value}`"
              @click="setType(type.value)"
            >
              {{ type.label }}
            </button>
          </div>
        </div>
      </section>

      <p v-if="partial" class="inline-warning" data-testid="workflow-partial">
        {{ partialReason }}
      </p>

      <section v-if="loading" class="panel" data-testid="workflow-loading">
        <div class="panel__body">
          <el-skeleton :rows="5" animated />
          <span class="visually-hidden">正在加载审批案例</span>
        </div>
      </section>

      <section
        v-else-if="loadError !== null && isForbidden(loadError)"
        class="panel"
        data-testid="workflow-forbidden"
      >
        <div class="panel__body">
          <h2 class="panel__title">无权查看这些审批案例</h2>
          <p class="muted">案例可能不在你的数据范围内，或流程查看权限已被收回。请联系系统管理员确认授权范围。</p>
        </div>
      </section>

      <section v-else-if="loadError !== null" class="panel" data-testid="workflow-error">
        <div class="panel__body">
          <h2 class="panel__title">审批案例加载失败</h2>
          <p class="muted">{{ platformErrorCopy(loadError) }}</p>
          <el-button type="primary" plain @click="retry">重试</el-button>
        </div>
      </section>

      <section v-else-if="isEmpty" class="panel" data-testid="workflow-empty">
        <div class="panel__body">
          <h2 class="panel__title">当前条件下没有审批案例</h2>
          <p class="muted">可以切换到“待我审批”或调整状态与事项类型后重试。</p>
        </div>
      </section>

      <section v-else class="panel">
        <div class="panel__header">
          <div>
            <h2 class="panel__title">审批案例</h2>
            <p class="panel__subtitle">共 {{ total }} 条可见案例；待办时限按当前指派任务展示。</p>
          </div>
        </div>
        <DataTable
          :columns="columns"
          :rows="rows as unknown as Array<Record<string, unknown>>"
          :total="total"
          :page="page"
          :page-size="pageSize"
          :row-test-id="(row) => `workflow-case-row-${row.id}`"
          data-testid="workflow-case-table"
          empty-title="当前条件下没有审批案例"
          @update:page="changePage"
          @update:page-size="changePageSize"
        >
          <template #cell-caseNo="{ row }">
            <span class="mono">{{ row.caseNo }}</span>
          </template>
          <template #cell-type="{ row }">
            {{ caseTypeLabel(String(row.type)) }}
          </template>
          <template #cell-title="{ row }">
            <span>
              {{ row.title }} · {{ row.caseNo }} · {{ caseTypeLabel(String(row.type)) }} ·
              {{ workflowStatusLabel(String(row.applicationStatus)) }} · {{ effectStatusLabel(String(row.effectStatus)) }}
            </span>
          </template>
          <template #cell-status="{ row }">
            <span class="plain-chip" :class="statusTone(String(row.applicationStatus))">
              {{ workflowStatusLabel(String(row.applicationStatus)) }}
            </span>
          </template>
          <template #cell-effect="{ row }">
            <span class="plain-chip" :class="effectTone(String(row.effectStatus))">
              {{ effectStatusLabel(String(row.effectStatus)) }}
            </span>
          </template>
          <template #cell-currentNode="{ row }">
            {{ currentNode(row as unknown as Record<string, unknown>) }}
          </template>
          <template #cell-sla="{ row }">
            <span class="plain-chip" :class="slaFor(row as unknown as Record<string, unknown>).tone">
              {{ slaFor(row as unknown as Record<string, unknown>).label }}
            </span>
          </template>
          <template #cell-dueAt="{ row }">
            {{ dueFor(row as unknown as Record<string, unknown>) }}
          </template>
          <template #cell-actions="{ row }">
            <RouterLink
              :to="`/workflow/cases/${row.id}`"
              class="workspace-link"
              :data-testid="`workflow-case-link-${row.id}`"
            >
              查看详情
            </RouterLink>
          </template>
        </DataTable>
      </section>
    </template>
  </PageShell>
</template>

<style scoped>
.workspace-filters {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
  padding: 0 16px 16px;
}
.workspace-filter-tabs {
  display: inline-flex;
  padding: 3px;
  background: var(--color-canvas);
  border: 1px solid var(--color-line);
  border-radius: 8px;
}
.workspace-filter-tab {
  padding: 6px 12px;
  color: var(--color-ink-secondary);
  font: inherit;
  font-size: 12px;
  cursor: pointer;
  background: transparent;
  border: 0;
  border-radius: 6px;
}
.workspace-filter-tab--active {
  color: var(--color-brand-deep, #0f4956);
  font-weight: 650;
  background: #fff;
  box-shadow: 0 1px 3px rgb(14 55 65 / 12%);
}
.workspace-filter-group {
  display: flex;
  flex-wrap: wrap;
  gap: 5px;
  align-items: center;
}
.workspace-filter-label {
  margin-right: 3px;
  color: var(--color-ink-muted);
  font-size: 11px;
  font-weight: 600;
}
.workspace-filter-chip {
  padding: 4px 9px;
  color: var(--color-ink-secondary);
  font: inherit;
  font-size: 11px;
  cursor: pointer;
  background: var(--color-canvas);
  border: 1px solid var(--color-line);
  border-radius: 999px;
}
.workspace-filter-chip--active {
  color: var(--color-brand-deep, #0f4956);
  background: #eaf4f7;
  border-color: #a8d2dc;
}
.workspace-link {
  color: var(--color-brand);
  font-size: 12px;
  text-decoration: none;
}
.workspace-link:hover,
.workspace-link:focus-visible {
  text-decoration: underline;
}
.plain-chip--success {
  color: #146c43;
  background: #e8f6ee;
  border-color: #b7dcc7;
}
.plain-chip--warning {
  color: #8a5a00;
  background: #fff6e5;
  border-color: #ead49f;
}
.plain-chip--danger {
  color: #a12828;
  background: #fdecec;
  border-color: #efc2c2;
}
.plain-chip--info {
  color: #0f5466;
  background: #eaf4f7;
  border-color: #c4dde4;
}
.plain-chip--muted {
  color: var(--color-ink-muted);
}
.inline-warning {
  padding: 9px 12px;
  color: #8a5a00;
  font-size: 12px;
  background: #fff6e5;
  border: 1px solid #ead49f;
  border-radius: 7px;
}
</style>
