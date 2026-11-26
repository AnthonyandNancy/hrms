<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { ApiError, errorCopy } from '@/api/errors'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import LoadingState from '@/components/LoadingState.vue'
import PageShell from '@/shared/ui/PageShell.vue'
import { useSessionStore } from '@/stores/session'
import { formatBusinessDate, formatDateTimeInTimezone, formatMoney } from '@/utils/format'
import { requestIdFromError } from '@/utils/renderErrors'
import RequisitionFormDrawer from '../components/RequisitionFormDrawer.vue'
import {
  REQUISITION_STATUS_OPTIONS,
  closeRequisition,
  createRequisition,
  isForbidden,
  listOrgUnits,
  listRequisitions,
  updateRequisition,
  type OrgUnitOption,
  type Requisition,
  type RequisitionInput,
  type RequisitionPatch,
} from '../api'

defineOptions({ name: 'RequisitionWorkspacePage' })

type LoadState = 'idle' | 'loading' | 'ready' | 'error' | 'forbidden'

const session = useSessionStore()
const canRead = computed(() => session.can('recruitment.requisition.read'))
const canManage = computed(() => session.can('recruitment.requisition.manage'))

const state = ref<LoadState>('idle')
const rows = ref<Requisition[]>([])
const orgUnits = ref<OrgUnitOption[]>([])
const orgLookupError = ref('')
const pageError = ref<unknown>(null)
const statusFilter = ref('')
const search = ref('')

const drawerOpen = ref(false)
const drawerMode = ref<'create' | 'edit'>('create')
const editing = ref<Requisition | null>(null)
const saving = ref(false)
const saveError = ref<ApiError | null>(null)

const closeTarget = ref<Requisition | null>(null)
const closeReason = ref('')
const closing = ref(false)
const closeError = ref('')

let requestSequence = 0

const loading = computed(() => state.value === 'idle' || state.value === 'loading')
const forbidden = computed(() => !canRead.value || state.value === 'forbidden')
const isEmpty = computed(() => state.value === 'ready' && rows.value.length === 0)
const partial = computed(() => orgLookupError.value !== '' && state.value === 'ready')
const errorMessage = computed(() => {
  if (pageError.value === null) return '招聘需求暂时无法加载。'
  if (pageError.value instanceof ApiError && pageError.value.message !== '') return pageError.value.message
  return errorCopy(pageError.value)
})
const errorRequestId = computed(() =>
  pageError.value === null ? null : requestIdFromError(pageError.value),
)

async function load(): Promise<void> {
  if (!canRead.value) {
    state.value = 'forbidden'
    return
  }

  const sequence = ++requestSequence
  state.value = 'loading'
  pageError.value = null
  orgLookupError.value = ''

  const [requisitions, units] = await Promise.allSettled([
    listRequisitions({ status: statusFilter.value, search: search.value }),
    listOrgUnits(),
  ])

  if (sequence !== requestSequence) return

  if (units.status === 'fulfilled') {
    orgUnits.value = units.value
  } else {
    orgUnits.value = []
    orgLookupError.value = '部分数据：组织单元目录加载失败，组织名称以“组织单元 #编号”展示，创建前需恢复目录读取。'
  }

  if (requisitions.status === 'rejected') {
    rows.value = []
    pageError.value = requisitions.reason
    state.value = isForbidden(requisitions.reason) ? 'forbidden' : 'error'
    return
  }

  rows.value = requisitions.value.items
  state.value = 'ready'
}

function openCreate(): void {
  if (!canManage.value) return
  drawerMode.value = 'create'
  editing.value = null
  saveError.value = null
  drawerOpen.value = true
}

function openEdit(requisition: Requisition): void {
  if (!canManage.value || requisition.status === 'CLOSED') return
  drawerMode.value = 'edit'
  editing.value = requisition
  saveError.value = null
  drawerOpen.value = true
}

function replaceRow(next: Requisition): void {
  const index = rows.value.findIndex((row) => row.id === next.id)
  if (index === -1) {
    rows.value = [next, ...rows.value]
    return
  }
  rows.value = rows.value.map((row) => (row.id === next.id ? next : row))
}

