<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { resourceGet, resourcePost } from '@/api/resourceClient'
import { errorCopy } from '@/api/errors'
import { usePageError } from '@/composables/usePageError'
import { useSessionStore } from '@/stores/session'
import LoadingState from '@/components/LoadingState.vue'
import ErrorState from '@/components/ErrorState.vue'
import EmptyState from '@/components/EmptyState.vue'

type Me = {
  id: number
  username: string
  email: string | null
  employment_id: number | null
  has_mfa: boolean
  mfa_verified: boolean
  auth_version: number
}

const route = useRoute()
const router = useRouter()
const session = useSessionStore()
const pageError = usePageError()

function nextIdempotencyKey(prefix: string): string {
  const random = globalThis.crypto?.randomUUID?.() ?? Math.random().toString(36).slice(2)
  return `account-${prefix}-${random}`
}

const mode = computed<'mfa' | 'sessions'>(() => (route.path.endsWith('/sessions') ? 'sessions' : 'mfa'))
const loading = ref(true)
const me = ref<Me | null>(null)
const enrolling = ref(false)
const enrollment = ref<{ secret: string; otpauth_uri: string; recovery_codes: string[] } | null>(null)
const confirmationCode = ref('')
const submitting = ref(false)
const formError = ref('')

async function load(): Promise<void> {
  loading.value = true
  pageError.clear()
  try {
    me.value = await resourceGet<Me>('/v1/me')
  } catch (reason) {
    pageError.capture(reason)
  } finally {
    loading.value = false
  }
}

async function startEnrollment(): Promise<void> {
  enrolling.value = true
  formError.value = ''
  try {
    enrollment.value = await resourcePost('/v1/auth/mfa/totp/enroll', {}, { idempotencyKey: nextIdempotencyKey('mfa-enroll') })
    ElMessage.success('已生成 TOTP 密钥，请在认证器中完成绑定。')
  } catch (reason) {
    formError.value = errorCopy(reason)
  } finally {
    enrolling.value = false
  }
}

async function confirmEnrollment(): Promise<void> {
  if (confirmationCode.value.trim().length !== 6) {
    formError.value = '请输入 6 位 TOTP 验证码。'
    return
  }
  submitting.value = true
  formError.value = ''
  try {
    await resourcePost('/v1/auth/mfa/totp/confirm', { code: confirmationCode.value.trim() }, { idempotencyKey: nextIdempotencyKey('mfa-confirm') })
    ElMessage.success('MFA 已启用。')
    enrollment.value = null
    confirmationCode.value = ''
    await load()
  } catch (reason) {
    formError.value = errorCopy(reason)
  } finally {
    submitting.value = false
  }
}

async function stepUp(): Promise<void> {
  if (confirmationCode.value.trim().length !== 6) {
    formError.value = '请输入 6 位 TOTP 验证码。'
    return
  }
  submitting.value = true
  formError.value = ''
  try {
    await resourcePost('/v1/auth/mfa/step-up', { code: confirmationCode.value.trim() }, { idempotencyKey: nextIdempotencyKey('mfa-stepup') })
    ElMessage.success('本次会话已完成二次验证。')
    confirmationCode.value = ''
    await load()
  } catch (reason) {
    formError.value = errorCopy(reason)
  } finally {
    submitting.value = false
  }
}

