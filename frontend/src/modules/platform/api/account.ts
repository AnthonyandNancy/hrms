import { ApiError } from '@/api/errors'
import { resourceDelete, resourceGet, resourcePost } from '@/api/resourceClient'
import { useSessionStore } from '@/stores/session'

/**
 * Account-security API boundary.
 *
 * Only the server decides whether a session is current, whether MFA is enabled
 * for this deployment, and which sessions are live. This module normalises the
 * transport shape and never invents rows or capabilities.
 */

type Raw = Record<string, unknown>

function isRecord(value: unknown): value is Raw {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
}

function record(value: unknown, field: string): Raw {
  if (!isRecord(value)) {
    throw new ApiError('INTERNAL', `账号安全数据不符合契约：${field}。`, { status: 502 })
  }
  return value
}

function text(value: unknown, fallback = ''): string {
  return typeof value === 'string' ? value : fallback
}

function nullableText(value: unknown): string | null {
  return typeof value === 'string' && value !== '' ? value : null
}

function integer(value: unknown): number {
  if (typeof value === 'number' && Number.isSafeInteger(value)) return value
  if (typeof value === 'string' && /^-?\d+$/.test(value)) return Number(value)
  return 0
}

function boolean(value: unknown): boolean {
  return value === true
}

function rowsOf(value: unknown): Raw[] {
  if (Array.isArray(value)) return value.filter(isRecord)
  if (isRecord(value) && Array.isArray(value.items)) return value.items.filter(isRecord)
  return []
}

function nextIdempotencyKey(prefix: string): string {
  const random = globalThis.crypto?.randomUUID?.() ?? Math.random().toString(36).slice(2)
  return `account-${prefix}-${random}`
}

/** CSRF is session-bound; attach it when the SPA already holds the login token. */
function writeHeaders(): Record<string, string> {
  const token = useSessionStore().csrfToken
  return token !== null && token !== '' ? { 'X-CSRF-Token': token } : {}
}

export type AccountProfile = {
  id: number
  companyName: string
  username: string
  email: string | null
  employmentId: number | null
  isPrivileged: boolean
  hasMfa: boolean
  mfaVerified: boolean
  authVersion: number
  roles: string[]
}

export type AccountSession = {
  id: string
  device: string
  ip: string | null
  current: boolean
  mfaVerified: boolean
  lastSeenAt: string
  createdAt: string
  absoluteExpiresAt: string
}

export type MfaStatus = {
  enabled: boolean
  enrolled: boolean
  mfaVerified: boolean
  recoveryCodesRemaining: number
}

export type PasswordChangeInput = {
  currentPassword: string
  newPassword: string
  newPasswordConfirmation: string
}

export type PasswordChangeResult = {
  passwordChanged: boolean
  reauthRequired: boolean
  sessionsRevoked: boolean
}

export type MfaEnrollment = {
  factorId: number
  secret: string
  otpauthUri: string
}

export type SessionRevocation = {
  revoked: boolean
  alreadyRevoked: boolean
  currentSession: boolean
}

export async function getAccountProfile(): Promise<AccountProfile> {
  const payload = record(await resourceGet<unknown>('/v1/me'), '当前账号')
  const roles = Array.isArray(payload.roles)
    ? payload.roles.filter((role): role is string => typeof role === 'string')
    : []

  return {
    id: integer(payload.id),
    companyName: text(payload.company_name, '当前公司'),
    username: text(payload.username),
    email: nullableText(payload.email),
    employmentId: payload.employment_id === null || payload.employment_id === undefined
      ? null
      : integer(payload.employment_id),
    isPrivileged: boolean(payload.is_privileged),
    hasMfa: boolean(payload.has_mfa),
    mfaVerified: boolean(payload.mfa_verified),
    authVersion: integer(payload.auth_version),
    roles,
  }
}

