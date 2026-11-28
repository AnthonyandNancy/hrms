<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import FormDrawer from '@/shared/ui/FormDrawer.vue'
import { ApiError, errorCopy } from '@/api/errors'
import { nextIdempotencyKey } from '@/modules/people/peopleRead'
import { DISPLAY_CODES, translate } from '@/shared/display/dictionary'
import {
  onboardEmployee,
  type OnboardPayload,
  type OrgUnitOption,
  type PendingOnboardingCase,
  type PositionOption,
} from '../api'

const props = withDefaults(
  defineProps<{
    modelValue: boolean
    orgUnits?: OrgUnitOption[]
    positions?: PositionOption[]
  }>(),
  { orgUnits: () => [], positions: () => [] },
)

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  created: [result: PendingOnboardingCase]
}>()

type FieldKey =
  | 'person.family_name'
  | 'person.given_name'
  | 'person.email'
  | 'person.mobile'
  | 'person.national_id'
  | 'employment.employee_no'
  | 'employment.hire_date'
  | 'employment.employment_type'
  | 'assignment.org_unit_id'
  | 'assignment.position_id'
  | 'assignment.valid_from'
  | 'terms.salary_minor'
  | 'terms.currency'
  | 'terms.work_location'
  | 'terms.work_schedule'
  | 'terms.effective_from'

const EMPLOYMENT_TYPES = [
  { value: DISPLAY_CODES.employmentType.fullTime, label: translate('people', 'employmentType', DISPLAY_CODES.employmentType.fullTime).label },
  { value: DISPLAY_CODES.employmentType.partTime, label: translate('people', 'employmentType', DISPLAY_CODES.employmentType.partTime).label },
  { value: DISPLAY_CODES.employmentType.fixedTerm, label: translate('people', 'employmentType', DISPLAY_CODES.employmentType.fixedTerm).label },
  { value: DISPLAY_CODES.employmentType.intern, label: translate('people', 'employmentType', DISPLAY_CODES.employmentType.intern).label },
  { value: DISPLAY_CODES.employmentType.contractor, label: translate('people', 'employmentType', DISPLAY_CODES.employmentType.contractor).label },
] as const

function today(): string {
  return new Date().toISOString().slice(0, 10)
}

function blankDraft() {
  return {
    family_name: '',
    given_name: '',
    email: '',
    mobile: '',
    national_id: '',
    employee_no: '',
    hire_date: today(),
    employment_type: DISPLAY_CODES.employmentType.fullTime,
    org_unit_id: props.orgUnits[0] === undefined ? '' : String(props.orgUnits[0].id),
    position_id: '',
    valid_from: '',
    salary_minor: '',
    currency: 'CNY',
    work_location: '',
    work_schedule: '',
    effective_from: '',
  }
}

const draft = reactive(blankDraft())
const submitting = ref(false)
const summaryError = ref('')
const fieldErrors = reactive<Partial<Record<FieldKey, string>>>({})

/**
 * One key belongs to one logical submission. It is only replaced after a
 * success or after the operator edits the payload after a failure, so an
 * ambiguous timeout can be retried without creating a duplicate employee.
 */
let idempotencyKey: string | null = null
let keyPayload: string | null = null

const title = computed(() => '新建员工')

function payload(): OnboardPayload {
  return {
    person: {
      given_name: draft.given_name.trim(),
      family_name: draft.family_name.trim(),
      email: draft.email.trim() === '' ? null : draft.email.trim(),
      mobile: draft.mobile.trim() === '' ? null : draft.mobile.trim(),
      national_id: draft.national_id.trim() === '' ? null : draft.national_id.trim(),
    },
    employment: {
      employee_no: draft.employee_no.trim(),
      hire_date: draft.hire_date,
      employment_type: draft.employment_type,
    },
    assignment: {
      org_unit_id: Number(draft.org_unit_id),
      position_id: draft.position_id === '' ? null : Number(draft.position_id),
      valid_from: draft.valid_from === '' ? null : draft.valid_from,
    },
    terms: {
      salary_minor: Number(draft.salary_minor),
      currency: draft.currency.trim().toUpperCase() || 'CNY',
      work_location: draft.work_location.trim() === '' ? null : draft.work_location.trim(),
      work_schedule: draft.work_schedule.trim() === '' ? null : draft.work_schedule.trim(),
      effective_from: draft.effective_from === '' ? null : draft.effective_from,
    },
  }
}

