<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import { RouterLink } from 'vue-router'
import { ApiError, errorCopy } from '@/api/errors'
import { resourceGet, resourcePatch, resourcePost } from '@/api/resourceClient'
import { requestIdFromError } from '@/utils/renderErrors'
import ActionGate from '@/components/ActionGate.vue'
import DataTable, { type DataTableColumn } from '@/components/DataTable.vue'
import ErrorState from '@/components/ErrorState.vue'
import FormDialog from '@/shared/ui/FormDialog.vue'
import { nextIdempotencyKey } from '@/modules/people/peopleRead'

const props = defineProps<{ employmentId: number }>()

defineOptions({ name: 'EmployeeChangeList' })

const HIDDEN = '无权查看'

const CHANGE_TYPES = [
  { value: 'PROFILE_CORRECTION', label: '档案信息更正' },
  { value: 'CONTACT_UPDATE', label: '联系方式更新' },
  { value: 'EDUCATION_CORRECTION', label: '教育经历更正' },
  { value: 'CERTIFICATE_UPDATE', label: '证照信息更新' },
  { value: 'OTHER', label: '其他变更' },
] as const

const STATUS_LABELS: Readonly<Record<string, string>> = {
  DRAFT: '草稿',
  SUBMITTED: '已提交',
  IN_APPROVAL: '审批中',
  APPROVED: '已通过',
  REJECTED: '已驳回',
  WITHDRAWN: '已撤回',
}

const STATUS_TONES: Readonly<Record<string, string>> = {
  DRAFT: 'muted',
  SUBMITTED: 'info',
  IN_APPROVAL: 'warning',
  APPROVED: 'success',
  REJECTED: 'danger',
  WITHDRAWN: 'muted',
}

type ChangeRow = {
  id: number
  status: string | null
  statusVisible: boolean
  reason: string | null
  reasonVisible: boolean
  changeType: string | null
  changeTypeVisible: boolean
  requestedBy: number | null
  submittedAt: string | null
  submittedAtVisible: boolean
  decidedBy: number | null
  decidedByVisible: boolean
  decidedAt: string | null
  decidedAtVisible: boolean
  caseId: number | null
  caseIdVisible: boolean
  raw: Record<string, unknown>
}

type Draft = {
  changeType: string
  reason: string
}

const rows = ref<ChangeRow[]>([])
const total = ref(0)
const loading = ref(false)
const forbidden = ref(false)
const error = ref<string | null>(null)
const requestId = ref<string | null>(null)
const selectedId = ref<number | null>(null)
const formOpen = ref(false)
const editingId = ref<number | null>(null)
const submitting = ref(false)
const formError = ref<string | null>(null)
const draft = ref<Draft>({ changeType: 'PROFILE_CORRECTION', reason: '' })

const columns: DataTableColumn[] = [
  { key: 'display_type', label: '变更类型' },
  { key: 'display_status', label: '状态' },
  { key: 'display_submitted', label: '提交时间' },
  { key: 'display_decision', label: '审批结果' },
  { key: 'display_actions', label: '操作', width: '160px' },
]

const selected = computed(() => rows.value.find((row) => row.id === selectedId.value) ?? null)
const selectedTimeline = computed(() => (selected.value === null ? [] : timelineFor(selected.value)))
const dialogTitle = computed(() =>
  editingId.value === null ? '新建变更申请' : '编辑变更草稿',
)

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function has(row: Record<string, unknown>, key: string): boolean {
  return Object.prototype.hasOwnProperty.call(row, key)
}

function nullableText(value: unknown): string | null {
  return typeof value === 'string' && value.trim() !== '' ? value : null
}

function nullableInteger(value: unknown): number | null {
  if (typeof value === 'number' && Number.isSafeInteger(value)) return value
  return null
}

function changeTypeOf(row: Record<string, unknown>): string | null {
  const diff = row.diff
  if (!isRecord(diff)) return null
  return nullableText(diff.change_type)
}

function normaliseRow(row: Record<string, unknown>): ChangeRow | null {
  const id = nullableInteger(row.id)
  if (id === null || id <= 0) return null
  return {
    id,
    status: nullableText(row.status),
    statusVisible: has(row, 'status'),
    reason: nullableText(row.reason),
    reasonVisible: has(row, 'reason'),
    changeType: changeTypeOf(row),
    changeTypeVisible: has(row, 'diff'),
    requestedBy: nullableInteger(row.requested_by),
    submittedAt: nullableText(row.submitted_at),
    submittedAtVisible: has(row, 'submitted_at'),
    decidedBy: nullableInteger(row.decided_by),
    decidedByVisible: has(row, 'decided_by'),
    decidedAt: nullableText(row.decided_at),
    decidedAtVisible: has(row, 'decided_at'),
    caseId: nullableInteger(row.case_id),
    caseIdVisible: has(row, 'case_id'),
    raw: row,
  }
}

