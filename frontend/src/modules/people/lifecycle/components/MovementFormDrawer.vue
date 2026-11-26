<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { ApiError } from '@/api/errors'
import {
  formatMinorUnits,
  movementKindLabel,
  movementTypeForKind,
  parseMinorUnitsInput,
  stableSubmissionKey,
  createMovement,
  employeeLabel,
  orgUnitLabel,
  type EmployeeOption,
  type MovementKind,
  type MovementCreateResult,
  type OrgUnitOption,
  type StableSubmissionKey,
} from '../api'

const props = defineProps<{
  modelValue: boolean
  allowedKinds: readonly MovementKind[]
  employees: readonly EmployeeOption[]
  orgUnits: readonly OrgUnitOption[]
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  created: [result: MovementCreateResult]
}>()

defineOptions({ name: 'MovementFormDrawer' })

type Draft = {
  kind: MovementKind
  employmentId: string
  personLegalName: string
  personEmail: string
  employeeNo: string
  employmentType: string
  orgUnitId: string
  positionId: string
  managerAssignmentId: string
  effectiveDate: string
  reason: string
  reasonCode: string
  comment: string
  workLocation: string
  workSchedule: string
  probationEndDate: string
  salaryMinor: string
  salaryCurrency: string
  salarySchemeCode: string
  hostOrgUnitId: string
  secondmentTo: string
  lastWorkingDate: string
  terminationType: string
  finalPayrollRequired: string
  finalPayrollWaiverReason: string
}

const EMPLOYMENT_TYPES = [
  { value: 'FULL_TIME', label: '全职' },
  { value: 'PART_TIME', label: '兼职' },
  { value: 'FIXED_TERM', label: '固定期限' },
  { value: 'INTERN', label: '实习' },
  { value: 'CONTRACTOR', label: '外包/顾问' },
]

const TERMINATION_TYPES = [
  { value: 'RESIGNATION', label: '主动离职' },
  { value: 'TERMINATION', label: '公司解除' },
  { value: 'RETIREMENT', label: '退休' },
  { value: 'CONTRACT_END', label: '合同到期' },
  { value: 'MUTUAL_AGREEMENT', label: '协商一致' },
]

const draft = reactive<Draft>(emptyDraft(props.allowedKinds[0] ?? 'transfer'))
const submitting = ref<'draft' | 'submit' | null>(null)
const localError = ref('')
const serverError = ref('')
const serverRequestId = ref<string | null>(null)
const fieldErrors = ref<Record<string, string[]>>({})
const submissionKey = ref<StableSubmissionKey | null>(null)

const open = computed({
  get: () => props.modelValue,
  set: (value: boolean) => emit('update:modelValue', value),
})

const definition = computed(() => movementTypeForKind(draft.kind))
const isWorkflow = computed(() => definition.value.workflow)
const isOnboarding = computed(() => draft.kind === 'onboarding')
const needsEmployment = computed(() => !isOnboarding.value)
const isSecondment = computed(() => draft.kind === 'secondment')
const isSalary = computed(() => draft.kind === 'salary')
const isTransfer = computed(() => draft.kind === 'transfer')
const isProbation = computed(() => draft.kind === 'probation')
const isOffboarding = computed(() => draft.kind === 'offboarding')

const kindOptions = computed(() =>
  props.allowedKinds.map((value) => ({ value, label: movementKindLabel(value) })),
)

const selectedEmployee = computed(() =>
  props.employees.find((employee) => String(employee.employmentId) === draft.employmentId) ?? null,
)

const salaryPreview = computed(() => {
  const amount = parseMinorUnitsInput(draft.salaryMinor)
  if (amount === null) return ''
  return formatMinorUnits(amount, draft.salaryCurrency.trim().toUpperCase())
})

const effectiveDisclosure = computed(() => {
  if (isSecondment.value) {
    return '借调会创建并行的 SECONDMENT 任职，不会改写主任职记录；仅在生效区间内生效。'
  }
  if (isOnboarding.value) {
    return '入职案例批准后按入职日期建档；审批通过前不会创建员工。'
  }
  if (isOffboarding.value) {
    return '离职案例批准后按最后工作日之后结束任职；审批通过前不会终止员工。'
  }
  return '审批通过后，变更从生效日期开始执行；不会被描述为立即变更。'
})

