<script setup lang="ts">
import { computed, ref } from 'vue'
import { ApiError, errorCopy } from '@/api/errors'
import {
  cancelLeaveRequest,
  formatLeaveNumber,
  newLeaveIdempotencyKey,
  revokeApprovedLeave,
  LEAVE_STATUS_LABEL,
  LEAVE_STATUS_TONE,
  leaveUnitLabel,
  type LeaveBalance,
  type LeaveRequestItem,
  type LeaveTypeOption,
} from '../api'

const props = withDefaults(
  defineProps<{
    requests: LeaveRequestItem[]
    types?: LeaveTypeOption[]
    balances?: LeaveBalance[]
    canCancel?: boolean
    canRevoke?: boolean
    canAdminRevoke?: boolean
    canOnBehalf?: boolean
    title?: string
    showOnBehalf?: boolean
    showEmployee?: boolean
  }>(),
  {
    types: () => [],
    balances: () => [],
    canCancel: false,
    canRevoke: false,
    canAdminRevoke: false,
    canOnBehalf: false,
    title: '我的请假申请',
    showOnBehalf: false,
    showEmployee: false,
  },
)

const emit = defineEmits<{
  cancel: [request: LeaveRequestItem]
  revoke: [request: LeaveRequestItem]
  'on-behalf': []
}>()

type FilterKey = 'ALL' | 'PENDING' | 'APPROVED' | 'REJECTED'

const activeFilter = ref<FilterKey>('ALL')

const filters: Array<{ key: FilterKey; label: string }> = [
  { key: 'ALL', label: '全部' },
  { key: 'PENDING', label: '待审批' },
  { key: 'APPROVED', label: '已通过' },
  { key: 'REJECTED', label: '已驳回' },
]

const CANCELLABLE = new Set(['SUBMITTED', 'IN_REVIEW', 'DRAFT'])

function matchesFilter(item: LeaveRequestItem): boolean {
  const tone = LEAVE_STATUS_TONE[item.applicationStatus] ?? 'closed'
  if (activeFilter.value === 'ALL') return true
  if (activeFilter.value === 'PENDING') return tone === 'pending'
  if (activeFilter.value === 'APPROVED') return tone === 'approved'
  return tone === 'rejected'
}

const visible = computed(() => props.requests.filter(matchesFilter))

const counts = computed(() => ({
  ALL: props.requests.length,
  PENDING: props.requests.filter((item) => (LEAVE_STATUS_TONE[item.applicationStatus] ?? 'closed') === 'pending').length,
  APPROVED: props.requests.filter((item) => (LEAVE_STATUS_TONE[item.applicationStatus] ?? 'closed') === 'approved').length,
  REJECTED: props.requests.filter((item) => (LEAVE_STATUS_TONE[item.applicationStatus] ?? 'closed') === 'rejected').length,
}))

const cancelTarget = ref<LeaveRequestItem | null>(null)
const cancelRevoke = ref(false)
const cancelReason = ref('')
const cancelError = ref<{ message: string; requestId: string | null } | null>(null)
const localCancelling = ref(false)
// One key per confirmed cancellation target. Editing the optional reason must
// not create a second request if the first response was lost in the network.
let cancelKey: string | null = null
let cancelKeyTarget: number | null = null

const cancelDialogOpen = computed(() => cancelTarget.value !== null)

const CANCEL_REASON_LABEL: Record<string, string> = {
  LEAVE_REQUEST_NOT_FOUND: '未找到该请假申请，可能已被撤回。',
  LEAVE_REQUEST_OVERLAP: '该申请与已有请假记录重叠。',
  INSUFFICIENT_BALANCE: '可用余额不足，无法撤回。',
  ATTENDANCE_PERIOD_CLOSED: '所选日期所在考勤期间已关账。',
  CASE_NOT_CANCELLABLE: '该申请当前状态不可撤回。',
  WORKFLOW_PRINCIPAL_MISMATCH: '当前账号与申请人不一致，无法撤回。',
  LEAVE_PERIOD_FROZEN: '工资期间已冻结，不能撤销已批准的请假。',
  LEAVE_REQUEST_NOT_APPROVABLE: '该请假申请当前状态不允许销假。',
}

