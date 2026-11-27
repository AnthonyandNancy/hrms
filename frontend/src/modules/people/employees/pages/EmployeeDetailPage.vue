<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessageBox } from 'element-plus'
import { ApiError, errorCopy } from '@/api/errors'
import { useSessionStore } from '@/stores/session'
import { requestIdFromError } from '@/utils/renderErrors'
import LoadingState from '@/components/LoadingState.vue'
import ErrorState from '@/components/ErrorState.vue'
import PageShell from '@/shared/ui/PageShell.vue'
import EmployeeSummary from '../components/EmployeeSummary.vue'
import EmployeeTabs from '../components/EmployeeTabs.vue'
import EmployeeProfileSection from '../components/EmployeeProfileSection.vue'
import EmployeeFileList from '../components/EmployeeFileList.vue'
import EmployeeChangeList from '../components/EmployeeChangeList.vue'
import {
  archiveEmployee,
  getEmployee,
  restoreEmployee,
  type EmployeeRecord,
  type EmployeeTabKey,
} from '../detailApi'

defineOptions({ name: 'EmployeeDetailPage' })

const route = useRoute()
const router = useRouter()
const session = useSessionStore()

const TAB_KEYS: readonly EmployeeTabKey[] = [
  'overview',
  'employment',
  'contracts',
  'credentials',
  'education',
  'experience',
  'contacts',
  'changes',
]

const TAB_ALIASES: Record<string, EmployeeTabKey> = {
  overview: 'overview',
  employment: 'employment',
  contracts: 'contracts',
  credentials: 'credentials',
  files: 'credentials',
  education: 'education',
  experience: 'experience',
  history: 'employment',
  'work-history': 'experience',
  contacts: 'contacts',
  changes: 'changes',
}

const loading = ref(false)
const loaded = ref(false)
const notFound = ref(false)
const forbidden = ref(false)
const pageError = ref<string | null>(null)
const requestId = ref<string | null>(null)
const employee = ref<EmployeeRecord | null>(null)
const busy = ref(false)
const actionError = ref<string | null>(null)
const tabOverride = ref<EmployeeTabKey | null>(null)

function routeEmploymentId(): number | null {
  const raw = route.params.employment_id ?? route.params.id
  if (typeof raw !== 'string' || !/^\d+$/.test(raw)) return null
  const parsed = Number(raw)
  return Number.isSafeInteger(parsed) && parsed > 0 ? parsed : null
}

const employmentId = computed(() => routeEmploymentId())

function normaliseQueryTab(value: unknown): EmployeeTabKey {
  if (typeof value !== 'string') return 'overview'
  return TAB_ALIASES[value] ?? 'overview'
}

const activeTab = computed<EmployeeTabKey>(() => {
  if (tabOverride.value !== null) return tabOverride.value
  return normaliseQueryTab(route.query.tab)
})

const canEdit = computed(() => session.can('people.employee.manage'))
const canArchive = computed(() => session.can('people.employee.archive'))
const canRestore = computed(() => session.can('people.employee.restore'))

const displayName = computed(() => {
  const value = employee.value?.person_name
  return typeof value === 'string' && value.trim() !== '' ? value : '员工详情'
})

const displayEmployeeNo = computed(() => {
  const value = employee.value?.employee_no
  return typeof value === 'string' && value.trim() !== '' ? value : null
})

const pageTitle = computed(() =>
  displayEmployeeNo.value === null ? displayName.value : `${displayName.value} · ${displayEmployeeNo.value}`,
)

const identity = computed(() =>
  displayEmployeeNo.value === null ? `「${displayName.value}」` : `「${displayName.value}（${displayEmployeeNo.value}）」`,
)

/** List filters are preserved on the detail URL so back navigation is lossless. */
function listQuery(): Record<string, string> {
  const query: Record<string, string> = {}
  for (const [key, value] of Object.entries(route.query)) {
    if (typeof value === 'string' && key !== 'tab') query[key] = value
  }
  return query
}

function backToList(): void {
  void router.push({ path: '/hr/employees', query: listQuery() })
}

