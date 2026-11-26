<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { ApiError } from '@/api/errors'
import { formatMoney } from '@/utils/format'
import type { OrgUnitOption, Requisition, RequisitionInput, RequisitionPatch } from '../api'

type Mode = 'create' | 'edit'

type Draft = {
  orgUnitId: string
  requisitionNo: string
  title: string
  headcount: string
  budgetMinor: string
  budgetCurrency: string
  targetStartDate: string
}

const props = withDefaults(
  defineProps<{
    modelValue: boolean
    mode?: Mode
    requisition?: Requisition | null
    orgUnits?: readonly OrgUnitOption[]
    saving?: boolean
    serverError?: ApiError | null
    orgLookupError?: string
  }>(),
  {
    mode: 'create',
    requisition: null,
    orgUnits: () => [],
    saving: false,
    serverError: null,
    orgLookupError: '',
  },
)

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  save: [input: RequisitionInput | RequisitionPatch]
}>()

defineOptions({ name: 'RequisitionFormDrawer' })

function blankDraft(): Draft {
  return {
    orgUnitId: '',
    requisitionNo: '',
    title: '',
    headcount: '1',
    budgetMinor: '',
    budgetCurrency: 'CNY',
    targetStartDate: '',
  }
}

const draft = reactive<Draft>(blankDraft())
const localError = ref('')
const summaryError = ref('')

const title = computed(() => (props.mode === 'edit' ? '编辑招聘需求' : '新建招聘需求'))
const description = computed(() =>
  props.mode === 'edit'
    ? '编辑只更新需求工单字段；已关闭需求不可再修改。'
    : '创建后自动进入审批流程；审批通过前不能发布岗位或安排面试。',
)

const fieldErrors = computed<Record<string, string[]>>(() => props.serverError?.fieldErrors ?? {})

function firstFieldError(field: string): string {
  const messages = fieldErrors.value[field]
  return Array.isArray(messages) && messages.length > 0 ? messages[0] ?? '' : ''
}

function hydrate(): void {
  Object.assign(draft, blankDraft())
  localError.value = ''
  summaryError.value = ''

  if (props.mode === 'edit' && props.requisition !== null) {
    draft.orgUnitId = String(props.requisition.orgUnitId)
    draft.requisitionNo = props.requisition.requisitionNo
    draft.title = props.requisition.title
    draft.headcount = String(props.requisition.headcount)
    draft.budgetMinor = props.requisition.budgetMinor === null ? '' : String(props.requisition.budgetMinor)
    draft.budgetCurrency = props.requisition.budgetCurrency ?? 'CNY'
    draft.targetStartDate = props.requisition.targetStartDate ?? ''
    return
  }

  draft.orgUnitId = props.orgUnits[0] === undefined ? '' : String(props.orgUnits[0].id)
}

watch(
  () => [props.modelValue, props.mode, props.requisition?.id] as const,
  ([open]) => {
    if (open) hydrate()
  },
  { immediate: true },
)

watch(
  () => props.serverError,
  (error) => {
    if (error === null) {
      summaryError.value = ''
      return
    }
    const first = Object.values(error.fieldErrors)[0]
    summaryError.value =
      first !== undefined && first.length > 0 && first[0] !== undefined
        ? first[0]
        : error.message || '保存未完成，请检查后重试。'
  },
)

const budgetPreview = computed(() => {
  const value = draft.budgetMinor.trim()
  if (!/^\d+$/.test(value)) return '以整数最小单位（分）保存，渲染时格式化。'
  return `约 ${formatMoney(Number(value))}，以整数最小单位（分）保存。`
})

function generatedRequisitionNo(): string {
  const now = new Date()
  const year = now.getUTCFullYear()
  const suffix = String(now.getTime()).slice(-6)
  return `REQ-${year}-${suffix}`
}

