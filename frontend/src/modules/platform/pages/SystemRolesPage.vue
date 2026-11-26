<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { resourceGet, resourcePut } from '@/api/resourceClient'
import { rowText, rowsFrom, textOf, type PlatformRow } from '../platformRead'

const ANCHOR_LABELS: Record<string, string> = {
  SELF: '仅本人',
  MANAGED: '下属',
  ORG_UNIT: '本部门',
  ORG_SUBTREE: '本部门及子部门',
  CASE: '指定案例',
  PAYROLL_RUN: '指定工资批次',
  COMPANY: '本公司',
}
const PROFILES = ['P0_PUBLIC', 'P1_INTERNAL', 'P2_SENSITIVE', 'P3_RESTRICTED'] as const
const HIGH_RISK_CAPABILITIES = new Set(['*', 'iam.binding.manage'])

const roles = ref<PlatformRow[]>([])
const capabilities = ref<PlatformRow[]>([])
const cataloguePages = ref<PlatformRow[]>([])
const rolePages = ref<PlatformRow[]>([])
const selectedRole = ref<PlatformRow | null>(null)
const activeTab = ref<'pages' | 'buttons' | 'scopes' | 'fields'>('pages')
const selectedPageCodes = ref<string[]>([])
const selectedPageForButtons = ref('')
const selectedCapabilities = ref<string[]>([])
const anchorByCapability = ref<Record<string, string>>({})
const fieldProfileByCapability = ref<Record<string, string>>({})
const diffOpen = ref(false)
const highRiskReason = ref('')
const error = ref<string | null>(null)
const message = ref('')
const loading = ref(false)

const originalPageCodes = ref<string[]>([])
const originalCapabilities = ref<string[]>([])

const selectedRoleCode = computed(() => textOf(selectedRole.value?.code ?? '', ''))

const pageGroups = computed(() => {
  const groups = new Map<string, PlatformRow[]>()
  for (const page of cataloguePages.value) {
    const group = rowText(page, 'menu_group', '其他')
    const list = groups.get(group) ?? []
    list.push(page)
    groups.set(group, list)
  }
  return [...groups.entries()].map(([label, pages]) => ({ key: label, label, pages }))
})

const selectedPages = computed(() =>
  cataloguePages.value.filter((page) => selectedPageCodes.value.includes(rowText(page, 'page_code', ''))),
)

const actionsForSelectedPage = computed(() => {
  const page = selectedPages.value.find((candidate) => rowText(candidate, 'page_code', '') === selectedPageForButtons.value)
  if (page === undefined) return []
  const actions = page.actions
  if (Array.isArray(actions)) {
    return actions.filter((action): action is PlatformRow => typeof action === 'object' && action !== null)
  }
  const required = rowText(page, 'required_capability', '')
  return required === '' || required === 'public'
    ? []
    : [{ capability: required, label: rowText(page, 'menu_label', required) }]
})

const diff = computed(() => ({
  pagesAdded: selectedPageCodes.value.filter((code) => !originalPageCodes.value.includes(code)),
  pagesRemoved: originalPageCodes.value.filter((code) => !selectedPageCodes.value.includes(code)),
  capabilitiesAdded: selectedCapabilities.value.filter((code) => !originalCapabilities.value.includes(code)),
  capabilitiesRemoved: originalCapabilities.value.filter((code) => !selectedCapabilities.value.includes(code)),
}))

const hasDiff = computed(
  () =>
    diff.value.pagesAdded.length +
      diff.value.pagesRemoved.length +
      diff.value.capabilitiesAdded.length +
      diff.value.capabilitiesRemoved.length >
    0,
)

const needsHighRiskReason = computed(() =>
  [...diff.value.capabilitiesAdded, ...selectedCapabilities.value].some(
    (code) =>
      HIGH_RISK_CAPABILITIES.has(code) || (fieldProfileByCapability.value[code] ?? '') === 'P3_RESTRICTED',
  ),
)

const capabilityByCode = computed(() => new Map(capabilities.value.map((row) => [rowText(row, 'code', ''), row])))

