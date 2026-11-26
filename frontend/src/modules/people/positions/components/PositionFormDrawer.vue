<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { ApiError } from '@/api/errors'
import {
  POSITION_DUPLICATE_REASON,
  POSITION_EDIT_READONLY_NOTE,
  type OrgUnitOption,
  type Position,
  type PositionCreateInput,
  type PositionGrade,
  type PositionUpdateInput,
} from '../api'

const props = defineProps<{
  modelValue: boolean
  mode: 'create' | 'edit'
  position: Position | null
  orgUnits: readonly OrgUnitOption[]
  grades: readonly PositionGrade[]
  positions: readonly Position[]
  saving: boolean
  serverError: ApiError | null
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  save: [input: PositionCreateInput | PositionUpdateInput]
}>()

defineOptions({ name: 'PositionFormDrawer' })

type Draft = {
  code: string
  title: string
  orgUnitId: string
  grade: string
  headcountLimit: string
  validFrom: string
  validTo: string
}

const draft = reactive<Draft>({
  code: '',
  title: '',
  orgUnitId: '',
  grade: '',
  headcountLimit: '',
  validFrom: '',
  validTo: '',
})

const localError = ref('')
const localCodeError = ref('')

const open = computed({
  get: () => props.modelValue,
  set: (value: boolean) => emit('update:modelValue', value),
})

const title = computed(() => (props.mode === 'create' ? '新增岗位' : '编辑岗位'))

const fieldErrors = computed<Record<string, string[]>>(() => props.serverError?.fieldErrors ?? {})

const serverReason = computed(() => props.serverError?.serverReason ?? null)

const duplicateCodeMessage = computed(() => {
  if (props.serverError === null) return ''
  const code = draft.code.trim()
  const genericCreateConflict =
    props.mode === 'create' &&
    (props.serverError.status === 409 || props.serverError.code === 'CONFLICT')
  if (
    serverReason.value === POSITION_DUPLICATE_REASON ||
    fieldErrors.value.code !== undefined ||
    fieldErrors.value.code_duplicate !== undefined ||
    genericCreateConflict
  ) {
    return `岗位编码「${code}」已存在，请使用其他编码。`
  }
  return ''
})

const dateRangeError = computed(() => {
  if (draft.validFrom === '' || draft.validTo === '') return ''
  if (draft.validTo <= draft.validFrom) {
    return '失效日期必须晚于生效日期；岗位有效期是半开区间 [生效日, 失效日)。'
  }
  return ''
})

const headcountPreview = computed(() => {
  const limit = numeric(draft.headcountLimit)
  const occupancy = props.position?.occupancy ?? null
  if (limit === null || occupancy === null) return ''
  if (occupancy > limit) {
    return `在岗 ${occupancy} 人，超过核定编制 ${occupancy - limit} 人；保存不会被阻断，但请通过员工异动流程处理人员调整。`
  }
  return `在岗 ${occupancy} 人，核定编制 ${limit} 人，空缺 ${limit - occupancy} 人。`
})

function firstFieldError(field: string): string {
  const messages = fieldErrors.value[field]
  return Array.isArray(messages) && messages.length > 0 ? messages[0] ?? '' : ''
}

function numeric(value: string): number | null {
  if (value.trim() === '') return null
  const parsed = Number(value)
  return Number.isSafeInteger(parsed) && parsed >= 0 ? parsed : null
}

function hydrate(): void {
  localError.value = ''
  localCodeError.value = ''

  if (props.mode === 'edit' && props.position !== null) {
    draft.code = props.position.code
    draft.title = props.position.title
    draft.orgUnitId = props.position.org_unit_id === null ? '' : String(props.position.org_unit_id)
    draft.grade = props.position.grade ?? ''
    draft.headcountLimit =
      props.position.headcount_limit === null ? '' : String(props.position.headcount_limit)
    draft.validFrom = props.position.valid_from
    draft.validTo = props.position.valid_to === '9999-12-31' ? '' : props.position.valid_to
    return
  }

  draft.code = ''
  draft.title = ''
  draft.orgUnitId = ''
  draft.grade = ''
  draft.headcountLimit = ''
  draft.validFrom = ''
  draft.validTo = ''
}

watch(
  () => [props.modelValue, props.mode, props.position?.id] as const,
  ([isOpen]) => {
    if (isOpen) hydrate()
  },
  { immediate: true },
)