function setTab(tab: EmployeeTabKey): void {
  tabOverride.value = tab
  const query: Record<string, string> = listQuery()
  query.tab = tab
  void router.replace({ path: route.path, query })
}

function onTabChange(tab: EmployeeTabKey): void {
  if (!TAB_KEYS.includes(tab)) return
  setTab(tab)
}

function editEmployee(): void {
  actionError.value = null
  // Core employee data is versioned and approval-bound; the truthful edit flow
  // is the change-request tab, not a client-side overwrite of the read model.
  setTab('changes')
}

async function load(): Promise<void> {
  loading.value = true
  loaded.value = false
  notFound.value = false
  forbidden.value = false
  pageError.value = null
  requestId.value = null
  employee.value = null
  tabOverride.value = null

  const id = employmentId.value
  if (id === null) {
    notFound.value = true
    loaded.value = true
    loading.value = false
    return
  }

  try {
    employee.value = await getEmployee(id)
  } catch (reason) {
    if (reason instanceof ApiError && (reason.status === 404 || reason.code === 'NOT_FOUND')) {
      notFound.value = true
      requestId.value = requestIdFromError(reason)
    } else if (reason instanceof ApiError && (reason.status === 403 || reason.code === 'FORBIDDEN')) {
      // An out-of-scope employee is indistinguishable from an unknown one at
      // the API boundary; the page must not leak the record or its request id.
      forbidden.value = true
    } else {
      pageError.value = errorCopy(reason)
      requestId.value = requestIdFromError(reason)
    }
  } finally {
    loading.value = false
    loaded.value = true
  }
}

