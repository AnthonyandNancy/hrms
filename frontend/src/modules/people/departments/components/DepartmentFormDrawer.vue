<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { ApiError } from '@/api/errors'
import {
  DEPARTMENT_TYPES,
  MISSING_MANAGER_ENDPOINT,
  type Department,
  type DepartmentCreateInput,
  type DepartmentType,
  type DepartmentUpdateInput,
} from '../api'

const props = defineProps<{
  modelValue: boolean
  mode: 'create' | 'edit'
  department: Department | null
  departments: readonly Department[]
  parentOptions: readonly Department[]
  saving: boolean
  serverError: ApiError | null
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  save: [input: DepartmentCreateInput]
}>()

defineOptions({ name: 'DepartmentFormDrawer' })

type Draft = {
  name: string
  code: string
  parentId: string
  type: DepartmentType
  validFrom: string
  validTo: string
  managerEmploymentId: string
}

const draft = reactive<Draft>({
  name: '',
  code: '',
  parentId: '',
  type: 'DEPARTMENT',
  validFrom: '',
  validTo: '',
  managerEmploymentId: '',
})

const localError = ref('')

const open = computed({
  get: () => props.modelValue,
  set: (value: boolean) => emit('update:modelValue', value),
})

const title = computed(() => (props.mode === 'create' ? '新增部门' : '编辑部门'))

const dateRangeError = computed(() => {
  if (draft.validFrom === '' || draft.validTo === '') return ''
  if (draft.validTo <= draft.validFrom) {
    return '失效日期必须晚于生效日期；部门有效期是半开区间 [生效日, 失效日)。'
  }
  return ''
})

const requiredError = computed(() => {
  if (draft.name.trim() === '' || draft.code.trim() === '') return '请填写部门名称与部门编码。'
  return ''
})

const fieldErrors = computed<Record<string, string[]>>(() => props.serverError?.fieldErrors ?? {})

function firstFieldError(field: string): string {
  return fieldErrors.value[field]?.[0] ?? ''
}

function hydrate(): void {
  localError.value = ''
  if (props.mode === 'edit' && props.department !== null) {
    draft.name = props.department.name
    draft.code = props.department.code
    draft.parentId = props.department.parent_id === null ? '' : String(props.department.parent_id)
    draft.type = props.department.type
    draft.validFrom = props.department.valid_from
    draft.validTo = props.department.valid_to === '9999-12-31' ? '' : props.department.valid_to
    draft.managerEmploymentId =
      props.department.manager_employment_id == null
        ? ''
        : String(props.department.manager_employment_id)
    return
  }

  draft.name = ''
  draft.code = ''
  draft.parentId = ''
  draft.type = 'DEPARTMENT'
  draft.validFrom = ''
  draft.validTo = ''
  draft.managerEmploymentId = ''
}

watch(
  () => [props.modelValue, props.mode, props.department?.id] as const,
  ([open]) => {
    if (open) hydrate()
  },
  { immediate: true },
)

function submit(): void {
  localError.value = ''
  if (requiredError.value !== '') {
    localError.value = requiredError.value
    return
  }
  if (dateRangeError.value !== '') {
    localError.value = dateRangeError.value
    return
  }

  const input: DepartmentUpdateInput = {
    parent_id: draft.parentId === '' ? null : Number(draft.parentId),
    code: draft.code.trim(),
    name: draft.name.trim(),
    type: draft.type,
    valid_from: draft.validFrom,
    valid_to: draft.validTo === '' ? null : draft.validTo,
    manager_employment_id:
      draft.managerEmploymentId === '' ? null : Number(draft.managerEmploymentId),
  }
  emit('save', input)
}
</script>

