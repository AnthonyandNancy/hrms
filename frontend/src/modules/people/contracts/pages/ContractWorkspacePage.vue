<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import DataTable, { type DataTableColumn } from '@/components/DataTable.vue'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import LoadingState from '@/components/LoadingState.vue'
import PageShell from '@/shared/ui/PageShell.vue'
import { ApiError, errorCopy } from '@/api/errors'
import { useSessionStore } from '@/stores/session'
import { requestIdFromError } from '@/utils/renderErrors'
import { formatBusinessDate, todayBusinessDate } from '@/utils/format'
import ContractFormDrawer from '../components/ContractFormDrawer.vue'
import {
  CONTRACT_STATUS_TONES,
  contractTypeLabel,
  createContract,
  isForbidden,
  listContracts,
  listEmployeeOptions,
  type Contract,
  type ContractFormInput,
  type ContractPage,
  type EmployeeOption,
} from '../api'

defineOptions({ name: 'ContractWorkspacePage' })

type LoadState = 'idle' | 'loading' | 'ready' | 'error' | 'forbidden'
type ExpiryFilter = 'ALL' | '60' | '30' | '7' | 'EXPIRED'

const router = useRouter()
const session = useSessionStore()

const canRead = computed(() => session.can('people.contract.read'))
const canManage = computed(() => session.can('people.contract.manage'))
const canAmend = computed(() => session.can('people.contract.amend'))
const canTerminate = computed(() => session.can('people.contract.terminate'))

const asOf = ref(todayBusinessDate())
const page = ref(1)
const pageSize = 20
const state = ref<LoadState>('idle')
const result = ref<ContractPage>({
  items: [],
  total: 0,
  page: 1,
  page_size: pageSize,
  partial: false,
  partial_reason: null,
  as_of: asOf.value,
})
const pageError = ref<unknown>(null)
const employees = ref<EmployeeOption[]>([])
const employeeLookupError = ref('')
const search = ref('')
const expiryFilter = ref<ExpiryFilter>('ALL')

const drawerOpen = ref(false)
const drawerMode = ref<'create' | 'renew'>('create')
const renewalSource = ref<Contract | null>(null)
const saving = ref(false)
const saveError = ref<ApiError | null>(null)

let requestSequence = 0

const loading = computed(() => state.value === 'loading')
const forbidden = computed(() => !canRead.value || state.value === 'forbidden')
const isEmpty = computed(() => state.value === 'ready' && filteredRows.value.length === 0)
const isPartial = computed(() => state.value === 'ready' && result.value.partial)
const errorMessage = computed(() =>
  pageError.value === null ? '劳动合同台账暂时无法加载。' : errorCopy(pageError.value),
)
const errorRequestId = computed(() =>
  pageError.value === null ? null : requestIdFromError(pageError.value),
)

const columns: DataTableColumn[] = [
  { key: 'employee_name', label: '员工', minWidth: '150px' },
  { key: 'contract_no', label: '合同编号', minWidth: '150px' },
  { key: 'contract_type_label', label: '合同类型', width: '110px' },
  { key: 'period', label: '起止日期', minWidth: '195px' },
  { key: 'status_label', label: '状态', width: '105px' },
  { key: 'renewal_label', label: '续签版本', width: '100px' },
  { key: 'expiry_label', label: '到期提醒', width: '120px' },
  { key: 'actions', label: '操作', width: '168px' },
]

function expiryLabel(contract: Contract): string {
  if (contract.display_status_label === '已终止') return '已终止'
  if (contract.display_status_label === '已续签') return '已续签'
  if (contract.display_status_label === '草稿') return '未生效'
  if (contract.display_status_label === '已到期') return '已到期'
  if (contract.end_date === null) return '长期有效'
  if (contract.days_to_expiry === null) return contract.end_date
  if (contract.days_to_expiry < 0) return `逾期 ${Math.abs(contract.days_to_expiry)} 天`
  if (contract.days_to_expiry === 0) return '今日到期'
  return `${contract.days_to_expiry} 天后到期`
}

