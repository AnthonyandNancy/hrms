<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import axios, { AxiosError } from 'axios'
import { ElMessage, ElMessageBox } from 'element-plus'
import { ApiError, errorCopy } from '@/api/errors'
import { resourceGet, resourcePost, resourcePatch } from '@/api/resourceClient'
import { requestIdFromError } from '@/utils/renderErrors'
import ActionGate from '@/components/ActionGate.vue'
import DataTable, { type DataTableColumn } from '@/components/DataTable.vue'
import ErrorState from '@/components/ErrorState.vue'
import FormDialog from '@/shared/ui/FormDialog.vue'
import { nextIdempotencyKey } from '@/modules/people/peopleRead'
import { todayBusinessDate } from '@/utils/format'

type ProfileResource = 'education' | 'experiences' | 'contacts' | 'certificates'
type FieldType = 'text' | 'date' | 'select' | 'textarea' | 'checkbox' | 'number'
type DisplayKind = 'text' | 'date' | 'enum' | 'boolean'

type FieldDefinition = {
  key: string
  label: string
  type: FieldType
  required?: boolean
  options?: ReadonlyArray<{ value: string; label: string }>
  hint?: string
}

type ColumnDefinition = {
  key: string
  label: string
  kind?: DisplayKind
  labels?: Readonly<Record<string, string>>
}

type SectionDefinition = {
  title: string
  singular: string
  addLabel: string
  columns: readonly ColumnDefinition[]
  fields: readonly FieldDefinition[]
  note?: string
}

const props = defineProps<{
  employmentId: number
  resource: ProfileResource
}>()

defineOptions({ name: 'EmployeeProfileSection' })

const HIDDEN = '无权查看'
const EMPTY = '—'
const NOT_REGISTERED = '未登记'

const RELATIONSHIP_OPTIONS = [
  { value: 'SPOUSE', label: '配偶' },
  { value: 'PARENT', label: '父母' },
  { value: 'CHILD', label: '子女' },
  { value: 'SIBLING', label: '兄弟姐妹' },
  { value: 'FRIEND', label: '朋友' },
  { value: 'OTHER', label: '其他' },
] as const

const DEGREE_OPTIONS = [
  { value: 'HIGH_SCHOOL', label: '高中' },
  { value: 'ASSOCIATE', label: '大专' },
  { value: 'BACHELOR', label: '本科' },
  { value: 'MASTER', label: '硕士' },
  { value: 'DOCTOR', label: '博士' },
  { value: 'OTHER', label: '其他' },
] as const

const CERTIFICATE_OPTIONS = [
  { value: 'PROFESSIONAL', label: '职业资格' },
  { value: 'ID', label: '身份证明' },
  { value: 'EDUCATION', label: '教育证明' },
  { value: 'OTHER', label: '其他' },
] as const

const DEGREE_LABELS: Readonly<Record<string, string>> = Object.fromEntries(
  DEGREE_OPTIONS.map((option) => [option.value, option.label]),
)
const RELATIONSHIP_LABELS: Readonly<Record<string, string>> = Object.fromEntries(
  RELATIONSHIP_OPTIONS.map((option) => [option.value, option.label]),
)
const CERTIFICATE_LABELS: Readonly<Record<string, string>> = Object.fromEntries(
  CERTIFICATE_OPTIONS.map((option) => [option.value, option.label]),
)

