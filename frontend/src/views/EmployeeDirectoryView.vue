<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { getApi } from '@/api'
import type { DirectoryEmployee, EmployeeDetail, OrgNode } from '@/api/contracts'
import { errorCopy } from '@/api/errors'
import { usePageError } from '@/composables/usePageError'
import { useSessionStore } from '@/stores/session'
import { formatBusinessDate, todayBusinessDate } from '@/utils/format'
import { EMPLOYEE_STATUS } from '@/utils/status'
import DataTable, { type DataTableColumn } from '@/components/DataTable.vue'
import AsOfDatePicker from '@/components/AsOfDatePicker.vue'
import MaskedField from '@/components/MaskedField.vue'
import Timeline from '@/components/Timeline.vue'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import LoadingState from '@/components/LoadingState.vue'

const route = useRoute()
const router = useRouter()
const session = useSessionStore()

const loading = ref(false)
const pageError = usePageError()
const rows = ref<DirectoryEmployee[]>([])
const total = ref(0)
const orgs = ref<OrgNode[]>([])
const filters = reactive({
  search: '',
  orgId: null as number | null,
  status: '' as DirectoryEmployee['status'] | '',
  asOf: todayBusinessDate(),
  page: 1,
  pageSize: 10,
})

const drawerOpen = ref(false)
const detailLoading = ref(false)
const detailError = ref('')
const detail = ref<EmployeeDetail | null>(null)
const revealTarget = ref<{ field: string; label: string } | null>(null)
const revealPurpose = ref('')
const revealError = ref('')
const revealLoading = ref(false)

const columns: DataTableColumn[] = [
  { key: 'name', label: '员工', minWidth: '150px' },
  { key: 'employeeNo', label: '工号', width: '140px' },
  { key: 'orgName', label: '组织', minWidth: '130px' },
  { key: 'positionName', label: '岗位', minWidth: '150px' },
  { key: 'status', label: '状态', width: '100px' },
  { key: 'phone', label: '手机', width: '130px' },
  { key: 'email', label: '邮箱', minWidth: '170px' },
  { key: 'idNumber', label: '身份证号', width: '170px' },
  { key: 'actions', label: '操作', width: '90px' },
]

const flatOrgs = computed(() => {
  const result: Array<{ id: number; label: string }> = []
  const walk = (nodes: OrgNode[], depth: number) => {
    for (const node of nodes) {
      result.push({ id: node.id, label: `${'　'.repeat(depth)}${node.name}（${node.headcount}）` })
      walk(node.children, depth + 1)
    }
  }
  walk(orgs.value, 0)
  return result
})

const canRevealSensitive = computed(() => session.can('people.employee.read'))
const employeeIdFromQuery = computed(() => {
  const value = Number(route.query.employee)
  return Number.isInteger(value) && value > 0 ? value : null
})

async function load(): Promise<void> {
  loading.value = true
  pageError.clear()
  try {
    const result = await getApi().employees.listEmployees({
      search: filters.search,
      orgId: filters.orgId,
      status: filters.status,
      asOf: filters.asOf,
      page: filters.page,
      pageSize: filters.pageSize,
    })
    rows.value = result.items
    total.value = result.total
  } catch (reason) {
    pageError.capture(reason)
    rows.value = []
    total.value = 0
  } finally {
    loading.value = false
  }
}

async function loadOrgs(): Promise<void> {
  try {
    orgs.value = await getApi().organization.getOrgTree(filters.asOf)
  } catch {
    orgs.value = []
  }
}

let searchTimer: ReturnType<typeof setTimeout> | undefined
watch(
  () => filters.search,
  () => {
    clearTimeout(searchTimer)
    searchTimer = setTimeout(() => {
      filters.page = 1
      void load()
    }, 250)
  },
)
watch(
  () => [filters.orgId, filters.status, filters.asOf, filters.page, filters.pageSize],
  () => void load(),
)

function resetFilters(): void {
  filters.search = ''
  filters.orgId = null
  filters.status = ''
  filters.asOf = todayBusinessDate()
  filters.page = 1
}

function maskedCell(employee: DirectoryEmployee, key: 'phone' | 'email' | 'idNumber'): string {
  const value = employee[key]
  return value.masked ? value.display ?? '已遮罩' : value.display ?? '—'
}

async function openDetail(id: number): Promise<void> {
  drawerOpen.value = true
  detailLoading.value = true
  detailError.value = ''
  detail.value = null
  try {
    detail.value = await getApi().employees.getEmployee(id, filters.asOf)
    if (route.query.employee !== String(id)) {
      void router.replace({ query: { ...route.query, employee: String(id) } })
    }
  } catch (reason) {
    detailError.value = errorCopy(reason)
  } finally {
    detailLoading.value = false
  }
}