function statusLabel(status: string | null): string {
  if (status === null) return HIDDEN
  return STATUS_LABELS[status] ?? '未知状态'
}

function statusTone(status: string | null): string {
  if (status === null) return 'muted'
  return STATUS_TONES[status] ?? 'muted'
}

function changeTypeLabel(value: string | null): string {
  if (value === null) return HIDDEN
  return CHANGE_TYPES.find((type) => type.value === value)?.label ?? '未知变更'
}

function dateTimeLabel(value: string | null): string {
  if (value === null) return HIDDEN
  const date = value.slice(0, 10)
  if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) return HIDDEN
  const [year, month, day] = date.split('-')
  const base = `${year}年${Number(month)}月${Number(day)}日`
  const time = value.slice(11, 16)
  return /^\d{2}:\d{2}$/.test(time) ? `${base} ${time}` : base
}

function decisionLabel(row: ChangeRow): string {
  if (row.status === 'APPROVED' || row.status === 'REJECTED') {
    const decision = row.status === 'APPROVED' ? '已通过' : '已驳回'
    if (row.decidedAtVisible && row.decidedAt !== null) {
      return `${decision} · ${dateTimeLabel(row.decidedAt)}`
    }
    return row.decidedAtVisible ? `${decision} · 暂无决定时间` : `${decision} · ${HIDDEN}`
  }
  if (row.status === 'SUBMITTED' || row.status === 'IN_APPROVAL') return '等待审批'
  return '尚未提交'
}

const displayRows = computed<Record<string, unknown>[]>(() =>
  rows.value.map((row) => ({
    id: row.id,
    display_type: row.changeTypeVisible ? changeTypeLabel(row.changeType) : HIDDEN,
    display_status: row.statusVisible ? statusLabel(row.status) : HIDDEN,
    display_submitted:
      !row.submittedAtVisible || row.submittedAt === null ? HIDDEN : dateTimeLabel(row.submittedAt),
    display_decision: decisionLabel(row),
    display_actions: '',
  })),
)

function timelineFor(row: ChangeRow): Array<{ label: string; at: string; state: 'done' | 'current' | 'pending' }> {
  const status = row.status
  const draftAt = row.submittedAtVisible ? row.submittedAt : null
  const submittedDone = status !== null && status !== 'DRAFT'
  const decidedDone = status === 'APPROVED' || status === 'REJECTED'
  const inApproval = status === 'IN_APPROVAL' || status === 'SUBMITTED'

  return [
    {
      label: '创建草稿',
      at: status === 'DRAFT' ? '当前为草稿' : '已创建',
      state: 'done',
    },
    {
      label: '提交审批',
      at: submittedDone ? (draftAt === null ? '时间无权查看' : dateTimeLabel(draftAt)) : row.submittedAtVisible ? '尚未提交' : HIDDEN,
      state: submittedDone ? (inApproval ? 'current' : 'done') : status === 'DRAFT' ? 'pending' : 'pending',
    },
    {
      label: decidedDone ? (status === 'APPROVED' ? '审批通过' : '审批驳回') : '审批结果',
      at: decidedDone
        ? row.decidedAtVisible
          ? row.decidedAt === null
            ? '决定时间未返回'
            : dateTimeLabel(row.decidedAt)
          : HIDDEN
        : decidedDone
          ? HIDDEN
          : '等待审批',
      state: decidedDone ? 'done' : 'pending',
    },
  ]
}

const decisionText = computed(() => {
  const row = selected.value
  if (row === null) return ''
  if (row.status !== 'APPROVED' && row.status !== 'REJECTED') return '暂无审批决定'
  const parts: string[] = []
  parts.push(row.status === 'APPROVED' ? '审批结果：已通过' : '审批结果：已驳回')
  parts.push(
    row.decidedByVisible
      ? row.decidedBy === null
        ? '审批人：接口未返回'
        : `审批人：用户 #${row.decidedBy}`
      : `审批人：${HIDDEN}`,
  )
  parts.push(
    row.decidedAtVisible
      ? row.decidedAt === null
        ? '决定时间：接口未返回'
        : `决定时间：${dateTimeLabel(row.decidedAt)}`
      : `决定时间：${HIDDEN}`,
  )
  return parts.join(' · ')
})

function rejectState(reason: unknown): void {
  if (reason instanceof ApiError && (reason.status === 403 || reason.code === 'FORBIDDEN')) {
    forbidden.value = true
    return
  }
  error.value = errorCopy(reason)
  requestId.value = requestIdFromError(reason)
}