function matchesExpiry(contract: Contract): boolean {
  const days = contract.days_to_expiry
  if (expiryFilter.value === 'ALL') return true
  if (expiryFilter.value === 'EXPIRED') return contract.display_status === 'EXPIRED'
  if (contract.end_date === null || days === null) return false
  if (contract.display_status_label === '已终止' || contract.display_status_label === '已续签') return false
  const window = Number(expiryFilter.value)
  return days >= 0 && days <= window
}

const filteredRows = computed(() => {
  const term = search.value.trim().toLowerCase()
  return result.value.items.filter((contract) => {
    if (!matchesExpiry(contract)) return false
    if (term === '') return true
    return [contract.employee_name, contract.employee_no, contract.contract_no, contractTypeLabel(contract.contract_type)]
      .some((value) => value !== null && value.toLowerCase().includes(term))
  })
})

const rows = computed<Array<Record<string, unknown>>>(() =>
  filteredRows.value.map((contract) => ({
    ...contract,
    employee_name: contract.employee_name ?? `员工 #${contract.employment_id}`,
    contract_type_label: contractTypeLabel(contract.contract_type),
    period: `${formatBusinessDate(contract.start_date)} 至 ${
      contract.end_date === null ? '无固定期限' : formatBusinessDate(contract.end_date)
    }`,
    status_label: contract.display_status_label,
    renewal_label: contract.renewal_count > 0 ? `续签 ${contract.renewal_count} 次` : '首份',
    expiry_label: expiryLabel(contract),
  })),
)

async function load(): Promise<void> {
  if (!canRead.value) {
    state.value = 'forbidden'
    return
  }

  const sequence = ++requestSequence
  state.value = 'loading'
  pageError.value = null
  employeeLookupError.value = ''

  const [ledger, employeePage] = await Promise.allSettled([
    listContracts(asOf.value, page.value, pageSize),
    listEmployeeOptions(asOf.value),
  ])

  if (sequence !== requestSequence) return

  if (employeePage.status === 'fulfilled') {
    employees.value = employeePage.value
  } else {
    employees.value = []
    employeeLookupError.value = '员工下拉加载失败；仍可查看到期合同，新建合同需先恢复员工目录。'
  }

  if (ledger.status === 'rejected') {
    result.value = { ...result.value, items: [], total: 0, partial: false, partial_reason: null }
    if (isForbidden(ledger.reason)) {
      pageError.value = ledger.reason
      state.value = 'forbidden'
      return
    }
    pageError.value = ledger.reason
    state.value = 'error'
    return
  }

  result.value = ledger.value
  asOf.value = ledger.value.as_of
  state.value = 'ready'
}

function onPageChange(next: number): void {
  page.value = next
  void load()
}

function openCreate(): void {
  if (!canManage.value) return
  drawerMode.value = 'create'
  renewalSource.value = null
  saveError.value = null
  drawerOpen.value = true
}

function openRenew(contract: Contract): void {
  if (!canManage.value) return
  drawerMode.value = 'renew'
  renewalSource.value = contract
  saveError.value = null
  drawerOpen.value = true
}

async function save(input: ContractFormInput): Promise<void> {
  saving.value = true
  saveError.value = null
  try {
    await createContract(input.employment_id, input)
    ElMessage.success(input.renewal_of_contract_id === null ? '劳动合同草稿已保存。' : '续签已生成新版本，原版本保留。')
    drawerOpen.value = false
    page.value = 1
    await load()
  } catch (error) {
    saveError.value = error instanceof ApiError ? error : null
    if (saveError.value === null) ElMessage.error(errorCopy(error))
  } finally {
    saving.value = false
  }
}

function view(contract: Contract): void {
  void router.push({ path: `/hr/contracts/${contract.id}`, query: { employment_id: String(contract.employment_id) } })
}

function setExpiryFilter(filter: ExpiryFilter): void {
  expiryFilter.value = filter
}

onMounted(load)
onBeforeUnmount(() => {
  requestSequence += 1
})
</script>