function closeDetail(): void {
  drawerOpen.value = false
  detail.value = null
  const query = { ...route.query }
  delete query.employee
  void router.replace({ query })
}

function askReveal(field: string, label: string): void {
  revealTarget.value = { field, label }
  revealPurpose.value = ''
  revealError.value = ''
}

async function confirmReveal(): Promise<void> {
  if (!detail.value || !revealTarget.value) return
  if (revealPurpose.value.trim().length < 3) {
    revealError.value = '请填写至少 3 个字的查看目的（将写入审计日志）。'
    return
  }
  revealLoading.value = true
  revealError.value = ''
  try {
    const value = await getApi().employees.revealField(detail.value.id, revealTarget.value.field, revealPurpose.value)
    const target = revealTarget.value.field as 'phone' | 'email' | 'idNumber' | 'personalEmail' | 'address' | 'bankAccount'
    detail.value[target] = value
    ElMessage.success(`已显示${revealTarget.value.label}；本次查看已记录审计。`)
    revealTarget.value = null
  } catch (reason) {
    revealError.value = errorCopy(reason)
  } finally {
    revealLoading.value = false
  }
}

onMounted(async () => {
  await Promise.all([loadOrgs(), load()])
  if (employeeIdFromQuery.value) await openDetail(employeeIdFromQuery.value)
})
</script>

