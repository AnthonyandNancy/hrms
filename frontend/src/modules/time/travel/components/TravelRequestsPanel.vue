<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { ApiError } from '@/api/errors'
import { formatBusinessDate } from '@/utils/format'
import DataTable, { type DataTableColumn } from '@/components/DataTable.vue'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import LoadingState from '@/components/LoadingState.vue'
import NoActiveEmploymentState from '@/components/NoActiveEmploymentState.vue'
import { useSessionStore } from '@/stores/session'
import {
  applicationStatusLabel,
  attendanceLinkStatusLabel,
  cancelTravelRequest,
  getTravelRequests,
  newIdempotencyKey,
  travelChangeStatusLabel,
  travelStatusLabel,
  type TravelRequest,
} from '../api'
import TravelRequestDrawer from './TravelRequestDrawer.vue'

const props = withDefaults(
  defineProps<{
    mode: 'mine' | 'requests'
    canRequest: boolean
    canChange: boolean
    canCancel: boolean
    openTravelId?: number | null
  }>(),
  { openTravelId: null },
)

const emit = defineEmits<{
  success: [message: string]
  'detail-closed': []
}>()

const session = useSessionStore()
const hasEmployment = computed(() => (session.principal?.employmentId ?? null) !== null)

const loading = ref(false)
const loaded = ref(false)
const errorMessage = ref('')
const errorRequestId = ref<string | null>(null)
const errorCode = ref('')
const rows = ref<TravelRequest[]>([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(20)
const statusFilter = ref('')
const fromFilter = ref('')
const toFilter = ref('')
const employmentFilter = ref('')
const departmentFilter = ref('')

const detail = ref<TravelRequest | null>(null)
const detailOpen = ref(false)
const changeTarget = ref<TravelRequest | null>(null)
const changeOpen = ref(false)
const cancellingId = ref<number | null>(null)
const cancelReason = ref('')
const cancelling = ref(false)
const actionError = ref('')
const cancelKeys = reactive<Record<number, { fingerprint: string; key: string } | null>>({})

const columns: DataTableColumn[] = [
  { key: 'travelId', label: '出差编号', width: '100px' },
  { key: 'employmentId', label: '员工', width: '80px' },
  { key: 'destination', label: '目的地', minWidth: '130px' },
  { key: 'dates', label: '日期', minWidth: '210px' },
  { key: 'purpose', label: '事由', minWidth: '200px' },
  { key: 'status', label: '状态', width: '100px' },
  { key: 'applicationStatus', label: '审批状态', width: '110px' },
  { key: 'change', label: '变更', minWidth: '140px' },
  { key: 'attendanceLink', label: '考勤关联', minWidth: '150px' },
  { key: 'actions', label: '操作', minWidth: '230px' },
]

const statusOptions = [
  { value: '', label: '全部状态' },
  { value: 'PENDING_APPROVAL', label: '待审批' },
  { value: 'APPROVED', label: '已批准' },
  { value: 'REJECTED', label: '已驳回' },
  { value: 'CANCELLED', label: '已取消' },
  { value: 'COMPLETED', label: '已完成' },
]

const title = computed(() => (props.mode === 'mine' ? '我的出差' : '出差台账'))
const description = computed(() =>
  props.mode === 'mine'
    ? '提交和跟踪本人出差申请；变更、取消均通过服务端命令执行。'
    : '按服务端授权范围查看出差台账；筛选和分页在服务端完成。',
)
const showEmpty = computed(() => loaded.value && rows.value.length === 0 && errorMessage.value === '')

function captureError(reason: unknown): void {
  if (reason instanceof ApiError) {
    errorMessage.value = reason.message || '出差数据暂时无法加载。'
    errorRequestId.value = reason.requestId
    errorCode.value = reason.code
    return
  }
  errorMessage.value = '出差数据暂时无法加载。'
  errorRequestId.value = null
  errorCode.value = 'UNKNOWN'
}

async function load(): Promise<void> {
  if (props.mode === 'mine' && !hasEmployment.value) {
    loading.value = false
    loaded.value = true
    rows.value = []
    total.value = 0
    errorMessage.value = ''
    errorRequestId.value = null
    errorCode.value = ''
    actionError.value = ''
    return
  }
  loading.value = true
  loaded.value = false
  errorMessage.value = ''
  errorRequestId.value = null
  errorCode.value = ''
  actionError.value = ''

  try {
    const employment = employmentFilter.value.trim()
    const department = departmentFilter.value.trim()
    const employmentId = employment === '' ? null : Number(employment)
    const departmentId = department === '' ? null : Number(department)
    const result = await getTravelRequests({
      view: props.mode === 'mine' ? 'my' : 'team',
      status: statusFilter.value,
      from: fromFilter.value,
      to: toFilter.value,
      employmentId: employmentId !== null && Number.isSafeInteger(employmentId) ? employmentId : null,
      departmentId: departmentId !== null && Number.isSafeInteger(departmentId) ? departmentId : null,
      page: page.value,
      pageSize: pageSize.value,
    })
    rows.value = result.items
    total.value = result.total
  } catch (reason) {
    captureError(reason)
    rows.value = []
    total.value = 0
  } finally {
    loading.value = false
    loaded.value = true
  }
}

async function openDetail(row: TravelRequest): Promise<void> {
  detail.value = row
  detailOpen.value = true
}

async function openDetailById(id: number): Promise<void> {
  if (props.mode === 'mine' && !hasEmployment.value) return
  const existing = rows.value.find((row) => row.travelId === id)
  if (existing !== undefined) {
    await openDetail(existing)
    return
  }

  try {
    const result = await getTravelRequests({
      view: props.mode === 'mine' ? 'my' : 'team',
      page: 1,
      pageSize: 100,
    })
    const found = result.items.find((row) => row.travelId === id)
    if (found !== undefined) await openDetail(found)
  } catch (reason) {
    captureError(reason)
  }
}

function closeDetail(): void {
  detailOpen.value = false
  detail.value = null
  emit('detail-closed')
}

function openRequestDrawer(): void {
  if (!props.canRequest) return
  changeTarget.value = null
  changeOpen.value = true
}

function startChange(row: TravelRequest): void {
  if (!props.canChange || !['PENDING_APPROVAL', 'APPROVED'].includes(row.status)) return
  changeTarget.value = row
  changeOpen.value = true
}

function startCancel(row: TravelRequest): void {
  if (!props.canCancel || ['CANCELLED', 'COMPLETED', 'REJECTED'].includes(row.status)) return
  cancellingId.value = row.travelId
  cancelReason.value = ''
  actionError.value = ''
  cancelKeys[row.travelId] = null
}

function stopCancel(): void {
  if (cancelling.value) return
  cancellingId.value = null
  cancelReason.value = ''
}

async function submitCancel(row: TravelRequest): Promise<void> {
  if (cancelReason.value.trim().length < 3) {
    actionError.value = '取消原因至少需要 3 个字符。'
    return
  }

  const payload = { travel_id: row.travelId, reason: cancelReason.value.trim() }
  const fingerprint = JSON.stringify(payload)
  const previous = cancelKeys[row.travelId] ?? null
  cancelKeys[row.travelId] =
    previous !== null && previous.fingerprint === fingerprint
      ? previous
      : { fingerprint, key: newIdempotencyKey('travel-cancel') }

  cancelling.value = true
  actionError.value = ''
  try {
    const cancelled = await cancelTravelRequest(row.travelId, payload.reason, cancelKeys[row.travelId]!.key)
    cancellingId.value = null
    actionError.value = ''
    emit('success', `出差申请 #${row.travelId} 已取消（${travelStatusLabel(cancelled.status)}）。`)
    await load()
  } catch (reason) {
    actionError.value =
      reason instanceof ApiError
        ? `${reason.message || '取消失败。'}${reason.requestId ? ` 请求编号：${reason.requestId}` : ''}`
        : '取消失败，请稍后重试。'
  } finally {
    cancelling.value = false
  }
}

function onSubmitted(): void {
  emit('success', '出差变更已提交审批。')
  changeOpen.value = false
  changeTarget.value = null
  void load()
}

function applyFilters(): void {
  if (fromFilter.value !== '' && toFilter.value !== '' && toFilter.value < fromFilter.value) {
    actionError.value = '结束日期不能早于开始日期。'
    return
  }

  const employment = employmentFilter.value.trim()
  const department = departmentFilter.value.trim()
  if (employment !== '' && (!/^\d+$/.test(employment) || Number(employment) < 1)) {
    actionError.value = '员工 ID 必须是正整数。'
    return
  }
  if (department !== '' && (!/^\d+$/.test(department) || Number(department) < 1)) {
    actionError.value = '部门 ID 必须是正整数。'
    return
  }

  actionError.value = ''
  page.value = 1
  void load()
}

function changePage(next: number): void {
  page.value = next
  void load()
}

function changePageSize(next: number): void {
  pageSize.value = next
  page.value = 1
  void load()
}

watch(
  () => props.openTravelId,
  (id) => {
    if (id !== null && id !== undefined && id > 0) void openDetailById(id)
  },
  { immediate: true },
)

onMounted(() => {
  void load()
})
</script>

<template>
  <section class="travel-requests" data-testid="travel-requests-panel">
    <header class="travel-requests__header">
      <div>
        <h2>{{ title }}</h2>
        <p class="travel-requests__muted">{{ description }}</p>
      </div>
      <div class="travel-requests__header-actions">
        <span class="travel-requests__badge">{{ total }} 条</span>
        <button type="button" class="travel-requests__secondary" :disabled="loading" data-testid="travel-requests-refresh" @click="load">
          刷新
        </button>
        <button
          v-if="props.canRequest"
          type="button"
          class="travel-requests__primary"
          data-testid="travel-request-open"
          @click="openRequestDrawer"
        >
          申请出差
        </button>
      </div>
    </header>

    <form class="travel-requests__filters" data-testid="travel-ledger-filters" @submit.prevent="applyFilters">
      <label>
        <span>状态</span>
        <select v-model="statusFilter" data-testid="travel-status-filter">
          <option v-for="option in statusOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
        </select>
      </label>
      <label>
        <span>开始日期</span>
        <input v-model="fromFilter" data-testid="travel-from-filter" type="date" />
      </label>
      <label>
        <span>结束日期</span>
        <input v-model="toFilter" data-testid="travel-to-filter" type="date" />
      </label>
      <label v-if="mode === 'requests'">
        <span>员工 ID</span>
        <input v-model="employmentFilter" data-testid="travel-employment-filter" inputmode="numeric" placeholder="可选" />
      </label>
      <label v-if="mode === 'requests'">
        <span>部门 ID</span>
        <input v-model="departmentFilter" data-testid="travel-department-filter" inputmode="numeric" placeholder="可选" />
      </label>
      <button type="submit" class="travel-requests__secondary" :disabled="loading">应用筛选</button>
    </form>

    <p v-if="actionError" class="travel-requests__error" role="alert" data-testid="travel-requests-action-error">{{ actionError }}</p>

    <NoActiveEmploymentState
      v-if="mode === 'mine' && !hasEmployment"
      title="当前账号未绑定员工"
      description="本人出差需要员工任职绑定；出差台账与政策视图仍按授权范围使用。"
    />

    <div v-else-if="loading" data-testid="travel-requests-loading">
      <LoadingState :rows="5" label="正在加载出差数据" />
    </div>
    <ErrorState
      v-else-if="errorMessage !== ''"
      data-testid="travel-requests-error"
      title="出差数据暂时无法加载"
      :description="errorMessage"
      :code="errorCode"
      :request-id="errorRequestId"
      @retry="load"
    />
    <template v-else>
      <EmptyState
        v-if="showEmpty"
        data-testid="travel-requests-empty"
        title="暂无出差申请"
        :description="mode === 'mine' ? '当前没有本人出差申请。' : '当前筛选条件下没有服务端返回的出差记录。'"
      />
      <DataTable
        v-else
        :columns="columns"
        :rows="rows as unknown as Array<Record<string, unknown>>"
        :total="total"
        :page="page"
        :page-size="pageSize"
        row-key="travelId"
        :row-test-id="(row) => `travel-row-${String(row.travelId)}`"
        data-testid="travel-request-table"
        empty-title="暂无出差申请"
        @update:page="changePage"
        @update:page-size="changePageSize"
      >
        <template #cell-travelId="{ row }"><span class="mono">#{{ row.travelId }}</span></template>
        <template #cell-dates="{ row }">
          {{ formatBusinessDate(String(row.fromDate)) }} 至 {{ formatBusinessDate(String(row.toDate)) }}
        </template>
        <template #cell-purpose="{ row }"><span class="travel-requests__wrap">{{ row.purpose }}</span></template>
        <template #cell-status="{ row }">{{ travelStatusLabel(String(row.status)) }}</template>
        <template #cell-applicationStatus="{ row }">{{ applicationStatusLabel((row.applicationStatus as string | null) ?? null) }}</template>
        <template #cell-change="{ row }">
          <template v-if="row.change">
            {{ travelChangeStatusLabel(String((row.change as Record<string, unknown>).status)) }}
            <span class="travel-requests__muted">#{{ (row.change as Record<string, unknown>).changeId }}</span>
          </template>
          <span v-else class="travel-requests__muted">无</span>
        </template>
        <template #cell-attendanceLink="{ row }">
          <span
            v-if="row.attendanceLink"
            :data-testid="`travel-attendance-link-${String(row.travelId)}`"
            class="travel-requests__link"
          >
            {{ attendanceLinkStatusLabel(String((row.attendanceLink as Record<string, unknown>).status)) }}
          </span>
          <span v-else class="travel-requests__muted">未生成</span>
        </template>
        <template #cell-actions="{ row }">
          <div class="travel-requests__row-actions">
            <button
              type="button"
              class="travel-requests__tertiary"
              :data-testid="`travel-detail-${String(row.travelId)}`"
              @click="openDetail(row as unknown as TravelRequest)"
            >
              详情
            </button>
            <button
              v-if="canChange && ['PENDING_APPROVAL', 'APPROVED'].includes(String(row.status))"
              type="button"
              class="travel-requests__tertiary"
              :data-testid="`travel-change-${String(row.travelId)}`"
              @click="startChange(row as unknown as TravelRequest)"
            >
              变更
            </button>
            <button
              v-if="canCancel && !['CANCELLED', 'COMPLETED', 'REJECTED'].includes(String(row.status))"
              type="button"
              class="travel-requests__danger"
              :data-testid="`travel-cancel-${String(row.travelId)}`"
              @click="startCancel(row as unknown as TravelRequest)"
            >
              取消
            </button>

            <div v-if="cancellingId === row.travelId" class="travel-requests__cancel-box">
              <label>
                <span>取消原因</span>
                <input v-model="cancelReason" :data-testid="`travel-cancel-reason-${String(row.travelId)}`" maxlength="500" />
              </label>
              <div class="travel-requests__row-actions">
                <button type="button" class="travel-requests__tertiary" :disabled="cancelling" @click="stopCancel">返回</button>
                <button
                  type="button"
                  class="travel-requests__danger"
                  :disabled="cancelling"
                  :data-testid="`travel-cancel-confirm-${String(row.travelId)}`"
                  @click="submitCancel(row as unknown as TravelRequest)"
                >
                  {{ cancelling ? '取消中…' : '确认取消' }}
                </button>
              </div>
            </div>
          </div>
        </template>
      </DataTable>
    </template>

    <div v-if="detailOpen && detail" class="travel-requests__drawer" role="dialog" aria-modal="true" data-testid="travel-detail-drawer">
      <section class="travel-requests__drawer-panel">
        <header class="travel-requests__drawer-header">
          <div>
            <p class="travel-requests__muted">出差详情</p>
            <h3>#{{ detail.travelId }} · {{ detail.destination ?? '未填写目的地' }}</h3>
          </div>
          <button type="button" class="travel-requests__tertiary" data-testid="travel-detail-close" @click="closeDetail">关闭</button>
        </header>
        <dl class="travel-requests__detail-grid">
          <div><dt>日期</dt><dd>{{ formatBusinessDate(detail.fromDate) }} 至 {{ formatBusinessDate(detail.toDate) }}</dd></div>
          <div><dt>状态</dt><dd>{{ travelStatusLabel(detail.status) }}</dd></div>
          <div><dt>审批状态</dt><dd>{{ applicationStatusLabel(detail.applicationStatus) }}</dd></div>
          <div><dt>审批案例</dt><dd>{{ detail.caseNo ?? (detail.caseId === null ? '—' : `#${detail.caseId}`) }}</dd></div>
          <div class="travel-requests__detail-wide"><dt>事由</dt><dd>{{ detail.purpose }}</dd></div>
          <div class="travel-requests__detail-wide">
            <dt>考勤关联</dt>
            <dd data-testid="travel-attendance-link">
              <template v-if="detail.attendanceLink">
                {{ attendanceLinkStatusLabel(detail.attendanceLink.status) }}：
                {{ formatBusinessDate(detail.attendanceLink.fromDate) }} 至 {{ formatBusinessDate(detail.attendanceLink.toDate) }}
                <span v-if="detail.attendanceLink.retireReason" class="travel-requests__muted">（{{ detail.attendanceLink.retireReason }}）</span>
              </template>
              <template v-else>尚未生成；仅审批通过的行程会写入考勤关联。</template>
            </dd>
          </div>
          <div v-if="detail.change" class="travel-requests__detail-wide">
            <dt>变更</dt>
            <dd>{{ travelChangeStatusLabel(detail.change.status) }} · {{ detail.change.reason }}</dd>
          </div>
        </dl>
      </section>
    </div>

    <TravelRequestDrawer
      v-model="changeOpen"
      :mode="changeTarget === null ? 'request' : 'change'"
      :request="changeTarget"
      @submitted="onSubmitted"
    />
  </section>
</template>

<style scoped>
.travel-requests { display: grid; gap: 14px; }
.travel-requests__header { display: flex; justify-content: space-between; gap: 16px; align-items: flex-start; }
.travel-requests__header h2 { margin: 0; font-size: 18px; }
.travel-requests__muted { margin: 3px 0 0; color: #64748b; font-size: 11.5px; line-height: 1.5; }
.travel-requests__header-actions { display: flex; gap: 8px; align-items: center; }
.travel-requests__badge { padding: 3px 8px; color: #1d4ed8; font-size: 10.5px; background: #eff6ff; border-radius: 999px; }
.travel-requests__filters { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; padding: 12px; background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; }
.travel-requests__filters label, .travel-requests__cancel-box label { display: flex; flex-direction: column; gap: 3px; font-size: 11px; }
.travel-requests__filters select, .travel-requests__filters input, .travel-requests__cancel-box input { min-width: 140px; padding: 6px 8px; font: inherit; font-size: 11.5px; border: 1px solid #cbd5e1; border-radius: 6px; }
.travel-requests__row-actions { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
.travel-requests__cancel-box { display: grid; gap: 6px; padding: 7px; background: #fff7ed; border: 1px solid #fed7aa; border-radius: 7px; }
.travel-requests__wrap { max-width: 280px; white-space: normal; word-break: break-word; }
.travel-requests__link { color: #047857; font-size: 11px; }
.travel-requests__drawer { position: fixed; inset: 0; z-index: 35; display: flex; justify-content: flex-end; background: rgb(15 23 42 / 35%); }
.travel-requests__drawer-panel { width: min(560px, 100vw); height: 100%; overflow-y: auto; background: #fff; box-shadow: -8px 0 24px rgb(15 23 42 / 18%); }
.travel-requests__drawer-header { display: flex; justify-content: space-between; gap: 14px; padding: 18px 20px; border-bottom: 1px solid #e2e8f0; }
.travel-requests__drawer-header h3 { margin: 4px 0 0; font-size: 17px; }
.travel-requests__detail-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px 18px; padding: 18px 20px; margin: 0; }
.travel-requests__detail-grid dt { color: #64748b; font-size: 10.5px; }
.travel-requests__detail-grid dd { margin: 3px 0 0; font-size: 12.5px; line-height: 1.55; }
.travel-requests__detail-wide { grid-column: 1 / -1; }
.travel-requests__primary, .travel-requests__secondary, .travel-requests__tertiary, .travel-requests__danger { padding: 6px 11px; font-size: 11.5px; border-radius: 6px; cursor: pointer; }
.travel-requests__primary { color: #fff; background: #1d4ed8; border: 1px solid #1d4ed8; }
.travel-requests__secondary { color: #334155; background: #fff; border: 1px solid #cbd5e1; }
.travel-requests__tertiary { color: #1d4ed8; background: transparent; border: 1px solid #bfdbfe; }
.travel-requests__danger { color: #b91c1c; background: #fff; border: 1px solid #fecaca; }
.travel-requests__primary:disabled, .travel-requests__secondary:disabled, .travel-requests__tertiary:disabled, .travel-requests__danger:disabled { cursor: not-allowed; opacity: 0.55; }
.travel-requests__error { margin: 0; padding: 9px 12px; color: #991b1b; font-size: 11.5px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; }
.mono { font-family: ui-monospace, monospace; }
@media (max-width: 820px) {
  .travel-requests__header { flex-direction: column; }
  .travel-requests__detail-grid { grid-template-columns: minmax(0, 1fr); }
}
</style>
