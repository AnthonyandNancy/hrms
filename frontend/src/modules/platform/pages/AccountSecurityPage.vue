<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { ApiError, errorCopy } from '@/api/errors'
import { useSessionStore } from '@/stores/session'
import PageShell from '@/shared/ui/PageShell.vue'
import LoadingState from '@/components/LoadingState.vue'
import ErrorState from '@/components/ErrorState.vue'
import EmptyState from '@/components/EmptyState.vue'
import ChangePasswordDialog from '../components/ChangePasswordDialog.vue'
import SessionList from '../components/SessionList.vue'
import {
  confirmTotp,
  disableMfa,
  enrollTotp,
  getAccountProfile,
  getAccountSessions,
  getMfaStatus,
  revokeAllSessions,
  revokeSession,
  type AccountProfile,
  type AccountSession,
  type MfaEnrollment,
  type MfaStatus,
} from '../api/account'

const router = useRouter()
const session = useSessionStore()

const profileLoading = ref(true)
const profileError = ref<unknown>(null)
const profile = ref<AccountProfile | null>(null)

const sessionsLoading = ref(true)
const sessionsError = ref<unknown>(null)
const sessions = ref<AccountSession[]>([])
const revokingId = ref<string | null>(null)

const mfaLoading = ref(true)
const mfaError = ref<unknown>(null)
const mfaStatus = ref<MfaStatus | null>(null)
const mfaFormError = ref('')
const enrollment = ref<MfaEnrollment | null>(null)
const enrollmentCode = ref('')
const enrolling = ref(false)
const confirming = ref(false)
const recoveryCodes = ref<string[]>([])
const disableCode = ref('')
const disableFactorType = ref<'TOTP' | 'RECOVERY_CODE'>('TOTP')
const disabling = ref(false)
const showDisable = ref(false)

const passwordOpen = ref(false)

const canOpen = computed(() => session.can('iam.session.manage'))
const canManageSessions = computed(() => session.can('iam.session.manage'))
const canManageMfa = computed(() => session.can('iam.session.manage'))
const companyName = computed(() => profile.value?.companyName ?? '当前公司')
const roleLabel = computed(() => {
  const roles = profile.value?.roles ?? []
  return roles.length > 0 ? roles.join('、') : '未分配角色'
})
const partial = computed(
  () => profileError.value !== null || sessionsError.value !== null || mfaError.value !== null,
)
const mfaVisible = computed(() => mfaStatus.value?.enabled === true)

function errorLabel(reason: unknown): string {
  return reason instanceof ApiError ? reason.code : 'UNKNOWN'
}

function requestIdOf(reason: unknown): string | null {
  return reason instanceof ApiError ? reason.requestId : null
}

async function loadProfile(): Promise<void> {
  profileLoading.value = true
  profileError.value = null
  try {
    profile.value = await getAccountProfile()
  } catch (reason) {
    profile.value = null
    profileError.value = reason
  } finally {
    profileLoading.value = false
  }
}

async function loadSessions(): Promise<void> {
  sessionsLoading.value = true
  sessionsError.value = null
  try {
    sessions.value = await getAccountSessions()
  } catch (reason) {
    sessions.value = []
    sessionsError.value = reason
  } finally {
    sessionsLoading.value = false
  }
}

async function loadMfaStatus(): Promise<void> {
  mfaLoading.value = true
  mfaError.value = null
  try {
    mfaStatus.value = await getMfaStatus()
  } catch (reason) {
    // A failure to read the deployment posture is an explicit error, never a
    // silent assumption that MFA is disabled.
    mfaStatus.value = null
    mfaError.value = reason
  } finally {
    mfaLoading.value = false
  }
}

async function loadAll(): Promise<void> {
  await Promise.all([loadProfile(), loadSessions(), loadMfaStatus()])
}

function logoutForReauth(message: string): void {
  passwordOpen.value = false
  session.expire(message)
  void router.push({ name: 'login', query: { expired: '1' } })
}

async function onPasswordChanged(payload: { sessionsRevoked: boolean }): Promise<void> {
  const suffix = payload.sessionsRevoked ? '其他设备上的会话已全部失效。' : '请使用新密码重新登录。'
  ElMessage.success(`密码已修改。${suffix}`)
  // The password-change dialog shows its own "done" panel with the re-auth
  // button; this page only reacts when the user leaves it.
}