<template>
  <div class="page directory">
    <header class="page-header">
      <div class="page-header__copy">
        <p class="page-header__breadcrumb">人员与组织</p>
        <h1 class="page-header__title">员工目录</h1>
        <p class="page-header__description">
          按 as-of 日期查看有效任职与组织归属。敏感字段保持遮罩，查看全文需填写目的并留下审计记录。
        </p>
      </div>
      <div class="page-header__actions">
        <span class="plain-chip">P2 字段默认遮罩</span>
      </div>
    </header>

    <section class="panel filters-panel">
      <div class="filters">
        <label class="filters__field" style="min-width: 260px">
          <span>as-of 日期</span>
          <AsOfDatePicker v-model="filters.asOf" label="员工目录 as-of 日期" />
        </label>
        <label class="filters__field">
          <span>组织</span>
          <el-select v-model="filters.orgId" clearable filterable placeholder="全部组织" aria-label="按组织筛选">
            <el-option v-for="org in flatOrgs" :key="org.id" :label="org.label" :value="org.id" />
          </el-select>
        </label>
        <label class="filters__field">
          <span>员工状态</span>
          <el-select v-model="filters.status" clearable placeholder="全部状态" aria-label="按员工状态筛选">
            <el-option v-for="(item, key) in EMPLOYEE_STATUS" :key="key" :label="item.label" :value="key" />
          </el-select>
        </label>
        <div class="filters__spacer" />
        <el-button @click="resetFilters">重置筛选</el-button>
      </div>
    </section>

    <section class="panel">
      <ErrorState
        v-if="pageError.message.value"
        description="员工目录暂时无法加载。请检查网络或稍后重试。"
        :code="pageError.code.value"
        :request-id="pageError.requestId.value"
        @retry="load"
      />
      <LoadingState v-else-if="loading && rows.length === 0" :rows="6" label="正在加载员工目录" />
      <EmptyState
        v-else-if="rows.length === 0 && !loading"
        title="没有匹配的员工"
        description="当前搜索、组织、状态与 as-of 日期组合下没有记录。请调整筛选条件。"
        action-label="清除筛选"
        @action="resetFilters"
      />
      <DataTable
        v-else
        v-model:search-value="filters.search"
        v-model:page="filters.page"
        v-model:page-size="filters.pageSize"
        :columns="columns"
        :rows="rows as unknown as Array<Record<string, unknown>>"
        :total="total"
        :loading="loading"
        show-search
        search-placeholder="搜索姓名、工号、岗位或组织"
        empty-title="当前筛选下没有员工"
      >
        <template #cell-name="{ row }">
          <button type="button" class="employee-link" @click="openDetail((row as unknown as DirectoryEmployee).id)">
            <span class="employee-avatar" aria-hidden="true">{{ (row as unknown as DirectoryEmployee).initials }}</span>
            <span>
              <span class="employee-name">{{ (row as unknown as DirectoryEmployee).name }}</span>
              <span class="employee-sub">入职 {{ formatBusinessDate((row as unknown as DirectoryEmployee).hireDate) }}</span>
            </span>
          </button>
        </template>
        <template #cell-status="{ row }">
          <span
            class="employment-status"
            :class="`employment-status--${EMPLOYEE_STATUS[(row as unknown as DirectoryEmployee).status].tone}`"
          >
            <span aria-hidden="true">{{ EMPLOYEE_STATUS[(row as unknown as DirectoryEmployee).status].symbol }}</span>
            {{ EMPLOYEE_STATUS[(row as unknown as DirectoryEmployee).status].label }}
          </span>
        </template>
        <template #cell-phone="{ row }">
          <span class="masked-value">{{ maskedCell(row as unknown as DirectoryEmployee, 'phone') }}</span>
        </template>
        <template #cell-email="{ row }">
          <span class="masked-value">{{ maskedCell(row as unknown as DirectoryEmployee, 'email') }}</span>
        </template>
        <template #cell-idNumber="{ row }">
          <span class="masked-value">
            {{ maskedCell(row as unknown as DirectoryEmployee, 'idNumber') }}
            <span class="lock-mark" aria-label="敏感字段已遮罩">●</span>
          </span>
        </template>
        <template #cell-actions="{ row }">
          <el-button text size="small" @click="openDetail((row as unknown as DirectoryEmployee).id)">查看</el-button>
        </template>
      </DataTable>
    </section>

    <el-drawer
      v-model="drawerOpen"
      size="min(640px, 94vw)"
      :title="detail ? `${detail.name} · ${detail.employeeNo}` : '员工详情'"
      :before-close="closeDetail"
    >
      <div class="drawer-body">
        <LoadingState v-if="detailLoading" :rows="7" label="正在加载员工详情" />
        <ErrorState
          v-else-if="detailError"
          title="无法加载员工详情"
          :description="detailError"
          @retry="employeeIdFromQuery && openDetail(employeeIdFromQuery)"
        />
        <template v-else-if="detail">
          <section class="drawer-section">
            <div class="employee-summary">
              <span class="employee-summary__avatar" aria-hidden="true">{{ detail.initials }}</span>
              <div>
                <h2>{{ detail.name }}</h2>
                <p>{{ detail.positionName }} · {{ detail.orgName }}</p>
                <p class="muted small">工号 {{ detail.employeeNo }} · {{ detail.employmentType }}</p>
              </div>
              <span class="plain-chip">{{ EMPLOYEE_STATUS[detail.status].label }}</span>
            </div>
            <p class="field-access-note">{{ detail.fieldAccessNote }}</p>
          </section>

          <section class="drawer-section">
            <h3 class="section-title">任职与合同</h3>
            <div class="detail-grid">
              <div class="detail-grid__item">
                <span class="detail-grid__label">直属经理</span>
                <span class="detail-grid__value">{{ detail.managerName }}</span>
              </div>
              <div class="detail-grid__item">
                <span class="detail-grid__label">工作地点</span>
                <span class="detail-grid__value">{{ detail.workLocation }}</span>
              </div>
              <div class="detail-grid__item">
                <span class="detail-grid__label">合同</span>
                <span class="detail-grid__value">{{ detail.contract.contractNo }}</span>
              </div>
            </div>
            <div class="assignment-list">
              <div v-for="assignment in detail.assignments" :key="assignment.id" class="assignment-row">
                <div>
                  <p class="assignment-row__org">{{ assignment.orgName }}</p>
                  <p class="muted small">{{ assignment.positionName }} · 汇报 {{ assignment.managerName }}</p>
                </div>
                <span class="small tabular">
                  {{ formatBusinessDate(assignment.validFrom) }} —
                  {{ assignment.validTo ? formatBusinessDate(assignment.validTo) : '至今' }}
                </span>
              </div>
            </div>
          </section>

          <section class="drawer-section">
            <h3 class="section-title">联系与敏感字段</h3>
            <p class="section-subtitle">P2 字段默认遮罩。单独查看需要填写目的，且会写入审计事件。</p>
            <div class="field-list">
              <MaskedField label="手机号" :value="detail.phone" :can-reveal="canRevealSensitive" @reveal="askReveal('phone', '手机号')" />
              <MaskedField label="工作邮箱" :value="detail.email" :can-reveal="canRevealSensitive" @reveal="askReveal('email', '工作邮箱')" />
              <MaskedField label="身份证号" :value="detail.idNumber" :can-reveal="canRevealSensitive" @reveal="askReveal('idNumber', '身份证号')" />
              <MaskedField label="个人邮箱" :value="detail.personalEmail" :can-reveal="canRevealSensitive" @reveal="askReveal('personalEmail', '个人邮箱')" />
              <MaskedField label="银行卡号" :value="detail.bankAccount" :can-reveal="canRevealSensitive" @reveal="askReveal('bankAccount', '银行卡号')" />
              <MaskedField label="住址" :value="detail.address" :can-reveal="canRevealSensitive" @reveal="askReveal('address', '住址')" />
            </div>
          </section>

          <section class="drawer-section">
            <h3 class="section-title">任职时间线</h3>
            <p class="section-subtitle">企业系统回答“当时是谁在哪个组织”的依据；历史记录不覆盖。</p>
            <Timeline :events="detail.timeline" />
          </section>
        </template>
      </div>
    </el-drawer>

    <el-dialog
      :model-value="Boolean(revealTarget)"
      title="查看敏感字段"
      width="460px"
      :close-on-click-modal="false"
      @close="revealTarget = null"
    >
      <div class="reveal-dialog__body">
        <p class="small">
          你即将查看 <strong>{{ revealTarget?.label }}</strong> 的完整值。此操作将记录操作者、对象、字段、目的与时间，并纳入敏感访问审计。
        </p>
        <label class="field-label" for="reveal-purpose">查看目的（必填）</label>
        <el-input
          id="reveal-purpose"
          v-model="revealPurpose"
          type="textarea"
          :rows="3"
          maxlength="120"
          show-word-limit
          placeholder="例如：合同续签身份核验"
        />
        <p v-if="revealError" class="field-error">{{ revealError }}</p>
        <el-alert
          title="不得截图、转发或将字段用于原目的之外的用途。"
          type="warning"
          :closable="false"
          show-icon
        />
      </div>
      <template #footer>
        <el-button @click="revealTarget = null">取消</el-button>
        <el-button type="primary" :loading="revealLoading" @click="confirmReveal">确认查看并记录审计</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.filters-panel {
  padding: 14px 16px;
}