function statusLabel(status: string): string {
  return LEAVE_STATUS_LABEL[status] ?? status
}

function statusTone(status: string): string {
  return `leave-request-row__status--${LEAVE_STATUS_TONE[status] ?? 'closed'}`
}

function durationText(item: LeaveRequestItem): string {
  if (!Number.isFinite(item.minutes) || item.minutes <= 0) return '—'
  const balance = props.balances.find((candidate) => candidate.leaveTypeId === item.leaveTypeId)
  const standardDay = balance?.standardDayMinutes ?? null
  const unit = balance?.unit ?? null
  if (unit === 'DAY' && standardDay !== null && standardDay > 0) {
    return `${formatLeaveNumber(item.minutes / standardDay)} ${leaveUnitLabel(unit)}`
  }
  if (unit === 'HOUR') return `${formatLeaveNumber(item.minutes / 60)} ${leaveUnitLabel(unit)}`
  // No unit projection means the truthful display is the canonical minutes.
  // Guessing hours here would silently turn two leave days into "16 小时".
  return `${formatLeaveNumber(item.minutes)} 分钟`
}

function typeName(item: LeaveRequestItem): string {
  return props.types.find((type) => type.id === item.leaveTypeId)?.name ?? '请假'
}

const REVOCABLE_STATES = new Set(['APPROVED', 'CONSUMED'])

function revocable(item: LeaveRequestItem): boolean {
  return (props.canRevoke || props.canAdminRevoke) && REVOCABLE_STATES.has(item.state)
}

function canCancelRequest(item: LeaveRequestItem): boolean {
  return props.canCancel && CANCELLABLE.has(item.applicationStatus)
}

function openCancel(item: LeaveRequestItem): void {
  if (!canCancelRequest(item)) return
  cancelTarget.value = item
  cancelRevoke.value = revocable(item)
  cancelReason.value = ''
  cancelError.value = null
  cancelKey = null
  cancelKeyTarget = null
}

function openRevoke(item: LeaveRequestItem): void {
  if (!revocable(item)) return
  cancelTarget.value = item
  cancelRevoke.value = true
  cancelReason.value = ''
  cancelError.value = null
  cancelKey = null
  cancelKeyTarget = null
}

function closeCancel(): void {
  if (localCancelling.value) return
  cancelTarget.value = null
  cancelReason.value = ''
  cancelError.value = null
  cancelKey = null
  cancelKeyTarget = null
}

function failureText(reason: unknown): string {
  if (reason instanceof ApiError) {
    const reasonText = reason.serverReason !== null ? CANCEL_REASON_LABEL[reason.serverReason] : ''
    const base = errorCopy(reason)
    const action = cancelRevoke.value ? '销假' : '撤回'
    return reasonText !== '' ? `${action}失败：${reasonText}（${base}）` : `${action}失败：${base}`
  }
  return `${cancelRevoke.value ? '销假' : '撤回'}失败：${errorCopy(reason)}`
}

async function confirmCancel(): Promise<void> {
  const target = cancelTarget.value
  if (target === null || localCancelling.value) return

  localCancelling.value = true
  cancelError.value = null
  try {
    // One key per confirmed cancellation. If the user clicks again after a
    // failure, the same key is reused so the server can replay exactly once.
    if (cancelKey === null || cancelKeyTarget !== target.caseId) {
      cancelKey = newLeaveIdempotencyKey('leave-cancel')
      cancelKeyTarget = target.caseId
    }
    if (cancelRevoke.value && props.canAdminRevoke) {
      await revokeApprovedLeave(
        target.caseId,
        cancelReason.value.trim() || '页面销假',
        cancelKey,
      )
    } else {
      await cancelLeaveRequest(
        target.caseId,
        cancelReason.value.trim() || (cancelRevoke.value ? '页面销假' : '页面撤回请假申请'),
        cancelKey,
        cancelRevoke.value,
      )
    }
    const wasRevoke = cancelRevoke.value
    cancelTarget.value = null
    cancelRevoke.value = false
    cancelReason.value = ''
    cancelKey = null
    cancelKeyTarget = null
    if (wasRevoke) emit('revoke', target)
    else emit('cancel', target)
  } catch (reason) {
    cancelError.value = {
      message: failureText(reason),
      requestId: reason instanceof ApiError ? reason.requestId : null,
    }
  } finally {
    localCancelling.value = false
  }
}
</script>

