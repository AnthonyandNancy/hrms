<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { resourceGet, resourcePost, resourceDelete } from '@/api/resourceClient'
import { isRecord, idText, nestedRows, rowText, rowsFrom, textOf, type PlatformRow } from '../platformRead'

const users = ref<PlatformRow[]>([])
const roles = ref<PlatformRow[]>([])
const loading = ref(false)
const error = ref<string | null>(null)
const partial = ref(false)
const selected = ref<PlatformRow | null>(null)
const selectedRoles = computed(() => {
  const value = selectedPreview.value?.roles
  return Array.isArray(value) ? value.filter((role): role is string => typeof role === 'string') : []
})
const selectedPreview = ref<PlatformRow | null>(null)
const previewOpen = ref(false)

const createForm = ref({ username: '', email: '', password: '', isPrivileged: false })
const roleForm = ref({ roleCode: '', reason: '', validFrom: '', validTo: '', acknowledgeSodRisk: false })
const actionMessage = ref('')
const actionError = ref('')

const previewPages = computed(() => (selectedPreview.value === null ? [] : rowsFrom(selectedPreview.value, 'pages')))
const previewActions = computed(() => (selectedPreview.value === null ? [] : rowsFrom(selectedPreview.value, 'actions')))
const previewDenied = computed(() => (selectedPreview.value === null ? [] : rowsFrom(selectedPreview.value, 'denied')))

const DENIED_LABEL: Record<string, string> = {
  PAGE_NOT_GRANTED: '角色未授予该页面',
  CAPABILITY_NOT_GRANTED: '缺少动作能力',
  OUT_OF_COMPANY_SCOPE: '超出公司数据范围',
  MENU_HIDDEN: '菜单被覆盖隐藏',
}

function nestedRoleCodes(user: PlatformRow): string {
  const values = nestedRows(user, 'roles').map((role) => rowText(role, 'role_code', ''))
  return values.filter((value) => value !== '').join('、') || '未分配'
}

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const [userPayload, rolePayload] = await Promise.all([
      resourceGet<unknown>('/v1/users', { page: 1, page_size: 50 }),
      resourceGet<unknown>('/v1/roles'),
    ])
    users.value = rowsFrom(userPayload)
    roles.value = rowsFrom(rolePayload, 'roles')
    partial.value = Boolean((userPayload as PlatformRow)?.partial || (rolePayload as PlatformRow)?.partial)
  } catch (reason) {
    error.value = reason instanceof Error ? reason.message : '加载用户与角色失败'
  } finally {
    loading.value = false
  }
}

async function loadUserRoles(user: PlatformRow): Promise<void> {
  const payload = await resourceGet<unknown>(`/v1/users/${idText(user.id)}/permissions-preview`)
  selectedPreview.value = isRecord(payload) ? payload : null
}

async function openPreview(user: PlatformRow): Promise<void> {
  selected.value = user
  previewOpen.value = true
  await loadUserRoles(user)
}

async function createUser(): Promise<void> {
  actionError.value = ''
  actionMessage.value = ''
  try {
    await resourcePost<unknown>('/v1/users', {
      username: createForm.value.username,
      email: createForm.value.email || null,
      password: createForm.value.password,
      is_privileged: createForm.value.isPrivileged,
    }, { idempotencyKey: `user-create-${createForm.value.username}-${Date.now()}` })
    createForm.value = { username: '', email: '', password: '', isPrivileged: false }
    actionMessage.value = '用户已创建。'
    await load()
  } catch (reason) {
    actionError.value = reason instanceof Error ? reason.message : '创建用户失败'
  }
}

async function assignRole(): Promise<void> {
  if (selected.value === null) return
  actionError.value = ''
  actionMessage.value = ''
  const roleCode = roleForm.value.roleCode.trim().toUpperCase()
  if (roleCode === '' || roleForm.value.reason.trim().length < 3) {
    actionError.value = '请选择角色并填写至少 3 个字符的原因。'
    return
  }
  try {
    await resourcePost<unknown>(`/v1/users/${idText(selected.value.id)}/roles`, {
      role_code: roleCode,
      reason: roleForm.value.reason.trim(),
      ...(roleForm.value.validFrom !== '' ? { valid_from: roleForm.value.validFrom } : {}),
      ...(roleForm.value.validTo !== '' ? { valid_to: roleForm.value.validTo } : {}),
      ...(roleForm.value.acknowledgeSodRisk ? { acknowledge_sod_risk: true } : {}),
    }, { idempotencyKey: `role-assign-${idText(selected.value.id)}-${roleCode}-${Date.now()}` })
    actionMessage.value = `角色 ${roleCode} 已分配。`
    await load()
    await loadUserRoles(selected.value)
  } catch (reason) {
    actionError.value = reason instanceof Error ? reason.message : '分配角色失败'
  }
}

async function revokeRole(roleCode: string): Promise<void> {
  if (selected.value === null) return
  if (roleCode === '') return
  actionError.value = ''
  actionMessage.value = ''
  try {
    await resourceDelete<unknown>(`/v1/users/${idText(selected.value.id)}/roles/${encodeURIComponent(roleCode)}`, {
      idempotencyKey: `role-revoke-${idText(selected.value.id)}-${roleCode}-${Date.now()}`,
    })
    actionMessage.value = `角色 ${roleCode} 已撤销，下一次请求立即失效。`
    await load()
    await loadUserRoles(selected.value)
  } catch (reason) {
    actionError.value = reason instanceof Error ? reason.message : '撤销角色失败'
  }
}

onMounted(load)
</script>