<template>
  <el-drawer
    v-model="open"
    :title="title"
    size="min(560px, 96vw)"
    :close-on-click-modal="false"
    data-testid="department-form-drawer"
  >
    <form class="department-form" @submit.prevent="submit">
      <div class="department-form__grid">
        <label class="department-field">
          <span>部门名称 *</span>
          <input
            v-model="draft.name"
            data-testid="department-name"
            maxlength="160"
            autocomplete="off"
          />
          <small v-if="firstFieldError('name')" class="department-field__error" data-testid="field-error-name">
            {{ firstFieldError('name') }}
          </small>
        </label>

        <label class="department-field">
          <span>部门编码 *</span>
          <input
            v-model="draft.code"
            data-testid="department-code"
            maxlength="64"
            autocomplete="off"
          />
          <small v-if="firstFieldError('code')" class="department-field__error" data-testid="field-error-code">
            {{ firstFieldError('code') }}
          </small>
        </label>

        <label class="department-field">
          <span>上级部门</span>
          <select v-model="draft.parentId" data-testid="department-parent">
            <option value="">无（根节点）</option>
            <option v-for="option in parentOptions" :key="option.id" :value="String(option.id)">
              {{ option.name }}（{{ option.code }}）
            </option>
          </select>
          <small v-if="firstFieldError('parent_id')" class="department-field__error" data-testid="field-error-parent">
            {{ firstFieldError('parent_id') }}
          </small>
        </label>

        <label class="department-field">
          <span>组织类型</span>
          <select v-model="draft.type" data-testid="department-type">
            <option v-for="type in DEPARTMENT_TYPES" :key="type.value" :value="type.value">
              {{ type.label }}
            </option>
          </select>
          <small v-if="firstFieldError('type')" class="department-field__error" data-testid="field-error-type">
            {{ firstFieldError('type') }}
          </small>
        </label>

        <label class="department-field">
          <span>生效日期</span>
          <input v-model="draft.validFrom" type="date" data-testid="department-valid-from" />
          <small v-if="firstFieldError('valid_from')" class="department-field__error" data-testid="field-error-valid-from">
            {{ firstFieldError('valid_from') }}
          </small>
        </label>

        <label class="department-field">
          <span>失效日期</span>
          <input v-model="draft.validTo" type="date" data-testid="department-valid-to" />
          <small class="department-field__hint">失效日当天不再生效；留空表示长期有效。</small>
          <small v-if="firstFieldError('valid_to')" class="department-field__error" data-testid="field-error-valid-to">
            {{ firstFieldError('valid_to') }}
          </small>
        </label>

        <label class="department-field department-field--wide">
          <span>负责人</span>
          <input
            v-model="draft.managerEmploymentId"
            data-testid="department-manager"
            inputmode="numeric"
            placeholder="按员工关系编号指定负责人"
          />
          <small class="department-field__hint" data-testid="department-manager-gap">
            {{ MISSING_MANAGER_ENDPOINT }}
          </small>
          <small
            v-if="firstFieldError('manager_employment_id')"
            class="department-field__error"
            data-testid="field-error-manager"
          >
            {{ firstFieldError('manager_employment_id') }}
          </small>
        </label>
      </div>

      <p
        v-if="dateRangeError !== ''"
        class="department-form__error"
        role="alert"
        data-testid="department-date-range-error"
      >
        {{ dateRangeError }}
      </p>

      <p
        v-if="serverError"
        class="department-form__error"
        role="alert"
        data-testid="department-form-error"
      >
        提交内容未通过校验：{{ serverError.message }}
        <span v-if="serverError.requestId">（请求编号 {{ serverError.requestId }}）</span>
      </p>

      <p v-else-if="localError !== ''" class="department-form__error" role="alert" data-testid="department-form-error">
        {{ localError }}
      </p>

      <div class="department-form__actions">
        <button type="button" class="department-button department-button--secondary" @click="open = false">
          取消
        </button>
        <button
          type="button"
          class="department-button"
          :disabled="saving"
          data-testid="department-save"
          @click="submit"
        >
          {{ saving ? '保存中…' : '保存' }}
        </button>
      </div>
    </form>
  </el-drawer>
</template>

<style scoped>
.department-form {
  display: flex;
  flex-direction: column;
  gap: 14px;
}
.department-form__grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}
.department-field {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
  color: var(--color-ink-muted);
  font-size: 11px;
}
.department-field--wide {
  grid-column: span 2;
}
.department-field > span {
  font-weight: 600;
}
.department-field input,
.department-field select {
  width: 100%;
  padding: 7px 9px;
  color: var(--color-ink);
  font-size: 12px;
  background: #fff;
  border: 1px solid var(--color-line-strong);
  border-radius: var(--radius-sm);
}
.department-field__hint {
  color: var(--color-ink-muted);
  font-size: 10px;
}
.department-field__hint--gap {
  color: #8a5a00;
}
.department-field__error {
  color: var(--color-danger);
  font-size: 10px;
}
.department-form__error {
  padding: 8px 10px;
  color: var(--color-danger);
  font-size: 12px;
  background: var(--color-danger-soft);
  border-radius: var(--radius-sm);
}
.department-form__actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}
.department-button {
  padding: 7px 16px;
  color: #fff;
  font-size: 12px;
  font-weight: 600;
  background: var(--color-brand);
  border: 1px solid var(--color-brand);
  border-radius: var(--radius-sm);
  cursor: pointer;
}
.department-button:disabled {
  cursor: not-allowed;
  opacity: 0.55;
}
.department-button--secondary {
  color: var(--color-brand);
  background: #fff;
}
</style>