function pageLabel(code: string): string {
  const page = cataloguePages.value.find((candidate) => rowText(candidate, 'page_code', '') === code)
  if (page === undefined) return code
  return `${rowText(page, 'menu_label', code)} (${rowText(page, 'path', code)})`
}

function anchorOptions(code: string): string[] {
  const anchors = capabilityByCode.value.get(code)?.anchor_types
  return Array.isArray(anchors) ? anchors.filter((anchor): anchor is string => typeof anchor === 'string') : ['COMPANY']
}

function profileOptions(code: string): string[] {
  const row = capabilityByCode.value.get(code)
  const max = row === undefined ? 'P0_PUBLIC' : rowText(row, 'field_profile_max', 'P0_PUBLIC')
  const maxRank = PROFILES.indexOf(max as (typeof PROFILES)[number])
  return PROFILES.filter((profile) => maxRank >= 0 && PROFILES.indexOf(profile) <= maxRank)
}

function isGroupFullySelected(group: { pages: PlatformRow[] }): boolean {
  return group.pages.every((page) => selectedPageCodes.value.includes(rowText(page, 'page_code', '')))
}

function toggleGroup(group: { pages: PlatformRow[] }, checked: boolean): void {
  const codes = group.pages.map((page) => rowText(page, 'page_code', ''))
  selectedPageCodes.value = checked
    ? [...new Set([...selectedPageCodes.value, ...codes])]
    : selectedPageCodes.value.filter((code) => !codes.includes(code))
}

function toggleCapability(code: string, checked: boolean): void {
  if (checked) {
    selectedCapabilities.value = [...new Set([...selectedCapabilities.value, code])]
    anchorByCapability.value[code] ??= anchorOptions(code)[0] ?? 'COMPANY'
    fieldProfileByCapability.value[code] ??= 'P0_PUBLIC'
    return
  }
  selectedCapabilities.value = selectedCapabilities.value.filter((item) => item !== code)
}

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const [rolePayload, capabilityPayload, permissionPayload] = await Promise.all([
      resourceGet<unknown>('/v1/roles'),
      resourceGet<unknown>('/v1/capabilities'),
      resourceGet<unknown>('/v1/pages/catalog'),
    ])
    roles.value = rowsFrom(rolePayload, 'roles')
    capabilities.value = rowsFrom(capabilityPayload, 'capabilities')
    cataloguePages.value = rowsFrom(permissionPayload, 'pages')
    const first = roles.value.find((role) => role.is_template !== true) ?? roles.value[0]
    if (first !== undefined) await selectRole(first)
  } catch (reason) {
    error.value = reason instanceof Error ? reason.message : '加载角色失败'
  } finally {
    loading.value = false
  }
}

async function selectRole(role: PlatformRow): Promise<void> {
  selectedRole.value = role
  diffOpen.value = false
  highRiskReason.value = ''
  const code = textOf(role.code, '')
  if (code === '') return

  const payload = await resourceGet<unknown>(`/v1/roles/${encodeURIComponent(code)}/pages`)
  rolePages.value = rowsFrom(payload, 'pages')
  originalPageCodes.value = rolePages.value.map((page) => rowText(page, 'page_code', ''))
  selectedPageCodes.value = [...originalPageCodes.value]

  const grants = Array.isArray(role.capabilities)
    ? role.capabilities.filter((grant): grant is PlatformRow => typeof grant === 'object' && grant !== null)
    : []
  originalCapabilities.value = grants.map((grant) => rowText(grant, 'capability', ''))
  selectedCapabilities.value = [...originalCapabilities.value]
  anchorByCapability.value = {}
  fieldProfileByCapability.value = {}
  for (const grant of grants) {
    const capability = rowText(grant, 'capability', '')
    anchorByCapability.value[capability] = rowText(grant, 'anchor_type', 'COMPANY')
    fieldProfileByCapability.value[capability] = rowText(grant, 'field_profile', 'P0_PUBLIC')
  }
  selectedPageForButtons.value = originalPageCodes.value[0] ?? ''
  activeTab.value = 'pages'
}