<template>
  <PageShell
    title="劳动合同"
    description="按员工维护劳动合同版本、续签链与到期预警；签订、续签、变更和终止均保留历史。"
  >
    <template #actions>
      <el-button :disabled="loading" data-testid="contract-refresh" @click="load">刷新</el-button>
      <el-button
        v-if="canManage && !forbidden"
        type="primary"
        data-testid="contract-create"
        @click="openCreate"
      >
        新建合同
      </el-button>
    </template>

    <section
      v-if="forbidden"
      class="state-panel error-state"
      role="alert"
      data-testid="contracts-forbidden"
    >
      <div class="state-panel__mark" aria-hidden="true">!</div>
      <div class="state-panel__content">
        <h2 class="state-panel__title">无权限查看劳动合同</h2>
        <p class="state-panel__body">当前账号缺少 people.contract.read，或数据范围不含这些员工关系。</p>
        <p v-if="errorRequestId" class="state-panel__request">请求编号：<span class="mono">{{ errorRequestId }}</span></p>
      </div>
      <el-button type="primary" plain @click="load">重试</el-button>
    </section>

    <ErrorState
      v-else-if="state === 'error'"
      data-testid="contracts-error"
      title="劳动合同台账暂时无法加载"
      :description="errorMessage"
      :request-id="errorRequestId"
      @retry="load"
    />

    <LoadingState v-else-if="loading || state === 'idle'" :rows="6" label="正在加载劳动合同台账" />

    <template v-else>
      <el-alert
        v-if="isPartial"
        type="warning"
        :closable="false"
        show-icon
        data-testid="contracts-partial"
      >
        <template #title>
          部分数据：{{ result.partial_reason ?? '服务端只返回了部分合同，请刷新后重试。' }}
        </template>
      </el-alert>

      <el-alert v-if="employeeLookupError !== ''" type="warning" :closable="false" show-icon>
        <template #title>{{ employeeLookupError }}</template>
      </el-alert>

      <section class="panel contract-toolbar" aria-label="合同筛选">
        <div class="panel__body contract-toolbar__body">
          <label class="contract-toolbar__search">
            <span>搜索</span>
            <el-input
              v-model="search"
              clearable
              placeholder="员工、工号或合同编号"
              aria-label="搜索劳动合同"
            />
          </label>
          <div class="contract-toolbar__filters">
            <span class="contract-toolbar__label">到期窗口</span>
            <button
              type="button"
              class="contract-filter"
              :class="{ 'contract-filter--active': expiryFilter === 'ALL' }"
              data-testid="contract-expiry-filter-all"
              @click="setExpiryFilter('ALL')"
            >
              全部
            </button>
            <button
              type="button"
              class="contract-filter"
              :class="{ 'contract-filter--active': expiryFilter === '60' }"
              data-testid="contract-expiry-filter"
              @click="setExpiryFilter('60')"
            >
              60 天内
            </button>
            <button
              type="button"
              class="contract-filter"
              :class="{ 'contract-filter--active': expiryFilter === '30' }"
              data-testid="contract-expiry-30"
              @click="setExpiryFilter('30')"
            >
              30 天内
            </button>
            <button
              type="button"
              class="contract-filter"
              :class="{ 'contract-filter--active': expiryFilter === '7' }"
              data-testid="contract-expiry-7"
              @click="setExpiryFilter('7')"
            >
              7 天内
            </button>
            <button
              type="button"
              class="contract-filter"
              :class="{ 'contract-filter--active': expiryFilter === 'EXPIRED' }"
              data-testid="contract-expiry-expired"
              @click="setExpiryFilter('EXPIRED')"
            >
              已到期
            </button>
          </div>
          <span class="contract-toolbar__asof">业务日期 {{ asOf }}</span>
        </div>
      </section>

      <EmptyState
        v-if="isEmpty && result.items.length === 0"
        data-testid="contracts-empty"
        title="暂无劳动合同"
        description="当前业务日期下没有可读取的劳动合同。可以新建草稿，或调整员工数据范围后重试。"
      />
      <EmptyState
        v-else-if="isEmpty"
        data-testid="contracts-filter-empty"
        title="筛选条件下没有合同"
        description="当前到期窗口或搜索条件没有匹配项。调整筛选后再试。"
      />

      <DataTable
        v-else
        data-testid="contract-table"
        :columns="columns"
        :rows="rows"
        :total="result.total"
        :page="page"
        :page-size="pageSize"
        row-key="id"
        search-placeholder="员工、工号或合同编号"
        @update:page="onPageChange"
      >
        <template #cell-employee_name="{ row }">
          <div class="contract-employee">
            <strong>{{ row.employee_name }}</strong>
            <small v-if="row.employee_no">{{ row.employee_no }}</small>
          </div>
        </template>
        <template #cell-contract_no="{ row }">
          <button type="button" class="contract-link mono" @click="view(row as unknown as Contract)">
            {{ row.contract_no }}
          </button>
        </template>
        <template #cell-status_label="{ row }">
          <span
            class="status-badge status-badge--sm"
            :class="`status-badge--${CONTRACT_STATUS_TONES[(row as unknown as Contract).display_status]}`"
          >
            {{ row.status_label }}
          </span>
        </template>
        <template #cell-expiry_label="{ row }">
          <span
            :class="{
              'contract-expiry--warning': (row as unknown as Contract).display_status === 'EXPIRING',
              'contract-expiry--danger': (row as unknown as Contract).display_status === 'EXPIRED',
            }"
          >
            {{ row.expiry_label }}
          </span>
        </template>
        <template #cell-actions="{ row }">
          <div class="contract-actions">
            <button type="button" class="contract-action" @click="view(row as unknown as Contract)">详情</button>
            <button
              v-if="
                canManage &&
                  (row as unknown as Contract).display_status_label !== '已终止' &&
                  (row as unknown as Contract).display_status_label !== '已续签'
              "
              type="button"
              class="contract-action"
              :data-testid="`contract-renew-${(row as unknown as Contract).id}`"
              @click="openRenew(row as unknown as Contract)"
            >
              续签
            </button>
            <button
              v-if="canTerminate && (row as unknown as Contract).display_status_label !== '已终止'"
              type="button"
              class="contract-action contract-action--danger"
              :data-testid="`contract-terminate-${(row as unknown as Contract).id}`"
              @click="view(row as unknown as Contract)"
            >
              终止
            </button>
          </div>
        </template>
      </DataTable>

      <p v-if="canAmend" class="contract-workspace__note">
        合同变更从详情页追加版本；历史版本与附件只读保留。
      </p>
      <p v-else class="contract-workspace__note">只读账号可查看合同、版本与附件，不显示写入动作。</p>
    </template>

    <ContractFormDrawer
      v-model="drawerOpen"
      :mode="drawerMode"
      :renewal-source="renewalSource"
      :employees="employees"
      :saving="saving"
      :server-error="saveError"
      @save="save"
    />
  </PageShell>