const RESOURCE_CONFIG: Record<ProfileResource, SectionDefinition> = {
  education: {
    title: '教育经历',
    singular: '教育经历',
    addLabel: '新增教育经历',
    columns: [
      { key: 'school', label: '学校' },
      { key: 'degree', label: '学历', kind: 'enum', labels: DEGREE_LABELS },
      { key: 'major', label: '专业' },
      { key: 'start_date', label: '开始日期', kind: 'date' },
      { key: 'end_date', label: '结束日期', kind: 'date' },
    ],
    fields: [
      { key: 'school', label: '学校', type: 'text', required: true },
      { key: 'degree', label: '学历', type: 'select', required: true, options: DEGREE_OPTIONS },
      { key: 'major', label: '专业', type: 'text' },
      { key: 'start_date', label: '开始日期', type: 'date', required: true },
      { key: 'end_date', label: '结束日期', type: 'date' },
      { key: 'notes', label: '备注', type: 'textarea' },
    ],
  },
  experiences: {
    title: '工作经历',
    singular: '工作经历',
    addLabel: '新增工作经历',
    columns: [
      { key: 'company', label: '任职单位' },
      { key: 'title', label: '岗位' },
      { key: 'start_date', label: '开始日期', kind: 'date' },
      { key: 'end_date', label: '结束日期', kind: 'date' },
      { key: 'leave_reason', label: '离职原因' },
    ],
    fields: [
      { key: 'company', label: '任职单位', type: 'text', required: true },
      { key: 'title', label: '岗位', type: 'text', required: true },
      { key: 'start_date', label: '开始日期', type: 'date', required: true },
      { key: 'end_date', label: '结束日期', type: 'date' },
      { key: 'leave_reason', label: '离职原因', type: 'text' },
      { key: 'notes', label: '备注', type: 'textarea' },
    ],
  },
  contacts: {
    title: '家庭/紧急联系人',
    singular: '联系人',
    addLabel: '新增联系人',
    note: '联系人电话属于 P2 敏感字段；页面只渲染服务端遮罩结果，不展示明文。',
    columns: [
      { key: 'name', label: '姓名' },
      { key: 'relationship', label: '关系', kind: 'enum', labels: RELATIONSHIP_LABELS },
      { key: 'phone', label: '电话' },
      { key: 'email', label: '邮箱' },
      { key: 'is_emergency', label: '紧急联系人', kind: 'boolean' },
    ],
    fields: [
      { key: 'name', label: '姓名', type: 'text', required: true },
      { key: 'relationship', label: '关系', type: 'select', required: true, options: RELATIONSHIP_OPTIONS },
      { key: 'phone', label: '电话', type: 'text', hint: '保存后只显示遮罩状态。' },
      { key: 'email', label: '邮箱', type: 'text' },
      { key: 'is_emergency', label: '紧急联系人', type: 'checkbox' },
      { key: 'is_primary', label: '主要联系人', type: 'checkbox' },
    ],
  },
  certificates: {
    title: '证照与资格',
    singular: '证照',
    addLabel: '新增证照',
    columns: [
      { key: 'certificate_type', label: '证照类型', kind: 'enum', labels: CERTIFICATE_LABELS },
      { key: 'certificate_no', label: '证照编号' },
      { key: 'issuer', label: '签发机构' },
      { key: 'issued_on', label: '签发日期', kind: 'date' },
      { key: 'expires_on', label: '有效期至', kind: 'date' },
    ],
    fields: [
      { key: 'certificate_type', label: '证照类型', type: 'select', required: true, options: CERTIFICATE_OPTIONS },
      { key: 'certificate_no', label: '证照编号', type: 'text' },
      { key: 'issuer', label: '签发机构', type: 'text' },
      { key: 'issued_on', label: '签发日期', type: 'date' },
      { key: 'expires_on', label: '有效期至', type: 'date' },
      { key: 'document_id', label: '关联附件编号', type: 'number' },
    ],
  },
}

const rows = ref<Array<Record<string, unknown>>>([])
const total = ref(0)
const loading = ref(false)
const forbidden = ref(false)
const error = ref<string | null>(null)
const requestId = ref<string | null>(null)
const formOpen = ref(false)
const editingId = ref<number | null>(null)
const submitting = ref(false)
const formError = ref<string | null>(null)
const fieldErrors = ref<Record<string, string[]>>({})
const draft = ref<Record<string, unknown>>({})
const retiringId = ref<number | null>(null)

