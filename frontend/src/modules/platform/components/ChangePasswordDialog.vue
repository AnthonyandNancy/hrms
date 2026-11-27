<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { ApiError, errorCopy } from '@/api/errors'
import { changePassword } from '../api/account'

const props = withDefaults(
  defineProps<{
    modelValue: boolean
    canManage?: boolean
  }>(),
  { canManage: true },
)

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  changed: [{ sessionsRevoked: boolean }]
  reauth: []
}>()

const open = computed({
  get: () => props.modelValue,
  set: (value: boolean) => emit('update:modelValue', value),
})

const MIN_LENGTH = 12

const form = reactive({
  currentPassword: '',
  newPassword: '',
  confirmation: '',
})
const fieldErrors = reactive<Record<string, string>>({})
const formError = ref('')
const submitting = ref(false)
const step = ref<'form' | 'done'>('form')

const canSubmit = computed(() => !submitting.value && props.canManage)

function reset(): void {
  form.currentPassword = ''
  form.newPassword = ''
  form.confirmation = ''
  formError.value = ''
  step.value = 'form'
  clearFieldErrors()
}

function clearFieldErrors(): void {
  for (const key of Object.keys(fieldErrors)) delete fieldErrors[key]
}

function validate(): boolean {
  clearFieldErrors()

  if (form.currentPassword.trim() === '') {
    fieldErrors.currentPassword = '请输入当前密码。'
  }
  if (form.newPassword.length < MIN_LENGTH) {
    fieldErrors.newPassword = `新密码至少需要 ${MIN_LENGTH} 位。`
  } else if (form.newPassword === form.currentPassword) {
    fieldErrors.newPassword = '新密码不能与当前密码相同。'
  }
  if (form.confirmation === '') {
    fieldErrors.confirmation = '请再次输入新密码。'
  } else if (form.confirmation !== form.newPassword) {
    fieldErrors.confirmation = '两次输入的新密码不一致。'
  }

  return Object.keys(fieldErrors).length === 0
}

function mapServerErrors(error: unknown): boolean {
  if (!(error instanceof ApiError) || error.code !== 'VALIDATION_FAILED') return false

  const fields = error.fieldErrors
  const mapping: Record<string, string> = {
    current_password: '当前密码不正确，请重新输入。',
    new_password: '新密码不符合要求：至少 12 位，且不能与当前密码相同。',
    new_password_confirmation: '两次输入的新密码不一致。',
    password: '新密码不符合要求：至少 12 位，且不能与当前密码相同。',
  }

  let mapped = false
  for (const [field, message] of Object.entries(mapping)) {
    if (Array.isArray(fields[field]) && fields[field].some((entry) => entry !== '')) {
      fieldErrors[field] = message
      mapped = true
    }
  }

  if (error.serverReason === 'BAD_CURRENT_PASSWORD') {
    fieldErrors.currentPassword = '当前密码不正确，请重新输入。'
    mapped = true
  }

  return mapped
}

async function submit(): Promise<void> {
  formError.value = ''
  if (!props.canManage) {
    formError.value = '你没有修改密码的权限。'
    return
  }
  if (!validate()) return

  submitting.value = true
  try {
    const result = await changePassword({
      currentPassword: form.currentPassword,
      newPassword: form.newPassword,
      newPasswordConfirmation: form.confirmation,
    })

    form.currentPassword = ''
    form.newPassword = ''
    form.confirmation = ''
    step.value = 'done'
    emit('changed', { sessionsRevoked: result.sessionsRevoked })
  } catch (error) {
    if (!mapServerErrors(error)) {
      formError.value = errorCopy(error)
    }
  } finally {
    submitting.value = false
  }
}

function close(): void {
  if (step.value === 'done') return
  open.value = false
}

watch(open, (value) => {
  if (value) reset()
})
</script>

<template>
  <el-dialog
    :model-value="open"
    title="修改密码"
    width="520px"
    :close-on-click-modal="false"
    :close-on-press-escape="step !== 'done'"
    :show-close="step !== 'done'"
    data-testid="change-password-dialog"
    @update:model-value="(value: boolean) => { if (!value) close() }"
  >
    <div v-if="step === 'done'" class="password-dialog__done" role="status" data-testid="password-change-done">
      <el-alert
        title="密码已修改"
        type="success"
        :closable="false"
        show-icon
      />
      <p>
        你在这台设备之外的所有会话已全部失效，本设备的登录也已结束。请使用新密码重新登录。
      </p>
      <p class="password-dialog__muted">
        其他浏览器或手机上的会话无法继续使用旧登录状态；未提交的输入不会被保存。
      </p>
    </div>

    <form v-else class="password-dialog__form" novalidate @submit.prevent="submit">
      <p class="password-dialog__copy">
        修改密码后，所有已登录设备都会退出。请使用至少 {{ MIN_LENGTH }} 位、且与当前密码不同的新密码。
      </p>

      <el-alert
        v-if="formError"
        :title="formError"
        type="error"
        :closable="false"
        show-icon
        data-testid="password-form-error"
      />

      <label class="field-label" for="password-current">当前密码</label>
      <el-input
        id="password-current"
        v-model="form.currentPassword"
        type="password"
        show-password
        autocomplete="current-password"
        data-testid="current-password"
      />
      <p v-if="fieldErrors.currentPassword" class="field-error" data-testid="current-password-error">
        {{ fieldErrors.currentPassword }}
      </p>

      <label class="field-label" for="password-new">新密码</label>
      <el-input
        id="password-new"
        v-model="form.newPassword"
        type="password"
        show-password
        autocomplete="new-password"
        data-testid="new-password"
      />
      <p v-if="fieldErrors.newPassword" class="field-error" data-testid="new-password-error">
        {{ fieldErrors.newPassword }}
      </p>

      <label class="field-label" for="password-confirmation">确认新密码</label>
      <el-input
        id="password-confirmation"
        v-model="form.confirmation"
        type="password"
        show-password
        autocomplete="new-password"
        data-testid="password-confirmation"
      />
      <p v-if="fieldErrors.confirmation" class="field-error" data-testid="password-confirmation-error">
        {{ fieldErrors.confirmation }}
      </p>

      <div class="password-dialog__actions">
        <el-button :disabled="submitting" @click="close">取消</el-button>
        <el-button type="primary" native-type="submit" :loading="submitting" :disabled="!canSubmit" data-testid="password-submit">
          保存新密码
        </el-button>
      </div>
    </form>

    <template #footer>
      <el-button v-if="step === 'done'" type="primary" data-testid="password-reauth" @click="emit('reauth')">
        重新登录
      </el-button>
      <el-button v-else :disabled="submitting" @click="close">取消</el-button>
    </template>
  </el-dialog>
</template>

<style scoped>
.password-dialog__form {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.password-dialog__copy,
.password-dialog__muted {
  margin: 0 0 8px;
  color: var(--color-ink-secondary);
  font-size: 13px;
  line-height: 1.7;
}

.password-dialog__muted {
  color: var(--muted);
}

.password-dialog__actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 12px;
}

.password-dialog__done {
  display: flex;
  flex-direction: column;
  gap: 12px;
  color: var(--color-ink-secondary);
  line-height: 1.7;
}
</style>