<template>
  <section class="leave-requests" aria-label="请假申请记录">
    <header class="leave-requests__header">
      <div>
        <h2 class="leave-requests__title">{{ props.title }}</h2>
        <p class="leave-requests__subtitle">
          按审批状态筛选；待审批申请可撤回并释放预占。已通过的申请只能走受工资冻结保护的销假。
        </p>
      </div>
      <button
        v-if="props.showOnBehalf && props.canOnBehalf"
        type="button"
        class="leave-requests__on-behalf"
        data-testid="leave-on-behalf"
        @click="emit('on-behalf')"
      >
        代员工申请
      </button>
    </header>

    <div class="leave-requests__filters" role="group" aria-label="按审批状态筛选">
      <button
        v-for="filter in filters"
        :key="filter.key"
        type="button"
        class="leave-requests__filter"
        :class="{ 'leave-requests__filter--active': activeFilter === filter.key }"
        :aria-pressed="activeFilter === filter.key"
        :data-testid="`leave-filter-${filter.key}`"
        @click="activeFilter = filter.key"
      >
        {{ filter.label }}
        <span class="leave-requests__filter-count">{{ counts[filter.key] }}</span>
      </button>
    </div>

    <p v-if="props.requests.length === 0" class="leave-requests__empty" data-testid="leave-requests-empty">
      暂无请假申请。服务端返回空列表时，这里不会展示占位记录。
    </p>
    <p v-else-if="visible.length === 0" class="leave-requests__empty">当前筛选条件下没有请假申请。</p>

    <div v-else class="leave-requests__table-wrap">
      <table class="leave-requests__table">
        <caption class="visually-hidden">请假申请记录，共 {{ visible.length }} 条</caption>
        <thead>
          <tr>
            <th v-if="props.showEmployee" scope="col">申请人</th>
            <th scope="col">申请编号</th>
            <th scope="col">请假日期</th>
            <th scope="col">时长</th>
            <th scope="col">审批状态</th>
            <th scope="col">操作</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="item in visible" :key="item.caseId" :data-testid="`leave-request-${item.caseId}`">
            <td class="leave-requests__case">
              <span class="mono">{{ item.caseNo }}</span>
              <small class="leave-requests__type">{{ typeName(item) }}</small>
            </td>
            <td v-if="props.showEmployee" class="leave-requests__employee">
              <span>{{ item.employeeNo || `任职 ${item.employmentId ?? '—'}` }}</span>
              <small>{{ item.departmentName || '部门未返回' }}</small>
              <small v-if="item.actorUserId && item.employmentId && item.actorUserId !== item.employmentId">
                由用户 {{ item.actorUserId }} 代申请
              </small>
            </td>
            <td class="leave-requests__dates">
              <span>{{ item.fromDate || '—' }}</span>
              <span aria-hidden="true">→</span>
              <span>{{ item.toDate || '—' }}</span>
            </td>
            <td class="leave-requests__duration">{{ durationText(item) }}</td>
            <td>
              <span class="leave-request-row__status" :class="statusTone(item.applicationStatus)">
                {{ statusLabel(item.applicationStatus) }}
              </span>
            </td>
            <td class="leave-requests__actions">
              <button
                v-if="canCancelRequest(item)"
                type="button"
                class="leave-requests__cancel"
                :data-testid="`leave-cancel-${item.caseId}`"
                @click="openCancel(item)"
              >
                撤回
              </button>
              <button
                v-else-if="revocable(item)"
                type="button"
                class="leave-requests__cancel"
                :data-testid="`leave-revoke-${item.caseId}`"
                @click="openRevoke(item)"
              >
                销假
              </button>
              <span v-else class="leave-requests__readonly">—</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div
      v-if="cancelDialogOpen"
      class="leave-cancel-overlay"
      role="presentation"
      @click.self="closeCancel"
    >
      <section
        class="leave-cancel-dialog"
        role="dialog"
        aria-modal="true"
        aria-label="确认撤回请假申请"
        data-testid="leave-cancel-dialog"
      >
        <h3>{{ cancelRevoke ? '确认销假？' : '确认撤回请假申请？' }}</h3>
        <p>
          申请 <strong class="mono">{{ cancelTarget?.caseNo }}</strong>
          （{{ cancelTarget?.fromDate }} 至 {{ cancelTarget?.toDate }}）将被{{ cancelRevoke ? '销假，已结算额度将冲正返还' : '撤回，服务端预占余额会释放' }}。
        </p>

        <label class="leave-cancel-dialog__field">
          <span>撤回原因</span>
          <input
            v-model="cancelReason"
            data-testid="leave-cancel-reason"
            maxlength="500"
            :placeholder="cancelRevoke ? '请填写销假原因（至少 3 个字）' : '可选，将记录在审批时间线'"
          />
        </label>

        <p v-if="cancelError" class="leave-cancel-dialog__error" role="alert" data-testid="leave-cancel-error">
          {{ cancelError.message }}
          <span v-if="cancelError.requestId" class="leave-cancel-dialog__request-id">
            （请求编号：{{ cancelError.requestId }}）
          </span>
        </p>

        <footer class="leave-cancel-dialog__actions">
          <button type="button" class="leave-cancel-dialog__secondary" :disabled="localCancelling" @click="closeCancel">
            保留申请
          </button>
          <button
            type="button"
            class="leave-cancel-dialog__danger"
            data-testid="leave-cancel-confirm"
            :disabled="localCancelling"
            @click="confirmCancel"
          >
            {{ localCancelling ? '正在处理…' : (cancelRevoke ? '确认销假' : '确认撤回') }}
          </button>
        </footer>
      </section>
    </div>
  </section>
