<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import DataTable, { type DataTableColumn } from '@/components/DataTable.vue'
import PageShell from '@/shared/ui/PageShell.vue'
import { resourceGet, resourcePatch } from '@/api/resourceClient'
import { ApiError, errorCopy } from '@/api/errors'
import { useSessionStore } from '@/stores/session'
import { formatDateTimeInTimezone } from '@/utils/format'
import { rowText, rowsFrom, type PlatformRow } from '../platformRead'
import UserCreateDialog, { type EmploymentOption } from '../components/UserCreateDialog.vue'
import UserRoleDrawer, { type RoleChoice, type UserRoleRow } from '../components/UserRoleDrawer.vue'
import UserPermissionPreview from '../components/UserPermissionPreview.vue'
import { DISPLAY_CODES, translate } from '@/shared/display/dictionary'

type Status = typeof DISPLAY_CODES.userStatus.active | typeof DISPLAY_CODES.userStatus.locked | typeof DISPLAY_CODES.userStatus.disabled | ''

type PendingAction =
  | { kind: 'disable'; user: PlatformRow }
  | { kind: 'enable'; user: PlatformRow }
  | { kind: 'sessions'; user: PlatformRow }

type UserRow = PlatformRow & {
  id: number
  roles: UserRoleRow[]
}

const session = useSessionStore()

const USERS_PAGE_SIZE = 20

