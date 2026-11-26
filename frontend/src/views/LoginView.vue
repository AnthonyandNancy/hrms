<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowRight, Lock, Setting } from '@element-plus/icons-vue'
import { useSessionStore } from '@/stores/session'
import { loginSchema, totpSchema, validate } from '@/utils/validation'
import { errorCopy } from '@/api/errors'

const router = useRouter()
const route = useRoute()
const session = useSessionStore()

// Never ship a working credential in the bundle. The username is a harmless
// convenience prefill; the password must be typed by the human operating the
// Local development helper. Production builds must use the HTTP adapter.
const form = reactive({
  username: '',
  password: '',
})
const totpCode = ref('')
const errors = reactive<Record<string, string>>({})
const totpError = ref('')
const submitting = ref(false)
const totpSubmitting = ref(false)
const expiredVisible = ref(route.query.expired === '1')

const mfaRequired = computed(() => session.status === 'mfa_required')
const tenantLabel = computed(() => session.principal?.legalEntityName ?? session.principal?.tenantName ?? 'HRMS')

function clearErrors(): void {
  for (const key of Object.keys(errors)) delete errors[key]
  totpError.value = ''
}

function redirectTarget(): string {
  const redirect = route.query.redirect
  return typeof redirect === 'string' && redirect.startsWith('/') ? redirect : '/workspace'
}

async function submitLogin(): Promise<void> {
  clearErrors()
  const result = validate(loginSchema, form)
  if (!result.success) {
    for (const issue of result.issues) errors[issue.path] = issue.message
    return
  }
  submitting.value = true
  try {
    const outcome = await session.login(result.data!)
    if (outcome === 'authenticated') {
      await router.replace(redirectTarget())
    } else {
      await router.replace({ name: 'login', query: { mfa: '1', redirect: redirectTarget() } })
    }
  } catch (error) {
    errors.form = errorCopy(error)
  } finally {
    submitting.value = false
  }
}

async function submitTotp(): Promise<void> {
  totpError.value = ''
  const result = validate(totpSchema, { code: totpCode.value })
  if (!result.success) {
    totpError.value = result.issues[0]?.message ?? '请输入 6 位动态验证码'
    return
  }
  totpSubmitting.value = true
  try {
    await session.verifyTotp(totpCode.value)
    await router.replace(redirectTarget())
  } catch (error) {
    totpError.value = errorCopy(error)
  } finally {
    totpSubmitting.value = false
  }
}

function backToPassword(): void {
  session.handoffMfaChallenge()
  totpCode.value = ''
  totpError.value = ''
  clearErrors()
}

onMounted(() => {
  if (route.query.expired === '1') {
    expiredVisible.value = true
  }
})
</script>