function validate(): boolean {
  localError.value = ''

  if (!/^\d+$/.test(draft.orgUnitId) || Number(draft.orgUnitId) <= 0) {
    localError.value = '请选择招聘需求所属组织单元。'
    return false
  }
  if (!/^\d+$/.test(String(draft.headcount).trim()) || Number(draft.headcount) < 1 || Number(draft.headcount) > 1000) {
    localError.value = '编制人数必须是 1 至 1000 的整数。'
    return false
  }
  if (draft.budgetMinor.trim() !== '' && !/^\d+$/.test(draft.budgetMinor.trim())) {
    localError.value = '预算必须是非负整数（最小货币单位，分）。'
    return false
  }
  if (draft.budgetCurrency.trim() !== '' && draft.budgetCurrency.trim().length !== 3) {
    localError.value = '币种必须是 3 位代码，例如 CNY。'
    return false
  }
  if (draft.targetStartDate.trim() !== '' && !/^\d{4}-\d{2}-\d{2}$/.test(draft.targetStartDate.trim())) {
    localError.value = '目标到岗日期格式应为 YYYY-MM-DD。'
    return false
  }
  return true
}

function submit(): void {
  if (!validate()) return

  const budgetMinor = draft.budgetMinor.trim() === '' ? null : Number(draft.budgetMinor.trim())
  const targetStartDate = draft.targetStartDate.trim() === '' ? null : draft.targetStartDate.trim()

  if (props.mode === 'edit' && props.requisition !== null) {
    const patch: RequisitionPatch = {
      org_unit_id: Number(draft.orgUnitId),
      title: draft.title.trim(),
      headcount: Number(String(draft.headcount).trim()),
      budget_minor: budgetMinor,
      budget_currency: draft.budgetCurrency.trim() === '' ? null : draft.budgetCurrency.trim().toUpperCase(),
      target_start_date: targetStartDate,
    }
    emit('save', patch)
    return
  }

  emit('save', {
    org_unit_id: Number(draft.orgUnitId),
    requisition_no: (draft.requisitionNo.trim() === '' ? generatedRequisitionNo() : draft.requisitionNo.trim()),
    title: draft.title.trim(),
    headcount: Number(draft.headcount),
    budget_minor: budgetMinor,
    budget_currency: draft.budgetCurrency.trim() === '' ? null : draft.budgetCurrency.trim().toUpperCase(),
    target_start_date: targetStartDate,
  })
}
</script>

<template>
  <div v-if="modelValue" class="requisition-drawer-backdrop" data-testid="requisition-drawer">
    <section class="requisition-drawer" role="dialog" aria-modal="true" aria-labelledby="requisition-drawer-title">
      <header class="requisition-drawer__header">
        <div>
          <h2 id="requisition-drawer-title">{{ title }}</h2>
          <p>{{ description }}</p>
        </div>
        <button type="button" class="requisition-drawer__close" aria-label="关闭" @click="emit('update:modelValue', false)">
          ×
        </button>
      </header>

      <form class="requisition-drawer__form" @submit.prevent="submit">
        <p v-if="orgLookupError !== ''" class="requisition-drawer__warning" role="status">
          {{ orgLookupError }}
        </p>

        <label>
          <span>岗位</span>
          <input
            v-model="draft.title"
            data-testid="requisition-title"
            maxlength="160"
            :required="mode === 'create'"
            placeholder="例如：合成工程师"
          />
          <small v-if="firstFieldError('title')" class="requisition-drawer__error">{{ firstFieldError('title') }}</small>
        </label>

        <label>
          <span>组织单元</span>
          <select v-model="draft.orgUnitId" data-testid="requisition-org-unit" required>
            <option value="" disabled>请选择组织单元</option>
            <option v-for="unit in orgUnits" :key="unit.id" :value="String(unit.id)">
              {{ unit.name }}（{{ unit.code }}）
            </option>
          </select>
          <small v-if="firstFieldError('org_unit_id')" class="requisition-drawer__error">{{ firstFieldError('org_unit_id') }}</small>
        </label>

        <label>
          <span>需求编号</span>
          <input
            v-model="draft.requisitionNo"
            data-testid="requisition-no"
            maxlength="64"
            :disabled="mode === 'edit'"
            :placeholder="mode === 'edit' ? '' : '留空自动生成'"
          />
          <small v-if="firstFieldError('requisition_no')" class="requisition-drawer__error">{{ firstFieldError('requisition_no') }}</small>
        </label>

        <label>
          <span>编制人数</span>
          <input v-model="draft.headcount" data-testid="requisition-headcount" type="number" min="1" max="1000" required />
          <small v-if="firstFieldError('headcount')" class="requisition-drawer__error">{{ firstFieldError('headcount') }}</small>
        </label>

        <label>
          <span>预算（分）</span>
          <input
            v-model="draft.budgetMinor"
            data-testid="requisition-budget-minor"
            inputmode="numeric"
            placeholder="可选，例如 12000000"
          />
          <small>{{ budgetPreview }}</small>
          <small v-if="firstFieldError('budget_minor')" class="requisition-drawer__error">{{ firstFieldError('budget_minor') }}</small>
        </label>

        <label>
          <span>币种</span>
          <input v-model="draft.budgetCurrency" data-testid="requisition-budget-currency" maxlength="3" placeholder="CNY" />
        </label>

        <label>
          <span>目标到岗日期</span>
          <input v-model="draft.targetStartDate" data-testid="requisition-target-start-date" type="date" />
          <small v-if="firstFieldError('target_start_date')" class="requisition-drawer__error">
            {{ firstFieldError('target_start_date') }}
          </small>
        </label>

        <p v-if="localError !== ''" class="requisition-drawer__error" role="alert">{{ localError }}</p>
        <p v-else-if="summaryError !== ''" class="requisition-drawer__error" role="alert">
          {{ summaryError }}
          <template v-if="serverError?.requestId">（请求编号：{{ serverError.requestId }}）</template>
        </p>

        <footer class="requisition-drawer__footer">
          <button type="button" class="requisition-button" :disabled="saving" @click="emit('update:modelValue', false)">
            取消
          </button>
          <button type="submit" class="requisition-button requisition-button--primary" :disabled="saving" data-testid="requisition-save">
            {{ saving ? '保存中…' : '保存' }}
          </button>
        </footer>
      </form>
    </section>
  </div>
