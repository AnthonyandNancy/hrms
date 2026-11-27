<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ApiError } from '@/api/errors'
import { formatBusinessDate } from '@/utils/format'
import DataTable, { type DataTableColumn } from '@/components/DataTable.vue'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import LoadingState from '@/components/LoadingState.vue'
import {
  applicationStatusLabel,
  approveTravelRequest,
  getTravelRequests,
  newIdempotencyKey,
  travelStatusLabel,
  type TravelRequest,
} from '../api'

const emit = defineEmits<{ success: [message: string] }>()

const loading = ref(false)
const errorMessage = ref('')
const errorCode = ref('')
const errorRequestId = ref<string | null>(null)
const rows = ref<TravelRequest[]>([])
const total = ref(0)
const actionError = ref('')
const actionMessage = ref('')
const decidingId = ref<number | null>(null)
const decision = ref<'APPROVE' | 'REJECT'>('APPROVE')
const comment = ref('')
const submitting = ref(false)
const decisionKey = ref<string | null>(null)
const decisionFingerprint = ref('')

const columns: DataTableColumn[] = [
  { key: 'travelId', label: '出差编号', width: '100px' },
  { key: 'employmentId', label: '员工', width: '80px' },
  { key: 'destination', label: '目的地', minWidth: '130px' },
  { key: 'dates', label: '日期', minWidth: '210px' },
  { key: 'purpose', label: '事由', minWidth: '190px' },
  { key: 'status', label: '状态', width: '100px' },
  { key: 'applicationStatus', label: '审批状态', width: '110px' },
  { key: 'actions', label: '审批', minWidth: '190px' },
]

const showEmpty = computed(() => !loading.value && errorMessage.value === '' && rows.value.length === 0)

function captureError(reason: unknown): void {
  if (reason instanceof ApiError) {
    errorMessage.value = reason.message || '审批队列暂时无法加载。'
    errorCode.value = reason.code
    errorRequestId.value = reason.requestId
    return
  }
  errorMessage.value = '审批队列暂时无法加载。'
  errorCode.value = 'UNKNOWN'
  errorRequestId.value = null
}

async function load(): Promise<void> {
  loading.value = true
  errorMessage.value = ''
  actionError.value = ''
  try {
    const result = await getTravelRequests({ view: 'my_approvals', page: 1, pageSize: 100 })
    rows.value = result.items.filter((row) => row.status === 'PENDING_APPROVAL')
    total.value = result.total
  } catch (reason) {
    captureError(reason)
    rows.value = []
    total.value = 0
  } finally {
    loading.value = false
  }
}

function startDecision(row: TravelRequest, next: 'APPROVE' | 'REJECT'): void {
  decidingId.value = row.travelId
  decision.value = next
  comment.value = ''
  actionError.value = ''
  decisionKey.value = null
  decisionFingerprint.value = ''
}

function stopDecision(): void {
  if (submitting.value) return
  decidingId.value = null
  comment.value = ''
}

async function submitDecision(row: TravelRequest): Promise<void> {
  if (decidingId.value === null) return
  const payload = { travel_id: row.travelId, decision: decision.value, comment: comment.value.trim() }
  const fingerprint = JSON.stringify(payload)
  if (decisionKey.value === null || decisionFingerprint.value !== fingerprint) {
    decisionKey.value = newIdempotencyKey(`travel-${decision.value.toLowerCase()}`)
    decisionFingerprint.value = fingerprint
  }

  submitting.value = true
  actionError.value = ''
  try {
    const decided = await approveTravelRequest(row.travelId, decision.value, payload.comment, decisionKey.value)
    actionMessage.value =
      decision.value === 'APPROVE'
        ? `出差申请 #${row.travelId} 已批准（${travelStatusLabel(decided.status)}）。`
        : `出差申请 #${row.travelId} 已驳回（${travelStatusLabel(decided.status)}）。`
    decidingId.value = null
    emit('success', actionMessage.value)
    await load()
  } catch (reason) {
    actionError.value =
      reason instanceof ApiError
        ? `${reason.message || '审批未完成。'}${reason.requestId ? ` 请求编号：${reason.requestId}` : ''}`
        : '审批未完成，请稍后重试。'
  } finally {
    submitting.value = false
  }
}

onMounted(() => {
  void load()
})
</script>