<template>
  <div class="login-screen">
    <section class="login-brand" aria-label="产品介绍">
      <div class="login-brand__top">
        <span class="login-brand__mark" aria-hidden="true">澄</span>
        <div>
          <p class="login-brand__name">澄明 HR</p>
          <p class="login-brand__tenant">{{ tenantLabel }}</p>
        </div>
      </div>

      <div class="login-brand__hero">
        <p class="login-brand__eyebrow">HRMS V1.0 · 人事工作台</p>
        <h1>让每一项人事决定<br />都解释得清楚。</h1>
        <p class="login-brand__lede">
          人员、组织、假勤、审批与薪酬共用同一套时间坐标与授权证据。你看到的数字有来源，做出的决定可追溯。
        </p>
        <ul class="login-brand__principles">
          <li>
            <span class="login-brand__bullet" aria-hidden="true">01</span>
            <span><strong>申请与生效分开</strong><br />审批通过不等于业务已生效</span>
          </li>
          <li>
            <span class="login-brand__bullet" aria-hidden="true">02</span>
            <span><strong>权限可解释</strong><br />角色 → 能力 → 锚点 → 字段 → 目的</span>
          </li>
          <li>
            <span class="login-brand__bullet" aria-hidden="true">03</span>
            <span><strong>操作可审计</strong><br />敏感查看与导出都会留下证据</span>
          </li>
        </ul>
      </div>

      <p class="login-brand__footer">报告问题请联系 IT 服务台</p>
    </section>

    <main class="login-panel">
      <div class="login-card">
        <template v-if="!mfaRequired">
          <div class="login-card__heading">
            <p class="login-card__eyebrow">安全登录</p>
            <h2>欢迎回来</h2>
            <p>使用公司账号和密码进入人事工作台。会话使用 HttpOnly Cookie，不在浏览器存储中保存访问令牌。</p>
          </div>

          <el-alert
            v-if="errors.form"
            :title="errors.form"
            type="error"
            show-icon
            :closable="false"
            class="login-card__alert"
          />

          <form class="login-form" novalidate @submit.prevent="submitLogin">
            <label class="field-label" for="login-username">账号</label>
            <el-input
              id="login-username"
              v-model="form.username"
              autocomplete="username"
              size="large"
              placeholder="登录账号或邮箱"
              :class="{ 'input-invalid': errors.username }"
              :aria-invalid="Boolean(errors.username)"
              aria-describedby="login-username-error"
              @keyup.enter="submitLogin"
            />
            <p v-if="errors.username" id="login-username-error" class="field-error">{{ errors.username }}</p>

            <div class="label-row">
              <label class="field-label" for="login-password">密码</label>
              <span class="field-hint">至少 8 位</span>
            </div>
            <el-input
              id="login-password"
              v-model="form.password"
              type="password"
              show-password
              autocomplete="current-password"
              size="large"
              placeholder="请输入密码"
              :class="{ 'input-invalid': errors.password }"
              :aria-invalid="Boolean(errors.password)"
              aria-describedby="login-password-error"
              @keyup.enter="submitLogin"
            />
            <p v-if="errors.password" id="login-password-error" class="field-error">{{ errors.password }}</p>

            <el-button
              type="primary"
              size="large"
              native-type="submit"
              :loading="submitting"
              class="login-form__submit"
            >
              登录
              <el-icon class="el-icon--right" aria-hidden="true"><ArrowRight /></el-icon>
            </el-button>
          </form>

          <div class="login-card__secondary-actions">
            <span class="login-card__help">忘记密码请联系 IT 服务台</span>
          </div>
        </template>

        <template v-else>
          <div class="login-card__heading">
            <el-icon class="login-card__step-icon" aria-hidden="true"><Lock /></el-icon>
            <p class="login-card__eyebrow">第二步 · 动态口令</p>
            <h2>验证你的身份</h2>
            <p>请输入认证器中为 HRMS 生成的 6 位动态验证码。验证码每 30 秒更新一次。</p>
          </div>

          <div class="mfa-destination" role="note">
            <el-icon aria-hidden="true"><Setting /></el-icon>
            验证目标：{{ session.mfaMaskedDestination }}
          </div>

          <form class="login-form" novalidate @submit.prevent="submitTotp">
            <label class="field-label" for="login-totp">动态验证码</label>
            <el-input
              id="login-totp"
              v-model="totpCode"
              inputmode="numeric"
              maxlength="6"
              size="large"
              placeholder="000000"
              autocomplete="one-time-code"
              :aria-invalid="Boolean(totpError)"
              aria-describedby="login-totp-error"
              class="totp-input"
            />
            <p v-if="totpError" id="login-totp-error" class="field-error" role="alert">{{ totpError }}</p>
            

            <el-button
              type="primary"
              size="large"
              native-type="submit"
              :loading="totpSubmitting"
              class="login-form__submit"
            >
              验证并进入工作台
            </el-button>
            <el-button text class="login-form__back" @click="backToPassword">返回重新输入账号</el-button>
          </form>
        </template>

        <p class="login-card__legal">
          登录即表示你同意遵守公司信息安全与数据使用规范。所有受保护操作均记录
          <span class="mono">request_id</span> 与操作者身份。
        </p>
      </div>
    </main>

    <el-dialog
      v-model="expiredVisible"
      title="登录状态已失效"
      width="420px"
      :close-on-click-modal="false"
    >
      <p class="dialog-copy">
        为保护人事与薪酬数据，当前会话已结束。请重新登录后继续刚才的操作；未提交的输入不会被保存。
      </p>
      <template #footer>
        <el-button type="primary" @click="expiredVisible = false">重新登录</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.login-screen {
  display: grid;
  grid-template-columns: minmax(380px, 1.05fr) minmax(400px, 1fr);
  min-height: 100vh;
  background: #fff;
}