.employee-link {
  display: flex;
  align-items: center;
  gap: 9px;
  padding: 0;
  color: inherit;
  text-align: left;
  background: transparent;
  border: 0;
  cursor: pointer;
}

.employee-link:hover .employee-name {
  color: var(--color-brand);
  text-decoration: underline;
}

.employee-avatar {
  display: grid;
  flex: 0 0 30px;
  width: 30px;
  height: 30px;
  place-items: center;
  color: var(--color-brand-deep);
  font-size: 12px;
  font-weight: 700;
  background: var(--color-brand-soft);
  border-radius: 50%;
}

.employee-name {
  display: block;
  color: var(--color-ink);
  font-size: 12.5px;
  font-weight: 650;
}

.employee-sub {
  display: block;
  color: var(--color-ink-muted);
  font-size: 10px;
}

.employment-status {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 1px 7px;
  color: var(--color-ink-secondary);
  font-size: 11px;
  font-weight: 600;
  background: var(--color-canvas);
  border: 1px solid var(--color-line);
  border-radius: 999px;
  white-space: nowrap;
}

.employment-status--success {
  color: var(--color-success);
  background: var(--color-success-soft);
  border-color: #b7dcc7;
}

.employment-status--info {
  color: var(--color-info);
  background: var(--color-info-soft);
  border-color: #b3d1e2;
}

.employment-status--warning {
  color: var(--color-warning);
  background: var(--color-warning-soft);
  border-color: #ead49f;
}

.employment-status--muted {
  color: var(--color-ink-muted);
}

.masked-value {
  font-family: "Cascadia Code", Consolas, monospace;
  font-size: 11.5px;
}

.lock-mark {
  margin-left: 4px;
  color: var(--color-accent);
  font-size: 7px;
  vertical-align: super;
}

.drawer-body {
  display: flex;
  flex-direction: column;
  gap: 22px;
  padding: 20px 22px 30px;
}

.drawer-section {
  padding-bottom: 18px;
  border-bottom: 1px solid var(--color-line);
}

.drawer-section:last-child {
  border-bottom: 0;
}

.employee-summary {
  display: flex;
  align-items: center;
  gap: 13px;
}

.employee-summary__avatar {
  display: grid;
  flex: 0 0 46px;
  width: 46px;
  height: 46px;
  place-items: center;
  color: #fff;
  font-size: 18px;
  font-weight: 700;
  background: linear-gradient(145deg, #1c6878, #0f4c5c);
  border-radius: 50%;
}

.employee-summary h2 {
  font-size: 18px;
  font-weight: 680;
}

.employee-summary p {
  color: var(--color-ink-secondary);
  font-size: 12px;
}

.employee-summary .plain-chip {
  margin-left: auto;
}

.field-access-note {
  margin-top: 12px;
  padding: 9px 11px;
  color: var(--color-ink-secondary);
  font-size: 11.5px;
  line-height: 1.6;
  background: var(--color-canvas);
  border-left: 3px solid var(--color-line-strong);
  border-radius: 0 var(--radius-sm) var(--radius-sm) 0;
}

.drawer-section .section-subtitle {
  margin-bottom: 12px;
}

.assignment-list {
  margin-top: 14px;
  border-top: 1px solid var(--color-line);
}

.assignment-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 9px 0;
  border-bottom: 1px solid #edf1ef;
}

.assignment-row__org {
  font-size: 12.5px;
  font-weight: 600;
}

.field-list {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 11px 16px;
  margin-top: 12px;
}

.field-label {
  font-size: 12px;
  font-weight: 600;
}

.field-error {
  color: var(--color-danger);
  font-size: 11.5px;
}

@media (max-width: 640px) {
  .field-list {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
