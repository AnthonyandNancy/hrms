<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import { getApi } from '@/api'
import type { MfaEnrollment } from '@/api/contracts'
import { errorCopy } from '@/api/errors'

const props = defineProps<{
  modelValue: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  enabled: []
}>()

const open = computed({
  get: () => props.modelValue,
  set: (value: boolean) => emit('update:modelValue', value),
})

const step = ref<'prepare' | 'verify' | 'recovery'>('prepare')
const enrollment = ref<MfaEnrollment | null>(null)
const loading = ref(false)
const code = reactive({ value: '' })
const error = ref('')

async function begin(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    enrollment.value = await getApi().auth.enrollMfa()
    step.value = 'verify'
  } catch (reason) {
    error.value = errorCopy(reason)
  } finally {
    loading.value = false
  }
}

async function confirm(): Promise<void> {
  if (!/^\d{6}$/.test(code.value)) {
    error.value = '请输入认证器显示的 6 位动态验证码'
    return
  }
  loading.value = true
  error.value = ''
  try {
    await getApi().auth.confirmMfa(code.value)
    step.value = 'recovery'
    ElMessage.success('动态口令验证通过')
  } catch (reason) {
    error.value = errorCopy(reason)
  } finally {
    loading.value = false
  }
}

function finish(): void {
  open.value = false
  emit('enabled')
  step.value = 'prepare'
  code.value = ''
}

watch(open, (value) => {
  if (value && step.value === 'prepare') void begin()
  if (!value) {
    error.value = ''
    code.value = ''
    if (step.value === 'recovery') step.value = 'prepare'
  }
})
</script>

<template>
  <el-dialog v-model="open" title="绑定动态口令（TOTP）" width="480px" :close-on-click-modal="false">
    <div class="mfa-enroll">
      <el-alert
        v-if="error"
        :title="error"
        type="error"
        show-icon
        :closable="false"
      />

      <template v-if="step === 'prepare'">
        <el-skeleton :rows="3" animated />
      </template>

      <template v-else-if="step === 'verify' && enrollment">
        <p class="mfa-enroll__copy">
          在认证器应用（Microsoft Authenticator、Google Authenticator 等）中添加以下密钥，然后输入当前 6 位动态验证码。
        </p>
        <div class="mfa-secret" aria-label="动态口令密钥">
          <span class="mono">{{ enrollment.secretPreview }}</span>
        </div>
        <label class="mfa-enroll__label" for="mfa-enroll-code">动态验证码</label>
        <el-input
          id="mfa-enroll-code"
          v-model="code.value"
          maxlength="6"
          inputmode="numeric"
          placeholder="000000"
          autocomplete="one-time-code"
        />
        <p class="mfa-enroll__hint">
          请在此输入认证器当前显示的 6 位数字。系统会校验 TOTP 时间窗口后完成绑定。
        </p>
      </template>

      <template v-else-if="step === 'recovery' && enrollment">
        <p class="mfa-enroll__copy">
          请立即保存以下恢复码。每个恢复码只能使用一次，存放在离线安全位置。
        </p>
        <ul class="recovery-codes">
          <li v-for="recoveryCode in enrollment.recoveryCodes" :key="recoveryCode" class="mono">
            {{ recoveryCode }}
          </li>
        </ul>
        <el-alert
          title="关闭此窗口后将无法再次查看这些恢复码。"
          type="warning"
          :closable="false"
          show-icon
        />
      </template>
    </div>

    <template #footer>
      <el-button @click="open = false">取消</el-button>
      <el-button v-if="step === 'verify'" type="primary" :loading="loading" @click="confirm">
        验证并启用
      </el-button>
      <el-button v-else-if="step === 'recovery'" type="primary" @click="finish">
        我已保存，完成
      </el-button>
    </template>
  </el-dialog>
</template>

<style scoped>
.mfa-enroll {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.mfa-enroll__copy {
  color: var(--color-ink-secondary);
  font-size: 12.5px;
  line-height: 1.7;
}

.mfa-secret {
  padding: 12px;
  color: var(--color-brand-deep);
  font-size: 16px;
  letter-spacing: 0.12em;
  text-align: center;
  background: var(--color-brand-soft);
  border: 1px dashed #9dbdb8;
  border-radius: 6px;
}

.mfa-enroll__label {
  margin-bottom: -5px;
  font-size: 12px;
  font-weight: 600;
}

.mfa-enroll__hint {
  color: var(--color-ink-muted);
  font-size: 11px;
}

.recovery-codes {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 6px;
  margin: 0;
  padding: 12px;
  list-style: none;
  background: var(--color-canvas);
  border-radius: 6px;
}

.recovery-codes li {
  padding: 4px 6px;
  font-size: 12px;
}
</style>