<template>
  <section class="travel-approval" data-testid="travel-approval-panel">
    <header class="travel-approval__header">
      <div>
        <h2>出差审批</h2>
        <p class="travel-approval__muted">
          待办来源为真实 Workflow 审批队列；批准或驳回会写入工作流决策并同步行程与考勤关联状态。
        </p>
      </div>
      <div class="travel-approval__header-actions">
        <span class="travel-approval__badge">{{ rows.length }} 条待审批</span>
        <button type="button" class="travel-approval__secondary" data-testid="travel-approval-refresh" :disabled="loading" @click="load">
          刷新
        </button>
      </div>
    </header>

    <p v-if="actionMessage" class="travel-approval__success" role="status" data-testid="travel-approval-success">{{ actionMessage }}</p>
    <p v-if="actionError" class="travel-approval__error" role="alert" data-testid="travel-approval-action-error">{{ actionError }}</p>

    <div v-if="loading" data-testid="travel-approval-loading">
      <LoadingState :rows="4" label="正在加载出差审批队列" />
    </div>
    <ErrorState
      v-else-if="errorMessage !== ''"
      data-testid="travel-approval-error"
      title="出差审批暂时无法加载"
      :description="errorMessage"
      :code="errorCode"
      :request-id="errorRequestId"
      @retry="load"
    />
    <EmptyState
      v-else-if="showEmpty"
      data-testid="travel-approval-empty"
      title="没有待审批的出差申请"
      description="当前账号的管理范围内没有待处理 Workflow 待办。"
    />
    <DataTable
      v-else
      :columns="columns"
      :rows="rows as unknown as Array<Record<string, unknown>>"
      :total="total"
      :page="1"
      :page-size="Math.max(1, rows.length)"
      row-key="travelId"
      :row-test-id="(row) => `travel-approval-row-${String(row.travelId)}`"
      data-testid="travel-approval-table"
      empty-title="没有待审批的出差申请"
    >
      <template #cell-travelId="{ row }"><span class="mono">#{{ row.travelId }}</span></template>
      <template #cell-dates="{ row }">
        {{ formatBusinessDate(String(row.fromDate)) }} 至 {{ formatBusinessDate(String(row.toDate)) }}
      </template>
      <template #cell-purpose="{ row }"><span class="travel-approval__wrap">{{ row.purpose }}</span></template>
      <template #cell-status="{ row }">{{ travelStatusLabel(String(row.status)) }}</template>
      <template #cell-applicationStatus="{ row }">{{ applicationStatusLabel((row.applicationStatus as string | null) ?? null) }}</template>
      <template #cell-actions="{ row }">
        <div v-if="decidingId === row.travelId" class="travel-approval__decision">
          <label>
            <span>审批意见</span>
            <input
              v-model="comment"
              :data-testid="`travel-decision-comment-${String(row.travelId)}`"
              maxlength="4000"
              placeholder="可填写审批说明"
            />
          </label>
          <div class="travel-approval__decision-actions">
            <button type="button" class="travel-approval__tertiary" :disabled="submitting" @click="stopDecision">返回</button>
            <button
              type="button"
              class="travel-approval__primary"
              :disabled="submitting"
              :data-testid="`travel-approve-${String(row.travelId)}`"
              @click="submitDecision(row as unknown as TravelRequest)"
            >
              {{ submitting ? '处理中…' : decision === 'APPROVE' ? '确认批准' : '确认驳回' }}
            </button>
          </div>
        </div>
        <div v-else class="travel-approval__decision-actions">
          <button
            type="button"
            class="travel-approval__primary"
            :data-testid="`travel-approve-start-${String(row.travelId)}`"
            @click="startDecision(row as unknown as TravelRequest, 'APPROVE')"
          >
            批准
          </button>
          <button
            type="button"
            class="travel-approval__danger"
            :data-testid="`travel-reject-start-${String(row.travelId)}`"
            @click="startDecision(row as unknown as TravelRequest, 'REJECT')"
          >
            驳回
          </button>
        </div>
      </template>
    </DataTable>
  </section>
</template>

<style scoped>
.travel-approval { display: grid; gap: 14px; }
.travel-approval__header { display: flex; justify-content: space-between; gap: 16px; align-items: flex-start; }
.travel-approval__header h2 { margin: 0; font-size: 18px; }
.travel-approval__muted { margin: 3px 0 0; color: #64748b; font-size: 11.5px; line-height: 1.5; }
.travel-approval__header-actions { display: flex; gap: 8px; align-items: center; }
.travel-approval__badge { padding: 3px 8px; color: #b45309; font-size: 10.5px; background: #fffbeb; border-radius: 999px; }
.travel-approval__wrap { max-width: 270px; white-space: normal; word-break: break-word; }
.travel-approval__decision { display: grid; gap: 6px; }
.travel-approval__decision label { display: flex; flex-direction: column; gap: 3px; font-size: 10.5px; }
.travel-approval__decision input { min-width: 150px; padding: 6px 8px; font: inherit; font-size: 11.5px; border: 1px solid #cbd5e1; border-radius: 6px; }
.travel-approval__decision-actions { display: flex; flex-wrap: wrap; gap: 6px; }
.travel-approval__primary, .travel-approval__secondary, .travel-approval__tertiary, .travel-approval__danger { padding: 6px 11px; font-size: 11.5px; border-radius: 6px; cursor: pointer; }
.travel-approval__primary { color: #fff; background: #1d4ed8; border: 1px solid #1d4ed8; }
.travel-approval__secondary { color: #334155; background: #fff; border: 1px solid #cbd5e1; }
.travel-approval__tertiary { color: #1d4ed8; background: transparent; border: 1px solid #bfdbfe; }
.travel-approval__danger { color: #b91c1c; background: #fff; border: 1px solid #fecaca; }
.travel-approval__success { margin: 0; padding: 9px 12px; color: #065f46; font-size: 11.5px; background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 8px; }
.travel-approval__error { margin: 0; padding: 9px 12px; color: #991b1b; font-size: 11.5px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; }
.mono { font-family: ui-monospace, monospace; }
</style>