function emptyDraft(kind: MovementKind): Draft {
  return {
    kind,
    employmentId: '',
    personLegalName: '',
    personEmail: '',
    employeeNo: '',
    employmentType: 'FULL_TIME',
    orgUnitId: '',
    positionId: '',
    managerAssignmentId: '',
    effectiveDate: '',
    reason: '',
    reasonCode: '',
    comment: '',
    workLocation: '',
    workSchedule: 'STANDARD',
    probationEndDate: '',
    salaryMinor: '',
    salaryCurrency: 'CNY',
    salarySchemeCode: '',
    hostOrgUnitId: '',
    secondmentTo: '',
    lastWorkingDate: '',
    terminationType: 'RESIGNATION',
    finalPayrollRequired: 'true',
    finalPayrollWaiverReason: '',
  }
}

function resetDraft(): void {
  const nextKind = props.allowedKinds.includes(draft.kind)
    ? draft.kind
    : (props.allowedKinds[0] ?? 'transfer')
  Object.assign(draft, emptyDraft(nextKind))
  localError.value = ''
  serverError.value = ''
  serverRequestId.value = null
  fieldErrors.value = {}
  submissionKey.value = null
}

watch(
  () => props.modelValue,
  (isOpen) => {
    if (isOpen) resetDraft()
  },
  { immediate: true },
)

watch(
  () => draft.kind,
  (next) => {
    if (!props.allowedKinds.includes(next)) return
    localError.value = ''
    serverError.value = ''
    fieldErrors.value = {}
    submissionKey.value = null
  },
)

function numericId(value: string): number | null {
  const trimmed = value.trim()
  if (!/^\d+$/.test(trimmed)) return null
  const parsed = Number(trimmed)
  return Number.isSafeInteger(parsed) && parsed > 0 ? parsed : null
}

function fieldErrorName(field: string): string {
  const labels: Record<string, string> = {
    employment_id: '员工',
    person: '员工姓名',
    employee_no: '工号',
    org_unit_id: isTransfer.value ? '目标组织' : '组织',
    host_org_unit_id: '借调接收组织',
    effective_date: '生效日期',
    effective_from: '借调开始日期',
    effective_to: '借调结束日期',
    hire_date: '入职日期',
    last_working_date: '最后工作日',
    base_salary_minor: '新基本工资',
    reason: '原因',
    reason_code: '离职原因代码',
    position_id: '岗位',
    manager_assignment_id: '直属主管任职',
    salary_currency: '币种',
    work_location: '工作地点',
    work_schedule: '工作安排',
    probation_end_date: '试用期结束日期',
    termination_type: '离职类型',
    comment: '意见',
  }
  return labels[field] ?? field
}

function serverFieldMessage(field: string, message: string): string {
  const trimmed = message.trim()
  if (field === 'org_unit_id' && /organization unit|org unit/i.test(trimmed)) {
    return '目标组织不存在或已停用，请重新选择。'
  }
  if (field === 'host_org_unit_id' && /organization unit|org unit/i.test(trimmed)) {
    return '借调接收组织不存在或已停用，请重新选择。'
  }
  if (field === 'employment_id' && /employment does not exist/i.test(trimmed)) {
    return '员工关系不存在或不在当前数据范围内。'
  }
  if (field === 'base_salary_minor') {
    return '新基本工资必须是最小货币单位的非负整数。'
  }
  if (field === 'effective_date' || field === 'effective_from' || field === 'effective_to') {
    return trimmed === '' ? '请填写有效的生效日期。' : `${fieldErrorName(field)}无效：${trimmed}`
  }
  return trimmed
}

function firstFieldError(field: string): string {
  const messages = fieldErrors.value[field]
  if (!Array.isArray(messages) || messages.length === 0) return ''
  return serverFieldMessage(field, messages[0] ?? '')
}