function requestSave(): void {
  message.value = ''
  error.value = null
  if (!hasDiff.value) {
    message.value = '没有变更。'
    return
  }
  diffOpen.value = true
}

async function confirmSave(): Promise<void> {
  if (selectedRole.value === null) return
  if (needsHighRiskReason.value && highRiskReason.value.trim().length < 8) {
    error.value = '高风险变更需要至少 8 个字符的原因。'
    return
  }
  const code = selectedRoleCode.value
  try {
    await resourcePut<unknown>(
      `/v1/roles/${encodeURIComponent(code)}/pages`,
      { pages: selectedPageCodes.value },
      { idempotencyKey: `role-pages-${code}-${Date.now()}` },
    )

    if (selectedCapabilities.value.length > 0) {
      await resourcePut<unknown>(
        `/v1/roles/${encodeURIComponent(code)}/capabilities`,
        {
          capabilities: selectedCapabilities.value.map((capability) => ({
            capability,
            anchor_type: anchorByCapability.value[capability] ?? 'COMPANY',
            field_profile: fieldProfileByCapability.value[capability] ?? 'P0_PUBLIC',
          })),
        },
        { idempotencyKey: `role-capabilities-${code}-${Date.now()}` },
      )
    }

    originalPageCodes.value = [...selectedPageCodes.value]
    originalCapabilities.value = [...selectedCapabilities.value]
    diffOpen.value = false
    message.value = '角色授权已保存并重放受影响用户的快照。'
    await load()
  } catch (reason) {
    error.value = reason instanceof Error ? reason.message : '保存失败'
  }
}

onMounted(load)
</script>