</template>

<style scoped>
.contract-toolbar__body {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  align-items: flex-end;
}
.contract-toolbar__search {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: min(300px, 100%);
  color: var(--color-ink-muted);
  font-size: 11px;
}
.contract-toolbar__filters {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  align-items: center;
}
.contract-toolbar__label,
.contract-toolbar__asof {
  color: var(--color-ink-muted);
  font-size: 11px;
}
.contract-toolbar__asof {
  margin-left: auto;
}
.contract-filter {
  padding: 5px 10px;
  color: var(--color-ink-secondary);
  font-size: 11px;
  background: #fff;
  border: 1px solid var(--color-line-strong);
  border-radius: 999px;
  cursor: pointer;
}
.contract-filter--active {
  color: #fff;
  background: var(--color-brand);
  border-color: var(--color-brand);
}
.contract-employee {
  display: flex;
  flex-direction: column;
  gap: 1px;
}
.contract-employee small {
  color: var(--color-ink-muted);
  font-size: 10px;
}
.contract-link {
  padding: 0;
  color: var(--color-brand);
  background: transparent;
  border: 0;
  cursor: pointer;
}
.contract-expiry--warning {
  color: var(--color-warning);
  font-weight: 600;
}
.contract-expiry--danger {
  color: var(--color-danger);
  font-weight: 600;
}
.contract-actions {
  display: flex;
  gap: 9px;
}
.contract-action {
  padding: 0;
  color: var(--color-brand);
  font-size: 11px;
  background: transparent;
  border: 0;
  cursor: pointer;
}
.contract-action--danger {
  color: var(--color-danger);
}
.contract-workspace__note {
  margin: 2px 0 0;
  color: var(--color-ink-muted);
  font-size: 11px;
}
@media (max-width: 760px) {
  .contract-toolbar__asof {
    margin-left: 0;
  }
}
</style>