const loading = ref(false)
const loadError = ref<unknown>(null)
const users = ref<UserRow[]>([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(USERS_PAGE_SIZE)
const search = ref('')

const filters = reactive({
  status: '' as Status,
  roleCode: '',
})

const roleChoices = ref<RoleChoice[]>([])
const roleLoading = ref(false)
const rolePermissionDenied = ref(false)

const createOpen = ref(false)
const employmentOptions = ref<EmploymentOption[]>([])
const employmentLoading = ref(false)

const roleDrawerOpen = ref(false)
const selectedUser = ref<UserRow | null>(null)

const previewOpen = ref(false)
const previewLoading = ref(false)
const preview = ref<Record<string, unknown> | null>(null)

const pendingAction = ref<PendingAction | null>(null)
const actionLoading = ref(false)
const actionError = ref('')
const actionMessage = ref('')
const statusReason = ref('')

const canRead = computed(() => session.can('iam.user.read'))
const canManageUsers = computed(() => session.can('iam.user.manage'))
const canManageRoles = computed(() => session.can('iam.role.manage'))

const columns = computed<DataTableColumn[]>(() => [
  { key: 'username', label: '用户名', width: '150px' },
  { key: 'display_name', label: '显示名称', minWidth: '150px' },
  { key: 'status', label: '状态', width: '100px' },
  { key: 'roles', label: '角色', minWidth: '160px' },
  { key: 'employment', label: '员工任职', minWidth: '150px' },
  { key: 'last_login_at', label: '最近登录', width: '160px' },
  { key: 'actions', label: '操作', minWidth: '260px' },
])

const STATUS_TONES: Record<string, 'success' | 'warning' | 'danger' | 'info'> = {
  [DISPLAY_CODES.userStatus.active]: 'success',
  [DISPLAY_CODES.userStatus.locked]: 'warning',
  [DISPLAY_CODES.userStatus.disabled]: 'danger',
}

const selectedRoles = computed<UserRoleRow[]>(() => selectedUser.value?.roles ?? [])

const displayNameOf = (user: PlatformRow): string => rowText(user, 'display_name', rowText(user, 'username', '—'))

function statusEntry(value: unknown) {
  const code = rowText({ status: value }, 'status', '')
  return {
    label: translate('platform', 'userStatus', code, '未知状态').label,
    tone: STATUS_TONES[code] ?? 'info' as const,
  }
}

function rolesFor(user: PlatformRow): UserRoleRow[] {
  const value = user.roles
  if (!Array.isArray(value)) return []
  return value.filter((role): role is UserRoleRow => typeof role === 'object' && role !== null && typeof (role as Record<string, unknown>).role_code === 'string')
}

function roleLabels(user: PlatformRow): string {
  const roles = rolesFor(user)
  if (roles.length === 0) return '未分配'
  // The list shows the canonical role code so administrators can match the
  // same identifier used by role templates and audit events.
  return roles.map((role) => role.role_code).join('、')
}

function employmentText(user: PlatformRow): string {
  const binding = user.employment_binding
  if (typeof binding !== 'object' || binding === null) return '未绑定'
  const record = binding as Record<string, unknown>
  const employeeNo = typeof record.employee_no === 'string' ? record.employee_no : ''
  const personName = typeof record.person_name === 'string' ? record.person_name : ''
  if (employeeNo === '' && personName === '') return '未绑定'
  return [employeeNo, personName].filter((part) => part !== '').join(' · ')
}

function lastLoginText(user: PlatformRow): string {
  const value = rowText(user, 'last_login_at', '')
  return value === '' || value === '—' ? '从未登录' : formatDateTimeInTimezone(value)
}

function errorCode(reason: unknown): string {
  return reason instanceof ApiError ? reason.code : 'UNKNOWN'
}

function friendlyError(reason: unknown): string {
  if (reason instanceof ApiError) {
    if (reason.code === 'FORBIDDEN') return '无权访问用户管理。请联系系统管理员授予用户查看权限。'
    if (reason.code === 'NETWORK_ERROR') return '网络连接失败，请检查网络后刷新重试。'
    if (reason.code === 'CONFLICT') return '数据状态已发生变化，请刷新后重试。'
    return errorCopy(reason)
  }
  return errorCopy(reason)
}

async function loadUsers(): Promise<void> {
  loading.value = true
  loadError.value = null
  try {
    const params: Record<string, unknown> = {
      page: page.value,
      page_size: pageSize.value,
    }
    if (search.value.trim() !== '') params.q = search.value.trim()
    if (filters.status !== '') params.status = filters.status
    if (filters.roleCode !== '') params.role_code = filters.roleCode

    const payload = await resourceGet<unknown>('/v1/users', params)
    users.value = rowsFrom(payload).map((row) => ({
      ...row,
      id: typeof row.id === 'number' ? row.id : Number(row.id),
      roles: rolesFor(row),
    })) as UserRow[]
    total.value = typeof (payload as PlatformRow | null)?.total === 'number'
      ? Number((payload as PlatformRow).total)
      : users.value.length
  } catch (reason) {
    users.value = []
    total.value = 0
    loadError.value = reason
  } finally {
    loading.value = false
  }
}

async function loadRoles(): Promise<void> {
  roleLoading.value = true
  rolePermissionDenied.value = false
  try {
    const payload = await resourceGet<unknown>('/v1/roles')
    roleChoices.value = rowsFrom(payload, 'roles').map((role) => {
      const capabilities = Array.isArray(role.capabilities) ? role.capabilities : []
      const anchorTypes = [...new Set(capabilities.flatMap((capability) => {
        if (typeof capability !== 'object' || capability === null) return []
        const anchorType = (capability as Record<string, unknown>).anchor_type
        return typeof anchorType === 'string' && anchorType !== '' ? [anchorType] : []
      }))]
      return {
        code: rowText(role, 'code', ''),
        name: rowText(role, 'name', rowText(role, 'code', '')),
        is_template: role.is_template === true,
        page_count: typeof role.page_count === 'number' ? role.page_count : undefined,
        anchor_types: anchorTypes,
      }
    })
  } catch (reason) {
    roleChoices.value = []
    rolePermissionDenied.value = reason instanceof ApiError && reason.code === 'FORBIDDEN'
  } finally {
    roleLoading.value = false
  }
}

async function loadEmploymentOptions(): Promise<void> {
  if (!session.can('people.employee.read')) return
  employmentLoading.value = true
  try {
    const payload = await resourceGet<unknown>('/v1/employees', { page: 1, page_size: 100 })
    employmentOptions.value = rowsFrom(payload)
      .map((row) => ({
        employment_id: Number(row.employment_id ?? row.id ?? 0),
        employee_no: rowText(row, 'employee_no', ''),
        person_name: rowText(row, 'person_name', '') === '—' ? null : rowText(row, 'person_name', ''),
      }))
      .filter((option) => Number.isInteger(option.employment_id) && option.employment_id > 0)
  } catch {
    employmentOptions.value = []
  } finally {
    employmentLoading.value = false
  }
}

function openCreate(): void {
  actionError.value = ''
  actionMessage.value = ''
  createOpen.value = true
  if (employmentOptions.value.length === 0) void loadEmploymentOptions()
}

function openRoleDrawer(user: PlatformRow): void {
  actionError.value = ''
  actionMessage.value = ''
  selectedUser.value = user as UserRow
  roleDrawerOpen.value = true
  if (roleChoices.value.length === 0 && !rolePermissionDenied.value) void loadRoles()
}

async function openPreview(user: PlatformRow): Promise<void> {
  actionError.value = ''
  actionMessage.value = ''
  selectedUser.value = user as UserRow
  preview.value = null
  previewOpen.value = true
  previewLoading.value = true
  try {
    const payload = await resourceGet<unknown>(`/v1/users/${user.id}/permissions-preview`)
    preview.value = typeof payload === 'object' && payload !== null ? (payload as Record<string, unknown>) : null
  } catch (reason) {
    actionError.value = friendlyError(reason)
  } finally {
    previewLoading.value = false
  }
}

async function refreshAfterCreate(): Promise<void> {
  actionMessage.value = '用户已创建。请继续分配角色，或通知本人完成首次登录。'
  page.value = 1
  await loadUsers()
}

async function refreshAfterRoleSave(): Promise<void> {
  actionMessage.value = '角色变更已提交；该用户的旧会话已撤销，下一次请求将按新权限求值。'
  await loadUsers()
  if (selectedUser.value !== null) {
    const refreshed = users.value.find((user) => user.id === selectedUser.value?.id)
    if (refreshed !== undefined) selectedUser.value = refreshed
  }
}

function askDisable(user: PlatformRow): void {
  actionError.value = ''
  actionMessage.value = ''
  pendingAction.value = { kind: 'disable', user }
  statusReason.value = ''
}

function askEnable(user: PlatformRow): void {
  actionError.value = ''
  actionMessage.value = ''
  pendingAction.value = { kind: 'enable', user }
  statusReason.value = ''
}

function askRevokeSessions(user: PlatformRow): void {
  actionError.value = ''
  actionMessage.value = ''
  pendingAction.value = { kind: 'sessions', user }
  statusReason.value = ''
}

function closeConfirm(): void {
  if (actionLoading.value) return
  pendingAction.value = null
  statusReason.value = ''
}

const confirmTitle = computed(() => {
  if (pendingAction.value === null) return ''
  if (pendingAction.value.kind === 'disable') return '停用用户'
  if (pendingAction.value.kind === 'enable') return '启用用户'
  return '撤销全部登录会话'
})

const confirmName = computed(() => (pendingAction.value === null ? '' : displayNameOf(pendingAction.value.user)))

const confirmMessage = computed(() => {
  if (pendingAction.value === null) return ''
  const name = confirmName.value
  if (pendingAction.value.kind === 'disable') {
    return `确定停用「${name}」吗？停用后该账号立即无法登录，所有旧会话会被撤销。`
  }
  if (pendingAction.value.kind === 'enable') {
    return `确定重新启用「${name}」吗？启用后该账号可以重新登录。`
  }
  return `确定撤销「${name}」的全部登录会话吗？该用户在所有设备上的下一次请求都需要重新登录。`
})

function translateConflict(reason: unknown): string {
  if (!(reason instanceof ApiError)) return friendlyError(reason)
  const serverReason = reason.serverReason
  if (serverReason === 'LAST_ACTIVE_ADMIN' || serverReason === 'LAST_ENABLED_ADMIN_PROTECTED' || serverReason === 'LAST_SYSTEM_ADMIN') {
    return '不能停用最后一名可用系统管理员。请先授予其他账号系统管理员角色，再停用该账号。'
  }
  if (reason.code === 'CONFLICT') {
    return '用户状态已发生变化，无法完成该操作。请刷新后重试。'
  }
  return friendlyError(reason)
}

async function confirmPending(): Promise<void> {
  if (pendingAction.value === null || actionLoading.value) return
  const action = pendingAction.value
  const user = action.user
  const trimmedReason = statusReason.value.trim()

  if (trimmedReason.length < 3) {
    actionError.value = '请填写至少 3 个字符的操作原因。'
    return
  }

  actionLoading.value = true
  actionError.value = ''
  actionMessage.value = ''
  try {
    // The contract has no dedicated administrator session-revocation
    // operation. The user-status command is the published audited operation
    // that revokes every live session and bumps auth_version, so keep the
    // current status unchanged while submitting the reason through it.
    const status = action.kind === 'sessions'
      ? rowText(user, 'status', DISPLAY_CODES.userStatus.active)
      : (action.kind === 'disable' ? DISPLAY_CODES.userStatus.disabled : DISPLAY_CODES.userStatus.active)
    await resourcePatch<unknown>(`/v1/users/${user.id}`, {
      status,
      reason: trimmedReason,
    }, {
      idempotencyKey: `user-status-${user.id}-${Date.now()}`,
    })
    actionMessage.value = action.kind === 'sessions'
      ? `已撤销「${displayNameOf(user)}」的全部登录会话。`
      : (action.kind === 'disable'
          ? `用户「${displayNameOf(user)}」已停用，旧会话已撤销。`
          : `用户「${displayNameOf(user)}」已启用。`)
    pendingAction.value = null
    statusReason.value = ''
    await loadUsers()
  } catch (reason) {
    actionError.value = translateConflict(reason)
    const lastAdmin = reason instanceof ApiError
      && (reason.serverReason === 'LAST_ACTIVE_ADMIN' || reason.serverReason === 'LAST_ENABLED_ADMIN_PROTECTED')
    if (reason instanceof ApiError && (reason.code === 'CONFLICT' || reason.code === 'NOT_FOUND' || lastAdmin)) {
      pendingAction.value = null
      statusReason.value = ''
      await loadUsers()
    }
  } finally {
    actionLoading.value = false
  }
}

let searchTimer: ReturnType<typeof setTimeout> | undefined
watch(search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    page.value = 1
    void loadUsers()
  }, 250)
})

