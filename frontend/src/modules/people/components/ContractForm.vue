<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { useRoute } from 'vue-router'
import type { PeopleForm, PeopleRow } from '../types'
import { peopleErrorInfo, type PeopleErrorInfo } from '../peopleErrors'
import { postRow } from '../write'
import { rowText } from '../peopleRead'
import { DISPLAY_CODES, translate } from '@/shared/display/dictionary'

const props = withDefaults(
  defineProps<{
    rows?: PeopleRow[]
    employmentId?: number | null
    form?: PeopleForm
  }>(),
  { rows: () => [], employmentId: null, form: 'contract-editor' },
)

const emit = defineEmits<{ created: []; saved: [] }>()
const route = useRoute()

const draft = reactive({
  employment_id: props.employmentId === null ? '' : String(props.employmentId),
  contract_no: '',
  contract_type: 'FIXED_TERM',
  start_date: '',
  end_date: '',
  signed_on: '',
  status: DISPLAY_CODES.contractStatus.active,
  terms_summary: '',
})
const amending = reactive({
  contract_id: '',
  effective_date: '',
  reason: '',
  contract_type: '',
  end_date: '',
  terms_summary: '',
})
const templateDraft = reactive({
  template_id: '',
  code: '',
  name: '',
  body: '',
})
const submitting = ref(false)
const historyLoading = ref(false)
const history = ref<PeopleRow[]>([])
const formError = ref<PeopleErrorInfo | null>(null)

const mode = computed<'create' | 'changes' | 'template'>(() => {
  if (props.form === 'contract-changes') return 'changes'
  if (props.form === 'contract-template-editor') return 'template'
  return 'create'
})

const routeContractId = computed(() => {
  const id = route.params.id
  return typeof id === 'string' && /^\d+$/.test(id) ? id : ''
})

function numeric(value: string): number | null {
  if (value.trim() === '') return null
  const parsed = Number(value)
  return Number.isFinite(parsed) ? parsed : null
}

function validationError(message: string): void {
  formError.value = {
    code: 'VALIDATION',
    message,
    requestId: null,
    reason: null,
    fieldErrors: {},
  }
}

function selectedContractId(): number | null {
  const explicit = numeric(amending.contract_id)
  if (explicit !== null) return explicit
  const routed = numeric(routeContractId.value)
  if (routed !== null) return routed
  const selected = props.rows.find((row) => row.status !== DISPLAY_CODES.contractStatus.terminated && row.status !== DISPLAY_CODES.contractStatus.archived) ?? props.rows[0]
  return selected ? numeric(String(selected.id ?? '')) : null
}

async function loadHistory(): Promise<void> {
  const contractId = selectedContractId()
  if (contractId === null) {
    history.value = []
    return
  }
  historyLoading.value = true
  try {
    const { resourceGet } = await import('@/api/resourceClient')
    const data = await resourceGet<unknown>(`/v1/employment-contracts/${contractId}/amendments`, { page: 1, page_size: 20 })
    if (typeof data === 'object' && data !== null && !Array.isArray(data)) {
      const items = (data as Record<string, unknown>).items
      history.value = Array.isArray(items)
        ? items.filter((item): item is PeopleRow => typeof item === 'object' && item !== null && !Array.isArray(item))
        : []
    } else {
      history.value = []
    }
  } catch (error) {
    formError.value = peopleErrorInfo(error)
  } finally {
    historyLoading.value = false
  }
}

