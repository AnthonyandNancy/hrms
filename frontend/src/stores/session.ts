import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import type { LoginRequest, Principal, SessionBootstrap } from '@/api/contracts'
import { ApiError } from '@/api/errors'
import { getApi } from '@/api'
import { visibleCaseActions, visibleNavigation } from '@/router/navigation'

export type SessionStatus = 'idle' | 'bootstrapping' | 'anonymous' | 'mfa_required' | 'authenticated' | 'expired'

export const useSessionStore = defineStore('session', () => {
  const status = ref<SessionStatus>('idle')
  const principal = ref<Principal | null>(null)
  const capabilities = ref<string[]>([])
  const csrfToken = ref<string | null>(null)
  const sessionExpiresAt = ref<string | null>(null)
  const mfaEnrollmentRequired = ref(false)
  const mfaChallengeId = ref<string | null>(null)
  const mfaMaskedDestination = ref('')
  const unauthorizedMessage = ref<string | null>(null)

  const isAuthenticated = computed(() => status.value === 'authenticated' && principal.value !== null)
  const navigation = computed(() => visibleNavigation(capabilities.value))
  const can = (capability: string | undefined): boolean =>
    !capability || capabilities.value.includes(capability)
  const caseActions = (actions: Parameters<typeof visibleCaseActions>[1]) =>
    visibleCaseActions(capabilities.value, actions)

  function adopt(session: SessionBootstrap): void {
    if (!session.authenticated || !session.principal) {
      clear()
      status.value = 'anonymous'
      return
    }
    principal.value = session.principal
    capabilities.value = [...session.capabilities]
    csrfToken.value = session.csrfToken
    sessionExpiresAt.value = session.sessionExpiresAt
    mfaEnrollmentRequired.value = session.mfaEnrollmentRequired
    mfaChallengeId.value = null
    status.value = 'authenticated'
  }

  function clear(): void {
    principal.value = null
    capabilities.value = []
    csrfToken.value = null
    sessionExpiresAt.value = null
    mfaEnrollmentRequired.value = false
    mfaChallengeId.value = null
    mfaMaskedDestination.value = ''
  }

  async function bootstrap(): Promise<void> {
    if (status.value === 'bootstrapping') return
    status.value = 'bootstrapping'
    try {
      adopt(await getApi().auth.bootstrap())
    } catch (error) {
      if (error instanceof ApiError && error.code === 'UNAUTHENTICATED') {
        clear()
        status.value = 'anonymous'
        return
      }
      clear()
      status.value = 'anonymous'
      throw error
    }
  }

  async function login(payload: LoginRequest): Promise<'authenticated' | 'mfa_required'> {
    const result = await getApi().auth.login(payload)
    if (result.status === 'MFA_REQUIRED') {
      mfaChallengeId.value = result.challengeId
      mfaMaskedDestination.value = result.maskedDestination
      status.value = 'mfa_required'
      return 'mfa_required'
    }
    adopt(result.session)
    return 'authenticated'
  }

  async function verifyTotp(code: string): Promise<void> {
    if (!mfaChallengeId.value) {
      throw new ApiError('CONFLICT', '验证会话已过期，请重新登录')
    }
    const session = await getApi().auth.verifyTotp({ challengeId: mfaChallengeId.value, code })
    adopt(session)
  }

  async function logout(): Promise<void> {
    try {
      await getApi().auth.logout()
    } finally {
      clear()
      status.value = 'anonymous'
    }
  }

  function expire(message = '登录状态已失效，为保护数据安全，请重新登录。'): void {
    clear()
    unauthorizedMessage.value = message
    status.value = 'expired'
  }

  function handleApiError(error: unknown): boolean {
    if (error instanceof ApiError && error.code === 'UNAUTHENTICATED') {
      expire()
      return true
    }
    return false
  }

  function handoffMfaChallenge(): void {
    mfaChallengeId.value = null
    mfaMaskedDestination.value = ''
    if (status.value === 'mfa_required') status.value = 'anonymous'
  }

  return {
    status,
    principal,
    capabilities,
    csrfToken,
    sessionExpiresAt,
    mfaEnrollmentRequired,
    mfaChallengeId,
    mfaMaskedDestination,
    unauthorizedMessage,
    isAuthenticated,
    navigation,
    can,
    caseActions,
    bootstrap,
    login,
    verifyTotp,
    logout,
    expire,
    handleApiError,
    handoffMfaChallenge,
  }
})
