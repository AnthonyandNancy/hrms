<script setup lang="ts">
import { computed } from 'vue'
import type { EmployeeRecord } from '../detailApi'

const props = withDefaults(
  defineProps<{
    employee: EmployeeRecord
    canEdit?: boolean
    canArchive?: boolean
    canRestore?: boolean
    busy?: boolean
  }>(),
  { canEdit: false, canArchive: false, canRestore: false, busy: false },
)

const emit = defineEmits<{
  edit: []
  archive: []
  restore: []
}>()

const EMPLOYEE_STATUS: Record<string, string> = {
  ACTIVE: '在职',
  PROBATION: '试用期',
  LEAVE: '休假中',
  ON_LEAVE: '休假中',
  SUSPENDED: '停职',
  TERMINATED: '已离职',
  ARCHIVED: '已归档',
}

const EMPLOYMENT_TYPE: Record<string, string> = {
  FULL_TIME: '全职',
  PART_TIME: '兼职',
  FIXED_TERM: '固定期限',
  INTERN: '实习',
  CONTRACTOR: '合同制',
}

const UNKNOWN = '未知状态'
const HIDDEN = '无权查看'
const EMPTY = '—'

const has = (value: unknown, key: string): boolean =>
  typeof value === 'object' && value !== null && Object.prototype.hasOwnProperty.call(value, key)

function text(value: unknown): string {
  if (value === null || value === undefined) return EMPTY
  if (typeof value === 'string') return value.trim() === '' ? EMPTY : value
  if (typeof value === 'number') return String(value)
  if (typeof value === 'boolean') return value ? '是' : '否'
  if (typeof value === 'object') {
    const record = value as Record<string, unknown>
    if (record.masked === true) return HIDDEN
    if (typeof record.display === 'string' && record.display !== '') return record.display
    if (typeof record.name === 'string' && record.name !== '') return record.name
    return HIDDEN
  }
  return HIDDEN
}

function projected(source: unknown, key: string): string {
  if (!has(source, key)) return HIDDEN
  const value = (source as Record<string, unknown>)[key]
  if (value === undefined) return HIDDEN
  return text(value)
}

const assignment = computed<Record<string, unknown> | null>(() =>
  typeof props.employee.current_assignment === 'object' && props.employee.current_assignment !== null
    ? (props.employee.current_assignment as Record<string, unknown>)
    : null,
)

const person = computed<Record<string, unknown> | null>(() =>
  typeof props.employee.person === 'object' && props.employee.person !== null
    ? (props.employee.person as Record<string, unknown>)
    : null,
)

const terms = computed<Record<string, unknown> | null>(() =>
  typeof props.employee.terms === 'object' && props.employee.terms !== null
    ? (props.employee.terms as Record<string, unknown>)
    : null,
)

function departmentValue(): string {
  if (!assignment.value) return HIDDEN
  if (has(assignment.value, 'org_unit_name')) {
    const value = assignment.value.org_unit_name
    return value === null || value === undefined || value === '' ? HIDDEN : text(value)
  }
  if (has(assignment.value, 'org_unit_id')) {
    const id = assignment.value.org_unit_id
    return typeof id === 'number' && id > 0 ? `部门 #${id}` : HIDDEN
  }
  return HIDDEN
}

function positionValue(): string {
  if (!assignment.value) return HIDDEN
  if (has(assignment.value, 'position_title')) {
    const value = assignment.value.position_title
    return value === null || value === undefined || value === '' ? HIDDEN : text(value)
  }
  if (has(assignment.value, 'position_id')) {
    const id = assignment.value.position_id
    return typeof id === 'number' && id > 0 ? `岗位 #${id}` : HIDDEN
  }
  return HIDDEN
}

function dateValue(value: unknown): string {
  if (typeof value !== 'string' || value.trim() === '') return HIDDEN
  const date = value.slice(0, 10)
  if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) return HIDDEN
  const [year, month, day] = date.split('-')
  return `${year}年${Number(month)}月${Number(day)}日`
}

function statusValue(): string {
  if (!has(props.employee, 'status')) return HIDDEN
  const value = props.employee.status
  if (typeof value !== 'string' || value.trim() === '') return HIDDEN
  return EMPLOYEE_STATUS[value] ?? UNKNOWN
}

function employmentTypeValue(): string {
  if (!has(props.employee, 'employment_type')) return HIDDEN
  const value = props.employee.employment_type
  if (typeof value !== 'string' || value.trim() === '') return HIDDEN
  return EMPLOYMENT_TYPE[value] ?? '未知类型'
}

const name = computed(() => projected(props.employee, 'person_name'))
const employeeNo = computed(() => projected(props.employee, 'employee_no'))
const archived = computed(() => {
  if (has(props.employee, 'archived_at')) {
    const value = props.employee.archived_at
    return value !== null && value !== undefined && value !== ''
  }
  return props.employee.status === 'ARCHIVED'
})