</template>

<style scoped>
.leave-requests {
  display: flex;
  flex-direction: column;
  gap: 10px;
  background: #fff;
  border: 1px solid var(--color-line);
  border-radius: var(--radius-md);
  box-shadow: var(--shadow-soft);
}

.leave-requests__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 14px;
  padding: 14px 16px 0;
}

.leave-requests__title {
  margin: 0;
  font-size: 14px;
  font-weight: 680;
}

.leave-requests__subtitle {
  margin: 4px 0 0;
  color: var(--color-ink-muted);
  font-size: 11px;
  line-height: 1.5;
}


.leave-requests__filters {
  display: flex;
  flex-wrap: wrap;
  gap: 5px;
  padding: 10px 16px;
  border-bottom: 1px solid #edf1ef;
}

.leave-requests__filter {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 4px 9px;
  color: var(--color-ink-muted);
  font-size: 11px;
  background: transparent;
  border: 1px solid transparent;
  border-radius: 999px;
  cursor: pointer;
}

.leave-requests__filter--active {
  color: var(--color-ink);
  background: var(--color-canvas);
  border-color: var(--color-line-strong);
  font-weight: 600;
}

.leave-requests__filter-count {
  font-size: 9px;
  font-variant-numeric: tabular-nums;
}

.leave-requests__table-wrap {
  overflow-x: auto;
}

.leave-requests__table {
  width: 100%;
  min-width: 620px;
  border-collapse: collapse;
  font-size: 11.5px;
}

.leave-requests__table th {
  padding: 7px 14px;
  color: var(--color-ink-muted);
  font-size: 9.5px;
  font-weight: 650;
  text-align: left;
  background: #f8faf9;
  border-bottom: 1px solid var(--color-line);
}

.leave-requests__table td {
  padding: 9px 14px;
  border-bottom: 1px solid #edf1ef;
}

.leave-requests__table tr:last-child td {
  border-bottom: 0;
}

.leave-requests__case {
  font-size: 10.5px;
}

.leave-requests__type {
  display: block;
  color: var(--color-ink-muted);
  font-size: 9px;
}

