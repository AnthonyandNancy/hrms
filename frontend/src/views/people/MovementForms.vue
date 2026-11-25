<script setup lang="ts">
import { reactive, ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import type { PeopleForm } from './types'
import { peopleErrorInfo, type PeopleErrorInfo } from './peopleErrors'
import { postRow } from './write'

const props = defineProps<{
  form: PeopleForm
}>()

const emit = defineEmits<{ created: [] }>()

type Draft = {
  legal_entity_id: string
  employee_no: string
  person_legal_name: string
  person_email: string
  org_unit_id: string
  position_id: string
  hire_date: string
  employment_type: string
  work_location: string
  work_schedule: string
  probation_end_date: string
  employment_id: string
  effective_date: string
  comment: string
  reason: string
  base_salary_minor: string
  salary_currency: string
  last_working_date: string
  termination_type: string
  reason_code: string
  final_payroll_required: string
  host_org_unit_id: string
  secondment_effective_to: string
}

const defaults: Record<string, Partial<Draft>> = {
  'movement-onboarding': {
    legal_entity_id: '1',
    employee_no: '',
    person_legal_name: '',
    person_email: '',
    org_unit_id: '',
    position_id: '',
    hire_date: '',
    employment_type: 'FULL_TIME',
    work_location: '',
    work_schedule: '',
    probation_end_date: '',
  },
  'movement-probation': { employment_id: '', effective_date: '', comment: '' },
  'movement-transfer': {
    employment_id: '',
    org_unit_id: '',
    position_id: '',
    effective_date: '',
    reason: '',
    legal_entity_id: '1',
  },
  'movement-secondment': {
    employment_id: '',
    host_org_unit_id: '',
    position_id: '',
    effective_date: '',
    secondment_effective_to: '',
    reason: '',
  },
  'movement-salary': {
    employment_id: '',
    effective_date: '',
    base_salary_minor: '',
    salary_currency: 'CNY',
    reason: '',
  },
  'movement-offboarding': {
    employment_id: '',
    last_working_date: '',
    termination_type: 'RESIGNATION',
    reason_code: '',
    final_payroll_required: 'false',
  },
}

const formKinds: readonly PeopleForm[] = [
  'movement-onboarding',
  'movement-probation',
  'movement-transfer',
  'movement-salary',
  'movement-secondment',
  'movement-offboarding',
]

const draft = reactive<Draft>({ ...(defaults[props.form] ?? {}) } as Draft)
const submitting = ref(false)
const formError = ref<PeopleErrorInfo | null>(null)

watch(
  () => props.form,
  (form) => {
    for (const key of Object.keys(draft) as Array<keyof Draft>) delete draft[key]
    Object.assign(draft, defaults[form])
    formError.value = null
  },
)

function numeric(value: string): number | null {
  if (value.trim() === '') return null
  const parsed = Number(value)
  return Number.isFinite(parsed) ? parsed : null
}

function pathFor(form: PeopleForm): string {
  if (form === 'movement-onboarding') return '/v1/lifecycle/onboarding'
  if (form === 'movement-probation') return '/v1/lifecycle/probation-confirmations'
  if (form === 'movement-transfer') return '/v1/lifecycle/transfers'
  if (form === 'movement-salary') return '/v1/lifecycle/salary-changes'
  if (form === 'movement-secondment') return '/v1/lifecycle/secondments'
  return '/v1/lifecycle/offboardings'
}

function bodyFor(form: PeopleForm): Record<string, unknown> | null {
  if (form === 'movement-onboarding') {
    if (draft.employee_no.trim() === '' || draft.person_legal_name.trim() === '' || draft['hire_date'] === '') return null
    const orgUnitId = numeric(draft.org_unit_id)
    if (orgUnitId === null || draft.work_location.trim() === '' || draft.work_schedule.trim() === '') return null
    return {
      person: { legal_name: draft.person_legal_name.trim(), email: draft.person_email.trim() === '' ? null : draft.person_email.trim() },
      legal_entity_id: numeric(draft.legal_entity_id) ?? 1,
      employee_no: draft.employee_no.trim(),
      org_unit_id: orgUnitId,
      position_id: numeric(draft.position_id),
      hire_date: draft.hire_date,
      employment_type: draft.employment_type,
      terms: {
        work_location: draft.work_location.trim(),
        work_schedule: draft.work_schedule.trim(),
        probation_end_date: draft['probation_end_date'] === '' ? null : draft.probation_end_date,
      },
      submit: true,
    }
  }
  if (form === 'movement-probation') {
    const employmentId = numeric(draft.employment_id)
    if (employmentId === null || draft['effective_date'] === '') return null
    return { employment_id: employmentId, effective_date: draft.effective_date, comment: draft.comment.trim() === '' ? null : draft.comment.trim(), submit: true }
  }
  if (form === 'movement-transfer') {
    const employmentId = numeric(draft.employment_id)
    const orgUnitId = numeric(draft.org_unit_id)
    if (employmentId === null || orgUnitId === null || draft['effective_date'] === '' || draft.reason.trim() === '') return null
    return {
      employment_id: employmentId,
      org_unit_id: orgUnitId,
      position_id: numeric(draft.position_id),
      effective_date: draft.effective_date,
      reason: draft.reason.trim(),
      legal_entity_id: numeric(draft.legal_entity_id) ?? 1,
      submit: true,
    }
  }
  if (form === 'movement-secondment') {
    const employmentId = numeric(draft.employment_id)
    const hostOrgUnitId = numeric(draft.host_org_unit_id)
    if (employmentId === null || hostOrgUnitId === null || draft['effective_date'] === '' || draft.secondment_effective_to === '' || draft.reason.trim() === '') return null
    return {
      employment_id: employmentId,
      host_org_unit_id: hostOrgUnitId,
      position_id: numeric(draft.position_id),
      effective_from: draft.effective_date,
      effective_to: draft.secondment_effective_to,
      reason: draft.reason.trim(),
    }
  }
  if (form === 'movement-salary') {
    const employmentId = numeric(draft.employment_id)
    const amount = numeric(draft.base_salary_minor)
    if (employmentId === null || amount === null || draft['effective_date'] === '' || draft.reason.trim() === '') return null
    return {
      employment_id: employmentId,
      effective_date: draft.effective_date,
      base_salary_minor: amount,
      salary_currency: draft.salary_currency,
      reason: draft.reason.trim(),
      submit: true,
    }
  }
  const employmentId = numeric(draft.employment_id)
  if (employmentId === null || draft['last_working_date'] === '' || draft.reason_code.trim() === '') return null
  return {
    employment_id: employmentId,
    last_working_date: draft.last_working_date,
    termination_type: draft.termination_type,
    reason_code: draft.reason_code.trim(),
    final_payroll_required: draft['final_payroll_required'] === 'true',
    submit: true,
  }
}

async function submit(): Promise<void> {
  formError.value = null
  const body = bodyFor(props.form)
  if (body === null) {
    formError.value = {
      code: 'VALIDATION',
      message: '请完整填写本表单的必填项，再提交。',
      requestId: null,
      reason: null,
      fieldErrors: {},
    }
    return
  }
  submitting.value = true
  try {
    await postRow(pathFor(props.form), body, props.form)
    ElMessage.success(
      props.form === 'movement-secondment'
        ? '借调已登记为并行任职；主任职历史保持不变。'
        : '异动案例已提交；审批通过后按生效日期执行。',
    )
    for (const key of Object.keys(draft) as Array<keyof Draft>) delete draft[key]
    Object.assign(draft, defaults[props.form])
    emit('created')
  } catch (error) {
    formError.value = peopleErrorInfo(error)
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <section v-if="formKinds.includes(form)" class="panel people-form-panel">
    <header class="panel__header">
      <div>
        <h2 class="panel__title">发起异动</h2>
        <p class="panel__subtitle">
          {{ form === 'movement-secondment' ? '借调创建 assignment_type=SECONDMENT 的并行任职（POST /v1/lifecycle/secondments），不改写主任职。' : '异动命令会创建审批案例；审批通过不等于立即生效。' }}
        </p>
      </div>
    </header>
    <div class="panel__body">
      <form class="people-form" @submit.prevent="submit">
        <div class="people-form__grid">
          <label v-if="form === 'movement-onboarding'" class="people-field"><span>员工姓名 *</span><input v-model="draft.person_legal_name" data-testid="movement-person-name" /></label>
          <label v-if="form === 'movement-onboarding'" class="people-field"><span>工号 *</span><input v-model="draft.employee_no" data-testid="movement-employee-no" /></label>
          <label v-if="form === 'movement-onboarding'" class="people-field"><span>入职日期 *</span><input v-model="draft.hire_date" data-testid="movement-hire-date" type="date" /></label>
          <label v-if="form === 'movement-onboarding'" class="people-field"><span>组织编号 *</span><input v-model="draft.org_unit_id" data-testid="movement-org-unit" inputmode="numeric" /></label>
          <label v-if="form === 'movement-onboarding'" class="people-field"><span>岗位编号</span><input v-model="draft.position_id" inputmode="numeric" /></label>
          <label v-if="form === 'movement-onboarding'" class="people-field"><span>工作地点 *</span><input v-model="draft.work_location" data-testid="movement-work-location" /></label>
          <label v-if="form === 'movement-onboarding'" class="people-field"><span>工作安排 *</span><input v-model="draft.work_schedule" data-testid="movement-work-schedule" /></label>
          <label v-if="form === 'movement-onboarding'" class="people-field"><span>试用期结束</span><input v-model="draft.probation_end_date" type="date" /></label>
          <label v-if="form === 'movement-onboarding'" class="people-field"><span>邮箱</span><input v-model="draft.person_email" type="email" /></label>

          <label v-if="form !== 'movement-onboarding'" class="people-field"><span>员工关系编号 *</span><input v-model="draft.employment_id" data-testid="movement-employment-id" inputmode="numeric" /></label>
          <label v-if="form === 'movement-probation'" class="people-field"><span>转正生效日 *</span><input v-model="draft.effective_date" data-testid="movement-effective-date" type="date" /></label>
          <label v-if="form === 'movement-transfer' || form === 'movement-salary' || form === 'movement-secondment'" class="people-field"><span>生效日期 *</span><input v-model="draft.effective_date" data-testid="movement-effective-date" type="date" /></label>
          <label v-if="form === 'movement-transfer'" class="people-field"><span>目标组织编号 *</span><input v-model="draft.org_unit_id" data-testid="movement-target-org" inputmode="numeric" /></label>
          <label v-if="form === 'movement-transfer'" class="people-field"><span>目标岗位编号</span><input v-model="draft.position_id" inputmode="numeric" /></label>
          <label v-if="form === 'movement-transfer' || form === 'movement-salary' || form === 'movement-secondment'" class="people-field people-field--wide"><span>原因 *</span><textarea v-model="draft.reason" data-testid="movement-reason" rows="2" /></label>
          <label v-if="form === 'movement-salary'" class="people-field"><span>新基本工资（分）*</span><input v-model="draft.base_salary_minor" data-testid="movement-salary-minor" inputmode="numeric" /></label>
          <label v-if="form === 'movement-salary'" class="people-field"><span>币种</span><input v-model="draft.salary_currency" maxlength="3" /></label>
          <label v-if="form === 'movement-probation'" class="people-field people-field--wide"><span>意见</span><textarea v-model="draft.comment" rows="2" /></label>

          <label v-if="form === 'movement-secondment'" class="people-field"><span>借调接收组织编号 *</span><input v-model="draft.host_org_unit_id" data-testid="movement-host-org" inputmode="numeric" /></label>
          <label v-if="form === 'movement-secondment'" class="people-field"><span>借调岗位编号</span><input v-model="draft.position_id" data-testid="movement-secondment-position" inputmode="numeric" /></label>
          <label v-if="form === 'movement-secondment'" class="people-field"><span>借调结束日 *</span><input v-model="draft.secondment_effective_to" data-testid="movement-secondment-to" type="date" /></label>

          <label v-if="form === 'movement-offboarding'" class="people-field"><span>最后工作日 *</span><input v-model="draft.last_working_date" data-testid="movement-last-working-date" type="date" /></label>
          <label v-if="form === 'movement-offboarding'" class="people-field"><span>离职类型 *</span><select v-model="draft.termination_type" data-testid="movement-termination-type"><option value="RESIGNATION">主动离职</option><option value="TERMINATION">公司解除</option><option value="RETIREMENT">退休</option><option value="CONTRACT_END">合同到期</option><option value="MUTUAL_AGREEMENT">协商一致</option></select></label>
          <label v-if="form === 'movement-offboarding'" class="people-field"><span>原因代码 *</span><input v-model="draft.reason_code" data-testid="movement-reason-code" /></label>
          <label v-if="form === 'movement-offboarding'" class="people-field"><span>需最终薪资结算</span><select v-model="draft.final_payroll_required"><option value="false">否</option><option value="true">是</option></select></label>
        </div>

        <p v-if="formError" class="people-form__error" role="alert" data-testid="movement-form-error">
          {{ formError.code }}：{{ formError.message }}
          <span v-if="formError.requestId">（请求编号 {{ formError.requestId }}）</span>
        </p>

        <div class="people-form__actions">
          <button type="submit" class="people-button" :disabled="submitting" data-testid="movement-submit">
            {{ submitting ? '提交中…' : '提交异动案例' }}
          </button>
        </div>
      </form>
    </div>
  </section>
</template>