async function revokeAll(): Promise<void> {
  submitting.value = true
  formError.value = ''
  try {
    await resourcePost('/v1/auth/logout-all', {}, { idempotencyKey: nextIdempotencyKey('logout-all') })
    ElMessage.success('所有会话已注销，请重新登录。')
    session.expire('所有会话已注销，请重新登录。')
    await router.push('/login')
  } catch (reason) {
    formError.value = errorCopy(reason)
  } finally {
    submitting.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="page account-security">
    <header class="page-header">
      <div class="page-header__copy">
        <p class="page-header__breadcrumb">账号安全</p>
        <h1 class="page-header__title">{{ mode === 'mfa' ? '多因素认证' : '登录会话' }}</h1>
        <p class="page-header__description">
          {{ mode === 'mfa'
            ? '绑定 TOTP 认证器，为高风险操作提供第二因素；密钥仅在绑定过程中显示一次。'
            : '查看当前会话的过期时间，并可一次性注销全部设备上的会话。' }}
        </p>
      </div>
    </header>

    <ErrorState
      v-if="pageError.message.value"
      description="账号安全信息暂时无法加载。"
      :code="pageError.code.value"
      :request-id="pageError.requestId.value"
      @retry="load"
    />
    <LoadingState v-else-if="loading" :rows="4" label="正在加载账号安全信息" />

    <template v-else-if="me">
      <section v-if="mode === 'mfa'" class="panel">
        <div class="panel__header">
          <h2 class="panel__title">TOTP 认证器</h2>
          <span class="plain-chip" :class="{ 'plain-chip--success': me.has_mfa }">
            {{ me.has_mfa ? '已启用' : '未启用' }}
          </span>
        </div>

        <p class="small muted">
          账号 <strong>{{ me.username }}</strong> · 当前会话{{ me.mfa_verified ? '已完成' : '尚未完成' }}二次验证。
        </p>

        <div v-if="!me.has_mfa" class="account-security__actions">
          <el-button type="primary" :loading="enrolling" @click="startEnrollment">生成 TOTP 密钥</el-button>
        </div>

        <div v-if="enrollment" class="account-security__enroll">
          <el-alert
            title="请立即将密钥加入认证器；关闭后不会再次显示。"
            type="warning"
            :closable="false"
            show-icon
          />
          <dl class="account-security__facts">
            <div><dt>密钥</dt><dd class="mono">{{ enrollment.secret }}</dd></div>
            <div><dt>otpauth URI</dt><dd class="mono break">{{ enrollment.otpauth_uri }}</dd></div>
          </dl>
          <el-input v-model="confirmationCode" maxlength="6" placeholder="输入 6 位验证码" aria-label="TOTP 验证码" />
          <el-button type="primary" :loading="submitting" @click="confirmEnrollment">确认绑定</el-button>
        </div>

        <div v-else-if="me.has_mfa && !me.mfa_verified" class="account-security__enroll">
          <p class="small">当前会话尚未完成二次验证，敏感操作需要 step-up。</p>
          <el-input v-model="confirmationCode" maxlength="6" placeholder="输入 6 位验证码" aria-label="TOTP 验证码" />
          <el-button type="primary" :loading="submitting" @click="stepUp">完成二次验证</el-button>
        </div>
      </section>

      <section v-else class="panel">
        <div class="panel__header">
          <h2 class="panel__title">当前会话</h2>
          <span class="plain-chip">服务端 HttpOnly Cookie</span>
        </div>
        <EmptyState
          v-if="!session.sessionExpiresAt"
          title="会话信息不可用"
          description="服务端未返回会话过期时间，请重新登录后查看。"
        />
        <template v-else>
          <dl class="account-security__facts">
            <div><dt>账号</dt><dd>{{ me.username }}</dd></div>
            <div><dt>授权版本</dt><dd>v{{ me.auth_version }}</dd></div>
            <div><dt>空闲过期</dt><dd>{{ session.sessionExpiresAt }}</dd></div>
            <div><dt>MFA</dt><dd>{{ me.has_mfa ? '已绑定' : '未绑定' }}</dd></div>
          </dl>
          <el-alert
            title="注销全部会话会使当前页面立即失效，需要重新登录。"
            type="warning"
            :closable="false"
            show-icon
          />
          <el-button type="danger" :loading="submitting" @click="revokeAll">注销全部会话</el-button>
        </template>
      </section>

      <p v-if="formError" class="field-error" role="alert">{{ formError }}</p>
    </template>
  </div>
</template>

<style scoped>
.account-security__actions {
  display: flex;
  gap: 10px;
  margin-top: 12px;
}

.account-security__enroll {
  display: grid;
  gap: 12px;
  margin-top: 16px;
  max-width: 720px;
}

.account-security__facts {
  display: grid;
  gap: 8px;
  margin: 12px 0;
}

.account-security__facts div {
  display: flex;
  gap: 12px;
}

.account-security__facts dt {
  min-width: 90px;
  color: var(--muted);
}

.account-security__facts dd {
  margin: 0;
}

.break {
  word-break: break-all;
}
</style>