.leave-requests__dates {
  display: flex;
  align-items: center;
  gap: 5px;
  white-space: nowrap;
}

.leave-requests__duration {
  white-space: nowrap;
  font-variant-numeric: tabular-nums;
}

.leave-request-row__status {
  display: inline-block;
  padding: 2px 7px;
  font-size: 10px;
  border-radius: 999px;
}

.leave-request-row__status--pending {
  color: #6b4a00;
  background: #fff7e6;
  border: 1px solid #e6c98d;
}

.leave-request-row__status--approved {
  color: #1f6b3a;
  background: #eef8f1;
  border: 1px solid #bfe3ca;
}

.leave-request-row__status--rejected {
  color: #9f1c12;
  background: #feecea;
  border: 1px solid #f5c2bd;
}

.leave-request-row__status--closed {
  color: var(--color-ink-muted);
  background: var(--color-canvas);
  border: 1px solid var(--color-line);
}

.leave-requests__actions {
  text-align: right;
}

.leave-requests__employee {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.leave-requests__employee small {
  color: var(--color-ink-muted);
  font-size: 9.5px;
}

.leave-requests__on-behalf {
  flex: none;
  padding: 6px 12px;
  color: #fff;
  font-size: 11.5px;
  background: var(--color-primary, #1f5f8b);
  border: 1px solid var(--color-primary, #1f5f8b);
  border-radius: 6px;
  cursor: pointer;
}

.leave-requests__cancel {
  padding: 3px 9px;
  color: #9f1c12;
  font-size: 10.5px;
  background: #fff;
  border: 1px solid #f5c2bd;
  border-radius: 5px;
  cursor: pointer;
}

.leave-requests__readonly {
  color: var(--color-ink-muted);
}

.leave-requests__empty {
  margin: 0;
  padding: 22px 16px;
  color: var(--color-ink-muted);
  font-size: 11.5px;
  text-align: center;
}

.leave-cancel-overlay {
  position: fixed;
  inset: 0;
  z-index: 70;
  display: grid;
  place-items: center;
  padding: 20px;
  background: rgb(15 23 32 / 38%);
}

.leave-cancel-dialog {
  width: min(420px, 100%);
  padding: 18px 20px;
  background: #fff;
  border-radius: 10px;
  box-shadow: 0 22px 60px rgb(15 23 32 / 22%);
}

.leave-cancel-dialog h3 {
  margin: 0 0 8px;
  font-size: 15px;
  font-weight: 680;
}

.leave-cancel-dialog p {
  margin: 0 0 12px;
  color: var(--color-ink-muted);
  font-size: 11.5px;
  line-height: 1.6;
}

.leave-cancel-dialog__field {
  display: flex;
  flex-direction: column;
  gap: 4px;
  color: var(--color-ink-muted);
  font-size: 10.5px;
}

.leave-cancel-dialog__field input {
  padding: 6px 8px;
  font: inherit;
  color: var(--color-ink);
  border: 1px solid var(--color-line-strong);
  border-radius: 6px;
}

.leave-cancel-dialog__error {
  margin-top: 10px !important;
  padding: 7px 9px;
  color: #9f1c12 !important;
  background: #feecea;
  border: 1px solid #f5c2bd;
  border-radius: 6px;
}

.leave-cancel-dialog__request-id {
  font-family: var(--font-mono, monospace);
  font-size: 9.5px;
}

.leave-cancel-dialog__actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 14px;
}

.leave-cancel-dialog__secondary,
.leave-cancel-dialog__danger {
  padding: 6px 12px;
  font-size: 11.5px;
  border-radius: 6px;
  cursor: pointer;
}

.leave-cancel-dialog__secondary {
  color: var(--color-ink);
  background: #fff;
  border: 1px solid var(--color-line-strong);
}

.leave-cancel-dialog__danger {
  color: #fff;
  background: #b3261e;
  border: 1px solid #b3261e;
}

.leave-cancel-dialog__secondary:disabled,
.leave-cancel-dialog__danger:disabled {
  cursor: not-allowed;
  opacity: 0.55;
}
</style>