async function submit(): Promise<void> {
  formError.value = null
  const employmentId = numeric(draft.employment_id)
  if (employmentId === null || draft.contract_no.trim() === '' || draft.start_date === '') {
    validationError('请填写员工关系编号、合同编号与开始日期。')
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

type ContractLifecycleOperation = 'amend' | 'terminate' | 'publish'

type ContractOperationInput = {
  operation: ContractLifecycleOperation
  contractId: number | null
  reason: string
  effectiveDate: string
  contractType: string
  endDate: string
  termsSummary: string
  templateId: number | null
  templateCode: string
  templateName: string
  templateBody: string
}

function contractOperationRequest(
  operation: ContractLifecycleOperation,
  input: ContractOperationInput,
): { path: string; body: Record<string, unknown>; key: string } | null {
  if (operation === 'publish') {
    if (input.templateId === null) return null
    return {
      path: `/v1/contract-templates/${input.templateId}/publish`,
      body: {},
      key: 'contract-template-publish',
    }
  }

  if (operation === 'terminate') {
    if (input.contractId === null || input.reason.trim() === '' || input.effectiveDate === '') return null
    return {
      path: `/v1/employment-contracts/${input.contractId}/terminate`,
      body: { effective_date: input.effectiveDate, reason: input.reason.trim() },
      key: 'contract-terminate',
    }
  }

  if (input.contractId === null || input.reason.trim() === '' || input.effectiveDate === '') return null
  const changes: Record<string, unknown> = {}
  if (input.contractType !== '') changes.contract_type = input.contractType
  if (input.endDate !== '') changes.end_date = input.endDate
  if (input.termsSummary.trim() !== '') changes.terms_summary = input.termsSummary.trim()
  if (input.templateId !== null) changes.template_id = input.templateId
  if (Object.keys(changes).length === 0) return null

  return {
    path: `/v1/employment-contracts/${input.contractId}/amendments`,
    body: {
      effective_date: input.effectiveDate,
      reason: input.reason.trim(),
      changes,
    },
    key: 'contract-amend',
  }
}

function operationInput(operation: ContractLifecycleOperation): ContractOperationInput {
  return {
    operation,
    contractId: selectedContractId(),
    reason: amending.reason,
    effectiveDate: amending.effective_date,
    contractType: amending.contract_type,
    endDate: amending.end_date,
    termsSummary: amending.terms_summary,
    templateId: operation === 'publish' ? numeric(templateDraft.template_id) : null,
    templateCode: templateDraft.code,
    templateName: templateDraft.name,
    templateBody: templateDraft.body,
  }
}

async function runOperation(operation: ContractLifecycleOperation): Promise<void> {
  formError.value = null
  const request = contractOperationRequest(operation, operationInput(operation))
  if (request === null) {
    validationError(
      operation === 'publish'
        ? '请填写要发布的模板版本编号。'
        : '请选择合同、填写生效日期与变更原因，并至少修改一个字段。',
    )
    return
  }
  submitting.value = true
  try {
    await postRow(request.path, request.body, request.key)
    ElMessage.success(operation === 'amend' ? '合同变更版本已追加。' : operation === 'terminate' ? '合同已终止并写入版本历史。' : '模板版本已发布。')
    amending.reason = ''
    amending.effective_date = ''
    amending.contract_type = ''
    amending.end_date = ''
    amending.terms_summary = ''
    await loadHistory()
    emit('saved')
  } catch (error) {
    formError.value = peopleErrorInfo(error)
  } finally {
    submitting.value = false
  }
}

async function createTemplate(): Promise<void> {
  formError.value = null
  if (templateDraft.code.trim() === '' || templateDraft.name.trim() === '' || templateDraft.body.trim() === '') {
    validationError('请填写模板编码、名称与正文。')
    return
  }
  submitting.value = true
  try {
    await postRow(
      '/v1/contract-templates',
      {
        code: templateDraft.code.trim().toUpperCase(),
        name: templateDraft.name.trim(),
        body: templateDraft.body.trim(),
      },
      'contract-template',
    )
    ElMessage.success('模板草稿已创建。发布后旧版本会自动归档。')
    templateDraft.code = ''
    templateDraft.name = ''
    templateDraft.body = ''
    emit('saved')
  } catch (error) {
    formError.value = peopleErrorInfo(error)
  } finally {
    submitting.value = false
  }
}

function historyChange(row: PeopleRow): string {
  return `${rowText(row, 'change_type')} · ${rowText(row, 'effective_from')}`
}
</script>

<template>
  <section class="panel people-form-panel">
    <header class="panel__header">
      <div>
        <h2 class="panel__title">{{ mode === 'changes' ? '合同变更 / 终止' : mode === 'template' ? '合同模板版本与发布' : '录入合同 / 生成续签版本' }}</h2>
        <p class="panel__subtitle">
          {{ mode === 'changes' ? '变更与终止都会追加不可改写的版本历史。' : mode === 'template' ? '同一编码的模板按版本递增；发布新版本会归档上一发布版本。' : (rows.length > 0 ? `当前已加载 ${rows.length} 份合同；新合同编号由服务端做租户内唯一校验。` : '选择员工关系后录入合同。') }}
        </p>
      </div>
    </header>
    <div class="panel__body">
      <form v-if="mode === 'create'" class="people-form" @submit.prevent="submit">
        <div class="people-form__grid">
          <label class="people-field"><span>员工关系编号 *</span><input v-model="draft.employment_id" data-testid="contract-employment-id" inputmode="numeric" /></label>
          <label class="people-field"><span>合同编号 *</span><input v-model="draft.contract_no" data-testid="contract-no" /></label>
          <label class="people-field"><span>合同类型 *</span><select v-model="draft.contract_type" data-testid="contract-type"><option value="FIXED_TERM">固定期限</option><option value="OPEN_ENDED">无固定期限</option><option value="INTERNSHIP">实习</option><option value="CONSULTANT">顾问</option></select></label>
          <label class="people-field"><span>开始日期 *</span><input v-model="draft.start_date" data-testid="contract-start" type="date" /></label>
          <label class="people-field"><span>结束日期</span><input v-model="draft.end_date" data-testid="contract-end" type="date" /></label>
          <label class="people-field"><span>签署日期</span><input v-model="draft.signed_on" type="date" /></label>
          <label class="people-field"><span>状态</span><select v-model="draft.status"><option :value="DISPLAY_CODES.contractStatus.active">{{ translate('people', 'contractStatus', DISPLAY_CODES.contractStatus.active).label }}</option><option :value="DISPLAY_CODES.contractStatus.draft">{{ translate('people', 'contractStatus', DISPLAY_CODES.contractStatus.draft).label }}</option></select></label>
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

      <form v-else-if="mode === 'changes'" class="people-form" @submit.prevent="runOperation('amend')">
        <div class="people-form__grid">
          <label class="people-field"><span>合同记录编号 *</span><input v-model="amending.contract_id" data-testid="contract-amend-id" inputmode="numeric" :placeholder="routeContractId || '选择台账中的合同记录编号'" /></label>
          <label class="people-field"><span>生效日期 *</span><input v-model="amending.effective_date" data-testid="contract-amend-effective" type="date" /></label>
          <label class="people-field people-field--wide"><span>变更原因 *</span><input v-model="amending.reason" data-testid="contract-amend-reason" /></label>
          <label class="people-field"><span>合同类型</span><select v-model="amending.contract_type" data-testid="contract-amend-type"><option value="">不变</option><option value="FIXED_TERM">固定期限</option><option value="OPEN_ENDED">无固定期限</option><option value="INTERNSHIP">实习</option><option value="CONSULTANT">顾问</option></select></label>
          <label class="people-field"><span>结束日期</span><input v-model="amending.end_date" data-testid="contract-amend-end" type="date" /></label>
          <label class="people-field people-field--wide"><span>条款摘要</span><textarea v-model="amending.terms_summary" data-testid="contract-amend-terms" rows="2" /></label>
        </div>
        <p v-if="formError" class="people-form__error" role="alert" data-testid="contract-form-error">
          {{ formError.code }}：{{ formError.message }}
          <span v-if="formError.requestId">（请求编号 {{ formError.requestId }}）</span>
        </p>
        <div class="people-form__actions">
          <button type="button" class="people-button" :disabled="submitting" data-testid="contract-amend-submit" @click="runOperation('amend')">追加变更版本</button>
          <button type="button" class="people-button people-button--secondary" :disabled="submitting" data-testid="contract-terminate-submit" @click="runOperation('terminate')">终止合同</button>
          <button type="button" class="people-button people-button--secondary" :disabled="historyLoading" data-testid="contract-history-load" @click="loadHistory">加载版本历史</button>
        </div>
        <ul v-if="history.length > 0" class="people-history" data-testid="contract-history">
          <li v-for="row in history" :key="String(row.id)">{{ historyChange(row) }} · {{ rowText(row, 'reason') }}</li>
        </ul>
      </form>

      <form v-else class="people-form" @submit.prevent="createTemplate">
        <div class="people-form__grid">
          <label class="people-field"><span>模板编码 *</span><input v-model="templateDraft.code" data-testid="contract-template-code" /></label>
          <label class="people-field"><span>模板名称 *</span><input v-model="templateDraft.name" data-testid="contract-template-name" /></label>
          <label class="people-field people-field--wide"><span>模板正文 *</span><textarea v-model="templateDraft.body" data-testid="contract-template-body" rows="4" /></label>
          <label class="people-field"><span>发布版本编号</span><input v-model="templateDraft.template_id" data-testid="contract-template-publish-id" inputmode="numeric" /></label>
        </div>
        <p v-if="formError" class="people-form__error" role="alert" data-testid="contract-form-error">
          {{ formError.code }}：{{ formError.message }}
          <span v-if="formError.requestId">（请求编号 {{ formError.requestId }}）</span>
        </p>
        <div class="people-form__actions">
          <button type="button" class="people-button" :disabled="submitting" data-testid="contract-template-submit" @click="createTemplate">创建模板草稿</button>
          <button type="button" class="people-button people-button--secondary" :disabled="submitting" data-testid="contract-template-publish" @click="runOperation('publish')">发布模板版本</button>
        </div>
      </form>
    </div>
  </section>
</template>

<style scoped>
.people-history {
  margin: 14px 0 0;
  padding: 0;
  color: var(--color-ink-secondary);
  font-size: 12px;
  list-style: none;
}

.people-history li {
  padding: 7px 0;
  border-bottom: 1px solid var(--color-line);
}
</style>
