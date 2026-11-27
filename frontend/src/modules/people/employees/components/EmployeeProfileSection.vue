<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import { ApiError, errorCopy } from '@/api/errors'
import { resourceGet, resourcePatch, resourcePost } from '@/api/resourceClient'
import { requestIdFromError } from '@/utils/renderErrors'
import ActionGate from '@/components/ActionGate.vue'
import DataTable, { type DataTableColumn } from '@/components/DataTable.vue'
import ErrorState from '@/components/ErrorState.vue'
import FormDialog from '@/shared/ui/FormDialog.vue'
import { nextIdempotencyKey } from '@/modules/people/peopleRead'

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
    note: '联系人接口不提供生效日期字段；页面按字段策略显示“无权查看”，不根据登记时间推断生效日期。',
    columns: [
      { key: 'name', label: '联系人' },
      { key: 'relationship', label: '关系', kind: 'enum', labels: RELATIONSHIP_LABELS },
      { key: 'phone', label: '联系电话' },
      { key: 'email', label: '邮箱' },
      { key: 'is_emergency', label: '紧急联系人', kind: 'boolean' },
      { key: 'effective_date', label: '生效日期', kind: 'date' },
    ],
    fields: [
      { key: 'name', label: '联系人', type: 'text', required: true },
      { key: 'relationship', label: '关系', type: 'select', required: true, options: RELATIONSHIP_OPTIONS },
      { key: 'phone', label: '联系电话', type: 'text', hint: 'P2 敏感字段，仅提交，接口不会回显明文。' },
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
      { key: 'expires_on', label: '到期日期', kind: 'date' },
    ],
    fields: [
      { key: 'certificate_type', label: '证照类型', type: 'select', required: true, options: CERTIFICATE_OPTIONS },
      { key: 'certificate_no', label: '证照编号', type: 'text' },
      { key: 'issuer', label: '签发机构', type: 'text' },
      { key: 'issued_on', label: '签发日期', type: 'date' },
      { key: 'expires_on', label: '到期日期', type: 'date' },
      { key: 'document_id', label: '关联文档编号', type: 'number' },
    ],
  },
}

type ProfileRow = Record<string, unknown>
type Draft = Record<string, string | boolean>

const rows = ref<ProfileRow[]>([])
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
const draft = ref<Draft>({})

const config = computed(() => RESOURCE_CONFIG[props.resource])
const sectionTestId = computed(() => `profile-section-${props.resource}`)
const columns = computed<DataTableColumn[]>(() => [
  ...config.value.columns.map((column) => ({ key: `display_${column.key}`, label: column.label })),
  { key: 'display_actions', label: '操作', width: '80px' },
])
const isEditing = computed(() => editingId.value !== null)
const dialogTitle = computed(() =>
  isEditing.value ? `编辑${config.value.singular}` : `新增${config.value.singular}`,
)

function isRecord(value: unknown): value is ProfileRow {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function has(row: ProfileRow, key: string): boolean {
  return Object.prototype.hasOwnProperty.call(row, key)
}

function businessDate(value: unknown): string {
  if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value.slice(0, 10))) return HIDDEN
  const [year, month, day] = value.slice(0, 10).split('-')
  return `${year}年${Number(month)}月${Number(day)}日`
}

/**
 * A field that the API omitted or the field policy masked is not the same as
 * an explicitly empty value. Both are surfaced honestly; neither is inferred.
 */
function displayCell(row: ProfileRow, column: ColumnDefinition): string {
  if (!has(row, column.key)) return HIDDEN
  const value = row[column.key]
  if (value === null || value === undefined) return NOT_REGISTERED
  if (typeof value === 'object') {
    return isRecord(value) && value.masked === true ? HIDDEN : HIDDEN
  }
  if (column.kind === 'enum') {
    if (typeof value !== 'string' || value.trim() === '') return HIDDEN
    return column.labels?.[value] ?? '未知'
  }
  if (column.kind === 'date') {
    if (value === '') return HIDDEN
    return businessDate(value)
  }
  if (column.kind === 'boolean') {
    if (typeof value !== 'boolean') return HIDDEN
    return value ? '是' : '否'
  }
  if (typeof value === 'string') return value.trim() === '' ? HIDDEN : value
  if (typeof value === 'number') return String(value)
  if (typeof value === 'boolean') return value ? '是' : '否'
  return HIDDEN
}

const displayRows = computed<ProfileRow[]>(() =>
  rows.value.map((row) => {
    const display: ProfileRow = { id: row.id }
    for (const column of config.value.columns) {
      display[`display_${column.key}`] = displayCell(row, column)
    }
    display.display_actions = ''
    return display
  }),
)

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
    const payload = await resourceGet<unknown>(`/v1/employees/${props.employmentId}/${props.resource}`)
    const items = Array.isArray(payload)
      ? payload
      : isRecord(payload) && Array.isArray(payload.items)
        ? payload.items
        : []
    rows.value = items.filter(isRecord)
    total.value =
      isRecord(payload) && typeof payload.total === 'number' ? payload.total : rows.value.length
  } catch (reason) {
    rows.value = []
    total.value = 0
    rejectState(reason)
  } finally {
    loading.value = false
  }
}

