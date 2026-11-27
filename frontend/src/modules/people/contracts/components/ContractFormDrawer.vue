<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { ApiError } from '@/api/errors'
import {
  CONTRACT_TYPES,
  type Contract,
  type ContractFormInput,
  type ContractType,
  type EmployeeOption,
} from '../api'

type Mode = 'create' | 'renew' | 'edit'

type Draft = {
  employmentId: string
  contractNo: string
  contractType: ContractType
  startDate: string
  endDate: string
  probationEndDate: string
  workLocation: string
  signedOn: string
  termsSummary: string
  reason: string
  effectiveDate: string
}

type EditChanges = {
  contract_type?: ContractType
  end_date?: string | null
  signed_on?: string | null
  terms_summary?: string | null
  work_location?: string | null
  probation_end_date?: string | null
}

const props = withDefaults(
  defineProps<{
    modelValue: boolean
    mode?: Mode
    contract?: Contract | null
    renewalSource?: Contract | null
    employees?: readonly EmployeeOption[]
    saving?: boolean
    serverError?: ApiError | null
  }>(),
  {
    mode: 'create',
    contract: null,
    renewalSource: null,
    employees: () => [],
    saving: false,
    serverError: null,
  },
)

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  save: [input: ContractFormInput]
  amend: [input: { effectiveDate: string; reason: string; changes: EditChanges }]
}>()

defineOptions({ name: 'ContractFormDrawer' })

const blankDraft = (): Draft => ({
  employmentId: '',
  contractNo: '',
  contractType: 'FIXED_TERM',
  startDate: '',
  endDate: '',
  probationEndDate: '',
  workLocation: '',
  signedOn: '',
  termsSummary: '',
  reason: '',
  effectiveDate: '',
})

const draft = reactive<Draft>(blankDraft())
const localError = ref('')
const idempotencyHint = ref('')

const open = computed({
  get: () => props.modelValue,
  set: (value: boolean) => emit('update:modelValue', value),
})

const title = computed(() => {
  if (props.mode === 'renew') return '续签劳动合同'
  if (props.mode === 'edit') return '变更劳动合同'
  return '新建劳动合同'
})

const description = computed(() => {
  if (props.mode === 'renew') return '续签会生成新版本并保留上一版本的完整历史，不会覆盖原合同。'
  if (props.mode === 'edit') return '台账接口未提供直接编辑；以下变更会作为新的版本追加，历史不可改写。'
  return '草稿可先保存，确认条款后再生效。工作地点与试用期会写入版本快照。'
})

const fieldErrors = computed<Record<string, string[]>>(() => props.serverError?.fieldErrors ?? {})

function firstFieldError(field: string): string {
  const messages = fieldErrors.value[field]
  return Array.isArray(messages) && messages.length > 0 ? messages[0] ?? '' : ''
}

const summaryError = computed(() => {
  if (localError.value !== '') return localError.value
  if (props.serverError === null) return ''
  const first = Object.values(fieldErrors.value)[0]
  if (first !== undefined && first.length > 0 && first[0] !== undefined) return first[0]
  return props.serverError.message || '保存未完成，请检查后重试。'
})

const employeeLabel = computed(() => {
  if (props.mode === 'edit' && props.contract !== null) {
    return props.contract.employee_name ?? `员工 #${props.contract.employment_id}`
  }
  const option = props.employees.find((employee) => String(employee.employment_id) === draft.employmentId)
  return option === undefined ? '' : `${option.person_name}（${option.employee_no}）`
})