function clearErrors(): void {
  summaryError.value = ''
  for (const key of Object.keys(fieldErrors) as FieldKey[]) delete fieldErrors[key]
}

function setFieldError(field: string, message: string): boolean {
  const known: readonly FieldKey[] = [
    'person.family_name',
    'person.given_name',
    'person.email',
    'person.mobile',
    'person.national_id',
    'employment.employee_no',
    'employment.hire_date',
    'employment.employment_type',
    'assignment.org_unit_id',
    'assignment.position_id',
    'assignment.valid_from',
    'terms.salary_minor',
    'terms.currency',
    'terms.work_location',
    'terms.work_schedule',
    'terms.effective_from',
  ]
  if (!known.includes(field as FieldKey)) return false
  fieldErrors[field as FieldKey] = message
  return true
}

function keyFor(request: OnboardPayload): string {
  const encoded = JSON.stringify(request)
  if (idempotencyKey === null || keyPayload !== encoded) {
    idempotencyKey = nextIdempotencyKey('employee-onboard')
    keyPayload = encoded
  }
  return idempotencyKey
}

function validate(): boolean {
  clearErrors()
  let valid = true
  if (draft.family_name.trim() === '') {
    fieldErrors['person.family_name'] = '请输入姓。'
    valid = false
  }
  if (draft.given_name.trim() === '') {
    fieldErrors['person.given_name'] = '请输入名。'
    valid = false
  }
  if (draft.employee_no.trim() === '') {
    fieldErrors['employment.employee_no'] = '请输入员工编号。'
    valid = false
  }
  if (!/^\d{4}-\d{2}-\d{2}$/.test(draft.hire_date)) {
    fieldErrors['employment.hire_date'] = '请选择入职日期。'
    valid = false
  }
  const orgUnitId = Number(draft.org_unit_id)
  if (!Number.isInteger(orgUnitId) || orgUnitId <= 0) {
    fieldErrors['assignment.org_unit_id'] = '请选择部门。'
    valid = false
  }
  const salary = Number(draft.salary_minor)
  if (!Number.isInteger(salary) || salary <= 0) {
    fieldErrors['terms.salary_minor'] = '请输入正整数基本工资（最小货币单位）。'
    valid = false
  }
  if (!/^[A-Za-z]{3}$/.test(draft.currency.trim())) {
    fieldErrors['terms.currency'] = '币种必须是三位字母代码。'
    valid = false
  }
  if (!valid) summaryError.value = '请检查标记的字段后再提交。'
  return valid
}

function conflictReason(error: ApiError): string | null {
  const reason = error.serverReason
  return typeof reason === 'string' ? reason : null
}

function applyError(error: unknown): void {
  clearErrors()
  if (error instanceof ApiError && error.code === 'VALIDATION_FAILED') {
    const mapped = Object.entries(error.fieldErrors).filter(([field, messages]) =>
      setFieldError(
        field,
        Array.isArray(messages) && typeof messages[0] === 'string' && messages[0] !== ''
          ? messages[0]
          : '该字段未通过校验。',
      ),
    )
    summaryError.value = '部分字段未通过校验，请检查标记的输入。'
    if (mapped.length === 0) summaryError.value = '提交内容未通过校验，请检查输入。'
    return
  }
  if (error instanceof ApiError && error.code === 'CONFLICT') {
    if (conflictReason(error) === 'EMPLOYEE_NO_DUPLICATE') {
      fieldErrors['employment.employee_no'] = `员工编号 ${draft.employee_no.trim()} 已存在，请更换后再提交。`
      summaryError.value = '员工编号重复，无法创建员工。'
      return
    }
    summaryError.value = '数据状态已发生变化，请刷新列表后重试。'
    return
  }
  summaryError.value = errorCopy(error)
}

async function submit(): Promise<void> {
  if (submitting.value) return
  if (!validate()) return

  const request = payload()
  const requestKey = keyFor(request)
  submitting.value = true
  try {
    const result = await onboardEmployee(request, requestKey)
    idempotencyKey = null
    keyPayload = null
    clearErrors()
    emit('created', result)
    emit('update:modelValue', false)
  } catch (error) {
    applyError(error)
  } finally {
    submitting.value = false
  }
}

function close(): void {
  if (submitting.value) return
  emit('update:modelValue', false)
}

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    clearErrors()
    Object.assign(draft, blankDraft())
    idempotencyKey = null
    keyPayload = null
  },
)
</script>