function validateCreate(): boolean {
  const code = draft.code.trim()
  if (code === '' || draft.title.trim() === '') {
    localError.value = '请填写岗位编码与岗位名称。'
    return false
  }
  if (props.positions.some((position) => position.code === code)) {
    localCodeError.value = `岗位编码「${code}」已存在，请使用其他编码。`
    return false
  }
  if (/^\d+$/.test(draft.headcountLimit.trim()) === false && draft.headcountLimit.trim() !== '') {
    localError.value = '核定编制必须是非负整数。'
    return false
  }
  if (dateRangeError.value !== '') {
    localError.value = dateRangeError.value
    return false
  }
  return true
}

function submit(): void {
  localError.value = ''
  localCodeError.value = ''

  if (props.mode === 'create') {
    if (!validateCreate()) return
    emit('save', {
      org_unit_id: draft.orgUnitId === '' ? null : Number(draft.orgUnitId),
      code: draft.code.trim(),
      title: draft.title.trim(),
      grade: draft.grade === '' ? null : draft.grade,
      headcount_limit: numeric(draft.headcountLimit),
      valid_from: draft.validFrom === '' ? null : draft.validFrom,
      valid_to: draft.validTo === '' ? null : draft.validTo,
    } satisfies PositionCreateInput)
    return
  }

  if (draft.title.trim() === '') {
    localError.value = '请填写岗位名称。'
    return
  }
  if (/^\d+$/.test(draft.headcountLimit.trim()) === false && draft.headcountLimit.trim() !== '') {
    localError.value = '核定编制必须是非负整数。'
    return
  }
  emit('save', {
    title: draft.title.trim(),
    grade: draft.grade === '' ? null : draft.grade,
    headcount_limit: numeric(draft.headcountLimit),
    status: props.position?.status === 'DISABLED' ? 'DISABLED' : 'ACTIVE',
  } satisfies PositionUpdateInput)
}
</script>

<template>
  <el-drawer
    v-model="open"
    :title="title"
    size="min(620px, 96vw)"
    :close-on-click-modal="false"
    data-testid="position-form-drawer"
  >
    <form class="position-form" @submit.prevent="submit">
      <div class="position-form__grid">
        <label class="position-field">
          <span>岗位编码 *</span>
          <input
            v-model="draft.code"
            data-testid="position-code"
            maxlength="64"
            :disabled="mode === 'edit'"
            autocomplete="off"
          />
          <small v-if="localCodeError !== ''" class="position-field__error" data-testid="field-error-code">
            {{ localCodeError }}
          </small>
          <small
            v-else-if="duplicateCodeMessage !== ''"
            class="position-field__error"
            data-testid="field-error-code"
          >
            {{ duplicateCodeMessage }}
          </small>
          <small
            v-else-if="firstFieldError('code') !== ''"
            class="position-field__error"
            data-testid="field-error-code"
          >
            {{ firstFieldError('code') }}
          </small>
        </label>

        <label class="position-field">
          <span>岗位名称 *</span>
          <input v-model="draft.title" data-testid="position-title" maxlength="160" autocomplete="off" />
          <small
            v-if="firstFieldError('title') !== ''"
            class="position-field__error"
            data-testid="field-error-title"
          >
            {{ firstFieldError('title') }}
          </small>
        </label>

        <label class="position-field">
          <span>部门</span>
          <select
            v-model="draft.orgUnitId"
            data-testid="position-org-unit"
            :disabled="mode === 'edit'"
          >
            <option value="">未关联组织</option>
            <option v-for="unit in orgUnits" :key="unit.id" :value="String(unit.id)">
              {{ unit.name }}（{{ unit.code }}）
            </option>
          </select>
          <small
            v-if="firstFieldError('org_unit_id') !== ''"
            class="position-field__error"
            data-testid="field-error-org-unit"
          >
            {{ firstFieldError('org_unit_id') }}
          </small>
        </label>

        <label class="position-field">
          <span>职级</span>
          <select v-model="draft.grade" data-testid="position-grade">
            <option value="">未设置</option>
            <option v-for="grade in grades" :key="grade.id" :value="grade.code">
              {{ grade.name }}（{{ grade.code }}）
            </option>
            <option v-if="draft.grade !== '' && !grades.some((grade) => grade.code === draft.grade)" :value="draft.grade">
              {{ draft.grade }}
            </option>
          </select>
          <small
            v-if="firstFieldError('grade') !== ''"
            class="position-field__error"
            data-testid="field-error-grade"
          >
            {{ firstFieldError('grade') }}
          </small>
        </label>

        <label class="position-field">
          <span>核定编制</span>
          <input
            v-model="draft.headcountLimit"
            data-testid="position-headcount-limit"
            inputmode="numeric"
            autocomplete="off"
          />
          <small
            v-if="firstFieldError('headcount_limit') !== ''"
            class="position-field__error"
            data-testid="field-error-headcount-limit"
          >
            {{ firstFieldError('headcount_limit') }}
          </small>
        </label>

        <label class="position-field">
          <span>生效日期</span>
          <input
            v-model="draft.validFrom"
            type="date"
            data-testid="position-valid-from"
            :disabled="mode === 'edit'"
          />
          <small
            v-if="firstFieldError('valid_from') !== ''"
            class="position-field__error"
            data-testid="field-error-valid-from"
          >
            {{ firstFieldError('valid_from') }}
          </small>
        </label>

        <label class="position-field">
          <span>失效日期</span>
          <input
            v-model="draft.validTo"
            type="date"
            data-testid="position-valid-to"
            :disabled="mode === 'edit'"
          />
          <small class="position-field__hint">失效日当天不再生效；留空表示长期有效。</small>
          <small
            v-if="firstFieldError('valid_to') !== ''"
            class="position-field__error"
            data-testid="field-error-valid-to"
          >
            {{ firstFieldError('valid_to') }}
          </small>
        </label>
      </div>

      <p
        v-if="mode === 'edit'"
        class="position-form__note"
        data-testid="position-edit-readonly-note"
      >
        {{ POSITION_EDIT_READONLY_NOTE }}
      </p>

      <p
        v-if="dateRangeError !== ''"
        class="position-form__error"
        role="alert"
        data-testid="position-date-range-error"
      >
        {{ dateRangeError }}
      </p>

      <p v-if="headcountPreview !== ''" class="position-form__note" data-testid="position-form-variance">
        {{ headcountPreview }}
      </p>

      <p
        v-if="serverError !== null"
        class="position-form__error"
        role="alert"
        data-testid="position-form-error"
      >
        <template v-if="duplicateCodeMessage !== ''">{{ duplicateCodeMessage }}</template>
        <template v-else>
          提交内容未通过校验：{{ serverError.message }}
          <span v-if="serverError.requestId">（请求编号 {{ serverError.requestId }}）</span>
        </template>
      </p>
      <p
        v-else-if="localError !== ''"
        class="position-form__error"
        role="alert"
        data-testid="position-form-error"
      >
        {{ localError }}
      </p>

      <div class="position-form__actions">
        <button
          type="button"
          class="position-button position-button--secondary"
          @click="open = false"
        >
          取消
        </button>
        <button
          type="button"
          class="position-button"
          data-testid="position-save"
          :disabled="saving"
          @click="submit"
        >
          {{ saving ? '保存中…' : '保存' }}
        </button>
      </div>
    </form>
  </el-drawer>
