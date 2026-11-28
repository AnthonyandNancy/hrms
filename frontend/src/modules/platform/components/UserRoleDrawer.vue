<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { ApiError, errorCopy } from '@/api/errors'
import { resourceDelete, resourcePost } from '@/api/resourceClient'
import FormDrawer from '@/shared/ui/FormDrawer.vue'

export type UserRoleRow = {
  role_code: string
  name?: string | null
  valid_from?: string | null
  valid_to?: string | null
}

export type RoleChoice = {
  code: string
  name: string
  is_template?: boolean
  page_count?: number
  anchor_types?: string[]
}

const props = withDefaults(
  defineProps<{
    modelValue: boolean
    userId: number | null
    username: string
    currentRoles: UserRoleRow[]
    roleChoices?: RoleChoice[]
    roleChoicesLoading?: boolean
    roleChoicesPermissionDenied?: boolean
  }>(),
  {
    roleChoices: () => [],
    roleChoicesLoading: false,
    roleChoicesPermissionDenied: false,
  },
)

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  saved: []
}>()

const working = ref<string[]>([])
const anchorValues = reactive<Record<string, string>>({})

const ID_ANCHORS = new Set(['ORG_UNIT', 'ORG_SUBTREE', 'CASE', 'PAYROLL_RUN'])
const ANCHOR_LABELS: Record<string, string> = {
  ORG_UNIT: '组织单元 ID',
  ORG_SUBTREE: '组织子树 ID',
  CASE: '案例 ID',
  PAYROLL_RUN: '工资批次 ID',
}
const reason = ref('')
const error = ref('')
const warning = ref('')
const submitting = ref(false)

function originalCodes(): string[] {
  return props.currentRoles.map((role) => role.role_code)
}

function reset(): void {
  working.value = [...originalCodes()]
  reason.value = ''
  error.value = ''
  warning.value = ''
  submitting.value = false
  for (const key of Object.keys(anchorValues)) delete anchorValues[key]
}

watch(
  () => [props.modelValue, props.userId] as const,
  ([open]) => {
    if (open) reset()
  },
  { immediate: true },
)

watch(
  () => props.currentRoles,
  () => {
    if (!submitting.value) working.value = [...originalCodes()]
  },
  { deep: true },
)

const added = computed(() => working.value.filter((code) => !originalCodes().includes(code)))
const removed = computed(() => originalCodes().filter((code) => !working.value.includes(code)))

function roleFor(code: string): RoleChoice | undefined {
  return props.roleChoices.find((role) => role.code === code)
}

function requiredAnchors(role: RoleChoice): string[] {
  return [...new Set((role.anchor_types ?? []).filter((anchor) => ID_ANCHORS.has(anchor)))]
}

function anchorKey(roleCode: string, anchorType: string): string {
  return `${roleCode}:${anchorType}`
}

function anchorInputsForAddedRoles(): Array<{ role: RoleChoice; anchors: string[] }> {
  return added.value.flatMap((code) => {
    const role = roleFor(code)
    if (role === undefined) return []
    const anchors = requiredAnchors(role)
    return anchors.length === 0 ? [] : [{ role, anchors }]
  })
}
const hasDiff = computed(() => added.value.length + removed.value.length > 0)

const HIGH_RISK_ROLES = new Set(['SUPER_ADMIN', 'SYSTEM_ADMIN'])

const riskWarnings = computed(() => {
  const messages: string[] = []
  for (const code of added.value) {
    if (HIGH_RISK_ROLES.has(code)) {
      messages.push(`角色 ${roleLabel(code)} 拥有全公司管理权限，只能由既有超级管理员授予，并会立即撤销该用户的全部旧会话。`)
    }
  }
  if (removed.value.length > 0) {
    messages.push('移除角色会在保存后立即生效，该用户下一次请求将重新求值权限并被撤销现有会话。')
  }
  return messages
})

function roleLabel(code: string): string {
  const choice = props.roleChoices.find((role) => role.code === code)
  return choice === undefined ? code : `${choice.name}（${code}）`
}

function toggle(code: string): void {
  if (submitting.value) return
  working.value = working.value.includes(code)
    ? working.value.filter((item) => item !== code)
    : [...working.value, code]
}

function isSelected(code: string): boolean {
  return working.value.includes(code)
}

function close(): void {
  if (submitting.value) return
  emit('update:modelValue', false)
}