async function reload(showLoading = true): Promise<void> {
  if (showLoading) loading.value = true
  forbidden.value = false
  error.value = null
  requestId.value = null

  try {
    const payload = await resourceGet<unknown>(
      `/v1/employees/${props.employmentId}/change_requests`,
    )
    const items = isRecord(payload) && Array.isArray(payload.items) ? payload.items : []
    rows.value = items
      .filter(isRecord)
      .map(normaliseRow)
      .filter((row): row is ChangeRow => row !== null)
    total.value = isRecord(payload) && typeof payload.total === 'number' ? payload.total : rows.value.length
    if (selectedId.value !== null && !rows.value.some((row) => row.id === selectedId.value)) {
      selectedId.value = null
    }
    if (selectedId.value === null && rows.value.length > 0) selectedId.value = rows.value[0]?.id ?? null
  } catch (reason) {
    rows.value = []
    total.value = 0
    selectedId.value = null
    rejectState(reason)
  } finally {
    loading.value = false
  }
}

function openCreate(): void {
  editingId.value = null
  draft.value = { changeType: 'PROFILE_CORRECTION', reason: '' }
  formError.value = null
  formOpen.value = true
}

function openEdit(row: ChangeRow): void {
  if (row.status !== 'DRAFT') return
  editingId.value = row.id
  draft.value = {
    changeType: row.changeType ?? 'PROFILE_CORRECTION',
    reason: row.reason ?? '',
  }
  formError.value = null
  formOpen.value = true
}

async function submitDraftForm(): Promise<void> {
  formError.value = null
  if (draft.value.reason.trim().length < 3) {
    formError.value = '请填写至少 3 个字的变更原因。'
    return
  }

  submitting.value = true
  try {
    const body = {
      reason: draft.value.reason.trim(),
      diff: { change_type: draft.value.changeType },
    }
    const base = `/v1/employees/${props.employmentId}/change_requests`
    if (editingId.value === null) {
      await resourcePost(base, body, { idempotencyKey: nextIdempotencyKey('change-request') })
      ElMessage.success('变更草稿已创建。确认后请提交审批。')
    } else {
      await resourcePatch(`${base}/${editingId.value}`, body, {
        idempotencyKey: nextIdempotencyKey('change-request-edit'),
      })
      ElMessage.success('变更草稿已保存。')
    }
    formOpen.value = false
    await reload(false)
  } catch (reason) {
    formError.value = errorCopy(reason)
  } finally {
    submitting.value = false
  }
}

async function submitRequest(row: ChangeRow): Promise<void> {
  if (row.status !== 'DRAFT') return
  submitting.value = true
  try {
    await resourcePost(
      `/v1/employees/${props.employmentId}/change_requests/${row.id}/submit`,
      {},
      { idempotencyKey: nextIdempotencyKey('change-request-submit') },
    )
    ElMessage.success('变更申请已提交；提交后内容只读。')
    await reload(false)
  } catch (reason) {
    if (reason instanceof ApiError && reason.code === 'CONFLICT') {
      ElMessage.error(
        reason.serverReason === 'CHANGE_REQUEST_NOT_DRAFT'
          ? '该申请已提交或已审批，不能再次提交。'
          : errorCopy(reason),
      )
    } else {
      ElMessage.error(errorCopy(reason))
    }
  } finally {
    submitting.value = false
  }
}

onMounted(() => {
  void reload()
})

watch(
  () => props.employmentId,
  () => {
    formOpen.value = false
    selectedId.value = null
    void reload()
  },
)
</script>