async function archive(): Promise<void> {
  const id = employmentId.value
  if (id === null || employee.value === null || busy.value) return
  actionError.value = null

  let reason: string
  try {
    const result = await ElMessageBox.prompt(
      `确认归档员工${identity.value}？归档只逻辑停用员工，历史、合同与审批记录都会保留。请输入归档原因（至少 3 个字符）。`,
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

  if (reason.length < 3) {
    actionError.value = '归档原因至少需要 3 个字符。'
    return
  }

  busy.value = true
  try {
    await archiveEmployee(id, reason)
    await load()
  } catch (reasonValue) {
    actionError.value = errorCopy(reasonValue)
  } finally {
    busy.value = false
  }
}

async function restore(): Promise<void> {
  const id = employmentId.value
  if (id === null || employee.value === null || busy.value) return
  actionError.value = null

  try {
    await ElMessageBox.confirm(
      `确认恢复员工${identity.value}？恢复后该员工会重新出现在员工列表中，归档原因会被清空。`,
      '恢复员工',
      {
        confirmButtonText: '确认恢复',
        cancelButtonText: '取消',
        type: 'warning',
      },
    )
  } catch {
    return
  }

  busy.value = true
  try {
    await restoreEmployee(id)
    await load()
  } catch (reasonValue) {
    actionError.value = errorCopy(reasonValue)
  } finally {
    busy.value = false
  }
}

onMounted(() => {
  void load()
})

watch(
  () => employmentId.value,
  (next, previous) => {
    if (next === previous) return
    void load()
  },
)

watch(
  () => route.query.tab,
  () => {
    tabOverride.value = null
  },
)
</script>

<template>
  <PageShell :title="pageTitle" description="同一员工对象、同一任务范围的员工详情工作区；不混入薪资、考勤或绩效页签。">
    <template #actions>
      <button type="button" class="employee-detail__back" data-testid="back-to-list" @click="backToList">
        返回员工列表
      </button>
    </template>

    <LoadingState v-if="loading" :rows="5" label="正在加载员工详情" />

    <section v-else-if="notFound" class="employee-detail__state" data-testid="employee-not-found" role="status">
      <p class="employee-detail__state-code">404</p>
      <h2>员工不存在或不在你的数据范围内</h2>
      <p>链接可能已失效，或该员工已被移出你的授权范围。</p>
      <p v-if="requestId" class="employee-detail__request">
        请求编号：<span class="employee-detail__mono">{{ requestId }}</span>
      </p>
      <button type="button" class="employee-detail__button" @click="backToList">返回员工列表</button>
    </section>

    <section v-else-if="forbidden" class="employee-detail__state employee-detail__state--forbidden" data-testid="employee-forbidden" role="status">
      <p class="employee-detail__state-code">403</p>
      <h2>无权查看</h2>
      <p>由于数据范围或字段权限限制，当前账号不能查看该员工的档案。本页不会透露该员工是否存在。</p>
      <button type="button" class="employee-detail__button" @click="backToList">返回员工列表</button>
    </section>

    <ErrorState
      v-else-if="pageError"
      title="员工详情暂时无法加载"
      :description="pageError"
      :request-id="requestId"
      @retry="load"
    />

    <template v-else-if="loaded && employee !== null">
      <div v-if="actionError" class="employee-detail__action-error" role="alert">{{ actionError }}</div>
      <EmployeeSummary
        :employee="employee"
        :can-edit="canEdit"
        :can-archive="canArchive"
        :can-restore="canRestore"
        :busy="busy"
        @edit="editEmployee"
        @archive="archive"
        @restore="restore"
      />
      <EmployeeTabs
        :employment-id="employee.employment_id"
        :initial-tab="activeTab"
        @update:tab="onTabChange"
      />

      <!--
        Sub-profile tabs render their editable workspace once, after the tabs
        shell, and EmployeeTabs only shows read-only summaries. This prevents
        the duplicate education/experience/contact/certificate/change tables
        that appeared when the same resource was mounted twice.
      -->
      <EmployeeProfileSection
        v-if="activeTab === 'education'"
        class="employee-detail__profile"
        :employment-id="employee.employment_id"
        resource="education"
      />
      <EmployeeProfileSection
        v-else-if="activeTab === 'experience'"
        class="employee-detail__profile"
        :employment-id="employee.employment_id"
        resource="experiences"
      />
      <EmployeeProfileSection
        v-else-if="activeTab === 'contacts'"
        class="employee-detail__profile"
        :employment-id="employee.employment_id"
        resource="contacts"
      />
      <EmployeeProfileSection
        v-else-if="activeTab === 'credentials'"
        class="employee-detail__profile"
        :employment-id="employee.employment_id"
        resource="certificates"
      />
      <EmployeeFileList
        v-if="activeTab === 'credentials'"
        class="employee-detail__profile"
        :employment-id="employee.employment_id"
      />
      <EmployeeChangeList
        v-if="activeTab === 'changes'"
        class="employee-detail__profile"
        :employment-id="employee.employment_id"
      />
    </template>
  </PageShell>
</template>

<style scoped>
.employee-detail__back,
.employee-detail__button {
  padding: 7px 14px;
  font: inherit;
  font-size: 13px;
  cursor: pointer;
  background: #fff;
  border: 1px solid var(--color-line, #d1d5db);
  border-radius: 6px;
}

.employee-detail__state {
  max-width: 600px;
  padding: 32px;
  text-align: center;
  background: var(--color-surface, #fff);
  border: 1px solid var(--color-line, #e5e7eb);
  border-radius: var(--radius-md, 10px);
}

.employee-detail__state--forbidden {
  border-color: #fecaca;
}

.employee-detail__state-code {
  margin: 0;
  color: var(--color-ink-muted, #6b7280);
  font-size: 13px;
  font-weight: 700;
  letter-spacing: 0.16em;
}

.employee-detail__state h2 {
  margin: 8px 0;
  font-size: 20px;
}

.employee-detail__state p {
  color: var(--color-ink-secondary, #4b5563);
  font-size: 13px;
}

.employee-detail__request {
  margin: 12px 0;
}

.employee-detail__mono {
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
}

.employee-detail__profile {
  display: flex;
  flex-direction: column;
  gap: 16px;
  margin-top: 16px;
}

.employee-detail__action-error {
  padding: 10px 12px;
  color: #b91c1c;
  font-size: 13px;
  background: #fef2f2;
  border: 1px solid #fecaca;
  border-radius: 8px;
}
</style>
