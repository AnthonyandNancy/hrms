<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import DataTable, { type DataTableColumn } from '@/components/DataTable.vue'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import LoadingState from '@/components/LoadingState.vue'
import PageShell from '@/shared/ui/PageShell.vue'
import { ApiError, errorCopy } from '@/api/errors'
import { useSessionStore } from '@/stores/session'
import { requestIdFromError } from '@/utils/renderErrors'
import { formatBusinessDate, formatDateTimeInTimezone, todayBusinessDate } from '@/utils/format'
import ContractFormDrawer from '../components/ContractFormDrawer.vue'
import {
  CONTRACT_STATUS_TONES,
  contractTypeLabel,
  createContract,
  createContractTemplate,
  isForbidden,
  listContractTemplates,
  listContracts,
  listEmployeeOptions,
  listExpiringContracts,
  publishContractTemplate,
  type Contract,
  type ContractFormInput,
  type ContractPage,
  type ContractTemplate,
  type ContractTemplatePage,
  type EmployeeOption,
} from '../api'

defineOptions({ name: 'ContractWorkspacePage' })

type LoadState = 'idle' | 'loading' | 'ready' | 'error' | 'forbidden'
type ContractTab = 'ledger' | 'expiry' | 'templates'
type ExpiryWindow = '60' | '30' | '7'

const router = useRouter()
const route = useRoute()
const session = useSessionStore()

const canRead = computed(() => session.can('people.contract.read'))
const canManage = computed(() => session.can('people.contract.manage'))
const canAmend = computed(() => session.can('people.contract.amend'))
const canTerminate = computed(() => session.can('people.contract.terminate'))
const canManageTemplates = computed(() => session.can('people.contract.template.manage'))

const asOf = ref(todayBusinessDate())
const page = ref(1)
const pageSize = ref(20)
const state = ref<LoadState>('idle')
const result = ref<ContractPage>({
  items: [],
  total: 0,
  page: 1,
  page_size: pageSize.value,
  partial: false,
  partial_reason: null,
  as_of: asOf.value,
})
const pageError = ref<unknown>(null)
const employees = ref<EmployeeOption[]>([])
const employeeLookupError = ref('')
const search = ref('')
const appliedSearch = ref('')
const expiryWindow = ref<ExpiryWindow>('60')

const templates = ref<ContractTemplatePage>({
  items: [],
  total: 0,
  partial: false,
  partial_reason: null,
})
const templatesState = ref<LoadState>('idle')
const templatesError = ref<unknown>(null)
const templateSearch = ref('')
const templateDialogOpen = ref(false)
const templateSaving = ref(false)
const templateError = ref<ApiError | null>(null)
const templateDraft = ref({ code: '', name: '', body: '' })

const drawerOpen = ref(false)
const drawerMode = ref<'create' | 'renew'>('create')
const renewalSource = ref<Contract | null>(null)
const saving = ref(false)
const saveError = ref<ApiError | null>(null)

let requestSequence = 0
let templateSequence = 0

const activeTab = computed<ContractTab>(() => {
  const raw = route.query.tab
  return raw === 'expiry' || raw === 'templates' ? raw : 'ledger'
})

const loading = computed(() => state.value === 'loading')
const forbidden = computed(() => !canRead.value || state.value === 'forbidden')
const isEmpty = computed(() =>
  state.value === 'ready' && (activeTab.value === 'templates' ? templates.value.items.length === 0 : result.value.items.length === 0),
)
const isPartial = computed(() =>
  state.value === 'ready' && (activeTab.value === 'templates' ? templates.value.partial : result.value.partial),
)
const errorMessage = computed(() =>
  pageError.value === null ? '劳动合同台账暂时无法加载。' : errorCopy(pageError.value),
)
const errorRequestId = computed(() =>
  pageError.value === null ? null : requestIdFromError(pageError.value),
)
const templatesErrorMessage = computed(() =>
  templatesError.value === null ? '合同模板暂时无法加载。' : errorCopy(templatesError.value),
)