function validateDateOrdering(): boolean {
  if (isSecondment.value) {
    if (draft.effectiveDate === '' || draft.secondmentTo === '') {
      localError.value = '请填写借调开始日期与结束日期。'
      return false
    }
    if (draft.secondmentTo <= draft.effectiveDate) {
      localError.value = '借调结束日期必须晚于开始日期；借调区间是半开区间 [开始日, 结束日)。'
      return false
    }
  }

  if (isOnboarding.value) {
    if (draft.probationEndDate !== '' && draft.effectiveDate !== '' && draft.probationEndDate <= draft.effectiveDate) {
      localError.value = '试用期结束日期必须晚于入职日期。'
      return false
    }
  }

  const employee = selectedEmployee.value
  if (
    employee !== null &&
    employee.validFrom !== null &&
    draft.effectiveDate !== '' &&
    needsEmployment.value &&
    !isSecondment.value &&
    draft.effectiveDate <= employee.validFrom
  ) {
    localError.value = '生效日期必须晚于该员工的任职开始日期。'
    return false
  }
  return true
}

function buildPayload(): Record<string, unknown> | null {
  localError.value = ''
  fieldErrors.value = {}
  serverError.value = ''

  if (isOnboarding.value) {
    const orgUnitId = numericId(draft.orgUnitId)
    if (draft.personLegalName.trim() === '' || draft.employeeNo.trim() === '' || draft.effectiveDate === '') {
      localError.value = '请填写员工姓名、工号与入职日期。'
      return null
    }
    if (orgUnitId === null) {
      localError.value = '请选择入职组织。'
      return null
    }
    if (draft.workLocation.trim() === '' || draft.workSchedule.trim() === '') {
      localError.value = '请填写工作地点与工作安排。'
      return null
    }
    if (!validateDateOrdering()) return null
    return {
      person: {
        legal_name: draft.personLegalName.trim(),
        email: draft.personEmail.trim() === '' ? null : draft.personEmail.trim(),
      },
      employee_no: draft.employeeNo.trim(),
      org_unit_id: orgUnitId,
      position_id: numericId(draft.positionId),
      manager_assignment_id: numericId(draft.managerAssignmentId),
      hire_date: draft.effectiveDate,
      employment_type: draft.employmentType,
      terms: {
        work_location: draft.workLocation.trim(),
        work_schedule: draft.workSchedule.trim(),
        probation_end_date: draft.probationEndDate === '' ? null : draft.probationEndDate,
      },
    }
  }

  const employmentId = numericId(draft.employmentId)
  if (employmentId === null) {
    localError.value = '请选择员工。'
    return null
  }

  if (isProbation.value) {
    if (draft.effectiveDate === '' || !validateDateOrdering()) {
      if (localError.value === '') localError.value = '请填写转正生效日期。'
      return null
    }
    return {
      employment_id: employmentId,
      effective_date: draft.effectiveDate,
      comment: draft.comment.trim() === '' ? null : draft.comment.trim(),
    }
  }

  if (isTransfer.value) {
    const targetOrg = numericId(draft.orgUnitId)
    if (targetOrg === null) {
      localError.value = '请选择目标组织。'
      return null
    }
    if (draft.effectiveDate === '' || draft.reason.trim() === '') {
      localError.value = '请填写生效日期与调动原因。'
      return null
    }
    if (!validateDateOrdering()) return null
    return {
      employment_id: employmentId,
      org_unit_id: targetOrg,
      position_id: numericId(draft.positionId),
      manager_assignment_id: numericId(draft.managerAssignmentId),
      effective_date: draft.effectiveDate,
      reason: draft.reason.trim(),
    }
  }

  if (isSalary.value) {
    const amount = parseMinorUnitsInput(draft.salaryMinor)
    if (amount === null) {
      localError.value = '金额必须是最小货币单位的非负整数，不能包含小数或符号。'
      return null
    }
    const currency = draft.salaryCurrency.trim().toUpperCase()
    if (!/^[A-Z]{3}$/.test(currency)) {
      localError.value = '币种必须是 3 位 ISO 4217 代码，例如 CNY。'
      return null
    }
    if (draft.effectiveDate === '' || draft.reason.trim() === '') {
      localError.value = '请填写生效日期与调薪原因。'
      return null
    }
    if (!validateDateOrdering()) return null
    return {
      employment_id: employmentId,
      effective_date: draft.effectiveDate,
      base_salary_minor: amount,
      salary_currency: currency,
      salary_scheme_code:
        draft.salarySchemeCode.trim() === '' ? null : draft.salarySchemeCode.trim(),
      reason: draft.reason.trim(),
    }
  }

  if (isSecondment.value) {
    const hostOrg = numericId(draft.hostOrgUnitId)
    if (hostOrg === null) {
      localError.value = '请选择借调接收组织。'
      return null
    }
    if (draft.reason.trim() === '') {
      localError.value = '请填写借调原因。'
      return null
    }
    if (!validateDateOrdering()) return null
    return {
      employment_id: employmentId,
      host_org_unit_id: hostOrg,
      position_id: numericId(draft.positionId),
      effective_from: draft.effectiveDate,
      effective_to: draft.secondmentTo,
      reason: draft.reason.trim(),
    }
  }

  if (draft.lastWorkingDate === '' || draft.reasonCode.trim() === '') {
    localError.value = '请填写最后工作日与离职原因代码。'
    return null
  }
  const finalPayrollRequired = draft.finalPayrollRequired === 'true'
  if (!finalPayrollRequired && draft.finalPayrollWaiverReason.trim() === '') {
    localError.value = '选择无需最终薪资结算时，必须填写豁免原因。'
    return null
  }
  return {
    employment_id: employmentId,
    last_working_date: draft.lastWorkingDate,
    termination_type: draft.terminationType,
    reason_code: draft.reasonCode.trim(),
    final_payroll_required: finalPayrollRequired,
    ...(finalPayrollRequired
      ? {}
      : { final_payroll_waiver_reason: draft.finalPayrollWaiverReason.trim() }),
  }
}