watch([() => filters.status, () => filters.roleCode], () => {
  page.value = 1
  void loadUsers()
})

watch([page, pageSize], () => void loadUsers())

onMounted(async () => {
  await Promise.all([loadUsers(), loadRoles()])
})
</script>

<template>
  <PageShell
    data-testid="system-users-page"
    title="用户与角色"
    description="维护登录账号、角色与员工任职；所有变更均按能力校验并记录审计。"
  >
    <template #actions>
      <el-button
        v-if="canManageUsers"
        type="primary"
        data-testid="user-create"
        @click="openCreate"
      >
        新增用户
      </el-button>
    </template>

    <p v-if="actionMessage" class="inline-success" data-testid="users-action-message">{{ actionMessage }}</p>
    <p v-if="actionError" class="inline-error" role="alert" data-testid="users-action-error">{{ actionError }}</p>

    <section v-if="loadError !== null && errorCode(loadError) === 'FORBIDDEN'" class="panel" data-testid="users-forbidden">
      <div class="panel__body">
        <h2 class="panel__title">无权访问用户管理</h2>
        <p class="muted">当前账号没有查看用户列表的权限。请联系系统管理员授予“用户查看”能力。</p>
      </div>
    </section>

    <section v-else class="panel">
      <div class="panel__header">
        <h2 class="panel__title">账号列表</h2>
        <span class="muted small">共 {{ total }} 个账号</span>
      </div>

      <div class="user-filters" data-testid="user-filters">
        <label class="platform-field user-filter">
          <span>状态</span>
          <el-select v-model="filters.status" data-testid="filter-status">
            <el-option value="" label="全部状态" />
            <el-option :value="DISPLAY_CODES.userStatus.active" :label="translate('platform', 'userStatus', DISPLAY_CODES.userStatus.active).label" />
            <el-option :value="DISPLAY_CODES.userStatus.locked" :label="translate('platform', 'userStatus', DISPLAY_CODES.userStatus.locked).label" />
            <el-option :value="DISPLAY_CODES.userStatus.disabled" :label="translate('platform', 'userStatus', DISPLAY_CODES.userStatus.disabled).label" />
          </el-select>
        </label>
        <label class="platform-field user-filter">
          <span>角色</span>
          <el-select v-model="filters.roleCode" data-testid="filter-role">
            <el-option value="" label="全部角色" />
            <el-option
              v-for="role in roleChoices"
              :key="role.code"
              :value="role.code"
              :label="role.name"
            />
          </el-select>
        </label>
        <el-button text @click="loadUsers">刷新</el-button>
      </div>

      <DataTable
        v-model:search-value="search"
        v-model:page="page"
        v-model:page-size="pageSize"
        :columns="columns"
        :rows="users as unknown as Array<Record<string, unknown>>"
        :total="total"
        :loading="loading"
        :error="loadError !== null ? friendlyError(loadError) : null"
        show-search
        search-placeholder="搜索用户名或邮箱"
        empty-title="当前筛选下没有用户"
        @retry="loadUsers"
      >
        <template #cell-display_name="{ row }">
          <span data-testid="user-display-name">{{ displayNameOf(row as PlatformRow) }}</span>
        </template>

        <template #cell-status="{ row }">
          <span class="plain-chip" :class="`plain-chip--${statusEntry((row as PlatformRow).status).tone}`">
            {{ statusEntry((row as PlatformRow).status).label }}
          </span>
        </template>

        <template #cell-roles="{ row }">
          <span data-testid="user-roles">{{ roleLabels(row as PlatformRow) }}</span>
        </template>

        <template #cell-employment="{ row }">
          <span data-testid="user-employment">{{ employmentText(row as PlatformRow) }}</span>
        </template>

        <template #cell-last_login_at="{ row }">
          <span data-testid="user-last-login">{{ lastLoginText(row as PlatformRow) }}</span>
        </template>

        <template #cell-actions="{ row }">
          <div class="user-actions">
            <el-button
              v-if="canManageRoles"
              text
              size="small"
              data-testid="assign-roles"
              @click="openRoleDrawer(row as PlatformRow)"
            >
              分配角色
            </el-button>
            <el-button
              v-if="canRead"
              text
              size="small"
              data-testid="permission-preview"
              @click="openPreview(row as PlatformRow)"
            >
              权限预览
            </el-button>
            <el-button
              v-if="canManageUsers && rowText(row as PlatformRow, 'status', '') === DISPLAY_CODES.userStatus.active"
              text
              size="small"
              type="danger"
              data-testid="user-disable"
              @click="askDisable(row as PlatformRow)"
            >
              停用
            </el-button>
            <el-button
              v-if="canManageUsers && ([DISPLAY_CODES.userStatus.locked, DISPLAY_CODES.userStatus.disabled] as string[]).includes(rowText(row as PlatformRow, 'status', ''))"
              text
              size="small"
              data-testid="user-enable"
              @click="askEnable(row as PlatformRow)"
            >
              启用
            </el-button>
            <el-button
              v-if="canManageUsers"
              text
              size="small"
              data-testid="user-revoke-sessions"
              @click="askRevokeSessions(row as PlatformRow)"
            >
              撤销全部会话
            </el-button>
          </div>
        </template>
      </DataTable>
    </section>

    <UserCreateDialog
      v-model="createOpen"
      :employment-options="employmentOptions"
      :employment-loading="employmentLoading"
      @created="refreshAfterCreate"
    />

    <UserRoleDrawer
      v-model="roleDrawerOpen"
      :user-id="selectedUser?.id ?? null"
      :username="selectedUser === null ? '' : displayNameOf(selectedUser)"
      :current-roles="selectedRoles"
      :role-choices="roleChoices"
      :role-choices-loading="roleLoading"
      :role-choices-permission-denied="rolePermissionDenied"
      @saved="refreshAfterRoleSave"
    />

    <el-drawer v-model="previewOpen" size="min(680px, 94vw)" title="权限预览">
      <UserPermissionPreview :preview="preview" :loading="previewLoading" />
    </el-drawer>

    <el-dialog
      :model-value="pendingAction !== null"
      :title="confirmTitle"
      width="460px"
      :close-on-click-modal="false"
      @close="closeConfirm"
    >
      <div data-testid="confirm-message">
        <p>{{ confirmMessage }}</p>
        <label class="platform-field">
          <span>操作原因 *</span>
          <el-input v-model="statusReason" data-testid="confirm-reason" maxlength="255" placeholder="至少 3 个字符" />
        </label>
      </div>
      <template #footer>
        <el-button @click="closeConfirm">取消</el-button>
        <el-button type="danger" :loading="actionLoading" data-testid="confirm-ok" @click="confirmPending">
          确认
        </el-button>
      </template>
    </el-dialog>
  </PageShell>
</template>

<style scoped>
.user-filters {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
  padding: 0 16px 12px;
}
.user-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 2px;
}
.user-filter {
  min-width: 150px;
}
</style>