.login-brand {
  position: relative;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  overflow: hidden;
  min-height: 100vh;
  padding: 34px 52px 28px;
  color: #edf6f3;
  background:
    radial-gradient(80% 60% at 85% 10%, rgb(224 181 96 / 18%), transparent 58%),
    linear-gradient(158deg, #0f4956 0%, #0a3540 52%, #082b34 100%);
}

.login-brand::after {
  position: absolute;
  right: -120px;
  bottom: -160px;
  width: 420px;
  height: 420px;
  content: "";
  border: 1px solid rgb(255 255 255 / 12%);
  border-radius: 50%;
  box-shadow: 0 0 0 80px rgb(255 255 255 / 3%), 0 0 0 160px rgb(255 255 255 / 2%);
}

.login-brand__top {
  display: flex;
  align-items: center;
  gap: 12px;
}

.login-brand__mark {
  display: grid;
  width: 42px;
  height: 42px;
  place-items: center;
  color: #16343a;
  font-size: 21px;
  font-weight: 700;
  background: linear-gradient(140deg, #f6d88b, #d4a445);
  border-radius: 10px;
}

.login-brand__name {
  font-size: 17px;
  font-weight: 680;
  letter-spacing: 0.03em;
}

.login-brand__tenant {
  color: #a6c3c0;
  font-size: 11px;
  letter-spacing: 0.08em;
}

.login-brand__hero {
  position: relative;
  z-index: 1;
  max-width: 520px;
  margin: auto 0;
  padding: 50px 0;
}

.login-brand__eyebrow {
  color: #e3c37b;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.16em;
}

.login-brand__hero h1 {
  margin-top: 16px;
  font-size: clamp(32px, 3.6vw, 48px);
  font-weight: 680;
  letter-spacing: -0.035em;
  line-height: 1.22;
}

.login-brand__lede {
  max-width: 470px;
  margin-top: 18px;
  color: #b9d0cd;
  font-size: 14px;
  line-height: 1.8;
}

.login-brand__principles {
  display: grid;
  gap: 17px;
  margin: 34px 0 0;
  padding: 0;
  list-style: none;
}

.login-brand__principles li {
  display: flex;
  gap: 13px;
  font-size: 12.5px;
  line-height: 1.65;
}

.login-brand__principles strong {
  color: #fff;
  font-weight: 650;
}

.login-brand__principles span:last-child {
  color: #a9c3c0;
}

.login-brand__bullet {
  flex: 0 0 26px;
  width: 26px;
  height: 26px;
  color: #e4c377;
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.05em;
  text-align: center;
  line-height: 24px;
  border: 1px solid rgb(228 195 119 / 45%);
  border-radius: 50%;
}

.login-brand__footer {
  position: relative;
  z-index: 1;
  color: #7fa09d;
  font-size: 11px;
}

.login-panel {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 36px 28px;
  background:
    linear-gradient(90deg, rgb(15 76 92 / 4%) 1px, transparent 1px) 0 0 / 40px 40px,
    linear-gradient(rgb(15 76 92 / 4%) 1px, transparent 1px) 0 0 / 40px 40px,
    #fbfcfb;
}

.login-card {
  width: min(460px, 100%);
}

.login-card__heading {
  margin-bottom: 24px;
}

.login-card__eyebrow {
  color: var(--color-brand);
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.12em;
}

.login-card__heading h2 {
  margin-top: 7px;
  font-size: 28px;
  font-weight: 700;
  letter-spacing: -0.025em;
}

.login-card__heading p {
  margin-top: 8px;
  color: var(--color-ink-secondary);
  font-size: 12.5px;
  line-height: 1.7;
}

.login-card__step-icon {
  display: grid;
  width: 34px;
  height: 34px;
  margin-bottom: 12px;
  place-items: center;
  color: var(--color-brand);
  background: var(--color-brand-soft);
  border-radius: 50%;
}

.login-card__alert {
  margin-bottom: 16px;
}

.login-form {
  display: flex;
  flex-direction: column;
}

.field-label {
  margin-bottom: 6px;
  color: var(--color-ink);
  font-size: 12.5px;
  font-weight: 650;
}

.label-row {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  margin-top: 15px;
}

.field-hint {
  color: var(--color-ink-muted);
  font-size: 11px;
}

.field-error {
  margin-top: 5px;
  color: var(--color-danger);
  font-size: 11.5px;
}

.login-form__submit {
  width: 100%;
  margin-top: 22px;
}

.login-form__back {
  align-self: center;
  margin-top: 8px;
}

.login-card__secondary-actions {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 4px;
  margin-top: 12px;
  color: var(--color-ink-muted);
  font-size: 11.5px;
}

.login-card__separator {
  margin: 0 2px;
}

.login-card__help {
  color: var(--color-ink-muted);
}

.mfa-destination {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 12px;
  margin-bottom: 18px;
  color: var(--color-info);
  font-size: 12px;
  background: var(--color-info-soft);
  border: 1px solid #b8d4e3;
  border-radius: 6px;
}

.totp-input :deep(input) {
  font-family: "Cascadia Code", Consolas, monospace;
  font-size: 20px;
  letter-spacing: 0.35em;
}

.login-card__legal {
  margin-top: 28px;
  color: var(--color-ink-muted);
  font-size: 10.5px;
  line-height: 1.6;
}

.dialog-copy {
  color: var(--color-ink-secondary);
  font-size: 13px;
  line-height: 1.7;
}

@media (max-width: 900px) {
  .login-screen {
    grid-template-columns: minmax(0, 1fr);
  }

  .login-brand {
    min-height: auto;
    padding: 22px 24px;
  }

  .login-brand__hero {
    padding: 34px 0 22px;
  }

  .login-brand__principles {
    display: none;
  }

  .login-brand__footer {
    display: none;
  }

  .login-panel {
    padding: 28px 20px 40px;
  }
}

@media (max-width: 480px) {
  .login-brand__hero h1 {
    font-size: 29px;
  }

  .login-brand__lede {
    font-size: 13px;
  }
}
</style>
