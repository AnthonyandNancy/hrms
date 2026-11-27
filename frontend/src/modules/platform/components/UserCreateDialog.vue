<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { resourcePost } from '@/api/resourceClient'
import { ApiError, errorCopy } from '@/api/errors'
import FormDialog from '@/shared/ui/FormDialog.vue'

export type EmploymentOption = {
  employment_id: number
  employee_no: string
  person_name: string | null
}

const props = withDefaults(
  defineProps<{
    modelValue: boolean
    employmentOptions?: EmploymentOption[]
    employmentLoading?: boolean
  }>(),
  {
    employmentOptions: () => [],
    employmentLoading: false,
  },
)

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  created: []
}>()

const PASSWORD_MIN_LENGTH = 12
const fieldErrors = ref<Record<string, string>>({})
const generalError = ref('')
const submitting = ref(false)

const form = reactive({
  username: '',
  email: '',
  displayName: '',
  password: '',
  employmentId: '',
})

const FIELD_LABELS: Record<string, string> = {
  username: '用户名',
  email: '邮箱',
  display_name: '显示名称',
  password: '初始密码',
  employment_id: '任职绑定',
}

const employmentChoices = computed(() =>
  props.employmentOptions.map((option) => ({
    value: String(option.employment_id),
    label: `${option.employee_no}${option.person_name ? ` · ${option.person_name}` : ''}`,
  })),
)

function reset(): void {
  form.username = ''
  form.email = ''
  form.displayName = ''
  form.password = ''
  form.employmentId = ''
  fieldErrors.value = {}
  generalError.value = ''
  submitting.value = false
}

watch(
  () => props.modelValue,
  (open) => {
    if (open) reset()
  },
)

function close(): void {
  if (submitting.value) return
  emit('update:modelValue', false)
}

function translateServerError(field: string, messages: string[]): string {
  const raw = messages.join(' ').toLowerCase()
  const label = FIELD_LABELS[field] ?? '该字段'

  if (field === 'username' && (raw.includes('taken') || raw.includes('already'))) {
    return '该用户名已被使用，请换一个。'
  }
  if (field === 'username' && (raw.includes('format') || raw.includes('regex'))) {
    return '用户名只能包含字母、数字、点、下划线和连字符。'
  }
  if (field === 'password' && (raw.includes('12') || raw.includes('least') || raw.includes('min'))) {
    return '初始密码至少 12 位。'
  }
  if (field === 'password' && raw.includes('max')) {
    return '初始密码过长，请缩短后重试。'
  }
  if (field === 'email') {
    return '邮箱格式不正确。'
  }
  if (field === 'display_name') {
    return '显示名称不能为空且不能超过 120 个字符。'
  }
  if (field === 'employment_id') {
    return '所选员工任职不存在或已失效，请重新选择。'
  }

  return `${label}不符合要求，请检查后重试。`
}

function validate(): boolean {
  const errors: Record<string, string> = {}
  const username = form.username.trim()
  const email = form.email.trim()
  const displayName = form.displayName.trim()

  if (username === '') {
    errors.username = '请输入用户名。'
  } else if (!/^[a-zA-Z0-9._-]+$/.test(username)) {
    errors.username = '用户名只能包含字母、数字、点、下划线和连字符。'
  } else if (username.length > 80) {
    errors.username = '用户名不能超过 80 个字符。'
  }

  if (email !== '' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    errors.email = '邮箱格式不正确。'
  }

  if (displayName === '') {
    errors.display_name = '请输入显示名称。'
  } else if (displayName.length > 120) {
    errors.display_name = '显示名称不能超过 120 个字符。'
  }

  if (form.password.length < PASSWORD_MIN_LENGTH) {
    errors.password = `初始密码至少 ${PASSWORD_MIN_LENGTH} 位。`
  } else if (form.password.length > 1024) {
    errors.password = '初始密码过长。'
  }

  if (form.employmentId.trim() !== '') {
    const parsed = Number(form.employmentId)
    if (!Number.isInteger(parsed) || parsed <= 0) {
      errors.employment_id = '任职编号必须是正整数。'
    }
  }

  fieldErrors.value = errors
  return Object.keys(errors).length === 0
}