<template>
  <section class="change-list" data-testid="employee-change-list">
    <header class="change-list__header">
      <div>
        <h3>变更申请</h3>
        <p>先保存草稿，再提交审批；提交后申请内容只读。审批人与决定时间以服务端返回为准。</p>
      </div>
      <ActionGate capability="people.employee.manage">
        <button type="button" class="change-list__add" data-testid="change-add" @click="openCreate">
          新建变更申请
        </button>
      </ActionGate>
    </header>

    <section v-if="forbidden" class="change-list__state change-list__state--forbidden" data-testid="change-forbidden">
      <h4>无权查看</h4>
      <p>你没有查看变更申请的权限；员工摘要与其他档案区域仍可使用。</p>
    </section>

    <ErrorState
      v-else-if="error"
      title="变更申请暂时无法加载"
      :description="error"
      :request-id="requestId"
      @retry="reload()"
    />

    <template v-else>
      <DataTable
        :columns="columns"
        :rows="displayRows"
        :total="total"
        :page="1"
        :page-size="20"
        :loading="loading"
        empty-title="暂无变更申请"
      >
        <template #cell-display_actions="{ row }">
          <span class="change-list__actions">
            <button
              type="button"
              class="change-list__link"
              :aria-label="`查看变更申请 #${String(row.id)}`"
              @click="selectedId = Number(row.id)"
            >
              查看
            </button>
            <ActionGate capability="people.employee.manage">
              <button
                v-if="rows.find((item) => item.id === Number(row.id))?.status === 'DRAFT'"
                type="button"
                class="change-list__link"
                :disabled="submitting"
                :data-testid="`change-submit-${String(row.id)}`"
                @click="submitRequest(rows.find((item) => item.id === Number(row.id))!)"
              >
                提交
              </button>
            </ActionGate>
          </span>
        </template>
      </DataTable>

      <section v-if="selected" class="change-detail" data-testid="change-detail">
        <header class="change-detail__header">
          <div>
            <h4>申请 #{{ selected.id }}</h4>
            <p>
              <span class="change-status" :class="`change-status--${statusTone(selected.status)}`">
                {{ selected.statusVisible ? statusLabel(selected.status) : HIDDEN }}
              </span>
              <span v-if="selected.changeTypeVisible"> · {{ changeTypeLabel(selected.changeType) }}</span>
            </p>
          </div>
          <ActionGate capability="people.employee.manage">
            <button
              v-if="selected.status === 'DRAFT'"
              type="button"
              class="change-list__link"
              data-testid="change-detail-edit"
              @click="openEdit(selected)"
            >
              编辑草稿
            </button>
          </ActionGate>
        </header>

        <p class="change-detail__reason">
          <strong>变更原因：</strong>
          {{ selected.reasonVisible ? selected.reason ?? '未填写' : HIDDEN }}
        </p>

        <h5>状态时间线</h5>
        <ol class="change-timeline" data-testid="change-timeline">
          <li
            v-for="(step, index) in selectedTimeline"
            :key="index"
            class="change-timeline__step"
            :class="`change-timeline__step--${step.state}`"
          >
            <span class="change-timeline__dot" aria-hidden="true" />
            <div>
              <strong>{{ step.label }}</strong>
              <p>{{ step.at }}</p>
            </div>
          </li>
        </ol>

        <dl class="change-detail__meta">
          <div>
            <dt>提交时间</dt>
            <dd>
              {{ !selected.submittedAtVisible || selected.submittedAt === null ? HIDDEN : dateTimeLabel(selected.submittedAt) }}
            </dd>
          </div>
          <div>
            <dt>审批决定</dt>
            <dd data-testid="change-decision">{{ decisionText }}</dd>
          </div>
          <div>
            <dt>审批入口</dt>
            <dd>
              <template v-if="selected.caseIdVisible && selected.caseId !== null">
                <ActionGate capability="workflow.case.read">
                  <RouterLink
                    :to="`/cases/${selected.caseId}`"
                    class="change-detail__link"
                    data-testid="change-case-link"
                  >
                    查看审批案例 #{{ selected.caseId }}
                  </RouterLink>
                </ActionGate>
              </template>
              <span v-else-if="selected.caseIdVisible">
                尚未生成审批案例（提交后由审批流程创建）。
              </span>
              <span v-else>{{ HIDDEN }}</span>
            </dd>
          </div>
        </dl>
      </section>
    </template>

    <FormDialog
      v-if="formOpen"
      :model-value="formOpen"
      :title="dialogTitle"
      :confirm-loading="submitting"
      @update:model-value="formOpen = false"
      @confirm="submitDraftForm"
    >
      <div class="change-form" data-testid="change-form">
        <label class="change-form__field">
          <span>变更类型 *</span>
          <select v-model="draft.changeType" aria-label="变更类型" data-testid="change-form-type">
            <option v-for="type in CHANGE_TYPES" :key="type.value" :value="type.value">
              {{ type.label }}
            </option>
          </select>
        </label>
        <label class="change-form__field">
          <span>变更原因 *</span>
          <textarea v-model="draft.reason" aria-label="变更原因" rows="4" data-testid="change-form-reason" placeholder="请说明需要更正或变更的内容" />
        </label>
        <p v-if="formError" class="change-form__error" role="alert" data-testid="change-form-error">
          {{ formError }}
        </p>
      </div>
    </FormDialog>
  </section>
</template>