async function submit(submitWorkflow: boolean): Promise<void> {
  const payload = buildPayload()
  if (payload === null) return
  if (!props.allowedKinds.includes(draft.kind)) {
    localError.value = '当前账号没有该异动类型的操作能力。'
    return
  }

  const requestBody = definition.value.workflow ? { ...payload, submit: submitWorkflow } : payload
  submissionKey.value = stableSubmissionKey(submissionKey.value, `movement-${draft.kind}`, requestBody)
  submitting.value = submitWorkflow ? 'submit' : 'draft'
  serverError.value = ''
  serverRequestId.value = null

  try {
    const result = await createMovement(
      draft.kind,
      payload,
      submitWorkflow,
      submissionKey.value.key,
    )
    emit('created', result)
    open.value = false
  } catch (reason) {
    if (reason instanceof ApiError) {
      fieldErrors.value = { ...reason.fieldErrors }
      serverRequestId.value = reason.requestId
      if (reason.code === 'VALIDATION_FAILED' && Object.keys(reason.fieldErrors).length > 0) {
        serverError.value = '提交内容未通过校验，请在标记的字段修正后重试。'
      } else if (reason.code === 'FORBIDDEN') {
        serverError.value = `当前账号无权发起${movementKindLabel(draft.kind)}。`
      } else {
        serverError.value = reason.message || '员工异动未创建，请稍后重试。'
      }
    } else {
      serverError.value = reason instanceof Error ? reason.message : '员工异动未创建，请稍后重试。'
    }
  } finally {
    submitting.value = null
  }
}

function close(): void {
  if (submitting.value !== null) return
  open.value = false
}

function saveDraft(): void {
  void submit(false)
}

function submitForApproval(): void {
  void submit(true)
}
</script>