async function askRevoke(target: AccountSession): Promise<void> {
  if (!canManageSessions.value) return
  const label = target.device === '' ? '该设备' : target.device
  const copy = target.current
    ? `将退出当前会话（${label}）。本页面会立即失效，你需要重新登录。`
    : `将撤销 ${label} 的登录会话。该设备需要重新登录。`

  try {
    await ElMessageBox.confirm(copy, target.current ? '退出当前会话' : '撤销登录会话', {
      confirmButtonText: target.current ? '退出并重新登录' : '撤销会话',
      cancelButtonText: '取消',
      type: 'warning',
    })
  } catch {
    return
  }

  revokingId.value = target.id
  try {
    const result = await revokeSession(target.id)
    if (result.currentSession) {
      logoutForReauth('当前会话已撤销，请重新登录。')
      return
    }
    ElMessage.success('会话已撤销。')
    await loadSessions()
  } catch (reason) {
    ElMessage.error(errorCopy(reason))
  } finally {
    revokingId.value = null
  }
}

async function askRevokeAll(): Promise<void> {
  if (!canManageSessions.value) return

  try {
    await ElMessageBox.confirm(
      '将退出所有设备上的登录会话，包括当前设备。你需要重新登录。',
      '退出全部会话',
      { confirmButtonText: '全部退出', cancelButtonText: '取消', type: 'warning' },
    )
  } catch {
    return
  }

  try {
    await revokeAllSessions()
    logoutForReauth('所有会话已退出，请重新登录。')
  } catch (reason) {
    ElMessage.error(errorCopy(reason))
  }
}

async function beginEnrollment(): Promise<void> {
  if (!canManageMfa.value) return
  mfaFormError.value = ''
  enrolling.value = true
  try {
    enrollment.value = await enrollTotp()
    enrollmentCode.value = ''
    recoveryCodes.value = []
  } catch (reason) {
    mfaFormError.value = mfaFormErrorFor(reason)
  } finally {
    enrolling.value = false
  }
}

function mfaFormErrorFor(reason: unknown): string {
  if (reason instanceof ApiError && reason.code === 'VALIDATION_FAILED') {
    if (reason.serverReason === 'MFA_CODE_INVALID') return '验证码不正确，请确认认证器当前显示的 6 位数字。'
    if (reason.fieldErrors.code !== undefined) return '请输入认证器显示的 6 位验证码。'
  }
  if (reason instanceof ApiError && reason.code === 'FORBIDDEN') {
    return '你没有管理多因素认证的权限。'
  }
  return errorCopy(reason)
}

async function confirmEnrollment(): Promise<void> {
  if (enrollment.value === null) return
  if (!/^\d{6}$/.test(enrollmentCode.value.trim())) {
    mfaFormError.value = '请输入认证器显示的 6 位动态验证码。'
    return
  }

  confirming.value = true
  mfaFormError.value = ''
  try {
    recoveryCodes.value = await confirmTotp(enrollment.value.factorId, enrollmentCode.value.trim())
    enrollment.value = null
    enrollmentCode.value = ''
    ElMessage.success('多因素认证已启用。')
    await loadMfaStatus()
    await loadProfile()
    await loadSessions()
  } catch (reason) {
    mfaFormError.value = mfaFormErrorFor(reason)
  } finally {
    confirming.value = false
  }
}

async function submitDisable(): Promise<void> {
  if (disableCode.value.trim() === '') {
    mfaFormError.value = '请输入当前动态验证码或一个恢复码。'
    return
  }

  disabling.value = true
  mfaFormError.value = ''
  try {
    await disableMfa(disableCode.value.trim(), disableFactorType.value)
    showDisable.value = false
    disableCode.value = ''
    logoutForReauth('多因素认证已停用，请重新登录。')
  } catch (reason) {
    mfaFormError.value = mfaFormErrorFor(reason)
  } finally {
    disabling.value = false
  }
}

onMounted(() => {
  if (canOpen.value) void loadAll()
})
</script>