async function submit(): Promise<void> {
  if (submitting.value) return
  generalError.value = ''
  if (!validate()) return

  const username = form.username.trim()
  const payload: Record<string, unknown> = {
    username,
    email: form.email.trim() === '' ? null : form.email.trim(),
    display_name: form.displayName.trim(),
    password: form.password,
  }

  if (form.employmentId.trim() !== '') {
    payload.employment_id = Number(form.employmentId)
  }

  submitting.value = true
  try {
    await resourcePost<unknown>('/v1/users', payload, {
      idempotencyKey: `user-create-${username}-${Date.now()}`,
    })
    emit('created')
    emit('update:modelValue', false)
  } catch (reason) {
    if (reason instanceof ApiError && Object.keys(reason.fieldErrors).length > 0) {
      const mapped: Record<string, string> = {}
      for (const [field, messages] of Object.entries(reason.fieldErrors)) {
        mapped[field] = translateServerError(field, messages)
      }
      fieldErrors.value = mapped
      generalError.value = '提交内容未通过校验，请检查标记的字段。'
    } else {
      fieldErrors.value = {}
      generalError.value = errorCopy(reason)
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <FormDialog
    :model-value="props.modelValue"
    title="新增用户"
    :confirm-loading="submitting"
    @update:model-value="close"
    @confirm="submit"
  >
    <div class="user-create-form" data-testid="user-create-dialog">
      <p class="muted small">创建登录账号后，再为其分配角色与员工任职。初始密码只展示一次，请通过安全渠道交付本人。</p>

      <p v-if="generalError" class="inline-error" role="alert" data-testid="user-create-error">
        {{ generalError }}
      </p>

      <div class="platform-form-grid">
        <label class="platform-field">
          <span>用户名 *</span>
          <input
            v-model="form.username"
            data-testid="user-username"
            autocomplete="off"
            maxlength="80"
            placeholder="例如 demo.hr"
            :aria-invalid="fieldErrors.username !== undefined"
          />
          <small v-if="fieldErrors.username" class="field-error" data-testid="field-error-username">
            {{ fieldErrors.username }}
          </small>
        </label>

        <label class="platform-field">
          <span>邮箱</span>
          <input
            v-model="form.email"
            data-testid="user-email"
            type="email"
            autocomplete="off"
            placeholder="可选，用于登录通知"
            :aria-invalid="fieldErrors.email !== undefined"
          />
          <small v-if="fieldErrors.email" class="field-error" data-testid="field-error-email">
            {{ fieldErrors.email }}
          </small>
        </label>
      </div>

      <label class="platform-field">
        <span>显示名称 *</span>
        <input
          v-model="form.displayName"
          data-testid="user-display-name-input"
          autocomplete="off"
          maxlength="120"
          placeholder="例如 王小明"
          :aria-invalid="fieldErrors.display_name !== undefined"
        />
        <small v-if="fieldErrors.display_name" class="field-error" data-testid="field-error-display-name">
          {{ fieldErrors.display_name }}
        </small>
      </label>

      <label class="platform-field">
        <span>初始密码 *</span>
        <input
          v-model="form.password"
          data-testid="user-password"
          type="password"
          autocomplete="new-password"
          maxlength="1024"
          :aria-invalid="fieldErrors.password !== undefined"
        />
        <small class="muted small">至少 {{ PASSWORD_MIN_LENGTH }} 位，建议同时包含大小写字母、数字与符号。</small>
        <small v-if="fieldErrors.password" class="field-error" data-testid="field-error-password">
          {{ fieldErrors.password }}
        </small>
      </label>

      <label class="platform-field">
        <span>员工任职绑定</span>
        <input
          v-model="form.employmentId"
          data-testid="user-employment-input"
          list="user-employment-options"
          inputmode="numeric"
          autocomplete="off"
          placeholder="可选，输入或选择任职编号"
          :aria-invalid="fieldErrors.employment_id !== undefined"
        />
        <datalist id="user-employment-options">
          <option v-for="choice in employmentChoices" :key="choice.value" :value="choice.value">
            {{ choice.label }}
          </option>
        </datalist>
        <small class="muted small">
          {{ props.employmentLoading ? '正在加载可绑定任职…' : '不绑定任职时，该账号仅用于系统管理或数据查看。' }}
        </small>
        <small v-if="fieldErrors.employment_id" class="field-error" data-testid="field-error-employment">
          {{ fieldErrors.employment_id }}
        </small>
      </label>
    </div>
  </FormDialog>
</template>

<style scoped>
.user-create-form {
  display: flex;
  flex-direction: column;
  gap: 14px;
}
.field-error {
  color: var(--el-color-danger);
  font-size: 12px;
}
</style>