const fields = computed(() => [
  { key: 'name', label: '姓名', value: name.value },
  { key: 'employee_no', label: '员工编号', value: employeeNo.value },
  { key: 'department', label: '部门', value: departmentValue() },
  { key: 'position', label: '岗位', value: positionValue() },
  { key: 'status', label: '状态', value: statusValue() },
  {
    key: 'hire_date',
    label: '入职日期',
    value: has(props.employee, 'hire_date') ? dateValue(props.employee.hire_date) : HIDDEN,
  },
  { key: 'employment_type', label: '用工类型', value: employmentTypeValue() },
  { key: 'work_location', label: '工作地点', value: terms.value ? projected(terms.value, 'work_location') : HIDDEN },
  { key: 'work_schedule', label: '工作安排', value: terms.value ? projected(terms.value, 'work_schedule') : HIDDEN },
  { key: 'email', label: '联系邮箱', value: person.value ? projected(person.value, 'email') : HIDDEN },
])
</script>

<template>
  <div data-testid="employee-summary" class="employee-summary">
    <div v-if="archived" class="employee-summary__banner" role="status" data-testid="archived-banner">
      <strong>该员工已归档</strong>
      <span>档案只读；恢复后可重新进入在职管理。归档记录与历史不会删除。</span>
      <span v-if="employee.archive_reason" class="employee-summary__reason">归档原因：{{ employee.archive_reason }}</span>
    </div>

    <header class="employee-summary__header">
      <div>
        <p class="employee-summary__eyebrow">员工档案 · 同一员工对象</p>
        <h2 class="employee-summary__title">{{ name }}</h2>
        <p class="employee-summary__subtitle">本页所有页签都只属于这位员工，不混入薪资、考勤或绩效模块。</p>
      </div>
      <div class="employee-summary__actions">
        <button
          v-if="!archived && canEdit"
          type="button"
          class="employee-action"
          :disabled="busy"
          @click="emit('edit')"
        >
          编辑
        </button>
        <button
          v-if="!archived && canArchive"
          type="button"
          class="employee-action employee-action--danger"
          :disabled="busy"
          data-testid="archive-employee"
          @click="emit('archive')"
        >
          归档
        </button>
        <button
          v-if="archived && canRestore"
          type="button"
          class="employee-action employee-action--primary"
          :disabled="busy"
          data-testid="restore-employee"
          @click="emit('restore')"
        >
          恢复
        </button>
      </div>
    </header>

    <dl class="employee-summary__facts">
      <div v-for="field in fields" :key="field.key" class="employee-summary__fact">
        <dt>{{ field.label }}</dt>
        <dd :class="{ 'employee-summary__fact--hidden': field.value === HIDDEN }">{{ field.value }}</dd>
      </div>
    </dl>
  </div>
</template>

<style scoped>
.employee-summary {
  display: flex;
  flex-direction: column;
  gap: 16px;
  padding: 20px;
  background: var(--color-surface, #fff);
  border: 1px solid var(--color-line, #e5e7eb);
  border-radius: var(--radius-md, 10px);
}

.employee-summary__banner {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  padding: 12px 14px;
  color: #7a4b00;
  font-size: 13px;
  background: #fff7e6;
  border: 1px solid #ffd591;
  border-radius: 8px;
}

.employee-summary__reason {
  flex-basis: 100%;
}

.employee-summary__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
}

.employee-summary__eyebrow {
  margin: 0 0 4px;
  color: var(--color-ink-muted, #6b7280);
  font-size: 12px;
}

.employee-summary__title {
  margin: 0;
  font-size: 22px;
}

.employee-summary__subtitle {
  margin: 6px 0 0;
  color: var(--color-ink-secondary, #4b5563);
  font-size: 13px;
}

.employee-summary__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.employee-action {
  padding: 7px 14px;
  font: inherit;
  font-size: 13px;
  color: var(--color-ink, #111827);
  cursor: pointer;
  background: #fff;
  border: 1px solid var(--color-line, #d1d5db);
  border-radius: 6px;
}

.employee-action:disabled {
  cursor: not-allowed;
  opacity: 0.6;
}

.employee-action--primary {
  color: #fff;
  background: var(--color-brand, #2563eb);
  border-color: var(--color-brand, #2563eb);
}

.employee-action--danger {
  color: #b91c1c;
  border-color: #fca5a5;
}

.employee-summary__facts {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: 12px 18px;
  margin: 0;
}

.employee-summary__fact {
  min-width: 0;
}

.employee-summary__fact dt {
  color: var(--color-ink-muted, #6b7280);
  font-size: 12px;
}

.employee-summary__fact dd {
  margin: 4px 0 0;
  overflow: hidden;
  font-size: 14px;
  color: var(--color-ink, #111827);
  text-overflow: ellipsis;
  white-space: nowrap;
}

.employee-summary__fact--hidden {
  color: var(--color-ink-muted, #6b7280);
  font-style: italic;
}
</style>