async function save(input: RequisitionInput | RequisitionPatch): Promise<void> {
  saving.value = true
  saveError.value = null
  try {
    const saved =
      drawerMode.value === 'edit' && editing.value !== null
        ? await updateRequisition(editing.value.id, input as RequisitionPatch)
        : await createRequisition(input as RequisitionInput)
    replaceRow(saved)
    drawerOpen.value = false
  } catch (error) {
    saveError.value = error instanceof ApiError ? error : new ApiError('INTERNAL', errorCopy(error), { status: 500 })
  } finally {
    saving.value = false
  }
}

function askClose(requisition: Requisition): void {
  if (!canManage.value || requisition.status === 'CLOSED') return
  closeTarget.value = requisition
  closeReason.value = ''
  closeError.value = ''
}

async function confirmClose(): Promise<void> {
  if (closeTarget.value === null) return

  closing.value = true
  closeError.value = ''
  try {
    const saved = await closeRequisition(closeTarget.value.id, closeReason.value)
    replaceRow(saved)
    closeTarget.value = null
    closeReason.value = ''
  } catch (error) {
    closeError.value =
      error instanceof ApiError
        ? error.message || errorCopy(error)
        : '关闭招聘需求未完成，请重试。'
  } finally {
    closing.value = false
  }
}

function orgName(requisition: Requisition): string {
  if (requisition.orgUnitName !== null && requisition.orgUnitName !== '') return requisition.orgUnitName
  return `组织单元 #${requisition.orgUnitId}`
}

function openedLabel(value: string | null): string {
  if (value === null) return '—'
  if (/^\d{4}-\d{2}-\d{2}/.test(value)) return value.slice(0, 10)
  return formatDateTimeInTimezone(value)
}

function applyFilters(): void {
  void load()
}

onMounted(load)
onBeforeUnmount(() => {
  requestSequence += 1
})
</script>