export async function getAccountSessions(): Promise<AccountSession[]> {
  return rowsOf(await resourceGet<unknown>('/v1/auth/sessions')).map((row) => ({
    id: text(row.id),
    // The server derives this label from the user agent. A missing label is
    // shown as "未知设备", never as a raw UA string.
    device: text(row.device, '未知设备'),
    ip: nullableText(row.ip),
    current: boolean(row.current),
    mfaVerified: boolean(row.mfa_verified),
    lastSeenAt: text(row.last_seen_at),
    createdAt: text(row.created_at),
    absoluteExpiresAt: text(row.absolute_expires_at),
  })).filter((row) => row.id !== '')
}

export async function getMfaStatus(): Promise<MfaStatus> {
  const payload = record(await resourceGet<unknown>('/v1/auth/mfa/status'), 'MFA 状态')
  // `enabled` is required from the API; absence is a contract error rather
  // than a licence to guess the deployment posture on the client.
  if (typeof payload.enabled !== 'boolean') {
    throw new ApiError('INTERNAL', 'MFA 状态不符合契约：缺少 enabled。', { status: 502 })
  }

  return {
    enabled: payload.enabled,
    enrolled: boolean(payload.enrolled),
    mfaVerified: boolean(payload.mfa_verified),
    recoveryCodesRemaining: integer(payload.recovery_codes_remaining),
  }
}

export async function changePassword(input: PasswordChangeInput): Promise<PasswordChangeResult> {
  const payload = record(
    await resourcePost<unknown>('/v1/auth/password', {
      current_password: input.currentPassword,
      new_password: input.newPassword,
      new_password_confirmation: input.newPasswordConfirmation,
    }, { idempotencyKey: nextIdempotencyKey('password'), headers: writeHeaders() }),
    '修改密码结果',
  )

  return {
    passwordChanged: boolean(payload.password_changed),
    reauthRequired: boolean(payload.reauth_required),
    sessionsRevoked: boolean(payload.sessions_revoked),
  }
}

export async function enrollTotp(): Promise<MfaEnrollment> {
  const payload = record(
    await resourcePost<unknown>('/v1/auth/mfa/totp/enroll', {}, {
      idempotencyKey: nextIdempotencyKey('mfa-enroll'),
      headers: writeHeaders(),
    }),
    '动态口令绑定',
  )

  return {
    factorId: integer(payload.factor_id),
    secret: text(payload.secret),
    otpauthUri: text(payload.otpauth_uri),
  }
}

export async function confirmTotp(factorId: number, code: string): Promise<string[]> {
  const payload = record(
    await resourcePost<unknown>('/v1/auth/mfa/totp/confirm', { factor_id: factorId, code }, {
      idempotencyKey: nextIdempotencyKey('mfa-confirm'),
      headers: writeHeaders(),
    }),
    '动态口令确认',
  )

  return Array.isArray(payload.recovery_codes)
    ? payload.recovery_codes.filter((item): item is string => typeof item === 'string')
    : []
}

export async function disableMfa(code: string, factorType: 'TOTP' | 'RECOVERY_CODE'): Promise<void> {
  const payload = record(
    await resourcePost<unknown>('/v1/auth/mfa/totp/disable', { factor_type: factorType, code }, {
      idempotencyKey: nextIdempotencyKey('mfa-disable'),
      headers: writeHeaders(),
    }),
    '停用多因素认证',
  )

  if (payload.disabled !== true) {
    throw new ApiError('INTERNAL', '停用多因素认证未完成，请重试。', { status: 502 })
  }
}

export async function revokeSession(id: string): Promise<SessionRevocation> {
  const payload = record(
    await resourceDelete<unknown>(`/v1/auth/sessions/${encodeURIComponent(id)}`, {
      idempotencyKey: nextIdempotencyKey('session-revoke'),
      headers: writeHeaders(),
    }),
    '会话撤销结果',
  )

  return {
    revoked: boolean(payload.revoked),
    alreadyRevoked: boolean(payload.already_revoked),
    currentSession: boolean(payload.current_session),
  }
}

export async function revokeAllSessions(): Promise<void> {
  await resourcePost<unknown>('/v1/auth/logout-all', {}, {
    idempotencyKey: nextIdempotencyKey('logout-all'),
    headers: writeHeaders(),
  })
}