function draftFor(row: ProfileRow | null): Draft {
  const result: Draft = {}
  for (const field of config.value.fields) {
    if (field.type === 'checkbox') {
      result[field.key] = row !== null && row[field.key] === true
      continue
    }
    const value = row?.[field.key]
    result[field.key] = typeof value === 'string' || typeof value === 'number' ? String(value) : ''
  }
  return result
}

function openCreate(): void {
  editingId.value = null
  draft.value = draftFor(null)
  formError.value = null
  fieldErrors.value = {}
  formOpen.value = true
}

function openEdit(id: number): void {
  const row = rows.value.find((item) => Number(item.id) === id)
  if (row === undefined) return
  editingId.value = id
  draft.value = draftFor(row)
  formError.value = null
  fieldErrors.value = {}
  formOpen.value = true
}

function setCheckbox(key: string, checked: boolean): void {
  draft.value = { ...draft.value, [key]: checked }
}

function setText(key: string, value: string): void {
  draft.value = { ...draft.value, [key]: value }
}

function validateDraft(): string {
  for (const field of config.value.fields) {
    if (field.type === 'checkbox' || field.required !== true) continue
    const value = draft.value[field.key]
    if (typeof value !== 'string' || value.trim() === '') {
      return `请填写${field.label}。`
    }
  }
  const start = draft.value.start_date
  const end = draft.value.end_date
  if (typeof start === 'string' && typeof end === 'string' && start !== '' && end !== '') {
    if (end < start) return '结束日期不得早于开始日期。'
  }
  const issued = draft.value.issued_on
  const expires = draft.value.expires_on
  if (typeof issued === 'string' && typeof expires === 'string' && issued !== '' && expires !== '') {
    if (expires < issued) return '到期日期不得早于签发日期。'
  }
  return ''
}

function bodyFor(): Record<string, unknown> {
  const body: Record<string, unknown> = {}
  for (const field of config.value.fields) {
    const value = draft.value[field.key]
    if (field.type === 'checkbox') {
      body[field.key] = value === true
      continue
    }
    const text = typeof value === 'string' ? value.trim() : ''
    if (field.type === 'number') {
      body[field.key] = text === '' ? null : Number(text)
      continue
    }
    body[field.key] = text === '' ? null : text
  }
  return body
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
    await reload(false)
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
      @retry="reload()"
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
            rows="3"
            @input="setText(field.key, ($event.target as HTMLTextAreaElement).value)"
          />
          <input
            v-else-if="field.type === 'checkbox'"
            type="checkbox"
            :checked="draft[field.key] === true"
            :aria-label="field.label"
            :data-testid="`profile-field-${field.key}`"
            @change="setCheckbox(field.key, ($event.target as HTMLInputElement).checked)"
          >
          <input
            v-else
            :value="String(draft[field.key] ?? '')"
            :type="field.type === 'date' ? 'date' : field.type === 'number' ? 'number' : 'text'"
            :aria-label="field.label"
            :data-testid="`profile-field-${field.key}`"
            @input="setText(field.key, ($event.target as HTMLInputElement).value)"
          >
          <small v-if="field.hint">{{ field.hint }}</small>
          <small v-if="firstFieldError(field.key)" class="profile-form__error">
            {{ firstFieldError(field.key) }}
          </small>
        </label>
        <p v-if="formError" class="profile-form__alert" role="alert" data-testid="profile-form-error">
          {{ formError }}
        </p>
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
  gap: 12px;
  align-items: flex-start;
  justify-content: space-between;
  margin-bottom: 12px;
}
.profile-section__header h3 {
  margin: 0;
  font-size: 15px;
}
.profile-section__header p {
  margin: 5px 0 0;
  color: var(--color-ink-muted, #6b7280);
  font-size: 12px;
}
.profile-section__add {
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
.profile-section__state {
  padding: 20px;
  text-align: center;
  background: var(--color-canvas, #f9fafb);
  border-radius: 8px;
}
.profile-section__state--forbidden {
  color: #991b1b;
  background: #fef2f2;
}
.profile-section__state h4 {
  margin: 0 0 6px;
}
.profile-section__edit {
  padding: 3px 8px;
  color: var(--color-brand, #2563eb);
  font: inherit;
  font-size: 12px;
  cursor: pointer;
  background: transparent;
  border: 1px solid var(--color-line, #d1d5db);
  border-radius: 5px;
}
.profile-form {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}
.profile-form__field {
  display: flex;
  flex-direction: column;
  gap: 5px;
  color: var(--color-ink-secondary, #4b5563);
  font-size: 12px;
}
.profile-form__field:has(textarea),
.profile-form__field:has(.profile-form__error) {
  grid-column: 1 / -1;
}
.profile-form__field span {
  font-weight: 600;
}
.profile-form__field em {
  color: #b91c1c;
  font-style: normal;
}
.profile-form__field input[type='text'],
.profile-form__field input[type='date'],
.profile-form__field input[type='number'],
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
.profile-form__field small {
  color: var(--color-ink-muted, #6b7280);
  font-size: 11px;
}
.profile-form__field .profile-form__error,
.profile-form__alert {
  color: #b91c1c;
}
.profile-form__alert {
  grid-column: 1 / -1;
  margin: 0;
  padding: 9px 10px;
  font-size: 12px;
  background: #fef2f2;
  border: 1px solid #fecaca;
  border-radius: 6px;
}
@media (max-width: 720px) {
  .profile-form {
    grid-template-columns: 1fr;
  }
}
</style>