<template>
  <el-drawer
    v-model="open"
    :title="`发起异动 · ${movementKindLabel(draft.kind)}`"
    size="min(720px, 96vw)"
    :close-on-click-modal="false"
    data-testid="movement-form-drawer"
    @closed="resetDraft"
  >
    <form class="movement-form" data-testid="movement-form" @submit.prevent="submitForApproval">
      <section class="movement-form__disclosure" data-testid="movement-effective-disclosure">
        <strong>生效日导向</strong>
        <p>{{ effectiveDisclosure }}</p>
      </section>

      <div class="movement-form__grid">
        <label class="movement-field">
          <span>异动类型 *</span>
          <select v-model="draft.kind" data-testid="movement-kind">
            <option v-for="option in kindOptions" :key="option.value" :value="option.value">
              {{ option.label }}
            </option>
          </select>
        </label>

        <label v-if="needsEmployment" class="movement-field">
          <span>员工 *</span>
          <select v-model="draft.employmentId" data-testid="movement-employment">
            <option value="">请选择员工</option>
            <option v-for="employee in employees" :key="employee.employmentId" :value="String(employee.employmentId)">
              {{ employeeLabel(employee) }}
            </option>
          </select>
          <small v-if="selectedEmployee !== null" class="movement-field__hint">
            任职自 {{ selectedEmployee.validFrom ?? '—' }} 起
          </small>
          <small v-if="firstFieldError('employment_id') !== ''" class="movement-field__error">
            {{ firstFieldError('employment_id') }}
          </small>
        </label>

        <template v-if="isOnboarding">
          <label class="movement-field">
            <span>员工姓名 *</span>
            <input v-model="draft.personLegalName" data-testid="movement-person-name" maxlength="120" />
            <small v-if="firstFieldError('person.legal_name') !== ''" class="movement-field__error">
              {{ firstFieldError('person.legal_name') }}
            </small>
          </label>
          <label class="movement-field">
            <span>工号 *</span>
            <input v-model="draft.employeeNo" data-testid="movement-employee-no" maxlength="64" />
            <small v-if="firstFieldError('employee_no') !== ''" class="movement-field__error">
              {{ firstFieldError('employee_no') }}
            </small>
          </label>
          <label class="movement-field">
            <span>邮箱</span>
            <input v-model="draft.personEmail" type="email" maxlength="254" />
          </label>
          <label class="movement-field">
            <span>入职日期 *</span>
            <input v-model="draft.effectiveDate" data-testid="movement-effective-date" type="date" />
            <small v-if="firstFieldError('hire_date') !== ''" class="movement-field__error">
              {{ firstFieldError('hire_date') }}
            </small>
          </label>
          <label class="movement-field">
            <span>用工类型 *</span>
            <select v-model="draft.employmentType">
              <option v-for="option in EMPLOYMENT_TYPES" :key="option.value" :value="option.value">
                {{ option.label }}
              </option>
            </select>
          </label>
          <label class="movement-field">
            <span>入职组织 *</span>
            <select v-model="draft.orgUnitId" data-testid="movement-target-org">
              <option value="">请选择组织</option>
              <option v-for="unit in orgUnits" :key="unit.id" :value="String(unit.id)">
                {{ orgUnitLabel(unit) }}
              </option>
            </select>
            <small v-if="firstFieldError('org_unit_id') !== ''" class="movement-field__error">
              {{ firstFieldError('org_unit_id') }}
            </small>
          </label>
          <label class="movement-field">
            <span>工作地点 *</span>
            <input v-model="draft.workLocation" data-testid="movement-work-location" maxlength="160" />
          </label>
          <label class="movement-field">
            <span>工作安排 *</span>
            <input v-model="draft.workSchedule" data-testid="movement-work-schedule" maxlength="80" />
          </label>
          <label class="movement-field">
            <span>试用期结束日期</span>
            <input v-model="draft.probationEndDate" type="date" />
            <small v-if="firstFieldError('probation_end_date') !== ''" class="movement-field__error">
              {{ firstFieldError('probation_end_date') }}
            </small>
          </label>
        </template>

        <template v-else>
          <label class="movement-field">
            <span>{{ isOffboarding ? '最后工作日 *' : '生效日期 *' }}</span>
            <input
              v-if="isOffboarding"
              v-model="draft.lastWorkingDate"
              data-testid="movement-last-working-date"
              type="date"
            />
            <input
              v-else
              v-model="draft.effectiveDate"
              data-testid="movement-effective-date"
              type="date"
            />
            <small class="movement-field__hint">
              {{ isSecondment ? '借调开始日（含）' : '变更从该日期开始生效，批准本身不改变入离职状态' }}
            </small>
            <small
              v-if="isOffboarding ? firstFieldError('last_working_date') !== '' : firstFieldError('effective_date') !== ''"
              class="movement-field__error"
            >
              {{ isOffboarding ? firstFieldError('last_working_date') : firstFieldError('effective_date') }}
            </small>
          </label>
        </template>

        <label v-if="isProbation" class="movement-field movement-field--wide">
          <span>转正意见</span>
          <textarea v-model="draft.comment" rows="2" maxlength="1000" />
          <small v-if="firstFieldError('comment') !== ''" class="movement-field__error">
            {{ firstFieldError('comment') }}
          </small>
        </label>

        <label v-if="isTransfer" class="movement-field">
          <span>目标组织 *</span>
          <select v-model="draft.orgUnitId" data-testid="movement-target-org">
            <option value="">请选择目标组织</option>
            <option v-for="unit in orgUnits" :key="unit.id" :value="String(unit.id)">
              {{ orgUnitLabel(unit) }}
            </option>
          </select>
          <small v-if="firstFieldError('org_unit_id') !== ''" class="movement-field__error">
            {{ firstFieldError('org_unit_id') }}
          </small>
        </label>

        <label v-if="isTransfer || isSecondment" class="movement-field">
          <span>目标岗位（可选）</span>
          <input v-model="draft.positionId" inputmode="numeric" placeholder="岗位编号" />
          <small v-if="firstFieldError('position_id') !== ''" class="movement-field__error">
            {{ firstFieldError('position_id') }}
          </small>
        </label>

        <label v-if="isTransfer" class="movement-field">
          <span>直属主管任职编号（可选）</span>
          <input v-model="draft.managerAssignmentId" inputmode="numeric" />
          <small v-if="firstFieldError('manager_assignment_id') !== ''" class="movement-field__error">
            {{ firstFieldError('manager_assignment_id') }}
          </small>
        </label>

        <template v-if="isSalary">
          <label class="movement-field">
            <span>新基本工资（最小货币单位）*</span>
            <input
              v-model="draft.salaryMinor"
              data-testid="movement-salary-minor"
              inputmode="numeric"
              placeholder="例如 123456 表示 ¥1,234.56"
            />
            <small class="movement-field__hint">
              只接受整数最小货币单位，禁止小数与千分位输入。
            </small>
            <small v-if="firstFieldError('base_salary_minor') !== ''" class="movement-field__error">
              {{ firstFieldError('base_salary_minor') }}
            </small>
          </label>
          <label class="movement-field">
            <span>币种 *</span>
            <input v-model="draft.salaryCurrency" maxlength="3" />
            <small v-if="firstFieldError('salary_currency') !== ''" class="movement-field__error">
              {{ firstFieldError('salary_currency') }}
            </small>
          </label>
          <label class="movement-field">
            <span>薪资方案代码（可选）</span>
            <input v-model="draft.salarySchemeCode" maxlength="64" />
          </label>
          <p v-if="salaryPreview !== ''" class="movement-form__preview" data-testid="movement-salary-preview">
            格式化预览：{{ salaryPreview }}
          </p>
        </template>

        <template v-if="isSecondment">
          <label class="movement-field">
            <span>借调接收组织 *</span>
            <select v-model="draft.hostOrgUnitId" data-testid="movement-host-org">
              <option value="">请选择接收组织</option>
              <option v-for="unit in orgUnits" :key="unit.id" :value="String(unit.id)">
                {{ orgUnitLabel(unit) }}
              </option>
            </select>
            <small v-if="firstFieldError('host_org_unit_id') !== ''" class="movement-field__error">
              {{ firstFieldError('host_org_unit_id') }}
            </small>
          </label>
          <label class="movement-field">
            <span>借调结束日期 *</span>
            <input v-model="draft.secondmentTo" data-testid="movement-secondment-to" type="date" />
            <small v-if="firstFieldError('effective_to') !== ''" class="movement-field__error">
              {{ firstFieldError('effective_to') }}
            </small>
          </label>
        </template>

        <template v-if="isOffboarding">
          <label class="movement-field">
            <span>离职类型 *</span>
            <select v-model="draft.terminationType" data-testid="movement-termination-type">
              <option v-for="option in TERMINATION_TYPES" :key="option.value" :value="option.value">
                {{ option.label }}
              </option>
            </select>
          </label>
          <label class="movement-field">
            <span>离职原因代码 *</span>
            <input v-model="draft.reasonCode" data-testid="movement-reason-code" maxlength="64" />
            <small v-if="firstFieldError('reason_code') !== ''" class="movement-field__error">
              {{ firstFieldError('reason_code') }}
            </small>
          </label>
          <label class="movement-field">
            <span>需要最终薪资结算</span>
            <select v-model="draft.finalPayrollRequired">
              <option value="true">需要</option>
              <option value="false">不需要（需填豁免原因）</option>
            </select>
          </label>
          <label v-if="draft.finalPayrollRequired === 'false'" class="movement-field movement-field--wide">
            <span>最终薪资豁免原因 *</span>
            <input v-model="draft.finalPayrollWaiverReason" maxlength="500" />
          </label>
        </template>

        <label v-if="isTransfer || isSalary || isSecondment" class="movement-field movement-field--wide">
          <span>原因 *</span>
          <textarea v-model="draft.reason" data-testid="movement-reason" rows="2" maxlength="500" />
          <small v-if="firstFieldError('reason') !== ''" class="movement-field__error">
            {{ firstFieldError('reason') }}
          </small>
        </label>
      </div>

      <p v-if="localError !== ''" class="movement-form__error" role="alert" data-testid="movement-validation-error">
        {{ localError }}
      </p>
      <p v-if="serverError !== ''" class="movement-form__error" role="alert" data-testid="movement-server-error">
        {{ serverError }}
        <span v-if="serverRequestId !== null"> 请求编号：{{ serverRequestId }}</span>
      </p>

      <footer class="movement-form__actions">
        <button type="button" class="movement-form__secondary" :disabled="submitting !== null" @click="close">
          取消
        </button>
        <button
          v-if="isWorkflow"
          type="button"
          class="movement-form__secondary"
          :disabled="submitting !== null"
          data-testid="movement-save-draft"
          @click="saveDraft"
        >
          {{ submitting === 'draft' ? '保存中…' : '保存草稿' }}
        </button>
        <button
          type="button"
          class="movement-form__primary"
          :disabled="submitting !== null"
          data-testid="movement-submit"
          @click="submitForApproval"
        >
          {{ submitting === 'submit' ? '提交中…' : isSecondment ? '提交借调' : '提交审批' }}
        </button>
      </footer>
    </form>
  </el-drawer>