const config = computed(() => RESOURCE_CONFIG[props.resource])
const sectionTestId = computed(() => `profile-${props.resource}`)
const dialogTitle = computed(() =>
  editingId.value === null ? config.value.addLabel : `修订${config.value.singular} · 旧版本保留`,
)
const columns = computed<DataTableColumn[]>(() => [
  ...config.value.columns.map((column) => ({ key: column.key, label: column.label })),
  { key: 'display_actions', label: '操作', width: '150px' },
])

function has(row: Record<string, unknown>, key: string): boolean {
  return Object.prototype.hasOwnProperty.call(row, key)
}

function isMasked(value: unknown): boolean {
  return typeof value === 'object' && value !== null && (value as Record<string, unknown>).masked === true
}

function text(value: unknown): string {
  if (value === null || value === undefined) return EMPTY
  if (typeof value === 'string') return value.trim() === '' ? EMPTY : value
  if (typeof value === 'number') return String(value)
  if (typeof value === 'boolean') return value ? '是' : '否'
  if (typeof value === 'object') {
    if (isMasked(value)) return HIDDEN
    return HIDDEN
  }
  return HIDDEN
}

function cell(row: Record<string, unknown>, column: ColumnDefinition): string {
  if (!has(row, column.key)) return HIDDEN
  const value = row[column.key]
  if (value === null || value === undefined) return NOT_REGISTERED
  if (column.kind === 'boolean') return typeof value === 'boolean' ? (value ? '是' : '否') : HIDDEN
  if (column.kind === 'enum' && typeof value === 'string') return column.labels?.[value] ?? HIDDEN
  if (column.kind === 'date' && typeof value === 'string') {
    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(value)
    return match === null ? HIDDEN : `${match[1]}年${Number(match[2])}月${Number(match[3])}日`
  }
  return text(value)
}

const displayRows = computed(() =>
  rows.value.map((row) => {
    const display: Record<string, unknown> = { ...row }
    for (const column of config.value.columns) {
      display[column.key] = cell(row, column)
    }
    return display
  }),
)

function openCreate(): void {
  editingId.value = null
  draft.value = {}
  for (const field of config.value.fields) {
    draft.value[field.key] = field.type === 'checkbox' ? false : ''
  }
  formError.value = null
  fieldErrors.value = {}
  formOpen.value = true
}

function openEdit(id: number): void {
  const row = rows.value.find((candidate) => Number(candidate.id) === id)
  if (row === undefined) return
  editingId.value = id
  const next: Record<string, unknown> = {}
  for (const field of config.value.fields) {
    const value = row[field.key]
    next[field.key] =
      field.type === 'checkbox'
        ? value === true
        : value === null || value === undefined || isMasked(value)
          ? ''
          : String(value)
  }
  draft.value = next
  formError.value = null
  fieldErrors.value = {}
  formOpen.value = true
}

function setText(key: string, value: string): void {
  draft.value = { ...draft.value, [key]: value }
}

function setBoolean(key: string, value: boolean): void {
  draft.value = { ...draft.value, [key]: value }
}

function validateDraft(): string {
  for (const field of config.value.fields) {
    if (field.required !== true) continue
    const value = draft.value[field.key]
    if (value === null || value === undefined || String(value).trim() === '') {
      return `${field.label}为必填项。`
    }
  }
  return ''
}

function bodyFor(): Record<string, unknown> {
  const body: Record<string, unknown> = {}
  for (const field of config.value.fields) {
    const value = draft.value[field.key]
    if (field.type === 'checkbox') {
      body[field.key] = value === true
    } else if (field.type === 'number') {
      const parsed = Number(value)
      body[field.key] = Number.isFinite(parsed) && parsed > 0 ? parsed : null
    } else {
      const trimmed = value === null || value === undefined ? '' : String(value).trim()
      body[field.key] = trimmed === '' ? null : trimmed
    }
  }
  return body
}

