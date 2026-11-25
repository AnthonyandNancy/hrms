<script setup lang="ts">
import { reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import type { PeopleRow } from './types'
import { peopleErrorInfo, type PeopleErrorInfo } from './peopleErrors'
import { postRow } from './write'

const props = withDefaults(
  defineProps<{
    rows?: PeopleRow[]
    employmentId?: number | null
  }>(),
  { rows: () => [], employmentId: null },
)

const emit = defineEmits<{ created: [] }>()

const draft = reactive({
  employment_id: props.employmentId === null ? '' : String(props.employmentId),
  contract_no: '',
  contract_type: 'FIXED_TERM',
  start_date: '',
  end_date: '',
  signed_on: '',
  status: 'ACTIVE',
  terms_summary: '',
})
const submitting = ref(false)
const formError = ref<PeopleErrorInfo | null>(null)

function numeric(value: string): number | null {
  if (value.trim() === '') return null
  const parsed = Number(value)
  return Number.isFinite(parsed) ? parsed : null
}

async function submit(): Promise<void> {
  formError.value = null
  const employmentId = numeric(draft.employment_id)
  if (employmentId === null || draft.contract_no.trim() === '' || draft.start_date === '') {
    formError.value = {
      code: 'VALIDATION',
      message: '请填写员工关系编号、合同编号与开始日期。',
      requestId: null,
      reason: null,
      fieldErrors: {},
    }
    return
  }
  submitting.value = true
  try {
    await postRow(
      `/v1/employments/${employmentId}/contracts`,
      {
        contract_no: draft.contract_no.trim(),
        contract_type: draft.contract_type,
        start_date: draft.start_date,
        end_date: draft.end_date === '' ? null : draft.end_date,
        signed_on: draft.signed_on === '' ? null : draft.signed_on,
        status: draft.status,
        terms_summary: draft.terms_summary.trim() === '' ? null : draft.terms_summary.trim(),
      },
      'contract',
    )
    ElMessage.success('合同版本已创建；如已有生效合同，前一版本会标记为已续签。')
    draft.contract_no = ''
    draft.start_date = ''
    draft.end_date = ''
    draft.signed_on = ''
    draft.terms_summary = ''
    emit('created')
  } catch (error) {
    formError.value = peopleErrorInfo(error)
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <section class="panel people-form-panel">
    <header class="panel__header">
      <div>
        <h2 class="panel__title">录入合同 / 生成续签版本</h2>
        <p class="panel__subtitle">
          {{ rows.length > 0 ? `当前已加载 ${rows.length} 份合同；新合同编号由服务端做租户内唯一校验。` : '选择员工关系后录入合同。' }}
        </p>
      </div>
    </header>
    <div class="panel__body">
      <form class="people-form" @submit.prevent="submit">
        <div class="people-form__grid">
          <label class="people-field"><span>员工关系编号 *</span><input v-model="draft.employment_id" data-testid="contract-employment-id" inputmode="numeric" /></label>
          <label class="people-field"><span>合同编号 *</span><input v-model="draft.contract_no" data-testid="contract-no" /></label>
          <label class="people-field"><span>合同类型 *</span><select v-model="draft.contract_type" data-testid="contract-type"><option value="FIXED_TERM">固定期限</option><option value="OPEN_ENDED">无固定期限</option><option value="INTERNSHIP">实习</option><option value="CONSULTANT">顾问</option></select></label>
          <label class="people-field"><span>开始日期 *</span><input v-model="draft.start_date" data-testid="contract-start" type="date" /></label>
          <label class="people-field"><span>结束日期</span><input v-model="draft.end_date" data-testid="contract-end" type="date" /></label>
          <label class="people-field"><span>签署日期</span><input v-model="draft.signed_on" type="date" /></label>
          <label class="people-field"><span>状态</span><select v-model="draft.status"><option value="ACTIVE">生效</option><option value="DRAFT">草稿</option></select></label>
          <label class="people-field people-field--wide"><span>条款摘要</span><textarea v-model="draft.terms_summary" data-testid="contract-terms" rows="2" /></label>
        </div>
        <p v-if="formError" class="people-form__error" role="alert" data-testid="contract-form-error">
          {{ formError.code }}：{{ formError.message }}
          <span v-if="formError.requestId">（请求编号 {{ formError.requestId }}）</span>
        </p>
        <div class="people-form__actions">
          <button type="submit" class="people-button" :disabled="submitting" data-testid="contract-submit">
            {{ submitting ? '提交中…' : '创建合同版本' }}
          </button>
        </div>
      </form>
    </div>
  </section>
</template>