<template>
  <FormDrawer
    :model-value="modelValue"
    :title="title"
    :confirm-loading="submitting"
    @update:model-value="close"
    @confirm="submit"
  >
    <form class="employee-drawer" novalidate @submit.prevent="submit">
      <p v-if="summaryError" class="employee-drawer__error" role="alert" data-testid="employee-drawer-error">
        {{ summaryError }}
      </p>

      <fieldset class="employee-drawer__group">
        <legend>主档案</legend>
        <label class="employee-drawer__field">
          <span>姓 *</span>
          <input v-model="draft.family_name" aria-label="姓" :aria-invalid="fieldErrors['person.family_name'] ? 'true' : undefined" />
          <small v-if="fieldErrors['person.family_name']" class="employee-drawer__field-error">{{ fieldErrors['person.family_name'] }}</small>
        </label>
        <label class="employee-drawer__field">
          <span>名 *</span>
          <input v-model="draft.given_name" aria-label="名" :aria-invalid="fieldErrors['person.given_name'] ? 'true' : undefined" />
          <small v-if="fieldErrors['person.given_name']" class="employee-drawer__field-error">{{ fieldErrors['person.given_name'] }}</small>
        </label>
        <label class="employee-drawer__field">
          <span>邮箱</span>
          <input v-model="draft.email" type="email" aria-label="邮箱" :aria-invalid="fieldErrors['person.email'] ? 'true' : undefined" />
          <small v-if="fieldErrors['person.email']" class="employee-drawer__field-error">{{ fieldErrors['person.email'] }}</small>
        </label>
        <label class="employee-drawer__field">
          <span>手机号</span>
          <input v-model="draft.mobile" aria-label="手机号" :aria-invalid="fieldErrors['person.mobile'] ? 'true' : undefined" />
          <small v-if="fieldErrors['person.mobile']" class="employee-drawer__field-error">{{ fieldErrors['person.mobile'] }}</small>
        </label>
        <label class="employee-drawer__field">
          <span>证件号</span>
          <input v-model="draft.national_id" aria-label="证件号" :aria-invalid="fieldErrors['person.national_id'] ? 'true' : undefined" />
          <small v-if="fieldErrors['person.national_id']" class="employee-drawer__field-error">{{ fieldErrors['person.national_id'] }}</small>
        </label>
      </fieldset>

      <fieldset class="employee-drawer__group">
        <legend>入职信息</legend>
        <label class="employee-drawer__field">
          <span>员工编号 *</span>
          <input v-model="draft.employee_no" aria-label="员工编号" autocomplete="off" :aria-invalid="fieldErrors['employment.employee_no'] ? 'true' : undefined" />
          <small v-if="fieldErrors['employment.employee_no']" class="employee-drawer__field-error">{{ fieldErrors['employment.employee_no'] }}</small>
        </label>
        <label class="employee-drawer__field">
          <span>入职日期 *</span>
          <input v-model="draft.hire_date" type="date" aria-label="入职日期" :aria-invalid="fieldErrors['employment.hire_date'] ? 'true' : undefined" />
          <small v-if="fieldErrors['employment.hire_date']" class="employee-drawer__field-error">{{ fieldErrors['employment.hire_date'] }}</small>
        </label>
        <label class="employee-drawer__field">
          <span>用工类型</span>
          <select v-model="draft.employment_type" aria-label="用工类型" :aria-invalid="fieldErrors['employment.employment_type'] ? 'true' : undefined">
            <option v-for="type in EMPLOYMENT_TYPES" :key="type.value" :value="type.value">{{ type.label }}</option>
          </select>
          <small v-if="fieldErrors['employment.employment_type']" class="employee-drawer__field-error">{{ fieldErrors['employment.employment_type'] }}</small>
        </label>
      </fieldset>

      <fieldset class="employee-drawer__group">
        <legend>岗位任职</legend>
        <label class="employee-drawer__field">
          <span>部门 *</span>
          <select v-model="draft.org_unit_id" aria-label="部门编号" :aria-invalid="fieldErrors['assignment.org_unit_id'] ? 'true' : undefined">
            <option value="">请选择部门</option>
            <option v-for="unit in orgUnits" :key="unit.id" :value="String(unit.id)">
              {{ unit.name }}{{ unit.code ? `（${unit.code}）` : '' }}
            </option>
          </select>
          <small v-if="fieldErrors['assignment.org_unit_id']" class="employee-drawer__field-error">{{ fieldErrors['assignment.org_unit_id'] }}</small>
        </label>
        <label class="employee-drawer__field">
          <span>岗位</span>
          <select v-model="draft.position_id" aria-label="岗位编号" :aria-invalid="fieldErrors['assignment.position_id'] ? 'true' : undefined">
            <option value="">不指定</option>
            <option v-for="position in positions" :key="position.id" :value="String(position.id)">
              {{ position.title }}{{ position.code ? `（${position.code}）` : '' }}
            </option>
          </select>
          <small v-if="fieldErrors['assignment.position_id']" class="employee-drawer__field-error">{{ fieldErrors['assignment.position_id'] }}</small>
        </label>
        <label class="employee-drawer__field">
          <span>任职生效日</span>
          <input v-model="draft.valid_from" type="date" aria-label="任职生效日" :aria-invalid="fieldErrors['assignment.valid_from'] ? 'true' : undefined" />
          <small v-if="fieldErrors['assignment.valid_from']" class="employee-drawer__field-error">{{ fieldErrors['assignment.valid_from'] }}</small>
        </label>
      </fieldset>

      <fieldset class="employee-drawer__group">
        <legend>雇佣条款</legend>
        <label class="employee-drawer__field">
          <span>基本工资（分）*</span>
          <input v-model="draft.salary_minor" inputmode="numeric" aria-label="基本工资（分）" :aria-invalid="fieldErrors['terms.salary_minor'] ? 'true' : undefined" />
          <small v-if="fieldErrors['terms.salary_minor']" class="employee-drawer__field-error">{{ fieldErrors['terms.salary_minor'] }}</small>
        </label>
        <label class="employee-drawer__field">
          <span>币种 *</span>
          <input v-model="draft.currency" maxlength="3" aria-label="币种" :aria-invalid="fieldErrors['terms.currency'] ? 'true' : undefined" />
          <small v-if="fieldErrors['terms.currency']" class="employee-drawer__field-error">{{ fieldErrors['terms.currency'] }}</small>
        </label>
        <label class="employee-drawer__field">
          <span>工作地点</span>
          <input v-model="draft.work_location" aria-label="工作地点" :aria-invalid="fieldErrors['terms.work_location'] ? 'true' : undefined" />
          <small v-if="fieldErrors['terms.work_location']" class="employee-drawer__field-error">{{ fieldErrors['terms.work_location'] }}</small>
        </label>
        <label class="employee-drawer__field">
          <span>工作安排</span>
          <input v-model="draft.work_schedule" aria-label="工作安排" placeholder="例如 5x8" :aria-invalid="fieldErrors['terms.work_schedule'] ? 'true' : undefined" />
          <small v-if="fieldErrors['terms.work_schedule']" class="employee-drawer__field-error">{{ fieldErrors['terms.work_schedule'] }}</small>
        </label>
        <label class="employee-drawer__field">
          <span>条款生效日</span>
          <input v-model="draft.effective_from" type="date" aria-label="条款生效日" :aria-invalid="fieldErrors['terms.effective_from'] ? 'true' : undefined" />
          <small v-if="fieldErrors['terms.effective_from']" class="employee-drawer__field-error">{{ fieldErrors['terms.effective_from'] }}</small>
        </label>
      </fieldset>
    </form>
  </FormDrawer>