<style scoped>
.change-list {
  padding: 16px 18px;
  background: var(--color-surface, #fff);
  border: 1px solid var(--color-line, #e5e7eb);
  border-radius: var(--radius-md, 10px);
}
.change-list__header {
  display: flex;
  gap: 12px;
  align-items: flex-start;
  justify-content: space-between;
  margin-bottom: 12px;
}
.change-list__header h3 {
  margin: 0;
  font-size: 15px;
}
.change-list__header p {
  margin: 5px 0 0;
  color: var(--color-ink-muted, #6b7280);
  font-size: 12px;
}
.change-list__add {
  flex: none;
  padding: 7px 13px;
  color: #fff;
  font: inherit;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  background: var(--color-brand, #2563eb);
  border: 1px solid var(--color-brand, #2563eb);
  border-radius: 6px;
}
.change-list__state {
  padding: 20px;
  text-align: center;
  background: var(--color-canvas, #f9fafb);
  border-radius: 8px;
}
.change-list__state--forbidden {
  color: #991b1b;
  background: #fef2f2;
}
.change-list__actions {
  display: inline-flex;
  gap: 8px;
}
.change-list__link {
  padding: 3px 8px;
  color: var(--color-brand, #2563eb);
  font: inherit;
  font-size: 12px;
  cursor: pointer;
  background: transparent;
  border: 1px solid var(--color-line, #d1d5db);
  border-radius: 5px;
}
.change-detail {
  margin-top: 16px;
  padding: 14px;
  background: var(--color-canvas, #f9fafb);
  border-radius: 8px;
}
.change-detail__header {
  display: flex;
  gap: 12px;
  align-items: flex-start;
  justify-content: space-between;
}
.change-detail__header h4 {
  margin: 0;
  font-size: 14px;
}
.change-detail__header p {
  margin: 5px 0 0;
  color: var(--color-ink-secondary, #4b5563);
  font-size: 12px;
}
.change-status {
  padding: 2px 7px;
  font-size: 11px;
  border-radius: 999px;
}
.change-status--success {
  color: #166534;
  background: #dcfce7;
}
.change-status--danger {
  color: #991b1b;
  background: #fee2e2;
}
.change-status--warning {
  color: #92400e;
  background: #fef3c7;
}
.change-status--info {
  color: #1e40af;
  background: #dbeafe;
}
.change-status--muted {
  color: #4b5563;
  background: #e5e7eb;
}
.change-detail__reason {
  margin: 12px 0;
  font-size: 12px;
}
.change-detail h5 {
  margin: 12px 0 8px;
  font-size: 12px;
}
.change-timeline {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  padding: 0;
  margin: 0;
  list-style: none;
}
.change-timeline__step {
  display: flex;
  flex: 1;
  gap: 8px;
  min-width: 160px;
  padding: 10px;
  background: #fff;
  border: 1px solid var(--color-line, #e5e7eb);
  border-radius: 6px;
}
.change-timeline__step--current {
  border-color: var(--color-brand, #2563eb);
}
.change-timeline__step--pending {
  opacity: 0.65;
}
.change-timeline__dot {
  width: 8px;
  height: 8px;
  margin-top: 4px;
  background: var(--color-line-strong, #9ca3af);
  border-radius: 50%;
}
.change-timeline__step--done .change-timeline__dot {
  background: #16a34a;
}
.change-timeline__step--current .change-timeline__dot {
  background: var(--color-brand, #2563eb);
}
.change-timeline__step strong {
  font-size: 12px;
}
.change-timeline__step p {
  margin: 3px 0 0;
  color: var(--color-ink-muted, #6b7280);
  font-size: 11px;
}
.change-detail__meta {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 10px;
  margin: 14px 0 0;
}
.change-detail__meta dt {
  color: var(--color-ink-muted, #6b7280);
  font-size: 11px;
}
.change-detail__meta dd {
  margin: 4px 0 0;
  font-size: 12px;
}
.change-detail__link {
  color: var(--color-brand, #2563eb);
}
.change-form {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.change-form__field {
  display: flex;
  flex-direction: column;
  gap: 5px;
  color: var(--color-ink-secondary, #4b5563);
  font-size: 12px;
}
.change-form__field span {
  font-weight: 600;
}
.change-form__field select,
.change-form__field textarea {
  width: 100%;
  padding: 7px 9px;
  font: inherit;
  color: var(--color-ink, #111827);
  background: #fff;
  border: 1px solid var(--color-line-strong, #d1d5db);
  border-radius: 6px;
}
.change-form__error {
  margin: 0;
  padding: 9px 10px;
  color: #b91c1c;
  font-size: 12px;
  background: #fef2f2;
  border: 1px solid #fecaca;
  border-radius: 6px;
}
@media (max-width: 720px) {
  .change-detail__meta {
    grid-template-columns: 1fr;
  }
}
</style>