function translateConflict(reasonCode: string): string {
  const labels: Record<string, string> = {
    LAST_ACTIVE_ADMIN: '不能移除最后一名可用系统管理员的角色，请先授予其他账号系统管理员。',
    LAST_ENABLED_ADMIN_PROTECTED: '不能移除最后一名可用系统管理员的角色，请先授予其他账号系统管理员。',
    PRIVILEGE_ESCALATION_FORBIDDEN: '不能授予超出当前操作者自身权限范围的角色。',
    SUPER_ADMIN_GRANT_FORBIDDEN: '只有现有超级管理员可以授予超级管理员角色。',
    SOD_RISK_NOT_ACKNOWLEDGED: '该变更触发职责分离风险，需要先勾选确认。',
    TEMPLATE_ROLE_IMMUTABLE: '模板角色由系统维护，不能在此修改。',
    CONFLICT: '角色数据已发生变化，请刷新后重试。',
  }
  return labels[reasonCode] ?? '角色数据已发生变化，请刷新后重试。'
}

async function save(): Promise<void> {
  if (submitting.value) return
  error.value = ''
  warning.value = ''

  if (!hasDiff.value) {
    error.value = '没有需要保存的角色变更。'
    return
  }
  if (reason.value.trim().length < 3) {
    error.value = '请填写至少 3 个字符的变更原因。'
    return
  }
  if (props.userId === null) {
    error.value = '无法识别用户，请刷新后重试。'
    return
  }

  const userId = props.userId
  const trimmedReason = reason.value.trim()

  const bindingAnchors: Record<string, number> = {}
  for (const input of anchorInputsForAddedRoles()) {
    for (const anchor of input.anchors) {
      const raw = anchorValues[anchorKey(input.role.code, anchor)] ?? ''
      const parsed = Number(raw)
      if (!Number.isSafeInteger(parsed) || parsed <= 0) {
        error.value = `请填写“${input.role.name || input.role.code}”的${ANCHOR_LABELS[anchor] ?? anchor}。`
        return
      }
      bindingAnchors[anchor] = parsed
    }
  }

  submitting.value = true

  try {
    for (const code of added.value) {
      const role = roleFor(code)
      const anchors = role === undefined ? [] : requiredAnchors(role)
      const body: Record<string, unknown> = { role_code: code, reason: trimmedReason }
      if (anchors.length > 0) body.binding_anchors = bindingAnchors
      await resourcePost<unknown>(
        `/v1/users/${userId}/roles`,
        body,
        { idempotencyKey: `role-assign-${userId}-${code}-${Date.now()}` },
      )
    }
    for (const code of removed.value) {
      // The shared delete helper carries no request body, so the required
      // audit reason travels as a query input accepted by the same operation.
      await resourceDelete<unknown>(
        `/v1/users/${userId}/roles/${encodeURIComponent(code)}?reason=${encodeURIComponent(trimmedReason)}`,
        { idempotencyKey: `role-revoke-${userId}-${code}-${Date.now()}` },
      )
    }
    emit('saved')
    emit('update:modelValue', false)
  } catch (reasonValue) {
    const stableReason = reasonValue instanceof ApiError ? reasonValue.serverReason : null
    const adminConflict = stableReason === 'LAST_ACTIVE_ADMIN'
      || stableReason === 'LAST_ENABLED_ADMIN_PROTECTED'
      || stableReason === 'PRIVILEGE_ESCALATION_FORBIDDEN'
      || stableReason === 'SUPER_ADMIN_GRANT_FORBIDDEN'
    if (reasonValue instanceof ApiError && (reasonValue.code === 'CONFLICT' || adminConflict)) {
      error.value = translateConflict(stableReason ?? 'CONFLICT')
      emit('saved')
    } else {
      error.value = errorCopy(reasonValue)
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <FormDrawer
    :model-value="props.modelValue"
    :title="`分配角色 · ${props.username}`"
    :confirm-loading="submitting"
    @update:model-value="close"
    @confirm="save"
  >
    <div class="user-role-drawer" data-testid="user-role-drawer">
      <p v-if="error" class="inline-error" role="alert" data-testid="role-error">{{ error }}</p>
      <p v-if="warning" class="inline-warning">{{ warning }}</p>

      <section>
        <h3 class="section-title">当前角色</h3>
        <p v-if="props.currentRoles.length === 0" class="muted">该用户尚未分配角色。</p>
        <ul v-else class="role-list">
          <li v-for="role in props.currentRoles" :key="role.role_code" class="role-list__item">
            <span>{{ roleLabel(role.role_code) }}</span>
            <span v-if="!isSelected(role.role_code)" class="plain-chip plain-chip--danger">待移除</span>
            <span v-else class="plain-chip">保留</span>
          </li>
        </ul>
      </section>

      <section>
        <h3 class="section-title">可分配角色</h3>
        <p v-if="props.roleChoicesPermissionDenied" class="inline-warning">
          当前账号没有读取角色目录的权限，暂时无法分配或移除角色。
        </p>
        <p v-else-if="props.roleChoicesLoading" class="muted">正在加载角色…</p>
        <p v-else-if="props.roleChoices.length === 0" class="muted">没有可分配的角色。</p>
        <ul v-else class="role-list">
          <li v-for="role in props.roleChoices" :key="role.code" class="role-list__item">
            <label class="platform-check">
              <input
                type="checkbox"
                :checked="isSelected(role.code)"
                :data-testid="`role-add-${role.code}`"
                @change="toggle(role.code)"
              />
              {{ role.name }}（{{ role.code }}）
            </label>
            <button
              v-if="isSelected(role.code)"
              type="button"
              class="role-remove"
              :data-testid="props.currentRoles.some((current) => current.role_code === role.code) ? `role-remove-${role.code}` : `role-undo-${role.code}`"
              @click="toggle(role.code)"
            >
              {{ props.currentRoles.some((current) => current.role_code === role.code) ? '移除' : '撤销新增' }}
            </button>
          </li>
        </ul>
      </section>

      <section v-if="anchorInputsForAddedRoles().length > 0" data-testid="role-anchor-inputs">
        <h3 class="section-title">新增角色的授权锚点</h3>
        <p class="muted">角色包含组织/案例/工资批次锚点时，必须在这里填写真实编号；服务端会再次校验。</p>
        <div v-for="input in anchorInputsForAddedRoles()" :key="input.role.code" class="role-anchor-group">
          <h4>{{ input.role.name }}（{{ input.role.code }}）</h4>
          <label v-for="anchor in input.anchors" :key="anchor" class="platform-field">
            <span>{{ ANCHOR_LABELS[anchor] ?? anchor }}</span>
            <input
              v-model="anchorValues[anchorKey(input.role.code, anchor)]"
              type="number"
              min="1"
              :data-testid="`role-anchor-${input.role.code}-${anchor}`"
              placeholder="必填编号"
            />
          </label>
        </div>
      </section>

      <section data-testid="role-diff">
        <h3 class="section-title">变更差异</h3>
        <p v-if="!hasDiff" class="muted">尚未修改角色。选择上方角色后，这里会显示保存前后的差异。</p>
        <div v-else class="diff-grid">
          <div>
            <strong>新增（{{ added.length }}）</strong>
            <p v-if="added.length === 0" class="muted">无</p>
            <p v-for="code in added" :key="`add-${code}`" class="diff-add">+ {{ roleLabel(code) }}</p>
          </div>
          <div>
            <strong>移除（{{ removed.length }}）</strong>
            <p v-if="removed.length === 0" class="muted">无</p>
            <p v-for="code in removed" :key="`remove-${code}`" class="diff-remove">- {{ roleLabel(code) }}</p>
          </div>
        </div>
      </section>

      <section v-if="riskWarnings.length > 0" class="risk-box" aria-live="polite">
        <h3 class="section-title">风险提示</h3>
        <p v-for="message in riskWarnings" :key="message">{{ message }}</p>
      </section>

      <label class="platform-field">
        <span>变更原因 *</span>
        <input v-model="reason" data-testid="role-reason" maxlength="255" placeholder="至少 3 个字符，例如：岗位调整" />
      </label>
    </div>
  </FormDrawer>
</template>

<style scoped>
.user-role-drawer {
  display: flex;
  flex-direction: column;
  gap: 18px;
}
.role-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin: 0;
  padding: 0;
  list-style: none;
}
.role-list__item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}
.role-remove {
  border: none;
  background: none;
  color: var(--el-color-primary);
  cursor: pointer;
  padding: 0;
}
.diff-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
}
.diff-add {
  color: var(--el-color-success);
}
.diff-remove {
  color: var(--el-color-danger);
}
.risk-box {
  border: 1px solid var(--el-color-warning-light-5);
  background: var(--el-color-warning-light-9);
  border-radius: 6px;
  padding: 12px;
}
</style>