</template>

<style scoped>
.employee-drawer {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.employee-drawer__error {
  margin: 0;
  padding: 8px 10px;
  color: var(--color-danger);
  font-size: 12px;
  background: var(--color-danger-soft);
  border-radius: var(--radius-sm);
}

.employee-drawer__group {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px 12px;
  margin: 0;
  padding: 10px 12px 12px;
  border: 1px solid var(--color-line);
  border-radius: var(--radius-md);
}

.employee-drawer__group legend {
  padding: 0 6px;
  color: var(--color-ink-muted);
  font-size: 11px;
  font-weight: 600;
}

.employee-drawer__field {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
  color: var(--color-ink-muted);
  font-size: 11px;
}

.employee-drawer__field > span {
  font-weight: 600;
}

.employee-drawer__field input,
.employee-drawer__field select {
  width: 100%;
  padding: 7px 9px;
  color: var(--color-ink);
  font-size: 12px;
  background: #fff;
  border: 1px solid var(--color-line-strong);
  border-radius: var(--radius-sm);
}

.employee-drawer__field input[aria-invalid='true'],
.employee-drawer__field select[aria-invalid='true'] {
  border-color: var(--color-danger);
}

.employee-drawer__field-error {
  color: var(--color-danger);
  font-size: 11px;
}
</style>