<template>
  <PageShell
    title="登录与账号安全"
    description="管理与本账号关联的密码、登录设备和可选的第二因素。能力校验始终由服务端按角色授权执行。"
    data-testid="account-security-page"
  >
    <template #actions>
      <el-button data-testid="open-change-password" :disabled="!session.can('iam.session.manage')" @click="passwordOpen = true">
        修改密码
      </el-button>
      <el-button :loading="profileLoading || sessionsLoading || mfaLoading" @click="loadAll">刷新</el-button>
    </template>

    <section v-if="!canOpen" class="account-security__card" role="alert" data-testid="account-forbidden">
      <h2>无权访问账号安全</h2>
      <p class="account-security__copy">
        当前账号没有管理自身登录会话的能力。请联系系统管理员授予自助会话权限后再试。
      </p>
      <p class="account-security__muted">错误类别：FORBIDDEN · 能力：iam.session.manage</p>
    </section>

    <el-alert
      v-else-if="partial"
      type="warning"
      :closable="false"
      show-icon
      class="account-security__partial"
      data-testid="account-partial"
    >
      部分账号安全信息暂时无法加载。可分别重试对应区域；已加载的区域仍可正常使用。
    </el-alert>

    <section v-if="canOpen" class="account-security__card" data-testid="account-profile" aria-labelledby="account-profile-title">
      <header class="account-security__card-header">
        <h2 id="account-profile-title">账号信息</h2>
        <span class="plain-chip">密码登录为唯一必需方式</span>
      </header>

      <LoadingState v-if="profileLoading" :rows="3" label="正在加载账号信息" />
      <ErrorState
        v-else-if="profileError !== null"
        title="账号信息暂时无法加载"
        description="请重试；如持续失败，请记录错误类别和请求编号后联系系统管理员。"
        :code="errorLabel(profileError)"
        :request-id="requestIdOf(profileError)"
        @retry="loadProfile"
      />
      <EmptyState
        v-else-if="profile === null"
        title="没有可展示的账号信息"
        description="当前登录未返回账号资料。请重新登录后再试。"
      />
      <dl v-else class="account-security__facts" data-testid="account-profile-facts">
        <div><dt>公司</dt><dd>{{ companyName }}</dd></div>
        <div><dt>登录账号</dt><dd>{{ profile.username }}</dd></div>
        <div><dt>邮箱</dt><dd>{{ profile.email ?? '未登记' }}</dd></div>
        <div><dt>角色</dt><dd>{{ roleLabel }}</dd></div>
        <div>
          <dt>多因素认证</dt>
          <dd>{{ profile.hasMfa ? '已绑定' : '未绑定' }}</dd>
        </div>
      </dl>
    </section>

    <section v-if="canOpen" class="account-security__card" data-testid="account-sessions" aria-labelledby="account-sessions-title">
      <header class="account-security__card-header">
        <h2 id="account-sessions-title">登录会话</h2>
        <span class="plain-chip">服务端 HttpOnly Cookie</span>
      </header>

      <SessionList
        :sessions="sessions"
        :loading="sessionsLoading"
        :error="sessionsError"
        :revoking-id="revokingId"
        :can-manage="canManageSessions"
        @revoke="askRevoke"
        @revoke-all="askRevokeAll"
        @retry="loadSessions"
      />
    </section>

    <section v-if="canOpen" class="account-security__card" data-testid="account-mfa" aria-labelledby="account-mfa-title">
      <header class="account-security__card-header">
        <h2 id="account-mfa-title">多因素认证</h2>
        <span class="plain-chip">{{ mfaVisible ? '可为高风险操作增加第二因素' : '当前部署未启用' }}</span>
      </header>

      <LoadingState v-if="mfaLoading" :rows="2" label="正在读取多因素认证配置" />

      <ErrorState
        v-else-if="mfaError !== null"
        title="无法读取多因素认证配置"
        description="MFA 是否可用由服务端配置决定，页面不会自行假设。请重试或联系系统管理员。"
        :code="errorLabel(mfaError)"
        :request-id="requestIdOf(mfaError)"
        @retry="loadMfaStatus"
      />

      <template v-else-if="mfaStatus !== null && !mfaStatus.enabled">
        <EmptyState
          title="当前部署未启用多因素认证"
          description="公司当前只要求账号密码登录。这不会绕过任何角色或数据权限校验；如未来启用 MFA，此区域会出现绑定入口。"
        />
      </template>

      <div v-else-if="mfaStatus !== null" class="account-security__mfa" data-testid="mfa-section">
        <p class="account-security__copy">
          MFA 为可选增强，不影响密码登录。绑定后，高风险操作可能要求第二步验证；请将恢复码保存在离线安全位置。
        </p>

        <el-alert
          v-if="mfaFormError"
          type="error"
          :closable="false"
          show-icon
          data-testid="mfa-form-error"
          :title="mfaFormError"
        />

        <template v-if="recoveryCodes.length > 0">
          <el-alert
            type="success"
            title="恢复码只显示一次"
            :closable="false"
            show-icon
          />
          <p class="account-security__copy">请立即保存以下恢复码。每个恢复码只能使用一次。</p>
          <ul class="account-security__recovery" data-testid="recovery-codes">
            <li v-for="code in recoveryCodes" :key="code" class="mono">{{ code }}</li>
          </ul>
          <el-button data-testid="recovery-codes-saved" @click="recoveryCodes = []">我已保存恢复码</el-button>
        </template>

        <template v-else-if="enrollment !== null">
          <div class="account-security__enroll">
            <p class="account-security__step">步骤 1：在认证器应用中添加以下密钥</p>
            <p class="account-security__secret mono" data-testid="mfa-secret">{{ enrollment.secret }}</p>
            <p class="account-security__copy">步骤 2：输入认证器当前显示的 6 位动态验证码以完成绑定。</p>
            <el-input
              v-model="enrollmentCode"
              maxlength="6"
              inputmode="numeric"
              placeholder="000000"
              data-testid="mfa-code"
              aria-label="动态验证码"
            />
            <div class="account-security__inline-actions">
              <el-button type="primary" :loading="confirming" data-testid="mfa-confirm" @click="confirmEnrollment">
                确认绑定
              </el-button>
              <el-button :disabled="confirming" @click="enrollment = null">取消</el-button>
            </div>
          </div>
        </template>

        <template v-else>
          <p class="account-security__copy">
            {{ mfaStatus.enrolled ? '本账号已绑定认证器。如认证器丢失，可使用恢复码停用后重新绑定。' : '本账号尚未绑定认证器。绑定为可选步骤。' }}
          </p>
          <div class="account-security__inline-actions">
            <el-button
              v-if="!mfaStatus.enrolled"
              type="primary"
              :loading="enrolling"
              :disabled="!canManageMfa"
              data-testid="mfa-enroll"
              @click="beginEnrollment"
            >
              启用多因素认证
            </el-button>
            <el-button
              v-else
              type="danger"
              plain
              :disabled="!canManageMfa"
              data-testid="mfa-disable-open"
              @click="showDisable = !showDisable"
            >
              停用多因素认证
            </el-button>
          </div>

          <div v-if="mfaStatus.enrolled && showDisable" class="account-security__disable" data-testid="mfa-disable-panel">
            <p class="account-security__copy">
              停用后所有会话都会退出。请输入当前 6 位动态验证码，或使用一个未用过的恢复码。
            </p>
            <el-select v-model="disableFactorType" aria-label="验证方式" style="width: 160px">
              <el-option label="动态验证码" value="TOTP" />
              <el-option label="恢复码" value="RECOVERY_CODE" />
            </el-select>
            <el-input
              v-model="disableCode"
              :maxlength="disableFactorType === 'TOTP' ? 6 : 32"
              :inputmode="disableFactorType === 'TOTP' ? 'numeric' : 'text'"
              :placeholder="disableFactorType === 'TOTP' ? '000000' : '输入恢复码'"
              data-testid="mfa-disable-code"
              aria-label="停用验证码"
            />
            <div class="account-security__inline-actions">
              <el-button type="danger" :loading="disabling" data-testid="mfa-disable-submit" @click="submitDisable">
                确认停用
              </el-button>
              <el-button :disabled="disabling" @click="showDisable = false">取消</el-button>
            </div>
          </div>
        </template>
      </div>
    </section>

    <ChangePasswordDialog
      v-if="canOpen"
      v-model="passwordOpen"
      :can-manage="session.can('iam.session.manage')"
      @changed="onPasswordChanged"
      @reauth="logoutForReauth('密码已修改，请使用新密码重新登录。')"
    />
  </PageShell>