const contractColumns: DataTableColumn[] = [
  { key: 'employee_name', label: '员工', minWidth: '150px' },
  { key: 'contract_no', label: '合同编号', minWidth: '150px' },
  { key: 'contract_type_label', label: '合同类型', width: '110px' },
  { key: 'period', label: '起止日期', minWidth: '195px' },
  { key: 'status_label', label: '状态', width: '105px' },
  { key: 'renewal_label', label: '续签版本', width: '100px' },
  { key: 'expiry_label', label: '到期提醒', width: '120px' },
  { key: 'actions', label: '操作', width: '168px' },
]

const templateColumns: DataTableColumn[] = [
  { key: 'code', label: '模板编码', minWidth: '130px' },
  { key: 'name', label: '模板名称', minWidth: '180px' },
  { key: 'version', label: '版本', width: '80px' },
  { key: 'status_label', label: '状态', width: '100px' },
  { key: 'published_at', label: '发布时间', minWidth: '150px' },
  { key: 'actions', label: '操作', width: '110px' },
]

const TEMPLATE_STATUS_LABELS: Readonly<Record<string, string>> = {
  DRAFT: '草稿',
  PUBLISHED: '已发布',
  ARCHIVED: '已归档',
}

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

const contractRows = computed<Array<Record<string, unknown>>>(() =>
  result.value.items.map((contract) => ({
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

const templateRows = computed<Array<Record<string, unknown>>>(() =>
  templates.value.items.map((template) => ({
    ...template,
    version: `第 ${template.version} 版`,
    status_label: TEMPLATE_STATUS_LABELS[template.status] ?? '未知状态',
    published_at: formatDateTimeInTimezone(template.published_at),
  })),
)

function setTab(tab: ContractTab): void {
  page.value = 1
  if (route.query.tab === tab) {
    if (tab === 'templates') {
      void loadTemplates()
    } else {
      void load()
    }
    return
  }
  // The route watcher performs the single load for this tab change.
  void router.replace({ query: { ...route.query, tab } })
}

async function load(): Promise<void> {
  if (!canRead.value) {
    state.value = 'forbidden'
    return
  }

  const sequence = ++requestSequence
  state.value = 'loading'
  pageError.value = null
  employeeLookupError.value = ''

  const ledgerPromise = activeTab.value === 'expiry'
    ? listExpiringContracts(Number(expiryWindow.value) as 60 | 30 | 7, asOf.value)
    : listContracts({
        asOf: asOf.value,
        page: page.value,
        pageSize: pageSize.value,
        q: appliedSearch.value,
      })

  const [ledger, employeePage] = await Promise.allSettled([
    ledgerPromise,
    listEmployeeOptions(asOf.value),
  ])

  if (sequence !== requestSequence) return

  if (employeePage.status === 'fulfilled') {
    employees.value = employeePage.value
  } else {
    employees.value = []
    employeeLookupError.value = '员工下拉加载失败；仍可查看合同台账，新建合同需先恢复员工目录。'
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

async function loadTemplates(): Promise<void> {
  const sequence = ++templateSequence
  templatesState.value = 'loading'
  templatesError.value = null

  try {
    const pageResult = await listContractTemplates({ asOf: asOf.value, q: templateSearch.value })
    if (sequence !== templateSequence) return
    templates.value = pageResult
    templatesState.value = 'ready'
  } catch (error) {
    if (sequence !== templateSequence) return
    templates.value = { items: [], total: 0, partial: false, partial_reason: null }
    templatesError.value = error
    templatesState.value = isForbidden(error) ? 'forbidden' : 'error'
  }
}

function submitSearch(): void {
  appliedSearch.value = search.value.trim()
  page.value = 1
  void load()
}

function setExpiryWindow(window: ExpiryWindow): void {
  expiryWindow.value = window
  void load()
}

function onPageChange(next: number): void {
  page.value = next
  void load()
}

function onPageSizeChange(next: number): void {
  pageSize.value = next
  page.value = 1
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
    const created = await createContract(input.employment_id, input)
    drawerOpen.value = false
    if (input.renewal_of_contract_id !== null && created !== null) {
      ElMessage.success('续签已生成新版本，已打开新合同详情。')
      void router.push({
        path: `/hr/contracts/${created.id}`,
        query: { employment_id: String(created.employment_id) },
      })
      return
    }
    ElMessage.success('劳动合同草稿已保存。')
    page.value = 1
    await load()
  } catch (error) {
    saveError.value = error instanceof ApiError ? error : null
    if (saveError.value === null) ElMessage.error(errorCopy(error))
  } finally {
    saving.value = false
  }
}

function openTemplateDialog(): void {
  if (!canManageTemplates.value) return
  templateDraft.value = { code: '', name: '', body: '' }
  templateError.value = null
  templateDialogOpen.value = true
}

async function saveTemplate(): Promise<void> {
  if (!canManageTemplates.value) return
  const { code, name, body } = templateDraft.value
  if (code.trim() === '' || name.trim() === '' || body.trim() === '') {
    ElMessage.warning('请填写模板编码、名称和正文。')
    return
  }

  templateSaving.value = true
  templateError.value = null
  try {
    await createContractTemplate({ code, name, body })
    ElMessage.success('合同模板草稿已创建，发布后新合同可引用。')
    templateDialogOpen.value = false
    await loadTemplates()
  } catch (error) {
    templateError.value = error instanceof ApiError ? error : null
    if (templateError.value === null) ElMessage.error(errorCopy(error))
  } finally {
    templateSaving.value = false
  }
}

async function publishTemplate(template: ContractTemplate): Promise<void> {
  if (!canManageTemplates.value || template.status !== 'DRAFT') return
  try {
    await publishContractTemplate(template.id)
    ElMessage.success('模板已发布；同编码的旧发布版本归档，历史保留。')
    await loadTemplates()
  } catch (error) {
    ElMessage.error(errorCopy(error))
  }
}

function view(contract: Contract): void {
  void router.push({ path: `/hr/contracts/${contract.id}`, query: { employment_id: String(contract.employment_id) } })
}

watch(
  () => route.query.tab,
  () => {
    asOf.value = todayBusinessDate()
    if (activeTab.value === 'templates') {
      void loadTemplates()
    } else {
      void load()
    }
  },
)

onMounted(() => {
  if (activeTab.value === 'templates') {
    void loadTemplates()
  } else {
    void load()
  }
})
onBeforeUnmount(() => {
  requestSequence += 1
  templateSequence += 1
})
</script>

<template>
  <PageShell
    title="劳动合同"
    description="按员工维护劳动合同版本、续签链、到期预警与合同模板；签订、续签、变更和终止均保留历史。"
  >
    <template #actions>
      <el-button
        :disabled="loading"
        data-testid="contract-refresh"
        @click="activeTab === 'templates' ? loadTemplates() : load()"
      >
        刷新
      </el-button>
      <el-button
        v-if="canManage && !forbidden && activeTab !== 'templates'"
        type="primary"
        data-testid="contract-create"
        @click="openCreate"
      >
        新建合同
      </el-button>
      <el-button
        v-if="canManageTemplates && !forbidden && activeTab === 'templates'"
        type="primary"
        data-testid="contract-template-create"
        @click="openTemplateDialog"
      >
        新建模板
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

    <template v-else>
      <nav class="contract-tabs" aria-label="劳动合同工作区">
        <button
          type="button"
          class="contract-tab"
          :class="{ 'contract-tab--active': activeTab === 'ledger' }"
          data-testid="contract-tab-ledger"
          @click="setTab('ledger')"
        >
          合同台账
        </button>
        <button
          type="button"
          class="contract-tab"
          :class="{ 'contract-tab--active': activeTab === 'expiry' }"
          data-testid="contract-tab-expiry"
          @click="setTab('expiry')"
        >
          到期管理
        </button>
        <button
          type="button"
          class="contract-tab"
          :class="{ 'contract-tab--active': activeTab === 'templates' }"
          data-testid="contract-tab-templates"
          @click="setTab('templates')"
        >
          合同模板
        </button>
      </nav>

      <ErrorState
        v-if="activeTab !== 'templates' && state === 'error'"
        data-testid="contracts-error"
        title="劳动合同台账暂时无法加载"
        :description="errorMessage"
        :request-id="errorRequestId"
        @retry="load"
      />

      <LoadingState
        v-else-if="activeTab !== 'templates' && (loading || state === 'idle')"
        :rows="6"
        label="正在加载劳动合同台账"
      />

      <ErrorState
        v-else-if="activeTab === 'templates' && templatesState === 'error'"
        data-testid="contract-templates-error"
        title="合同模板暂时无法加载"
        :description="templatesErrorMessage"
        @retry="loadTemplates"
      />

      <LoadingState
        v-else-if="activeTab === 'templates' && (templatesState === 'loading' || templatesState === 'idle')"
        :rows="4"
        label="正在加载合同模板"
      />

      <template v-else>
        <el-alert
          v-if="isPartial"
          type="warning"
          :closable="false"
          show-icon
          data-testid="contracts-partial"
        >
          <template #title>
            部分数据：{{ activeTab === 'templates' ? templates.partial_reason : result.partial_reason ?? '服务端只返回了部分记录，请刷新后重试。' }}
          </template>
        </el-alert>

        <el-alert v-if="employeeLookupError !== '' && activeTab !== 'templates'" type="warning" :closable="false" show-icon>
          <template #title>{{ employeeLookupError }}</template>
        </el-alert>

        <section v-if="activeTab !== 'templates'" class="panel contract-toolbar" aria-label="合同筛选">
          <div class="panel__body contract-toolbar__body">
            <form v-if="activeTab === 'ledger'" class="contract-toolbar__search" @submit.prevent="submitSearch">
              <span>搜索</span>
              <div class="contract-toolbar__search-row">
                <el-input
                  v-model="search"
                  clearable
                  placeholder="员工姓名、工号或合同编号"
                  aria-label="搜索劳动合同"
                  data-testid="contract-search"
                />
                <button type="submit" class="contract-filter" data-testid="contract-search-submit">搜索</button>
              </div>
              <small>筛选由服务端执行，翻页后仍保持。</small>
            </form>
            <div v-if="activeTab === 'expiry'" class="contract-toolbar__filters">
              <span class="contract-toolbar__label">到期窗口</span>
              <button
                v-for="window in (['7', '30', '60'] as ExpiryWindow[])"
                :key="window"
                type="button"
                class="contract-filter"
                :class="{ 'contract-filter--active': expiryWindow === window }"
                :data-testid="`contract-expiry-${window}`"
                @click="setExpiryWindow(window)"
              >
                {{ window }} 天内
              </button>
            </div>
            <span class="contract-toolbar__asof">业务日期 {{ asOf }}</span>
          </div>
        </section>

        <section v-else class="panel contract-toolbar" aria-label="模板筛选">
          <div class="panel__body contract-toolbar__body">
            <form class="contract-toolbar__search" @submit.prevent="loadTemplates">
              <span>搜索模板</span>
              <div class="contract-toolbar__search-row">
                <el-input
                  v-model="templateSearch"
                  clearable
                  placeholder="模板编码或名称"
                  aria-label="搜索合同模板"
                  data-testid="contract-template-search"
                />
                <button type="submit" class="contract-filter" data-testid="contract-template-search-submit">搜索</button>
              </div>
            </form>
            <span class="contract-toolbar__asof">业务日期 {{ asOf }}</span>
          </div>
        </section>

        <EmptyState
          v-if="isEmpty && activeTab === 'ledger' && appliedSearch === ''"
          data-testid="contracts-empty"
          title="暂无劳动合同"
          description="当前业务日期下没有可读取的劳动合同。可以新建草稿，或调整员工数据范围后重试。"
        />
        <EmptyState
          v-else-if="isEmpty && activeTab === 'ledger'"
          data-testid="contracts-filter-empty"
          title="没有匹配的劳动合同"
          description="服务端按员工姓名、工号或合同编号未找到匹配项。调整关键词后再试。"
        />
        <EmptyState
          v-else-if="isEmpty && activeTab === 'expiry'"
          data-testid="contracts-expiry-empty"
          title="到期窗口内没有合同"
          description="当前业务日期和到期窗口内没有即将到期的合同。切换窗口或回到台账查看全部。"
        />
        <EmptyState
          v-else-if="isEmpty && activeTab === 'templates'"
          data-testid="contract-templates-empty"
          title="暂无合同模板"
          description="尚未创建合同模板。具备模板管理权限的账号可新建草稿并发布。"
        />

        <DataTable
          v-else-if="activeTab !== 'templates'"
          data-testid="contract-table"
          :columns="contractColumns"
          :rows="contractRows"
          :total="activeTab === 'expiry' ? result.items.length : result.total"
          :page="page"
          :page-size="pageSize"
          row-key="id"
          @update:page="onPageChange"
          @update:page-size="onPageSizeChange"
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

        <DataTable
          v-else
          data-testid="contract-template-table"
          :columns="templateColumns"
          :rows="templateRows"
          :total="templates.total"
          :page="1"
          :page-size="50"
          row-key="id"
        >
          <template #cell-code="{ row }">
            <span class="mono">{{ row.code }}</span>
          </template>
          <template #cell-actions="{ row }">
            <button
              v-if="canManageTemplates && (row as unknown as ContractTemplate).status === 'DRAFT'"
              type="button"
              class="contract-action"
              :data-testid="`contract-template-publish-${(row as unknown as ContractTemplate).id}`"
              @click="publishTemplate(row as unknown as ContractTemplate)"
            >
              发布
            </button>
            <span v-else class="contract-archive-note">只读</span>
          </template>
        </DataTable>

        <p v-if="canAmend && activeTab !== 'templates'" class="contract-workspace__note">
          合同变更从详情页追加版本；历史版本与附件只读保留。
        </p>
        <p v-else-if="activeTab !== 'templates'" class="contract-workspace__note">
          只读账号可查看合同、版本与附件，不显示写入动作。
        </p>
        <p v-else class="contract-workspace__note">
          模板发布是版本化动作：发布新版本会归档同编码的旧发布版本，历史模板不删除。
        </p>
      </template>
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

    <el-dialog
      v-model="templateDialogOpen"
      title="新建合同模板草稿"
      width="560px"
      :close-on-click-modal="false"
      data-testid="contract-template-dialog"
    >
      <div class="contract-template-form">
        <label>
          <span>模板编码 *</span>
          <el-input v-model="templateDraft.code" data-testid="contract-template-code" maxlength="64" placeholder="例如 CN-FIXED" />
        </label>
        <label>
          <span>模板名称 *</span>
          <el-input v-model="templateDraft.name" data-testid="contract-template-name" maxlength="160" placeholder="例如 固定期限劳动合同" />
        </label>
        <label>
          <span>模板正文 *</span>
          <el-input
            v-model="templateDraft.body"
            data-testid="contract-template-body"
            type="textarea"
            :rows="8"
            maxlength="20000"
            placeholder="输入合同条款正文"
          />
        </label>
        <p v-if="templateError !== null" class="contract-form__error" role="alert">
          {{ templateError.message }}
          <span v-if="templateError.requestId">（请求编号 {{ templateError.requestId }}）</span>
        </p>
      </div>
      <template #footer>
        <el-button @click="templateDialogOpen = false">取消</el-button>
        <el-button
          type="primary"
          :loading="templateSaving"
          data-testid="contract-template-save"
          @click="saveTemplate"
        >
          保存草稿
        </el-button>
      </template>
    </el-dialog>
  </PageShell>
</template>

<style scoped>
.contract-tabs {
  display: flex;
  gap: 6px;
  padding: 4px;
  margin-bottom: 12px;
  background: var(--color-canvas);
  border: 1px solid var(--color-line);
  border-radius: 999px;
  width: fit-content;
}
.contract-tab {
  padding: 6px 14px;
  color: var(--color-ink-secondary);
  font-size: 12px;
  background: transparent;
  border: 0;
  border-radius: 999px;
  cursor: pointer;
}
.contract-tab--active {
  color: #fff;
  background: var(--color-brand);
}
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
  min-width: min(360px, 100%);
  color: var(--color-ink-muted);
  font-size: 11px;
}
.contract-toolbar__search-row {
  display: flex;
  gap: 6px;
  align-items: center;
}
.contract-toolbar__search-row :deep(.el-input) {
  flex: 1;
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
.contract-archive-note {
  color: var(--color-ink-muted);
  font-size: 11px;
}
.contract-workspace__note {
  margin: 2px 0 0;
  color: var(--color-ink-muted);
  font-size: 11px;
}
.contract-template-form {
  display: flex;
  flex-direction: column;
  gap: 12px;
  font-size: 12px;
}
.contract-template-form label {
  display: flex;
  flex-direction: column;
  gap: 4px;
  color: var(--color-ink-muted);
  font-size: 11px;
}
@media (max-width: 760px) {
  .contract-toolbar__asof {
    margin-left: 0;
  }
}
</style>