function hydrate(): void {
  localError.value = ''
  idempotencyHint.value = ''
  Object.assign(draft, blankDraft())

  if (props.mode === 'edit' && props.contract !== null) {
    draft.employmentId = String(props.contract.employment_id)
    draft.contractNo = props.contract.contract_no
    draft.contractType = (props.contract.contract_type as ContractType) ?? 'FIXED_TERM'
    draft.startDate = props.contract.start_date
    draft.endDate = props.contract.end_date ?? ''
    draft.probationEndDate = props.contract.probation_end_date ?? ''
    draft.workLocation = props.contract.work_location ?? ''
    draft.signedOn = props.contract.signed_on ?? ''
    draft.termsSummary = props.contract.terms_summary ?? ''
    draft.effectiveDate = props.contract.start_date
    return
  }

  if (props.mode === 'renew' && props.renewalSource !== null) {
    const nextYear = Number(props.renewalSource.end_date?.slice(0, 4) ?? new Date().getUTCFullYear()) + 1
    draft.employmentId = String(props.renewalSource.employment_id)
    draft.contractType = (props.renewalSource.contract_type as ContractType) ?? 'FIXED_TERM'
    draft.startDate = props.renewalSource.end_date ?? ''
    draft.endDate = `${nextYear}-12-31`
    draft.signedOn = ''
    draft.termsSummary = props.renewalSource.terms_summary ?? ''
    return
  }

  draft.employmentId = props.employees[0]?.employment_id.toString() ?? ''
}

watch(
  () => [props.modelValue, props.mode, props.contract?.id, props.renewalSource?.id] as const,
  ([isOpen]) => {
    if (isOpen) hydrate()
  },
  { immediate: true },
)

function isDate(value: string): boolean {
  return /^\d{4}-\d{2}-\d{2}$/.test(value)
}

function sixMonthsAfter(start: string): string {
  const date = new Date(`${start}T00:00:00Z`)
  date.setUTCDate(date.getUTCDate() + 183)
  return date.toISOString().slice(0, 10)
}

function validateDates(): boolean {
  if (!isDate(draft.startDate)) {
    localError.value = '请填写开始日期。'
    return false
  }
  if (draft.endDate !== '') {
    if (!isDate(draft.endDate)) {
      localError.value = '结束日期格式无效。'
      return false
    }
    if (draft.endDate <= draft.startDate) {
      localError.value = '结束日期必须晚于开始日期。'
      return false
    }
  }
  if (draft.probationEndDate !== '') {
    if (!isDate(draft.probationEndDate)) {
      localError.value = '试用期结束日期格式无效。'
      return false
    }
    if (draft.probationEndDate < draft.startDate) {
      localError.value = '试用期结束日期不能早于合同开始日期。'
      return false
    }
    if (draft.endDate !== '' && draft.probationEndDate > draft.endDate) {
      localError.value = '试用期结束日期必须落在合同期限内。'
      return false
    }
    if (draft.probationEndDate > sixMonthsAfter(draft.startDate)) {
      localError.value = '试用期不得超过六个月。'
      return false
    }
  }
  return true
}