async function reload(): Promise<void> {
  loading.value = true
  error.value = null
  requestId.value = null
  forbidden.value = false
  try {
    const payload = await resourceGet<unknown>(`/v1/employees/${props.employmentId}/${props.resource}`)
    const items = Array.isArray(payload)
      ? payload
      : typeof payload === 'object' && payload !== null && Array.isArray((payload as Record<string, unknown>).items)
        ? ((payload as Record<string, unknown>).items as unknown[])
        : []
    rows.value = items.filter(
      (row): row is Record<string, unknown> => typeof row === 'object' && row !== null && !Array.isArray(row),
    )
    total.value = rows.value.length
  } catch (reason) {
    if (reason instanceof ApiError && (reason.status === 403 || reason.code === 'FORBIDDEN')) {
      forbidden.value = true
      rows.value = []
      total.value = 0
      return
    }
    error.value = errorCopy(reason)
    requestId.value = requestIdFromError(reason)
  } finally {
    loading.value = false
  }
}

async function submit(): Promise<void> {
  formError.value = null
  fieldErrors.value = {}
  const validation = validateDraft()
  if (validation !== '') {
    formError.value = validation
    return
  }

  submitting.value = true
  try {
    const base = `/v1/employees/${props.employmentId}/${props.resource}`
    const body = bodyFor()
    if (editingId.value === null) {
      await resourcePost(base, body, { idempotencyKey: nextIdempotencyKey(`profile-${props.resource}`) })
      ElMessage.success(`${config.value.singular}已保存。`)
    } else {
      await resourcePatch(`${base}/${editingId.value}`, body, {
        idempotencyKey: nextIdempotencyKey(`profile-${props.resource}-edit`),
      })
      ElMessage.success(`${config.value.singular}修订已保存；旧版本保留在历史中。`)
    }
    formOpen.value = false
    await reload()
  } catch (reason) {
    if (reason instanceof ApiError) {
      fieldErrors.value = reason.fieldErrors
      formError.value = reason.message || errorCopy(reason)
    } else {
      formError.value = errorCopy(reason)
    }
  } finally {
    submitting.value = false
  }
}

async function retire(id: number): Promise<void> {
  if (retiringId.value !== null) return

  let reason: string
  try {
    const result = await ElMessageBox.prompt(
      `确认退役${config.value.singular} #${id}？记录不会被物理删除，历史版本仍保留；退役后将不再出现在当前档案中。请输入退役原因（至少 3 个字符）。`,
      `退役${config.value.singular}`,
      {
        confirmButtonText: '确认退役',
        cancelButtonText: '取消',
        inputType: 'textarea',
        inputPlaceholder: '例如：重复录入，经员工确认退役',
        inputValidator: (value: string) => (value.trim().length >= 3 ? true : '退役原因至少需要 3 个字符'),
        type: 'warning',
      },
    )
    reason = String(result.value ?? '').trim()
  } catch {
    return
  }

  if (reason.length < 3) return

  retiringId.value = id
  try {
    // resourceDelete cannot carry the JSON command body, so call the shared
    // client instance directly with the explicitly confirmed reason.
    await deleteWithBody(`/v1/employees/${props.employmentId}/${props.resource}/${id}`, {
      reason,
      effective_from: todayBusinessDate(),
    }, nextIdempotencyKey(`profile-${props.resource}-retire`))
    ElMessage.success(`${config.value.singular}已退役；历史版本仍保留。`)
    await reload()
  } catch (reasonValue) {
    if (
      reasonValue instanceof ApiError &&
      reasonValue.code === 'CONFLICT' &&
      reasonValue.serverReason === 'PROFILE_ALREADY_RETIRED'
    ) {
      ElMessage.error('该记录已退役，列表已刷新。')
      await reload()
    } else {
      ElMessage.error(errorCopy(reasonValue))
    }
  } finally {
    retiringId.value = null
  }
}