</template>

<style scoped>
.movement-form {
  display: grid;
  gap: 14px;
}

.movement-form__disclosure {
  padding: 12px 14px;
  color: #1e3a8a;
  background: #eff6ff;
  border: 1px solid #bfdbfe;
  border-radius: 8px;
}

.movement-form__disclosure strong {
  font-size: 12px;
}

.movement-form__disclosure p {
  margin: 4px 0 0;
  font-size: 11.5px;
  line-height: 1.6;
}

.movement-form__grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}

.movement-field {
  display: grid;
  gap: 4px;
  color: #334155;
  font-size: 11.5px;
}

.movement-field--wide {
  grid-column: 1 / -1;
}

.movement-field input,
.movement-field select,
.movement-field textarea {
  width: 100%;
  padding: 7px 9px;
  font: inherit;
  font-size: 12px;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
}

.movement-field__hint {
  color: #64748b;
  font-size: 10.5px;
  line-height: 1.5;
}

.movement-field__error {
  color: #b91c1c;
  font-size: 10.5px;
  line-height: 1.5;
}

.movement-form__preview {
  margin: 0;
  align-self: end;
  padding: 7px 9px;
  color: #047857;
  font-size: 12px;
  background: #ecfdf5;
  border: 1px solid #a7f3d0;
  border-radius: 6px;
}

.movement-form__error {
  margin: 0;
  color: #b91c1c;
  font-size: 11.5px;
  line-height: 1.6;
}

.movement-form__actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  padding-top: 4px;
}

.movement-form__primary,
.movement-form__secondary {
  padding: 7px 14px;
  font-size: 12px;
  border-radius: 6px;
  cursor: pointer;
}

.movement-form__primary {
  color: #fff;
  background: #1d4ed8;
  border: 1px solid #1d4ed8;
}

.movement-form__secondary {
  color: #334155;
  background: #fff;
  border: 1px solid #cbd5e1;
}

.movement-form__primary:disabled,
.movement-form__secondary:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

@media (max-width: 640px) {
  .movement-form__grid {
    grid-template-columns: 1fr;
  }
}
</style>