function submit(): void {
  localError.value = ''

  if (props.mode === 'edit') {
    if (props.contract === null) return
    if (!isDate(draft.effectiveDate)) {
      localError.value = '请填写变更生效日期。'
      return
    }
    if (draft.reason.trim().length < 3) {
      localError.value = '请填写变更原因（至少 3 个字符）。'
      return
    }
    const changes: EditChanges = {}
    if (draft.contractType !== (props.contract.contract_type as ContractType)) changes.contract_type = draft.contractType
    const nextEnd = draft.endDate === '' ? null : draft.endDate
    const nextProbation = draft.probationEndDate === '' ? null : draft.probationEndDate
    if (nextProbation !== null && nextEnd !== null && nextProbation > nextEnd) {
      localError.value = '试用期结束日期必须落在合同期限内。'
      return
    }
    if (nextProbation !== null && nextProbation < props.contract.start_date) {
      localError.value = '试用期结束日期不能早于合同开始日期。'
      return
    }
    if (nextEnd !== props.contract.end_date) changes.end_date = nextEnd
    const nextSigned = draft.signedOn === '' ? null : draft.signedOn
    if (nextSigned !== props.contract.signed_on) changes.signed_on = nextSigned
    const nextTerms = draft.termsSummary.trim() === '' ? null : draft.termsSummary.trim()
    if (nextTerms !== props.contract.terms_summary) changes.terms_summary = nextTerms
    const nextLocation = draft.workLocation.trim() === '' ? null : draft.workLocation.trim()
    if (nextLocation !== props.contract.work_location) changes.work_location = nextLocation
    if (nextProbation !== props.contract.probation_end_date) changes.probation_end_date = nextProbation
    if (Object.keys(changes).length === 0) {
      localError.value = '请至少修改一个可变更字段。'
      return
    }
    if (nextEnd !== null && nextEnd <= draft.effectiveDate) {
      localError.value = '新的结束日期必须晚于变更生效日期。'
      return
    }
    emit('amend', { effectiveDate: draft.effectiveDate, reason: draft.reason.trim(), changes })
    return
  }

  const employmentId = Number(draft.employmentId)
  if (!Number.isSafeInteger(employmentId) || employmentId <= 0) {
    localError.value = '请选择员工。'
    return
  }
  if (draft.contractNo.trim() === '') {
    localError.value = '请填写合同编号。'
    return
  }
  if (!validateDates()) return
  if (props.mode === 'renew' && draft.reason.trim().length < 3) {
    localError.value = '请填写续签原因（至少 3 个字符）。'
    return
  }

  idempotencyHint.value = props.mode === 'renew' ? '续签将生成新版本并保留当前版本。' : '草稿保存后可继续补充条款。'
  emit('save', {
    employment_id: employmentId,
    contract_no: draft.contractNo.trim(),
    contract_type: draft.contractType,
    start_date: draft.startDate,
    end_date: draft.endDate === '' ? null : draft.endDate,
    probation_end_date: draft.probationEndDate === '' ? null : draft.probationEndDate,
    work_location: draft.workLocation.trim() === '' ? null : draft.workLocation.trim(),
    signed_on: draft.signedOn === '' ? null : draft.signedOn,
    terms_summary: draft.termsSummary.trim() === '' ? null : draft.termsSummary.trim(),
    status: 'DRAFT',
    reason: props.mode === 'renew' ? draft.reason.trim() : null,
    renewal_of_contract_id: props.mode === 'renew' ? props.renewalSource?.id ?? null : null,
  })
}
</script>