async function deleteWithBody(path: string, data: Record<string, unknown>, idempotencyKey: string): Promise<void> {
  const base = import.meta.env.VITE_API_BASE_URL ?? '/v1'
  const normalized = path.startsWith('/v1/') ? path.slice(3) : path
  try {
    await axios.delete(`${base}${normalized}`, {
      data,
      withCredentials: true,
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'Idempotency-Key': idempotencyKey,
      },
    })
  } catch (error) {
    if (error instanceof ApiError) throw error
    const axiosError = error as AxiosError<{
      code?: string
      message?: string
      data?: { reason?: string; fields?: Record<string, string[]> }
      request_id?: string
    }>
    if (!axiosError.response) throw new ApiError('NETWORK_ERROR', '网络连接失败', { status: 0 })
    const body = axiosError.response.data
    throw new ApiError((body?.code ?? 'INTERNAL') as ApiError['code'], body?.message ?? '记录退役未完成', {
      status: axiosError.response.status,
      requestId: body?.request_id ?? null,
      fieldErrors: body?.data?.fields ?? {},
      details: body?.data ?? {},
    })
  }
}

function closeForm(): void {
  if (submitting.value) return
  formOpen.value = false
}

function firstFieldError(key: string): string {
  return fieldErrors.value[key]?.[0] ?? ''
}

onMounted(() => {
  void reload()
})

watch(
  () => [props.employmentId, props.resource],
  () => {
    formOpen.value = false
    void reload()
  },
)
</script>

<template>
  <section class="profile-section" :data-testid="sectionTestId">
    <header class="profile-section__header">
      <div>
        <h3>{{ config.title }}</h3>
        <p>{{ config.note ?? '按服务端返回的字段展示；缺省或遮罩字段统一显示“无权查看”。' }}</p>
      </div>
      <ActionGate capability="people.employee.manage">
        <button type="button" class="profile-section__add" data-testid="profile-add" @click="openCreate">
          {{ config.addLabel }}
        </button>
      </ActionGate>
    </header>

    <section v-if="forbidden" class="profile-section__state profile-section__state--forbidden" data-testid="profile-forbidden">
      <h4>无权查看</h4>
      <p>你没有查看该类档案的权限；员工摘要与其他档案区域仍可使用。</p>
    </section>

    <ErrorState
      v-else-if="error"
      :title="`${config.title}暂时无法加载`"
      :description="error"
      :request-id="requestId"
      @retry="reload"
    />

    <DataTable
      v-else
      :columns="columns"
      :rows="displayRows"
      :total="total"
      :page="1"
      :page-size="20"
      :loading="loading"
      :empty-title="`暂无${config.title}记录`"
    >
      <template #cell-display_actions="{ row }">
        <ActionGate capability="people.employee.manage">
          <button
            type="button"
            class="profile-section__edit"
            :aria-label="`编辑${config.singular} #${String(row.id)}`"
            @click="openEdit(Number(row.id))"
          >
            编辑
          </button>
          <button
            type="button"
            class="profile-section__retire"
            :aria-label="`退役${config.singular} #${String(row.id)}`"
            :disabled="retiringId === Number(row.id)"
            @click="retire(Number(row.id))"
          >
            退役
          </button>
        </ActionGate>
      </template>
    </DataTable>

    <FormDialog
      v-if="formOpen"
      :model-value="formOpen"
      :title="dialogTitle"
      :confirm-loading="submitting"
      @update:model-value="closeForm"
      @confirm="submit"
    >
      <div class="profile-form" data-testid="profile-form">
        <label v-for="field in config.fields" :key="field.key" class="profile-form__field">
          <span>{{ field.label }}<em v-if="field.required"> *</em></span>
          <select
            v-if="field.type === 'select'"
            :value="String(draft[field.key] ?? '')"
            :aria-label="field.label"
            :data-testid="`profile-field-${field.key}`"
            @change="setText(field.key, ($event.target as HTMLSelectElement).value)"
          >
            <option value="">请选择</option>
            <option v-for="option in field.options" :key="option.value" :value="option.value">
              {{ option.label }}
            </option>
          </select>
          <textarea
            v-else-if="field.type === 'textarea'"
            :value="String(draft[field.key] ?? '')"
            :aria-label="field.label"
            :data-testid="`profile-field-${field.key}`"
            @input="setText(field.key, ($event.target as HTMLTextAreaElement).value)"
          />
          <input
            v-else-if="field.type === 'checkbox'"
            type="checkbox"
            :checked="draft[field.key] === true"
            :aria-label="field.label"
            :data-testid="`profile-field-${field.key}`"
            @change="setBoolean(field.key, ($event.target as HTMLInputElement).checked)"
          />
          <input
            v-else
            :type="field.type"
            :value="String(draft[field.key] ?? '')"
            :aria-label="field.label"
            :data-testid="`profile-field-${field.key}`"
            @input="setText(field.key, ($event.target as HTMLInputElement).value)"
          />
          <small v-if="firstFieldError(field.key) !== ''" class="profile-form__field-error">
            {{ firstFieldError(field.key) }}
          </small>
          <small v-else-if="field.hint" class="profile-form__hint">{{ field.hint }}</small>
        </label>
        <p v-if="formError" class="profile-form__error" data-testid="profile-form-error">{{ formError }}</p>
      </div>
    </FormDialog>
  </section>