</template>

<style scoped>
.requisition-drawer-backdrop {
  position: fixed;
  inset: 0;
  z-index: 40;
  display: flex;
  justify-content: flex-end;
  background: rgb(15 23 42 / 38%);
}

.requisition-drawer {
  width: min(480px, 92vw);
  height: 100%;
  overflow-y: auto;
  background: #fff;
  box-shadow: -18px 0 42px rgb(15 23 42 / 18%);
}

.requisition-drawer__header {
  display: flex;
  justify-content: space-between;
  gap: 16px;
  padding: 22px 24px 12px;
  border-bottom: 1px solid #e2e8f0;
}

.requisition-drawer__header h2 {
  margin: 0;
  font-size: 18px;
}

.requisition-drawer__header p {
  margin: 8px 0 0;
  color: #64748b;
  font-size: 12.5px;
  line-height: 1.6;
}

.requisition-drawer__close {
  height: 30px;
  border: 0;
  background: transparent;
  font-size: 22px;
  cursor: pointer;
}

.requisition-drawer__form {
  display: flex;
  flex-direction: column;
  gap: 14px;
  padding: 20px 24px 28px;
}

.requisition-drawer__form label {
  display: flex;
  flex-direction: column;
  gap: 6px;
  color: #334155;
  font-size: 13px;
}

.requisition-drawer__form input,
.requisition-drawer__form select {
  height: 36px;
  padding: 0 10px;
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  background: #fff;
  color: #0f172a;
  font: inherit;
}

.requisition-drawer__form small {
  color: #64748b;
  font-size: 11.5px;
  line-height: 1.5;
}

.requisition-drawer__error {
  margin: 0;
  color: #b91c1c;
  font-size: 12.5px;
}

.requisition-drawer__warning {
  margin: 0;
  padding: 10px 12px;
  border-radius: 8px;
  background: #fff7ed;
  color: #9a3412;
  font-size: 12.5px;
}

.requisition-drawer__footer {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  padding-top: 8px;
}

.requisition-button {
  height: 34px;
  padding: 0 16px;
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  background: #fff;
  color: #334155;
  cursor: pointer;
}

.requisition-button--primary {
  border-color: #1d4ed8;
  background: #1d4ed8;
  color: #fff;
}

.requisition-button:disabled {
  cursor: not-allowed;
  opacity: 0.6;
}
</style>
