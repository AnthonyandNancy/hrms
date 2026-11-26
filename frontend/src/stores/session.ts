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
  const pages = ref<Array<{ pageCode: string; path: string }>>([])
  const permissionsError = ref<string | null>(null)
  const csrfToken = ref<string | null>(null)
  const sessionExpiresAt = ref<string | null>(null)
  const mfaEnrollmentRequired = ref(false)
  const mfaChallengeId = ref<string | null>(null)
  const mfaMaskedDestination = ref('')
  const unauthorizedMessage = ref<string | null>(null)

  const isAuthenticated = computed(() => status.value === 'authenticated' && principal.value !== null)
  const navigation = computed(() => visibleNavigation(capabilities.value, pages.value))
  const can = (capability: string | undefined): boolean =>
    !capability || capabilities.value.includes(capability)
  const canPage = (pageCode: string | undefined): boolean =>
    !pageCode || pages.value.some((page) => page.pageCode === pageCode || page.path === pageCode)
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
    pages.value = []
    permissionsError.value = null
    csrfToken.value = session.csrfToken
    sessionExpiresAt.value = session.sessionExpiresAt
    mfaEnrollmentRequired.value = session.mfaEnrollmentRequired
    mfaChallengeId.value = null
    status.value = 'authenticated'
  }

  function clear(): void {
    principal.value = null
    capabilities.value = []
    pages.value = []
    permissionsError.value = null
    csrfToken.value = null
    sessionExpiresAt.value = null
    mfaEnrollmentRequired.value = false
    mfaChallengeId.value = null
    mfaMaskedDestination.value = ''
  }

  /**
   * Load the single page/action projection. A failed load must never degrade
   * to an empty menu: the caller sees the error and the shell renders an
   * explicit failure state.
   */
  async function loadPermissions(): Promise<void> {
    const result = await getApi().auth.permissions()
    const entries = Array.isArray(result.pages) ? result.pages : []
    pages.value = entries
      .map((entry: unknown): { pageCode: string; path: string } | null => {
        if (typeof entry !== 'object' || entry === null) return null
        const record = entry as Record<string, unknown>
        const pageCode = typeof record.page_code === 'string' ? record.page_code : null
        const path = typeof record.path === 'string' ? record.path : ''
        return pageCode === null ? null : { pageCode, path }
      })
      .filter((entry): entry is { pageCode: string; path: string } => entry !== null)
    permissionsError.value = null
  }

  /**
   * Login and step-up change the effective principal, so the page projection
   * must be refreshed in the same turn. Skipping it left `pages` empty and the
   * router denied every page right after a successful login.
   */
  async function loadPermissionsOrFail(): Promise<void> {
    try {
      await loadPermissions()
    } catch (error) {
      permissionsError.value = error instanceof Error ? error.message : '无法加载页面权限'
      throw error
    }
  }

  async function bootstrap(): Promise<void> {
    if (status.value === 'bootstrapping') return
    status.value = 'bootstrapping'
    let session: SessionBootstrap
    try {
      session = await getApi().auth.bootstrap()
    } catch (error) {
      clear()
      status.value = 'anonymous'
      if (error instanceof ApiError && error.code === 'UNAUTHENTICATED') return
      throw error
    }

    adopt(session)

    if (!session.authenticated || !session.principal) return

    try {
      await loadPermissions()
    } catch (error) {
      // Keep the authenticated principal but expose the projection failure;
      // the shell must not present an empty, apparently-authorized menu.
      permissionsError.value = error instanceof Error ? error.message : '无法加载页面权限'
      status.value = 'authenticated'
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
    await loadPermissionsOrFail()
    return 'authenticated'
  }

  async function verifyTotp(code: string): Promise<void> {
    if (!mfaChallengeId.value) {
      throw new ApiError('CONFLICT', '验证会话已过期，请重新登录')
    }
    const session = await getApi().auth.verifyTotp({ challengeId: mfaChallengeId.value, code })
    adopt(session)
    await loadPermissionsOrFail()
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
    pages,
    permissionsError,
    csrfToken,
    sessionExpiresAt,
    mfaEnrollmentRequired,
    mfaChallengeId,
    mfaMaskedDestination,
    unauthorizedMessage,
    isAuthenticated,
    navigation,
    can,
    canPage,
    caseActions,
    loadPermissions,
    bootstrap,
    login,
    verifyTotp,
    logout,
    expire,
    handleApiError,
    handoffMfaChallenge,
  }
})