<template>
  <div class="page-stack" data-testid="system-roles-page">
    <header class="page-header">
      <div>
        <h1>角色与权限矩阵</h1>
        <p class="muted">
          页面权限来自 role_pages；按钮、数据范围与字段权限来自同一组 (capability, anchor_type, field_profile) 授权。
        </p>
      </div>
    </header>

    <p v-if="error" class="inline-error" role="alert">{{ error }}</p>
    <p v-if="message" class="inline-success">{{ message }}</p>
    <p v-if="loading" class="muted">加载中…</p>

    <section class="panel">
      <div class="panel__header"><h2 class="panel__title">角色</h2></div>
      <div class="platform-chip-row platform-panel-body">
        <button
          v-for="role in roles"
          :key="textOf(role.code)"
          type="button"
          class="plain-chip"
          :class="{ 'plain-chip--active': textOf(role.code) === selectedRoleCode }"
          @click="selectRole(role)"
        >
          {{ textOf(role.name) }} ({{ textOf(role.code) }}){{ role.is_template === true ? ' · 模板只读' : '' }}
        </button>
      </div>
    </section>

    <section v-if="selectedRole" class="panel">
      <div class="panel__header"><h2 class="panel__title">{{ textOf(selectedRole.name) }} 的授权</h2></div>

      <nav class="platform-tabs" role="tablist">
        <button type="button" role="tab" data-testid="tab-pages" :aria-selected="activeTab === 'pages'" @click="activeTab = 'pages'">页面权限</button>
        <button type="button" role="tab" data-testid="tab-buttons" :aria-selected="activeTab === 'buttons'" @click="activeTab = 'buttons'">按钮权限</button>
        <button type="button" role="tab" data-testid="tab-scopes" :aria-selected="activeTab === 'scopes'" @click="activeTab = 'scopes'">数据权限</button>
        <button type="button" role="tab" data-testid="tab-fields" :aria-selected="activeTab === 'fields'" @click="activeTab = 'fields'">字段权限</button>
      </nav>

      <div v-if="activeTab === 'pages'" class="panel__body stack">
        <details v-for="group in pageGroups" :key="group.key" open>
          <summary>
            <label>
              <input
                type="checkbox"
                :checked="isGroupFullySelected(group)"
                @change="toggleGroup(group, ($event.target as HTMLInputElement).checked)"
              />
              {{ group.label }}
            </label>
          </summary>
          <label v-for="page in group.pages" :key="rowText(page, 'page_code')" class="platform-check">
            <input
              v-model="selectedPageCodes"
              type="checkbox"
              :value="rowText(page, 'page_code')"
              :data-page-code="rowText(page, 'page_code')"
            />
            {{ rowText(page, 'menu_label') }}
            <span class="mono">{{ rowText(page, 'path') }}</span>
          </label>
        </details>
        <p v-if="pageGroups.length === 0" class="muted">页面目录为空。</p>
      </div>

      <div v-else-if="activeTab === 'buttons'" class="panel__body stack">
        <label class="platform-field">
          <span>选择已授权页面</span>
          <select v-model="selectedPageForButtons">
            <option v-for="page in selectedPages" :key="rowText(page, 'page_code')" :value="rowText(page, 'page_code')">
              {{ rowText(page, 'menu_label') }}
            </option>
          </select>
        </label>
        <label v-for="action in actionsForSelectedPage" :key="rowText(action, 'capability')" class="platform-check">
          <input
            type="checkbox"
            :checked="selectedCapabilities.includes(rowText(action, 'capability'))"
            :data-capability="rowText(action, 'capability')"
            @change="toggleCapability(rowText(action, 'capability'), ($event.target as HTMLInputElement).checked)"
          />
          {{ rowText(action, 'label', rowText(action, 'capability')) }}
          <span class="mono">{{ rowText(action, 'capability') }}</span>
        </label>
        <p v-if="actionsForSelectedPage.length === 0" class="muted">该页面未声明按钮动作。</p>
      </div>

      <div v-else-if="activeTab === 'scopes'" class="panel__body stack">
        <p class="muted">每个动作只允许能力目录声明的锚点类型；父菜单不隐含子页，数据范围也不跨越公司实例。</p>
        <div v-for="capability in selectedCapabilities" :key="capability" class="platform-form-grid">
          <span class="mono">{{ capability }}</span>
          <select v-model="anchorByCapability[capability]">
            <option v-for="anchor in anchorOptions(capability)" :key="anchor" :value="anchor">{{ ANCHOR_LABELS[anchor] ?? anchor }}</option>
          </select>
        </div>
        <p v-if="selectedCapabilities.length === 0" class="muted">先在“按钮权限”中选择动作。</p>
      </div>

      <div v-else class="panel__body stack">
        <p class="muted">字段上限不得超过能力目录的 field_profile_max；P3 需要单独的高风险原因。</p>
        <div v-for="capability in selectedCapabilities" :key="capability" class="platform-form-grid">
          <span class="mono">{{ capability }}</span>
          <select v-model="fieldProfileByCapability[capability]">
            <option v-for="profile in profileOptions(capability)" :key="profile" :value="profile">{{ profile }}</option>
          </select>
        </div>
        <p v-if="selectedCapabilities.length === 0" class="muted">先在“按钮权限”中选择动作。</p>
      </div>

      <div class="panel__body">
        <el-button type="primary" data-testid="save-role" @click="requestSave">保存授权</el-button>
      </div>
    </section>

    <section v-if="diffOpen" class="panel" data-testid="role-diff">
      <div class="panel__header"><h2 class="panel__title">保存前差异预览</h2></div>
      <div class="panel__body stack">
        <p>新增页面：{{ diff.pagesAdded.map(pageLabel).join('、') || '无' }}</p>
        <p>移除页面：{{ diff.pagesRemoved.map(pageLabel).join('、') || '无' }}</p>
        <p>新增动作：{{ diff.capabilitiesAdded.join('、') || '无' }}</p>
        <p>移除动作：{{ diff.capabilitiesRemoved.join('、') || '无' }}</p>
        <label v-if="needsHighRiskReason" class="platform-field">
          <span>高风险变更原因（至少 8 个字符）</span>
          <input v-model="highRiskReason" data-testid="high-risk-reason" />
        </label>
        <el-button type="danger" data-testid="confirm-save-role" @click="confirmSave">确认保存</el-button>
      </div>
    </section>
  </div>
</template>