</template>

<style scoped>
.account-security__partial {
  margin-bottom: 4px;
}

.account-security__card {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 16px 18px;
  background: var(--color-surface, #fff);
  border: 1px solid var(--color-border, #dde3e1);
  border-radius: 10px;
}

.account-security__card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.account-security__card-header h2 {
  margin: 0;
  font-size: 15px;
}

.account-security__facts {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 10px 18px;
  margin: 0;
}

.account-security__facts div {
  display: flex;
  gap: 10px;
  font-size: 13px;
}

.account-security__facts dt {
  min-width: 76px;
  color: var(--muted);
}

.account-security__facts dd {
  margin: 0;
}

.account-security__muted {
  margin: 0;
  color: var(--muted);
  font-size: 12px;
}

.account-security__copy {
  margin: 0;
  color: var(--color-ink-secondary);
  font-size: 13px;
  line-height: 1.7;
}

.account-security__mfa {
  display: flex;
  flex-direction: column;
  gap: 12px;
  max-width: 760px;
}

.account-security__enroll,
.account-security__disable {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 14px;
  border: 1px dashed var(--color-border, #dde3e1);
  border-radius: 8px;
}

.account-security__step {
  margin: 0;
  font-weight: 600;
}

.account-security__secret {
  padding: 8px 10px;
  overflow-wrap: anywhere;
  background: var(--color-brand-soft, #eef5f3);
  border-radius: 6px;
}

.account-security__inline-actions {
  display: flex;
  gap: 8px;
}

.account-security__recovery {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: 6px;
  padding: 12px;
  margin: 0;
  list-style: none;
  background: var(--color-brand-soft, #eef5f3);
  border-radius: 8px;
}
</style>