<template>
  <PageShell
    title="招聘需求"
    description="按组织单元维护招聘需求、编制和预算。创建后进入服务端审批；审批通过前不能发布岗位或安排面试。"
  >
    <template #actions>
      <button type="button" class="requisition-workspace__secondary" :disabled="loading" @click="load">刷新</button>
      <button
        v-if="canManage && !forbidden"
        type="button"
        class="requisition-workspace__primary"
        data-testid="requisition-create"
        @click="openCreate"
      >
        新建招聘需求
      </button>
    </template>

    <section v-if="forbidden" class="requisition-workspace__forbidden" role="alert" data-testid="requisitions-forbidden">
      <h2>无权查看招聘需求</h2>
      <p>当前账号缺少 recruitment.requisition.read，页面不会发起任何招聘需求读取请求。</p>
    </section>

    <LoadingState v-else-if="loading" :rows="6" label="正在加载招聘需求" />

    <ErrorState
      v-else-if="state === 'error'"
      title="招聘需求暂时无法加载"
      :description="errorMessage"
      :request-id="errorRequestId"
      @retry="load"
    />

    <template v-else>
      <p v-if="partial" class="requisition-workspace__warning" role="status" data-testid="requisitions-partial">
        {{ orgLookupError }}
      </p>

      <section class="requisition-workspace__filters" aria-label="招聘需求筛选">
        <label>
          <span>状态</span>
          <select v-model="statusFilter" data-testid="requisition-status-filter" @change="applyFilters">
            <option v-for="option in REQUISITION_STATUS_OPTIONS" :key="option.value" :value="option.value">
              {{ option.label }}
            </option>
          </select>
        </label>
        <label>
          <span>搜索</span>
          <input
            v-model="search"
            data-testid="requisition-search"
            placeholder="岗位或需求编号"
            @keyup.enter="applyFilters"
          />
        </label>
        <button type="button" class="requisition-workspace__secondary" @click="applyFilters">查询</button>
      </section>

      <EmptyState
        v-if="isEmpty"
        title="还没有招聘需求"
        description="新建招聘需求后会进入审批流程；本页不展示演示或虚构需求。"
        :action-label="canManage ? '新建招聘需求' : undefined"
        @action="openCreate"
      />

      <div v-else class="requisition-workspace__table-wrap">
        <table class="requisition-workspace__table" data-testid="requisition-table">
          <caption class="visually-hidden">招聘需求列表</caption>
          <thead>
            <tr>
              <th scope="col">需求编号</th>
              <th scope="col">岗位</th>
              <th scope="col">组织单元</th>
              <th scope="col">编制</th>
              <th scope="col">状态</th>
              <th scope="col">预算</th>
              <th scope="col">开启日期</th>
              <th scope="col">操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="requisition in rows" :key="requisition.id" :data-testid="`requisition-row-${requisition.id}`">
              <td class="mono">{{ requisition.requisitionNo }}</td>
              <td>
                <strong>{{ requisition.title }}</strong>
                <p v-if="requisition.targetStartDate" class="requisition-workspace__sub">
                  目标到岗 {{ formatBusinessDate(requisition.targetStartDate) }}
                </p>
              </td>
              <td>{{ orgName(requisition) }}</td>
              <td>{{ requisition.headcount }} 人</td>
              <td>
                <span class="requisition-workspace__status" :class="`requisition-workspace__status--${requisition.status.toLowerCase()}`">
                  {{ requisition.statusLabel }}
                </span>
              </td>
              <td>{{ requisition.budgetMinor === null ? '未设置' : formatMoney(requisition.budgetMinor) }}</td>
              <td>{{ openedLabel(requisition.openedAt) }}</td>
              <td class="requisition-workspace__actions">
                <button
                  v-if="canManage"
                  type="button"
                  class="requisition-workspace__link"
                  :disabled="requisition.status === 'CLOSED'"
                  :data-testid="`requisition-edit-${requisition.id}`"
                  @click="openEdit(requisition)"
                >
                  编辑
                </button>
                <button
                  v-if="canManage"
                  type="button"
                  class="requisition-workspace__link requisition-workspace__link--danger"
                  :disabled="requisition.status === 'CLOSED'"
                  :data-testid="`requisition-close-${requisition.id}`"
                  @click="askClose(requisition)"
                >
                  关闭
                </button>
                <span v-if="!canManage" class="requisition-workspace__sub">只读</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <p v-if="canManage" class="requisition-workspace__note">
        关闭需求会保留历史并停止新增候选人；操作需二次确认。编辑会记录审计。
      </p>
    </template>

    <RequisitionFormDrawer
      v-model="drawerOpen"
      :mode="drawerMode"
      :requisition="editing"
      :org-units="orgUnits"
      :saving="saving"
      :server-error="saveError"
      :org-lookup-error="orgLookupError"
      @save="save"
    />


    <div v-if="closeTarget !== null" class="requisition-workspace__modal" data-testid="requisition-close-dialog">
      <section role="dialog" aria-modal="true" aria-labelledby="requisition-close-title">
        <h2 id="requisition-close-title">关闭招聘需求</h2>
        <p>
          即将关闭“{{ closeTarget.title }}”（{{ closeTarget.requisitionNo }}）。关闭后不能继续新增候选人，
          历史审批与候选人记录会保留；此操作不可在原页面撤销。
        </p>
        <label>
          <span>关闭原因</span>
          <textarea v-model="closeReason" rows="3" maxlength="500" placeholder="例如：编制取消、需求合并" />
        </label>
        <p v-if="closeError !== ''" class="requisition-workspace__error" role="alert">{{ closeError }}</p>
        <footer>
          <button type="button" class="requisition-workspace__secondary" :disabled="closing" @click="closeTarget = null">
            取消
          </button>
          <button
            type="button"
            class="requisition-workspace__danger"
            :disabled="closing"
            data-testid="requisition-close-confirm"
            @click="confirmClose"
          >
            {{ closing ? '关闭中…' : '确认关闭' }}
          </button>
        </footer>
      </section>
    </div>
  </PageShell>
</template>

<style scoped>
.requisition-workspace__primary,
.requisition-workspace__secondary,
.requisition-workspace__danger {
  height: 34px;
  padding: 0 14px;
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  background: #fff;
  color: #334155;
  cursor: pointer;
}