<template>
  <el-drawer
    v-model="open"
    :title="title"
    size="min(640px, 96vw)"
    :close-on-click-modal="false"
    data-testid="contract-form-drawer"
  >
    <form class="contract-form" @submit.prevent="submit">
      <p class="contract-form__description">{{ description }}</p>

      <div
        v-if="mode === 'renew' && renewalSource !== null"
        class="contract-form__comparison"
        data-testid="renewal-comparison"
      >
        <h3>当前版本（保留）</h3>
        <dl>
          <div><dt>合同编号</dt><dd>{{ renewalSource.contract_no }}</dd></div>
          <div><dt>类型</dt><dd>{{ CONTRACT_TYPES.find((type) => type.value === renewalSource?.contract_type)?.label }}</dd></div>
          <div><dt>起止日期</dt><dd>{{ renewalSource.start_date }} 至 {{ renewalSource.end_date ?? '无固定期限' }}</dd></div>
          <div><dt>状态</dt><dd>{{ renewalSource.display_status_label }}</dd></div>
          <div><dt>版本</dt><dd>第 {{ renewalSource.version }} 版</dd></div>
        </dl>
        <p>新版本提交后，当前版本会标记为已续签，日期与签署信息不会被改写。</p>
      </div>

      <div class="contract-form__grid">
        <label class="contract-field">
          <span>员工 *</span>
          <select
            v-if="employees.length > 0 || mode !== 'create'"
            v-model="draft.employmentId"
            data-testid="contract-employee"
            :disabled="mode !== 'create'"
            aria-label="员工"
          >
            <option value="">请选择员工</option>
            <option v-for="employee in employees" :key="employee.employment_id" :value="String(employee.employment_id)">
              {{ employee.person_name }}（{{ employee.employee_no }}）
            </option>
            <option v-if="mode !== 'create' && employeeLabel !== ''" :value="draft.employmentId">
              {{ employeeLabel }}
            </option>
          </select>
          <input
            v-else
            v-model="draft.employmentId"
            data-testid="contract-employee-manual"
            inputmode="numeric"
            placeholder="员工目录不可读，请输入员工关系编号"
            aria-label="员工关系编号"
          />
          <small class="contract-field__hint">员工下拉来自员工目录；无员工读取权限时可手动输入员工关系编号。</small>
          <small v-if="firstFieldError('employment_id')" class="contract-field__error">{{ firstFieldError('employment_id') }}</small>
        </label>

        <label class="contract-field">
          <span>合同编号 *</span>
          <input
            v-model="draft.contractNo"
            data-testid="contract-no"
            maxlength="80"
            :disabled="mode === 'edit'"
            autocomplete="off"
          />
          <small v-if="firstFieldError('contract_no')" class="contract-field__error" data-testid="contract-no-error">{{ firstFieldError('contract_no') }}</small>
        </label>

        <label class="contract-field">
          <span>合同类型 *</span>
          <select v-model="draft.contractType" data-testid="contract-type">
            <option v-for="type in CONTRACT_TYPES" :key="type.value" :value="type.value">{{ type.label }}</option>
          </select>
          <small v-if="firstFieldError('contract_type')" class="contract-field__error">{{ firstFieldError('contract_type') }}</small>
        </label>

        <label class="contract-field">
          <span>开始日期 *</span>
          <input
            v-model="draft.startDate"
            data-testid="contract-start"
            type="date"
            :disabled="mode !== 'create'"
          />
          <small v-if="firstFieldError('start_date')" class="contract-field__error">{{ firstFieldError('start_date') }}</small>
        </label>

        <label class="contract-field">
          <span>结束日期</span>
          <input v-model="draft.endDate" data-testid="contract-end" type="date" />
          <small class="contract-field__hint">无固定期限时可留空。</small>
          <small v-if="firstFieldError('end_date')" class="contract-field__error">{{ firstFieldError('end_date') }}</small>
        </label>

        <label class="contract-field">
          <span>试用期结束</span>
          <input
            v-model="draft.probationEndDate"
            data-testid="contract-probation"
            type="date"
          />
          <small class="contract-field__hint">不得超过六个月且须在合同期限内。</small>
          <small v-if="firstFieldError('probation_end_date')" class="contract-field__error">{{ firstFieldError('probation_end_date') }}</small>
        </label>

        <label class="contract-field">
          <span>工作地点</span>
          <input
            v-model="draft.workLocation"
            data-testid="contract-location"
            maxlength="160"
          />
          <small class="contract-field__hint">工作地点随版本快照保存。</small>
          <small v-if="firstFieldError('work_location')" class="contract-field__error">{{ firstFieldError('work_location') }}</small>
        </label>

        <label class="contract-field">
          <span>签署日期</span>
          <input v-model="draft.signedOn" data-testid="contract-signed" type="date" />
          <small v-if="firstFieldError('signed_on')" class="contract-field__error">{{ firstFieldError('signed_on') }}</small>
        </label>

        <label v-if="mode === 'edit'" class="contract-field">
          <span>变更生效日期 *</span>
          <input v-model="draft.effectiveDate" data-testid="contract-effective" type="date" />
          <small v-if="firstFieldError('effective_date')" class="contract-field__error">{{ firstFieldError('effective_date') }}</small>
        </label>

        <label v-if="mode !== 'create'" class="contract-field contract-field--wide">
          <span>{{ mode === 'renew' ? '续签原因 *' : '变更原因 *' }}</span>
          <input v-model="draft.reason" data-testid="contract-reason" maxlength="500" />
          <small v-if="firstFieldError('reason')" class="contract-field__error">{{ firstFieldError('reason') }}</small>
        </label>

        <label class="contract-field contract-field--wide">
          <span>条款摘要</span>
          <textarea v-model="draft.termsSummary" data-testid="contract-terms" rows="3" maxlength="2000" />
          <small v-if="firstFieldError('terms_summary')" class="contract-field__error">{{ firstFieldError('terms_summary') }}</small>
        </label>
      </div>

      <p v-if="mode === 'edit'" class="contract-form__gap" data-testid="contract-edit-gap">
        合同不可直接覆盖：本抽屉会把合同类型、起止日期、签署信息、工作地点、试用期与条款摘要追加为新的变更版本，历史版本保留。
      </p>

      <p v-if="summaryError !== ''" class="contract-form__error" role="alert" data-testid="contract-form-error">
        {{ summaryError }}
        <span v-if="serverError?.requestId">（请求编号 {{ serverError.requestId }}）</span>
      </p>
      <p v-else-if="idempotencyHint !== ''" class="contract-form__hint">{{ idempotencyHint }}</p>

      <div class="contract-form__actions">
        <el-button @click="open = false">取消</el-button>
        <el-button type="primary" :loading="saving" data-testid="contract-save" @click="submit">
          {{ mode === 'edit' ? '追加变更版本' : mode === 'renew' ? '确认续签' : '保存草稿' }}
        </el-button>
      </div>
    </form>
  </el-drawer>