</template>

<style scoped>
.position-form {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.position-form__grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px 12px;
}

.position-field {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
  color: var(--color-ink-muted);
  font-size: 11px;
}

.position-field > span {
  font-weight: 600;
}

.position-field input,
.position-field select {
  width: 100%;
  padding: 7px 9px;
  color: var(--color-ink);
  font-size: 12px;
  background: #fff;
  border: 1px solid var(--color-line-strong);
  border-radius: var(--radius-sm);
}

.position-field input:disabled,
.position-field select:disabled {
  color: var(--color-ink-muted);
  background: var(--color-canvas);
}

.position-field input:focus,
.position-field select:focus {
  border-color: var(--color-brand);
  outline: 2px solid var(--color-brand-soft);
  outline-offset: 0;
}

.position-field__error {
  color: var(--color-danger);
  font-size: 11px;
}

.position-field__hint {
  color: var(--color-ink-muted);
  font-size: 10px;
}

.position-form__note {
  padding: 8px 10px;
  color: var(--color-ink-secondary);
  font-size: 11px;
  background: var(--color-canvas);
  border-radius: var(--radius-sm);
}

.position-form__error {
  padding: 8px 10px;
  color: var(--color-danger);
  font-size: 12px;
  background: var(--color-danger-soft);
  border-radius: var(--radius-sm);
}

.position-form__actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}

.position-button {
  padding: 7px 14px;
  color: #fff;
  font-size: 12px;
  font-weight: 600;
  background: var(--color-brand);
  border: 1px solid var(--color-brand);
  border-radius: var(--radius-sm);
  cursor: pointer;
}

.position-button--secondary {
  color: var(--color-brand);
  background: #fff;
}

.position-button:disabled {
  cursor: not-allowed;
  opacity: 0.55;
}
</style>