.requisition-workspace__primary {
  border-color: #1d4ed8;
  background: #1d4ed8;
  color: #fff;
}

.requisition-workspace__danger {
  border-color: #b91c1c;
  background: #b91c1c;
  color: #fff;
}

.requisition-workspace__forbidden {
  padding: 26px;
  border: 1px solid #fecaca;
  border-radius: 12px;
  background: #fef2f2;
  color: #7f1d1d;
}

.requisition-workspace__forbidden h2 {
  margin: 0 0 8px;
  font-size: 17px;
}

.requisition-workspace__warning {
  margin: 0;
  padding: 10px 12px;
  border: 1px solid #fed7aa;
  border-radius: 8px;
  background: #fff7ed;
  color: #9a3412;
  font-size: 12.5px;
}

.requisition-workspace__filters {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 10px;
  padding: 12px;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  background: #f8fafc;
}

.requisition-workspace__filters label {
  display: flex;
  flex-direction: column;
  gap: 4px;
  color: #475569;
  font-size: 12px;
}

.requisition-workspace__filters select,
.requisition-workspace__filters input {
  height: 32px;
  padding: 0 10px;
  border: 1px solid #cbd5e1;
  border-radius: 7px;
  background: #fff;
  font: inherit;
}

.requisition-workspace__table-wrap {
  overflow-x: auto;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
}

.requisition-workspace__table {
  width: 100%;
  border-collapse: collapse;
  font-size: 12.5px;
}

.requisition-workspace__table th,
.requisition-workspace__table td {
  padding: 10px 12px;
  border-bottom: 1px solid #eef2f7;
  text-align: left;
  vertical-align: top;
}

.requisition-workspace__table th {
  background: #f8fafc;
  color: #64748b;
  font-weight: 600;
}

.requisition-workspace__table td strong {
  color: #0f172a;
}

.requisition-workspace__sub {
  margin: 4px 0 0;
  color: #64748b;
  font-size: 11.5px;
}

.requisition-workspace__status {
  display: inline-flex;
  padding: 2px 8px;
  border-radius: 999px;
  background: #eef2ff;
  color: #3730a3;
  font-size: 11px;
  font-weight: 600;
}

.requisition-workspace__status--closed,
.requisition-workspace__status--rejected {
  background: #f1f5f9;
  color: #64748b;
}

.requisition-workspace__status--on_hold {
  background: #fff7ed;
  color: #c2410c;
}

.requisition-workspace__actions {
  display: flex;
  gap: 8px;
}

.requisition-workspace__link {
  padding: 0;
  border: 0;
  background: transparent;
  color: #1d4ed8;
  font-size: 12px;
  cursor: pointer;
}

.requisition-workspace__link--danger {
  color: #b91c1c;
}

.requisition-workspace__link:disabled {
  color: #94a3b8;
  cursor: not-allowed;
}

.requisition-workspace__note {
  margin: 0;
  color: #64748b;
  font-size: 12px;
}

.requisition-workspace__modal {
  position: fixed;
  inset: 0;
  z-index: 50;
  display: grid;
  place-items: center;
  background: rgb(15 23 42 / 42%);
}

.requisition-workspace__modal section {
  width: min(440px, 92vw);
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 22px;
  border-radius: 14px;
  background: #fff;
  box-shadow: 0 24px 60px rgb(15 23 42 / 25%);
}

.requisition-workspace__modal h2 {
  margin: 0;
  font-size: 17px;
}

.requisition-workspace__modal p {
  margin: 0;
  color: #475569;
  font-size: 12.5px;
  line-height: 1.7;
}

.requisition-workspace__modal label {
  display: flex;
  flex-direction: column;
  gap: 6px;
  color: #334155;
  font-size: 12.5px;
}

.requisition-workspace__modal textarea {
  padding: 8px 10px;
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  font: inherit;
}

.requisition-workspace__modal footer {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}

.requisition-workspace__error {
  margin: 0;
  color: #b91c1c;
  font-size: 12.5px;
}
</style>