</template>

<style scoped>
.contract-form {
  display: flex;
  flex-direction: column;
  gap: 14px;
}
.contract-form__description {
  margin: 0;
  color: var(--color-ink-muted);
  font-size: 12px;
}
.contract-form__grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}
.contract-field {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
  color: var(--color-ink-muted);
  font-size: 11px;
}
.contract-field--wide {
  grid-column: 1 / -1;
}
.contract-field > span {
  font-weight: 600;
}
.contract-field input,
.contract-field select,
.contract-field textarea {
  width: 100%;
  padding: 7px 9px;
  color: var(--color-ink);
  font-size: 12px;
  background: #fff;
  border: 1px solid var(--color-line-strong);
  border-radius: var(--radius-sm);
}
.contract-field__hint {
  color: var(--color-ink-muted);
  font-size: 10px;
}
.contract-field__error {
  color: var(--color-danger);
  font-size: 10.5px;
}
.contract-form__comparison {
  padding: 10px 12px;
  background: var(--color-brand-soft);
  border-radius: var(--radius-md);
}
.contract-form__comparison h3 {
  margin: 0 0 8px;
  font-size: 13px;
}
.contract-form__comparison dl {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 6px 14px;
  margin: 0;
}
.contract-form__comparison dt {
  color: var(--color-ink-muted);
  font-size: 10px;
}
.contract-form__comparison dd {
  margin: 1px 0 0;
  font-size: 12px;
}
.contract-form__comparison p {
  margin: 9px 0 0;
  color: var(--color-ink-secondary);
  font-size: 11px;
}
.contract-form__gap {
  margin: 0;
  padding: 9px 10px;
  color: #8a5a00;
  font-size: 11px;
  background: #fff7e6;
  border-radius: var(--radius-sm);
}
.contract-form__error {
  margin: 0;
  padding: 8px 10px;
  color: var(--color-danger);
  font-size: 12px;
  background: var(--color-danger-soft);
  border-radius: var(--radius-sm);
}
.contract-form__hint {
  margin: 0;
  color: var(--color-ink-muted);
  font-size: 11px;
}
.contract-form__actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}
@media (max-width: 640px) {
  .contract-form__grid,
  .contract-form__comparison dl {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