</template>

<style scoped>
.profile-section {
  padding: 16px 18px;
  background: var(--color-surface, #fff);
  border: 1px solid var(--color-line, #e5e7eb);
  border-radius: var(--radius-md, 10px);
}
.profile-section__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 12px;
}
.profile-section__header h3 {
  margin: 0;
  font-size: 15px;
}
.profile-section__header p {
  margin: 6px 0 0;
  color: var(--color-ink-muted, #6b7280);
  font-size: 12px;
}
.profile-section__add,
.profile-section__edit,
.profile-section__retire {
  padding: 5px 12px;
  font: inherit;
  font-size: 12px;
  cursor: pointer;
  background: #fff;
  border: 1px solid var(--color-line-strong, #d1d5db);
  border-radius: 5px;
}
.profile-section__add {
  color: #fff;
  background: var(--color-brand, #2563eb);
  border-color: var(--color-brand, #2563eb);
}
.profile-section__edit {
  margin-right: 6px;
}
.profile-section__retire {
  color: #b91c1c;
  border-color: #fecaca;
}
.profile-section__retire:disabled {
  cursor: wait;
  opacity: 0.6;
}
.profile-section__state {
  padding: 20px;
  text-align: center;
  background: var(--color-canvas, #f9fafb);
  border-radius: 8px;
}
.profile-section__state h4 {
  margin: 0 0 6px;
  font-size: 14px;
}
.profile-section__state p {
  margin: 4px 0;
  color: var(--color-ink-secondary, #4b5563);
  font-size: 13px;
}
.profile-section__state--forbidden {
  color: #991b1b;
  background: #fef2f2;
}
.profile-form {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.profile-form__field {
  display: flex;
  flex-direction: column;
  gap: 5px;
  color: var(--color-ink-secondary, #4b5563);
  font-size: 12px;
}
.profile-form__field span {
  font-weight: 600;
}
.profile-form__field input,
.profile-form__field select,
.profile-form__field textarea {
  width: 100%;
  padding: 7px 9px;
  font: inherit;
  color: var(--color-ink, #111827);
  background: #fff;
  border: 1px solid var(--color-line-strong, #d1d5db);
  border-radius: 6px;
}
.profile-form__field input[type='checkbox'] {
  width: auto;
  align-self: flex-start;
}
.profile-form__field-error {
  color: #b91c1c;
}
.profile-form__hint {
  color: var(--color-ink-muted, #6b7280);
}
.profile-form__error {
  margin: 0;
  padding: 9px 10px;
  color: #b91c1c;
  font-size: 12px;
  background: #fef2f2;
  border: 1px solid #fecaca;
  border-radius: 6px;
}
</style>
