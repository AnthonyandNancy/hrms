<script setup lang="ts">
import { reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { peopleErrorInfo, type PeopleErrorInfo } from '../peopleErrors'
import { postRow } from '../write'
import { DISPLAY_CODES, translate } from '@/shared/display/dictionary'

const emit = defineEmits<{ created: [employmentId: number | null] }>()

const draft = reactive({
  legal_name: '',
  email: '',
  employee_no: '',
  org_unit_id: '',
  position_id: '',
  employment_type: DISPLAY_CODES.employmentType.fullTime,
  status: DISPLAY_CODES.employeeStatus.probation,
  valid_from: '',
  hire_date: '',
  work_location: '',
  work_schedule: '',
})
const submitting = ref(false)
const formError = ref<PeopleErrorInfo | null>(null)
const createdEmploymentId = ref<number | null>(null)

function numeric(value: string): number | null {
  if (value.trim() === '') return null
  const parsed = Number(value)
  return Number.isFinite(parsed) ? parsed : null
}

function validation(message: string): void {
  formError.value = { code: 'VALIDATION', message, requestId: null, reason: null, fieldErrors: {} }
}

async function submit(): Promise<void> {
  formError.value = null
  createdEmploymentId.value = null
  const orgUnitId = numeric(draft.org_unit_id)
  if (
    orgUnitId === null ||
    draft.legal_name.trim() === '' ||
    draft.employee_no.trim() === '' ||
    draft.hire_date === '' ||
    draft.valid_from === '' ||
    draft.work_location.trim() === '' ||
    draft.work_schedule.trim() === ''
  ) {
    validation('请填写姓名、工号、入职日期、任职生效日期、组织、工作地点与工作安排。')
    return
  }

  submitting.value = true
  try {
    const person = await postRow(
      '/v1/persons',
      {
        legal_name: draft.legal_name.trim(),
        email: draft.email.trim() === '' ? null : draft.email.trim(),
      },
      'person-create',
    )
    if (person === null || typeof person.id !== 'number') throw new Error('人员接口未返回人员编号。')

    const employment = await postRow(
      '/v1/employments',
      {
        person_id: person.id,
        employee_no: draft.employee_no.trim(),
        employment_type: draft.employment_type,
        status: draft.status,
        valid_from: draft.valid_from,
        hire_date: draft.hire_date,
      },
      'employment-create',
    )
    if (employment === null || typeof employment.id !== 'number') throw new Error('员工关系接口未返回员工关系编号。')
    createdEmploymentId.value = employment.id

    await postRow(
      `/v1/employments/${employment.id}/assignments`,
      {
        org_unit_id: orgUnitId,
        position_id: numeric(draft.position_id),
        assignment_type: 'PRIMARY',
        is_primary: true,
        valid_from: draft.valid_from,
      },
      'assignment-create',
    )

    await postRow(
      `/v1/employments/${employment.id}/terms`,
      {
        effective_from: draft.valid_from,
        work_location: draft.work_location.trim(),
        work_schedule: draft.work_schedule.trim(),
      },
      'terms-create',
    )

    ElMessage.success('员工档案已建立：人员、员工关系、主任职与条款均已写入。')
    emit('created', employment.id)
  } catch (error) {
    formError.value = peopleErrorInfo(error)
    if (createdEmploymentId.value !== null) {
      formError.value.message = `${formError.value.message}（员工关系 ${createdEmploymentId.value} 已创建，可在档案详情继续补齐后续步骤。）`
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <section class="panel people-form-panel">
    <header class="panel__header">
      <div>
        <h2 class="panel__title">员工建档向导</h2>
        <p class="panel__subtitle">
          按 Person → Employment → Assignment → Terms 顺序调用真实接口；若中途失败会保留已成功的前序编号，避免重复建档。
        </p>
      </div>
    </header>
    <div class="panel__body">
      <form class="people-form" @submit.prevent="submit">
        <div class="people-form__grid">
          <label class="people-field"><span>姓名 *</span><input v-model="draft.legal_name" data-testid="employee-legal-name" /></label>
          <label class="people-field"><span>邮箱</span><input v-model="draft.email" data-testid="employee-email" type="email" /></label>
          <label class="people-field"><span>工号 *</span><input v-model="draft.employee_no" data-testid="employee-no" /></label>
          <label class="people-field"><span>组织编号 *</span><input v-model="draft.org_unit_id" data-testid="employee-org-unit" inputmode="numeric" /></label>
          <label class="people-field"><span>岗位编号</span><input v-model="draft.position_id" inputmode="numeric" /></label>
          <label class="people-field"><span>用工类型 *</span><select v-model="draft.employment_type" data-testid="employee-type"><option :value="DISPLAY_CODES.employmentType.fullTime">{{ translate('people', 'employmentType', DISPLAY_CODES.employmentType.fullTime).label }}</option><option :value="DISPLAY_CODES.employmentType.partTime">{{ translate('people', 'employmentType', DISPLAY_CODES.employmentType.partTime).label }}</option><option :value="DISPLAY_CODES.employmentType.fixedTerm">{{ translate('people', 'employmentType', DISPLAY_CODES.employmentType.fixedTerm).label }}</option><option :value="DISPLAY_CODES.employmentType.intern">{{ translate('people', 'employmentType', DISPLAY_CODES.employmentType.intern).label }}</option><option :value="DISPLAY_CODES.employmentType.contractor">{{ translate('people', 'employmentType', DISPLAY_CODES.employmentType.contractor).label }}</option></select></label>
          <label class="people-field"><span>初始状态</span><select v-model="draft.status"><option :value="DISPLAY_CODES.employeeStatus.probation">{{ translate('people', 'employeeStatus', DISPLAY_CODES.employeeStatus.probation).label }}</option><option :value="DISPLAY_CODES.employeeStatus.active">{{ translate('people', 'employeeStatus', DISPLAY_CODES.employeeStatus.active).label }}</option></select></label>
          <label class="people-field"><span>任职生效日 *</span><input v-model="draft.valid_from" data-testid="employee-valid-from" type="date" /></label>
          <label class="people-field"><span>入职日期 *</span><input v-model="draft.hire_date" data-testid="employee-hire-date" type="date" /></label>
          <label class="people-field"><span>工作地点 *</span><input v-model="draft.work_location" data-testid="employee-work-location" /></label>
          <label class="people-field"><span>工作安排 *</span><input v-model="draft.work_schedule" data-testid="employee-work-schedule" placeholder="例如 5x8" /></label>
        </div>

        <p v-if="formError" class="people-form__error" role="alert" data-testid="employee-form-error">
          {{ formError.code }}：{{ formError.message }}
          <span v-if="formError.requestId">（请求编号 {{ formError.requestId }}）</span>
        </p>

        <div class="people-form__actions">
          <button type="submit" class="people-button" :disabled="submitting" data-testid="employee-submit">
            {{ submitting ? '建档中…' : '创建员工档案' }}
          </button>
        </div>
      </form>
    </div>
  </section>
</template>