<template>
  <div class="page-stack" data-testid="system-users-page">
    <header class="page-header">
      <div>
        <h1>用户与角色</h1>
        <p class="muted">角色来自 <code>GET /v1/roles</code>；每个用户的权力预览来自 <code>GET /v1/users/{id}/permissions-preview</code>。</p>
      </div>
      <span class="plain-chip">{{ users.length }} 个账户</span>
    </header>

    <p v-if="error" class="inline-error" role="alert">{{ error }}</p>
    <p v-if="partial" class="inline-warning">部分读取失败，已保留成功返回的数据。</p>
    <p v-if="loading" class="muted">加载中…</p>

    <section class="panel">
      <div class="panel__header"><h2 class="panel__title">账户列表</h2></div>
      <div v-if="users.length > 0" class="platform-table-wrap">
        <table class="platform-table">
          <caption class="visually-hidden">用户账户列表</caption>
          <thead><tr><th>用户名</th><th>邮箱</th><th>状态</th><th>角色</th><th>MFA</th><th>操作</th></tr></thead>
          <tbody>
            <tr v-for="user in users" :key="idText(user.id)">
              <td class="mono">{{ rowText(user, 'username') }}</td>
              <td>{{ rowText(user, 'email') }}</td>
              <td><span class="plain-chip">{{ rowText(user, 'status') }}</span></td>
              <td data-testid="user-roles">{{ nestedRoleCodes(user) }}</td>
              <td>{{ user.mfa_enrolled === true ? '已启用' : '未启用' }}</td>
              <td>
                <el-button text size="small" data-testid="permission-preview" @click="openPreview(user)">权限预览 / 分配角色</el-button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-else class="panel__body"><p class="empty-inline">暂无账户。</p></div>
    </section>

    <section class="panel">
      <div class="panel__header"><h2 class="panel__title">创建用户</h2></div>
      <div class="panel__body platform-form">
        <div class="platform-form-grid">
          <label class="platform-field"><span>用户名</span><input v-model="createForm.username" data-testid="user-username" /></label>
          <label class="platform-field"><span>邮箱</span><input v-model="createForm.email" data-testid="user-email" /></label>
        </div>
        <label class="platform-field"><span>初始密码（至少 12 位）</span><input v-model="createForm.password" type="password" data-testid="user-password" /></label>
        <label class="platform-check"><input v-model="createForm.isPrivileged" type="checkbox" /> 特权账号（需 MFA）</label>
        <el-button type="primary" data-testid="user-submit" @click="createUser">创建用户</el-button>
      </div>
    </section>

    <section v-if="selected" class="panel" data-testid="user-management">
      <div class="panel__header">
        <h2 class="panel__title">管理 {{ rowText(selected, 'username') }}</h2>
        <el-button text size="small" @click="selected = null; previewOpen = false">关闭</el-button>
      </div>

      <div class="panel__body stack">
        <h3>分配角色</h3>
        <div class="platform-form-grid">
          <label class="platform-field">
            <span>角色</span>
            <select v-model="roleForm.roleCode" data-testid="assign-role-select">
              <option value="">选择角色</option>
              <option v-for="role in roles" :key="rowText(role, 'code')" :value="rowText(role, 'code')">
                {{ rowText(role, 'name') }} ({{ rowText(role, 'code') }})
              </option>
            </select>
          </label>
          <label class="platform-field"><span>原因（至少 3 个字）</span><input v-model="roleForm.reason" data-testid="role-reason" /></label>
          <label class="platform-field"><span>生效日</span><input v-model="roleForm.validFrom" type="date" /></label>
          <label class="platform-field"><span>到期日</span><input v-model="roleForm.validTo" type="date" /></label>
        </div>
        <label class="platform-check"><input v-model="roleForm.acknowledgeSodRisk" type="checkbox" /> 我确认接受职责分离风险</label>
        <el-button type="primary" data-testid="role-submit" @click="assignRole">分配角色</el-button>

        <h3>当前角色</h3>
        <div v-if="selectedRoles.length > 0" class="platform-chip-row">
          <span v-for="role in selectedRoles" :key="textOf(role)" class="plain-chip">
            {{ textOf(role) }}
            <button type="button" data-testid="revoke-role" @click="revokeRole(textOf(role))">撤销</button>
          </span>
        </div>
        <p v-else class="muted">未分配角色。</p>
      </div>

      <div v-if="previewOpen" class="panel__body stack" data-testid="permission-preview-panel">
        <h3>权限预览</h3>
        <div class="platform-form-grid">
          <div><strong>有效页面</strong><p class="muted">{{ previewPages.length }} 个</p></div>
          <div><strong>有效动作</strong><p class="muted">{{ previewActions.length }} 个</p></div>
          <div><strong>拒绝项</strong><p class="muted">{{ previewDenied.length }} 个</p></div>
        </div>
        <table v-if="previewPages.length > 0" class="platform-table">
          <thead><tr><th>页面</th><th>路径</th><th>所需能力</th></tr></thead>
          <tbody>
            <tr v-for="page in previewPages" :key="rowText(page, 'page_code')">
              <td>{{ rowText(page, 'menu_label', rowText(page, 'page_code')) }}</td>
              <td class="mono">{{ rowText(page, 'path') }}</td>
              <td class="mono">{{ rowText(page, 'required_capability') }}</td>
            </tr>
          </tbody>
        </table>
        <table v-if="previewDenied.length > 0" class="platform-table" data-testid="denied-list">
          <thead><tr><th>拒绝页面</th><th>路径</th><th>原因</th></tr></thead>
          <tbody>
            <tr v-for="item in previewDenied" :key="rowText(item, 'page_code')">
              <td>{{ rowText(item, 'page_code') }}</td>
              <td class="mono">{{ rowText(item, 'path') }}</td>
              <td>{{ DENIED_LABEL[rowText(item, 'reason', '')] ?? rowText(item, 'reason') }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>
