<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ApiError, errorCopy } from '@/api/errors'
import { resourceDelete, resourceGet, resourcePatch, resourcePost, resourcePut } from '@/api/resourceClient'
import { PAGE_REGISTRY } from '@/router/registry'
import { useSessionStore } from '@/stores/session'
import { nextIdempotencyKey, rowText, rowsFrom, type PlatformRow } from '../platformRead'
import RolePageTree, { type RolePageOption } from '../components/RolePageTree.vue'
import RoleActionMatrix, {
  type RoleActionCapability,
  type RoleActionGrant,
} from '../components/RoleActionMatrix.vue'
import RoleScopeEditor, {
  type OrgUnitOption,
  type ScopeCapability,
  type ScopeGrant,
} from '../components/RoleScopeEditor.vue'
import RoleFieldPolicyEditor, {
  type FieldPolicyCapability,
  type FieldPolicyGrant,
} from '../components/RoleFieldPolicyEditor.vue'
import { DISPLAY_CODES, translate } from '@/shared/display/dictionary'

type TabKey = 'pages' | 'actions' | 'scopes' | 'fields'
type FormMode = 'create' | 'rename' | 'copy' | 'delete'

type ActionGrant = RoleActionGrant
type PendingPageRemoval = {
  nextPageCodes: string[]
  removedPageCodes: string[]
  affectedActions: string[]
}

type DiffCounts = {
  pages_added: number
  pages_removed: number
  actions_added: number
  actions_removed: number
  scopes_changed: number
  fields_changed: number
}

const ROLE_MANAGE_CAPABILITY = 'iam.role.manage'
const ORG_ANCHORS = new Set<string>(['ORG_UNIT', 'ORG_SUBTREE'])
const ID_ANCHORS = new Set<string>(['CASE', 'PAYROLL_RUN'])

const session = useSessionStore()
const canManage = computed(() => session.can(ROLE_MANAGE_CAPABILITY))

const roles = ref<PlatformRow[]>([])
const capabilities = ref<PlatformRow[]>([])
const orgUnits = ref<OrgUnitOption[]>([])
const orgUnitsUnavailable = ref(false)
const selectedRole = ref<PlatformRow | null>(null)
const activeTab = ref<TabKey>('pages')

const selectedPageCodes = ref<string[]>([])
const selectedCapabilities = ref<string[]>([])
const anchorByCapability = ref<Record<string, string>>({})
const anchorIdByCapability = ref<Record<string, number | null>>({})
const fieldProfileByCapability = ref<Record<string, string>>({})
const pageForActions = ref('')

const originalPageCodes = ref<string[]>([])
const originalAnchorByCapability = ref<Record<string, string>>({})
const originalAnchorIdByCapability = ref<Record<string, number | null>>({})
const originalFieldProfileByCapability = ref<Record<string, string>>({})

const loading = ref(false)
const saving = ref(false)
const forbidden = ref(false)
const loadError = ref<string | null>(null)
const actionMessage = ref('')
const actionError = ref('')
const conflictMessage = ref('')
const diffOpen = ref(false)
const pendingPageRemoval = ref<PendingPageRemoval | null>(null)

const formMode = ref<FormMode | null>(null)
const formError = ref('')
const formSubmitting = ref(false)
const form = reactive({
  code: '',
  name: '',
  purpose: '',
  sourceCode: '',
})
const deleteTarget = ref<PlatformRow | null>(null)

const ANCHOR_LABELS: Record<string, string> = {
  SELF: '仅本人',
  MANAGED: '下属',
  ORG_UNIT: '本部门',
  ORG_SUBTREE: '本部门及子部门',
  COMPANY: '本公司',
  CASE: '指定案例',
  PAYROLL_RUN: '指定工资批次',
}

const selectedRoleCode = computed(() => rowText(selectedRole.value ?? {}, 'code', ''))
const selectedRoleIsTemplate = computed(() => selectedRole.value?.is_template === true)

/**
 * The editor always derives the catalogue from the read-only registry. The
 * backend page projection is used only for the role's currently granted codes,
 * never to invent action rows.
 */
const cataloguePages = computed<RolePageOption[]>(() => {
  const byName = new Map(PAGE_REGISTRY.map((page) => [page.name, page]))
  return PAGE_REGISTRY.map((page) => {
    const parent = page.detailOf === undefined ? undefined : byName.get(page.detailOf)
    const menu = page.menu ?? parent?.menu ?? null
    return {
      page_code: page.name,
      path: page.path,
      menu_group: menu?.group ?? (page.kind === 'base' ? '工作台与安全' : '其他页面'),
      menu_label:
        page.menu?.label ??
        (parent?.menu === null || parent?.menu === undefined
          ? page.name
          : `${parent.menu.label} · ${page.name}`),
      order: page.menu?.order ?? parent?.menu?.order ?? 999,
    }
  })
})

const selectedCapabilitySet = computed(() => new Set(selectedCapabilities.value))
const capabilityByCode = computed(() => new Map(capabilities.value.map((row) => [rowText(row, 'code', ''), row])))

const actionCapabilities = computed<RoleActionCapability[]>(() =>
  capabilities.value.map((row) => ({
    code: rowText(row, 'code', ''),
    anchor_types: stringArray(row.anchor_types),
    field_profile_max: rowText(row, 'field_profile_max', DISPLAY_CODES.fieldProfile.p0Public),
  })),
)

const scopeCapabilities = computed<ScopeCapability[]>(() =>
  capabilities.value.map((row) => ({
    code: rowText(row, 'code', ''),
    anchor_types: stringArray(row.anchor_types),
  })),
)

const fieldCapabilities = computed<FieldPolicyCapability[]>(() =>
  capabilities.value.map((row) => ({
    code: rowText(row, 'code', ''),
    field_profile_max: rowText(row, 'field_profile_max', DISPLAY_CODES.fieldProfile.p0Public),
  })),
)

const selectedActionGrants = computed<ActionGrant[]>(() =>
  selectedCapabilities.value.map((capability) => ({
    capability,
    anchor_type: anchorByCapability.value[capability] ?? defaultAnchor(capability),
    anchor_id: anchorIdByCapability.value[capability] ?? null,
    field_profile: fieldProfileByCapability.value[capability] ?? DISPLAY_CODES.fieldProfile.p0Public,
  })),
)

const scopeGrants = computed<ScopeGrant[]>(() => selectedActionGrants.value)
const fieldGrants = computed<FieldPolicyGrant[]>(() =>
  selectedCapabilities.value.map((capability) => ({
    capability,
    field_key: capability,
    field_profile: fieldProfileByCapability.value[capability] ?? DISPLAY_CODES.fieldProfile.p0Public,
  })),
)

const actionPageOptions = computed(() => cataloguePages.value)

const diff = computed<DiffCounts>(() => {
  const pagesAdded = selectedPageCodes.value.filter((code) => !originalPageCodes.value.includes(code))
  const pagesRemoved = originalPageCodes.value.filter((code) => !selectedPageCodes.value.includes(code))
  const actionsAdded = selectedCapabilities.value.filter((code) => !hasOriginalCapability(code))
  const actionsRemoved = Object.keys(originalAnchorByCapability.value).filter(
    (code) => !selectedCapabilitySet.value.has(code),
  )
  const scopesChanged = selectedCapabilities.value.filter((code) => {
    if (!hasOriginalCapability(code)) return false
    const anchorChanged = (originalAnchorByCapability.value[code] ?? '') !== (anchorByCapability.value[code] ?? '')
    const anchorIdChanged = (originalAnchorIdByCapability.value[code] ?? null) !== (anchorIdByCapability.value[code] ?? null)
    return anchorChanged || anchorIdChanged
  })
  const fieldsChanged = selectedCapabilities.value.filter((code) => {
    if (!hasOriginalCapability(code)) return false
    return (originalFieldProfileByCapability.value[code] ?? DISPLAY_CODES.fieldProfile.p0Public) !== (fieldProfileByCapability.value[code] ?? DISPLAY_CODES.fieldProfile.p0Public)
  })

  return {
    pages_added: pagesAdded.length,
    pages_removed: pagesRemoved.length,
    actions_added: actionsAdded.length,
    actions_removed: actionsRemoved.length,
    scopes_changed: scopesChanged.length,
    fields_changed: fieldsChanged.length,
  }
})

const hasDiff = computed(() => Object.values(diff.value).some((count) => count > 0))



function stringArray(value: unknown): string[] {
  return Array.isArray(value) ? value.filter((entry): entry is string => typeof entry === 'string') : []
}

function defaultAnchor(capability: string): string {
  const declared = stringArray(capabilityByCode.value.get(capability)?.anchor_types)
  return ['SELF', 'MANAGED', 'ORG_UNIT', 'ORG_SUBTREE', 'COMPANY', 'CASE', 'PAYROLL_RUN'].find((anchor) =>
    declared.includes(anchor),
  ) ?? 'COMPANY'
}

function hasOriginalCapability(capability: string): boolean {
  return Object.prototype.hasOwnProperty.call(originalAnchorByCapability.value, capability)
}

function isForbidden(reason: unknown): boolean {
  return reason instanceof ApiError && (reason.code === 'FORBIDDEN' || reason.status === 403)
}

function actionOfCapability(capability: string): string {
  const action = cataloguedAction(capability)
  return action === null ? capability : action.label
}

function cataloguedAction(capability: string): { label: string } | null {
  for (const page of cataloguePages.value) {
    const actions = PAGE_REGISTRY.find((entry) => entry.name === page.page_code)?.actions ?? []
    const match = actions.find((action) => action.capability === capability)
    if (match !== undefined) return { label: match.label }
  }
  return null
}

function capabilitiesForRole(role: PlatformRow): PlatformRow[] {
  const value = role.capabilities
  return Array.isArray(value)
    ? value.filter((grant): grant is PlatformRow => typeof grant === 'object' && grant !== null)
    : []
}

function resetDraft(): void {
  diffOpen.value = false
  pendingPageRemoval.value = null
  actionError.value = ''
  actionMessage.value = ''
  conflictMessage.value = ''
  selectedCapabilities.value = []
  anchorByCapability.value = {}
  anchorIdByCapability.value = {}
  fieldProfileByCapability.value = {}
}

function applyRole(role: PlatformRow, rolePagesPayload: unknown): void {
  resetDraft()
  selectedRole.value = role
  const payload = rolePagesPayload !== null && typeof rolePagesPayload === 'object'
    ? (rolePagesPayload as PlatformRow)
    : {}
  const pageCodes = stringArray(payload.page_codes)
  const pages = rowsFrom(payload, 'pages')
  const grantedCodes = pageCodes.length > 0
    ? pageCodes
    : pages.map((page) => rowText(page, 'page_code', '')).filter((code) => code !== '')
  originalPageCodes.value = [...new Set(grantedCodes)].sort((left, right) => left.localeCompare(right))
  selectedPageCodes.value = [...originalPageCodes.value]
  pageForActions.value = originalPageCodes.value[0] ?? ''

  const grants = capabilitiesForRole(role)
  const anchors: Record<string, string> = {}
  const anchorIds: Record<string, number | null> = {}
  const profiles: Record<string, string> = {}
  for (const grant of grants) {
    const capability = rowText(grant, 'capability', '')
    if (capability === '') continue
    anchors[capability] = rowText(grant, 'anchor_type', defaultAnchor(capability))
    const anchorId = grant.anchor_id
    anchorIds[capability] = typeof anchorId === 'number' ? anchorId : null
    profiles[capability] = rowText(grant, 'field_profile', DISPLAY_CODES.fieldProfile.p0Public)
  }
  originalAnchorByCapability.value = { ...anchors }
  originalAnchorIdByCapability.value = { ...anchorIds }
  originalFieldProfileByCapability.value = { ...profiles }
  selectedCapabilities.value = Object.keys(anchors).sort((left, right) => left.localeCompare(right))
  anchorByCapability.value = { ...anchors }
  anchorIdByCapability.value = { ...anchorIds }
  fieldProfileByCapability.value = { ...profiles }
}

async function load(): Promise<void> {
  if (!canManage.value) {
    forbidden.value = true
    loading.value = false
    return
  }

  forbidden.value = false
  loading.value = true
  loadError.value = null
  try {
    const [rolePayload, capabilityPayload] = await Promise.all([
      resourceGet<unknown>('/v1/roles'),
      resourceGet<unknown>('/v1/capabilities'),
    ])
    roles.value = rowsFrom(rolePayload, 'roles')
    capabilities.value = rowsFrom(capabilityPayload, 'capabilities')
    const previousCode = selectedRoleCode.value
    const target =
      roles.value.find((role) => rowText(role, 'code', '') === previousCode) ??
      roles.value.find((role) => role.is_template !== true) ??
      roles.value[0]
    if (target === undefined) {
      selectedRole.value = null
      resetDraft()
      originalPageCodes.value = []
      return
    }
    await selectRoleInternal(target)
  } catch (reason) {
    if (isForbidden(reason)) {
      forbidden.value = true
      roles.value = []
      capabilities.value = []
    } else {
      loadError.value = errorCopy(reason)
    }
  } finally {
    loading.value = false
  }

  try {
    const orgPayload = await resourceGet<unknown>('/v1/org-units/tree')
    orgUnits.value = rowsFrom(orgPayload).map((row) => ({
      id: Number(row.id),
      parent_id: typeof row.parent_id === 'number' ? row.parent_id : null,
      code: typeof row.code === 'string' ? row.code : null,
      name: typeof row.name === 'string' ? row.name : null,
      type: typeof row.type === 'string' ? row.type : null,
    }))
    orgUnitsUnavailable.value = false
  } catch {
    orgUnits.value = []
    orgUnitsUnavailable.value = true
  }
}

async function selectRoleInternal(role: PlatformRow): Promise<void> {
  const code = rowText(role, 'code', '')
  if (code === '') return
  const payload = await resourceGet<unknown>(`/v1/roles/${encodeURIComponent(code)}/pages`)
  applyRole(role, payload)
}

async function selectRole(role: PlatformRow): Promise<void> {
  if (saving.value) return
  actionError.value = ''
  actionMessage.value = ''
  try {
    await selectRoleInternal(role)
  } catch (reason) {
    if (isForbidden(reason)) {
      forbidden.value = true
    } else {
      actionError.value = errorCopy(reason)
    }
  }
}

function updatePages(nextPageCodes: string[]): void {
  const removed = selectedPageCodes.value.filter((code) => !nextPageCodes.includes(code))
  const removedWithActions = removed.filter((pageCode) => pageActions(pageCode).some((action) => selectedCapabilitySet.value.has(action)))
  if (removedWithActions.length === 0) {
    selectedPageCodes.value = nextPageCodes
    if (!nextPageCodes.includes(pageForActions.value)) pageForActions.value = nextPageCodes[0] ?? ''
    return
  }
  pendingPageRemoval.value = {
    nextPageCodes,
    removedPageCodes: removedWithActions,
    affectedActions: removedWithActions.flatMap((pageCode) =>
      pageActions(pageCode).filter((action) => selectedCapabilitySet.value.has(action)),
    ),
  }
}

function pageActions(pageCode: string): string[] {
  const page = PAGE_REGISTRY.find((entry) => entry.name === pageCode)
  if (page === undefined) return []
  return page.actions.map((action) => action.capability)
}

function confirmClearPageActions(): void {
  const pending = pendingPageRemoval.value
  if (pending === null) return
  const affected = new Set(pending.affectedActions)
  selectedCapabilities.value = selectedCapabilities.value.filter((capability) => !affected.has(capability))
  for (const action of affected) {
    delete anchorByCapability.value[action]
    delete anchorIdByCapability.value[action]
    delete fieldProfileByCapability.value[action]
  }
  selectedPageCodes.value = pending.nextPageCodes
  if (!pending.nextPageCodes.includes(pageForActions.value)) {
    pageForActions.value = pending.nextPageCodes[0] ?? ''
  }
  pendingPageRemoval.value = null
}

function dismissPageRemoval(): void {
  pendingPageRemoval.value = null
}

function removePageKeepingActions(): void {
  const pending = pendingPageRemoval.value
  if (pending === null) return
  selectedPageCodes.value = pending.nextPageCodes
  if (!pending.nextPageCodes.includes(pageForActions.value)) {
    pageForActions.value = pending.nextPageCodes[0] ?? ''
  }
  pendingPageRemoval.value = null
}

function ensureCapabilitySelected(capability: string): void {
  if (!selectedCapabilities.value.includes(capability)) {
    selectedCapabilities.value = [...selectedCapabilities.value, capability].sort((left, right) =>
      left.localeCompare(right),
    )
  }
  anchorByCapability.value[capability] ??= defaultAnchor(capability)
  anchorIdByCapability.value[capability] ??= null
  fieldProfileByCapability.value[capability] ??= DISPLAY_CODES.fieldProfile.p0Public
}

function onActionToggle(action: { capability: string }, checked: boolean, pageCode: string): void {
  if (checked) {
    if (pageCode !== '') {
      selectedPageCodes.value = [...new Set([...selectedPageCodes.value, pageCode])].sort((left, right) =>
        left.localeCompare(right),
      )
    }
    ensureCapabilitySelected(action.capability)
    return
  }
  selectedCapabilities.value = selectedCapabilities.value.filter((capability) => capability !== action.capability)
  delete anchorByCapability.value[action.capability]
  delete anchorIdByCapability.value[action.capability]
  delete fieldProfileByCapability.value[action.capability]
}

function onAnchorChange(payload: { capability: string; anchor_type: string; anchor_id: number | null }): void {
  ensureCapabilitySelected(payload.capability)
  anchorByCapability.value[payload.capability] = payload.anchor_type
  anchorIdByCapability.value[payload.capability] = ORG_ANCHORS.has(payload.anchor_type) || ID_ANCHORS.has(payload.anchor_type)
    ? payload.anchor_id
    : null
}

function onAnchorChangeFromMatrix(payload: { capability: string; anchor_type: string }): void {
  onAnchorChange({ ...payload, anchor_id: anchorIdByCapability.value[payload.capability] ?? null })
}

function onFieldProfileChange(payload: { capability: string; field_profile: string }): void {
  ensureCapabilitySelected(payload.capability)
  fieldProfileByCapability.value[payload.capability] = payload.field_profile
}

function requestSave(): void {
  actionError.value = ''
  conflictMessage.value = ''
  actionMessage.value = ''
  if (!hasDiff.value) {
    actionMessage.value = '没有需要保存的角色授权变更。'
    return
  }
  if (invalidAnchorAction.value !== null) {
    actionError.value = `请为“${actionOfCapability(invalidAnchorAction.value.capability)}”填写数据范围锚点编号，再保存授权。`
    return
  }
  diffOpen.value = true
}

function saveCapabilityGrants(): Array<{ capability: string; anchor_type: string; anchor_id: number | null; field_profile: string }> {
  return selectedCapabilities.value.map((capability) => ({
    capability,
    anchor_type: anchorByCapability.value[capability] ?? defaultAnchor(capability),
    anchor_id: ORG_ANCHORS.has(anchorByCapability.value[capability] ?? '') || ID_ANCHORS.has(anchorByCapability.value[capability] ?? '')
      ? (anchorIdByCapability.value[capability] ?? null)
      : null,
    field_profile: fieldProfileByCapability.value[capability] ?? DISPLAY_CODES.fieldProfile.p0Public,
  }))
}

async function confirmSave(): Promise<void> {
  if (saving.value || selectedRole.value === null) return
  if (!canManage.value) {
    actionError.value = '当前账号没有保存角色授权的权限。'
    return
  }

  const code = selectedRoleCode.value
  if (code === '') return
  saving.value = true
  actionError.value = ''
  conflictMessage.value = ''
  try {
    await resourcePut<unknown>(
      `/v1/roles/${encodeURIComponent(code)}/authorization`,
      {
        pages: [...selectedPageCodes.value],
        capabilities: saveCapabilityGrants(),
        reason: '角色授权工作台保存',
      },
      { idempotencyKey: nextIdempotencyKey(`role-authorization-${code}`) },
    )
    diffOpen.value = false
    actionMessage.value = '角色授权已原子保存，页面、动作、数据范围与字段权限同时生效。'
    await load()
  } catch (reason) {
    if (reason instanceof ApiError && reason.code === 'CONFLICT') {
      diffOpen.value = false
      try {
        await load()
      } catch {
        // The conflict message remains actionable even if the refetch fails.
      }
      conflictMessage.value = '角色授权已被其他管理员修改，已重新加载最新授权，请复核差异后再次提交。'
    } else if (isForbidden(reason)) {
      forbidden.value = true
      actionError.value = '无权保存角色授权。如需继续，请提交授权申请。'
    } else {
      actionError.value = errorCopy(reason)
    }
  } finally {
    saving.value = false
  }
}

function openCreateForm(): void {
  if (!canManage.value) return
  formMode.value = 'create'
  formError.value = ''
  form.code = ''
  form.name = ''
  form.purpose = ''
  const source = roles.value.find((role) => capabilitiesForRole(role).length > 0)
  form.sourceCode = source === undefined ? '' : rowText(source, 'code', '')
}

function openRenameForm(role: PlatformRow): void {
  if (!canManage.value || role.is_template === true) return
  formMode.value = 'rename'
  formError.value = ''
  form.code = rowText(role, 'code', '')
  form.name = rowText(role, 'name', '')
  form.purpose = rowText(role, 'purpose', '')
}

function openCopyForm(role: PlatformRow): void {
  if (!canManage.value) return
  formMode.value = 'copy'
  formError.value = ''
  form.code = ''
  form.name = `${rowText(role, 'name', rowText(role, 'code'))}（副本）`
  form.purpose = rowText(role, 'purpose', '')
  form.sourceCode = rowText(role, 'code', '')
}

function openDeleteForm(role: PlatformRow): void {
  if (!canManage.value || role.is_template === true) return
  formMode.value = 'delete'
  formError.value = ''
  deleteTarget.value = role
}

function closeForm(): void {
  if (formSubmitting.value) return
  formMode.value = null
  formError.value = ''
  deleteTarget.value = null
}

function validateForm(): boolean {
  if (form.code.trim() === '') {
    formError.value = '请填写角色代码。'
    return false
  }
  if (form.name.trim().length < 3) {
    formError.value = '角色名称至少需要 3 个字符。'
    return false
  }
  if (form.purpose.trim().length < 8) {
    formError.value = '请填写至少 8 个字符的角色用途。'
    return false
  }
  return true
}

async function submitForm(): Promise<void> {
  if (formSubmitting.value || !canManage.value) return
  if (formMode.value === null || formMode.value === 'delete') return
  if (!validateForm()) return

  formSubmitting.value = true
  formError.value = ''
  try {
    if (formMode.value === 'create') {
      const source = roles.value.find((role) => rowText(role, 'code', '') === form.sourceCode)
      const sourceGrants = source === undefined ? [] : capabilitiesForRole(source)
      if (sourceGrants.length === 0) {
        formError.value = '请选择至少包含一项动作授权的能力来源角色。'
        return
      }
      await resourcePost<unknown>(
        '/v1/roles',
        {
          code: form.code.trim().toUpperCase(),
          name: form.name.trim(),
          purpose: form.purpose.trim(),
          capabilities: sourceGrants.map((grant) => ({
            capability: rowText(grant, 'capability', ''),
            anchor_type: rowText(grant, 'anchor_type', 'COMPANY'),
            field_profile: rowText(grant, 'field_profile', DISPLAY_CODES.fieldProfile.p0Public),
          })),
        },
        { idempotencyKey: nextIdempotencyKey(`role-create-${form.code}`) },
      )
      actionMessage.value = '角色已创建。请继续配置页面、动作、范围与字段权限。'
    } else if (formMode.value === 'rename') {
      await resourcePatch<unknown>(
        `/v1/roles/${encodeURIComponent(form.code)}`,
        { name: form.name.trim(), purpose: form.purpose.trim() },
        { idempotencyKey: nextIdempotencyKey(`role-rename-${form.code}`) },
      )
      actionMessage.value = '角色名称与用途已更新。'
    } else {
      await resourcePost<unknown>(
        `/v1/roles/${encodeURIComponent(form.sourceCode)}/copy`,
        {
          code: form.code.trim().toUpperCase(),
          name: form.name.trim(),
          purpose: form.purpose.trim(),
        },
        { idempotencyKey: nextIdempotencyKey(`role-copy-${form.code}`) },
      )
      actionMessage.value = '角色已复制。请复核并调整复制后的授权。'
    }
    formMode.value = null
    await load()
  } catch (reason) {
    if (reason instanceof ApiError && reason.code === 'CONFLICT') {
      formError.value = reason.serverReason === 'ROLE_CODE_EXISTS'
        ? '角色代码已存在，请换一个代码。'
        : '角色数据已发生变化，请刷新后重试。'
    } else {
      formError.value = errorCopy(reason)
    }
  } finally {
    formSubmitting.value = false
  }
}

async function confirmDelete(): Promise<void> {
  const target = deleteTarget.value
  if (target === null || target.is_template === true || formSubmitting.value) return
  const code = rowText(target, 'code', '')
  formSubmitting.value = true
  formError.value = ''
  try {
    await resourceDelete<unknown>(`/v1/roles/${encodeURIComponent(code)}`, {
      idempotencyKey: nextIdempotencyKey(`role-delete-${code}`),
    })
    formMode.value = null
    deleteTarget.value = null
    actionMessage.value = '角色已删除；该角色的授权记录同时失效。'
    await load()
  } catch (reason) {
    formError.value = errorCopy(reason)
  } finally {
    formSubmitting.value = false
  }
}

function roleCapabilityCount(role: PlatformRow): number {
  return capabilitiesForRole(role).length
}

function anchorLabel(anchor: string): string {
  return ANCHOR_LABELS[anchor] ?? anchor
}

function selectedGrantCanBeSaved(grant: { anchor_type: string; anchor_id?: number | null }): boolean {
  return (!ORG_ANCHORS.has(grant.anchor_type) && !ID_ANCHORS.has(grant.anchor_type))
    || (typeof grant.anchor_id === 'number' && grant.anchor_id > 0)
}

const invalidAnchorAction = computed(() => selectedActionGrants.value.find((grant) => !selectedGrantCanBeSaved(grant)) ?? null)

function profileLabel(profile: string): string {
  return translate('platform', 'fieldProfile', profile, '未知字段档位').label
}

onMounted(load)
</script>

<template>
  <div class="page-stack" data-testid="system-roles-page">
    <header class="page-header">
      <div>
        <h1>角色与授权工作台</h1>
        <p class="muted">
          页面授权决定能否进入和查看；按钮动作必须单独授权，隐藏按钮不代替服务端校验。保存时一次性提交四类授权。
        </p>
      </div>
      <div class="platform-inline-actions">
        <span v-if="roles.length > 0" class="plain-chip">{{ roles.length }} 个角色</span>
        <button type="button" class="plain-chip" :disabled="loading || !canManage" @click="load">刷新</button>
        <button v-if="canManage" type="button" data-testid="role-create" @click="openCreateForm">新增角色</button>
      </div>
    </header>

    <p v-if="!canManage || forbidden" class="inline-error" role="alert" data-testid="roles-forbidden">
      {{ !canManage ? '无权访问角色与授权工作台。' : '你没有读取角色目录的权限。' }}
      需要 iam.role.manage 能力，如需访问请提交授权申请。
    </p>
    <p v-if="loadError" class="inline-error" role="alert">{{ loadError }}</p>
    <p v-if="actionError" class="inline-error" role="alert">{{ actionError }}</p>
    <p v-if="conflictMessage" class="inline-warning" role="alert" data-testid="role-conflict-message">{{ conflictMessage }}</p>
    <p v-if="actionMessage" class="inline-success" data-testid="role-message">{{ actionMessage }}</p>
    <p v-if="loading" class="muted" role="status">加载角色与授权目录…</p>

    <template v-if="canManage && !forbidden">
      <p v-if="!loading && !loadError && roles.length === 0" class="empty-inline">尚未返回任何角色。</p>

      <div v-if="roles.length > 0" class="role-workbench">
        <aside class="panel role-workbench__roles">
          <div class="panel__header"><h2 class="panel__title">角色列表</h2></div>
          <ul class="role-list panel__body">
            <li v-for="role in roles" :key="rowText(role, 'code')" :data-testid="`role-item-${rowText(role, 'code')}`">
              <button
                type="button"
                class="plain-chip role-list__select"
                :class="{ 'plain-chip--active': rowText(role, 'code') === selectedRoleCode }"
                @click="selectRole(role)"
              >
                <span>{{ rowText(role, 'name') }}</span>
                <span class="mono small">{{ rowText(role, 'code') }}</span>
              </button>
              <span v-if="role.is_template === true" class="plain-chip">内置</span>
              <div class="role-list__actions">
                <button
                  v-if="role.is_template !== true"
                  type="button"
                  class="plain-chip"
                  :data-testid="`role-rename-${rowText(role, 'code')}`"
                  :disabled="!canManage"
                  @click="openRenameForm(role)"
                >
                  重命名
                </button>
                <button
                  type="button"
                  class="plain-chip"
                  :data-testid="`role-copy-${rowText(role, 'code')}`"
                  :disabled="!canManage"
                  @click="openCopyForm(role)"
                >
                  复制
                </button>
                <button
                  v-if="role.is_template !== true"
                  type="button"
                  class="plain-chip"
                  :data-testid="`role-delete-${rowText(role, 'code')}`"
                  :disabled="!canManage"
                  @click="openDeleteForm(role)"
                >
                  删除
                </button>
              </div>
              <p
                v-if="role.is_template === true"
                class="small muted"
                :data-testid="`built-in-role-hint-${rowText(role, 'code')}`"
              >
                内置角色由代码维护，不能重命名或删除；需要调整时请复制后修改。
              </p>
            </li>
          </ul>
        </aside>

        <section v-if="selectedRole" class="panel role-workbench__detail">
          <div class="panel__header">
            <div>
              <h2 class="panel__title">{{ rowText(selectedRole, 'name') }} 的授权</h2>
              <p class="panel__subtitle">
                {{ roleCapabilityCount(selectedRole) }} 项动作授权 · {{ selectedPageCodes.length }} 个页面
                <span v-if="selectedRoleIsTemplate"> · 内置角色只读</span>
              </p>
            </div>
            <span v-if="selectedRoleIsTemplate" class="plain-chip">内置角色只读</span>
          </div>
          <el-collapse class="panel__body small">
            <el-collapse-item title="管理员技术明细">
              <p class="mono" data-testid="role-technical-detail">{{ selectedRoleCode }}</p>
            </el-collapse-item>
          </el-collapse>

          <nav class="platform-tabs" role="tablist">
            <button type="button" role="tab" data-testid="role-tab-pages" :aria-selected="activeTab === 'pages'" @click="activeTab = 'pages'">页面权限</button>
            <button type="button" role="tab" data-testid="role-tab-actions" :aria-selected="activeTab === 'actions'" @click="activeTab = 'actions'">按钮动作</button>
            <button type="button" role="tab" data-testid="role-tab-scopes" :aria-selected="activeTab === 'scopes'" @click="activeTab = 'scopes'">数据范围</button>
            <button type="button" role="tab" data-testid="role-tab-fields" :aria-selected="activeTab === 'fields'" @click="activeTab = 'fields'">字段权限</button>
          </nav>

          <div class="role-workbench__body">
            <template v-if="activeTab === 'pages'">
              <p v-if="selectedRoleIsTemplate" class="inline-warning">
                内置角色由代码拥有，不能在此修改；请先复制为自定义角色。
              </p>
              <RolePageTree
                :pages="cataloguePages"
                :model-value="selectedPageCodes"
                :disabled="selectedRoleIsTemplate"
                @update:model-value="updatePages"
              />

              <div v-if="pendingPageRemoval" class="inline-warning" role="alert" data-testid="page-action-removal-prompt">
                <p>
                  移除页面“{{ pendingPageRemoval.removedPageCodes.join('、') }}”时，该页面仍有
                  {{ pendingPageRemoval.affectedActions.length }} 个已选动作。是否同时清除这些动作授权？
                </p>
                <div class="platform-inline-actions">
                  <button type="button" data-testid="confirm-clear-page-actions" @click="confirmClearPageActions">清除动作并移除页面</button>
                  <button type="button" class="plain-chip" @click="removePageKeepingActions">仅移除页面，保留动作</button>
                  <button type="button" class="plain-chip" @click="dismissPageRemoval">取消</button>
                </div>
              </div>
            </template>

            <template v-else-if="activeTab === 'actions'">
              <label class="platform-field">
                <span>选择页面以查看动作</span>
                <el-select v-model="pageForActions" data-testid="action-page-select" :disabled="selectedRoleIsTemplate">
                  <el-option value="" label="请选择页面" />
                  <el-option
                    v-for="page in actionPageOptions"
                    :key="page.page_code"
                    :value="page.page_code"
                    :label="`${page.menu_label}（${page.page_code}）`"
                  />
                </el-select>
              </label>
              <p v-if="actionPageOptions.length === 0" class="empty-inline">请先授予至少一个页面，再配置按钮动作。</p>
              <RoleActionMatrix
                :page-code="pageForActions"
                :selected-capabilities="selectedCapabilities"
                :grants="selectedActionGrants"
                :capabilities="actionCapabilities"
                :disabled="selectedRoleIsTemplate"
                @toggle="onActionToggle"
                @update-anchor="onAnchorChangeFromMatrix"
                @update-field-profile="onFieldProfileChange"
              />
            </template>

            <template v-else-if="activeTab === 'scopes'">
              <p v-if="selectedRoleIsTemplate" class="inline-warning">内置角色只读。</p>
              <RoleScopeEditor
                :grants="scopeGrants"
                :capabilities="scopeCapabilities"
                :org-units="orgUnits"
                :org-units-unavailable="orgUnitsUnavailable"
                :disabled="selectedRoleIsTemplate"
                @update-anchor="onAnchorChange"
              />
            </template>

            <template v-else>
              <p v-if="selectedRoleIsTemplate" class="inline-warning">内置角色只读。</p>
              <RoleFieldPolicyEditor
                :grants="fieldGrants"
                :capabilities="fieldCapabilities"
                :original-profiles="originalFieldProfileByCapability"
                :disabled="selectedRoleIsTemplate"
                @update-field-profile="onFieldProfileChange"
              />
            </template>
          </div>

          <div class="panel__body role-workbench__save">
            <button
              v-if="canManage && !selectedRoleIsTemplate"
              type="button"
              data-testid="save-role"
              :disabled="saving"
              @click="requestSave"
            >
              {{ saving ? '保存中…' : '保存授权' }}
            </button>
            <span v-if="!selectedRoleIsTemplate" class="small muted">保存将在一个事务内提交四类变更。</span>
            <span v-else class="small muted">内置角色只读；复制后可编辑四类授权。</span>
          </div>
        </section>
      </div>

      <section v-if="diffOpen" class="panel" data-testid="role-diff">
        <div class="panel__header">
          <h2 class="panel__title">保存前差异</h2>
          <span class="plain-chip">原子保存</span>
        </div>
        <div class="panel__body stack">
          <div class="role-diff-grid">
            <p>新增页面：{{ diff.pages_added }}</p>
            <p>移除页面：{{ diff.pages_removed }}</p>
            <p>新增动作：{{ diff.actions_added }}</p>
            <p>移除动作：{{ diff.actions_removed }}</p>
            <p>范围变更：{{ diff.scopes_changed }}</p>
            <p>字段变更：{{ diff.fields_changed }}</p>
          </div>
          <p v-if="diff.pages_added > 0" class="small">待新增页面：{{ selectedPageCodes.filter((code) => !originalPageCodes.includes(code)).join('、') }}</p>
          <p v-if="diff.pages_removed > 0" class="small">待移除页面：{{ originalPageCodes.filter((code) => !selectedPageCodes.includes(code)).join('、') }}</p>
          <p v-if="diff.actions_added > 0" class="small">待新增动作：{{ selectedCapabilities.filter((code) => !hasOriginalCapability(code)).map(actionOfCapability).join('、') }}</p>
          <p v-if="diff.actions_removed > 0" class="small">待移除动作：{{ Object.keys(originalAnchorByCapability).filter((code) => !selectedCapabilitySet.has(code)).map(actionOfCapability).join('、') }}</p>
          <p v-if="diff.scopes_changed > 0" class="small">
            范围变更：
            {{ selectedCapabilities.filter((code) => hasOriginalCapability(code) && (originalAnchorByCapability[code] !== anchorByCapability[code] || (originalAnchorIdByCapability[code] ?? null) !== (anchorIdByCapability[code] ?? null))).map((code) => `${actionOfCapability(code)} → ${anchorLabel(anchorByCapability[code] ?? '')}`).join('、') }}
          </p>
          <p v-if="diff.fields_changed > 0" class="small">
            字段变更：
            {{ selectedCapabilities.filter((code) => hasOriginalCapability(code) && (originalFieldProfileByCapability[code] ?? DISPLAY_CODES.fieldProfile.p0Public) !== (fieldProfileByCapability[code] ?? DISPLAY_CODES.fieldProfile.p0Public)).map((code) => `${actionOfCapability(code)}：${profileLabel(originalFieldProfileByCapability[code] ?? DISPLAY_CODES.fieldProfile.p0Public)} → ${profileLabel(fieldProfileByCapability[code] ?? DISPLAY_CODES.fieldProfile.p0Public)}`).join('、') }}
          </p>
          <div class="platform-inline-actions">
            <button
              type="button"
              data-testid="confirm-save-role"
              :disabled="saving || selectedRoleIsTemplate"
              @click="confirmSave"
            >
              {{ saving ? '保存中…' : '确认保存' }}
            </button>
            <button type="button" class="plain-chip" :disabled="saving" @click="diffOpen = false">取消</button>
          </div>
        </div>
      </section>

      <section v-if="formMode && formMode !== 'delete'" class="panel" data-testid="role-create-form">
        <div class="panel__header">
          <h2 class="panel__title">
            {{ formMode === 'create' ? '新增角色' : formMode === 'rename' ? '重命名角色' : '复制角色' }}
          </h2>
        </div>
        <div class="panel__body stack">
          <p v-if="formError" class="inline-error" role="alert">{{ formError }}</p>
          <label v-if="formMode !== 'rename'" class="platform-field">
            <span>角色代码</span>
            <el-input v-model="form.code" data-testid="role-form-code" maxlength="64" placeholder="例如 HR_SPECIALIST" />
          </label>
          <label class="platform-field">
            <span>角色名称</span>
            <el-input v-model="form.name" data-testid="role-form-name" maxlength="120" />
          </label>
          <label class="platform-field">
            <span>用途说明（至少 8 个字符）</span>
            <el-input v-model="form.purpose" data-testid="role-form-purpose" type="textarea" :rows="3" maxlength="255" />
          </label>
          <label v-if="formMode === 'create'" class="platform-field">
            <span>能力来源角色</span>
            <el-select v-model="form.sourceCode" data-testid="role-form-source">
              <el-option value="" label="请选择来源角色" />
              <el-option
                v-for="role in roles.filter((item) => roleCapabilityCount(item) > 0)"
                :key="rowText(role, 'code')"
                :value="rowText(role, 'code')"
                :label="`${rowText(role, 'name')}（${rowText(role, 'code')}）`"
              />
            </el-select>
          </label>
          <p class="small muted">新角色页面授权默认留空，请在创建后进入页面权限配置；动作授权从所选来源角色复制。</p>
          <div class="platform-inline-actions">
            <button type="button" :disabled="formSubmitting" :data-testid="formMode === 'copy' ? 'role-copy-submit' : 'role-form-submit'" @click="submitForm">{{ formSubmitting ? '提交中…' : '提交' }}</button>
            <button type="button" class="plain-chip" :disabled="formSubmitting" @click="closeForm">取消</button>
          </div>
        </div>
      </section>

      <section v-if="formMode === 'delete' && deleteTarget" class="panel" data-testid="role-delete-form">
        <div class="panel__header"><h2 class="panel__title">删除角色</h2></div>
        <div class="panel__body stack">
          <p v-if="formError" class="inline-error" role="alert">{{ formError }}</p>
          <p>
            确认删除角色 <strong>{{ rowText(deleteTarget, 'name') }}</strong>
            （<span class="mono">{{ rowText(deleteTarget, 'code') }}</span>）？
            删除后现有成员将失去该角色授予的页面与动作。
          </p>
          <div class="platform-inline-actions">
            <button type="button" :disabled="formSubmitting" @click="confirmDelete">{{ formSubmitting ? '删除中…' : '确认删除' }}</button>
            <button type="button" class="plain-chip" :disabled="formSubmitting" @click="closeForm">取消</button>
          </div>
        </div>
      </section>
    </template>
  </div>
</template>

<style scoped>
.role-workbench {
  display: grid;
  grid-template-columns: minmax(260px, 340px) minmax(0, 1fr);
  gap: 16px;
  align-items: start;
}
.role-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
  margin: 0;
  padding: 0;
  list-style: none;
}
.role-list li {
  display: flex;
  flex-direction: column;
  gap: 6px;
  border-bottom: 1px solid var(--el-border-color-lighter);
  padding-bottom: 10px;
}
.role-list__select {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 2px;
  text-align: left;
}
.role-list__actions {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}
.role-workbench__body {
  display: flex;
  flex-direction: column;
  gap: 16px;
  padding: 16px;
}
.role-workbench__save {
  display: flex;
  align-items: center;
  gap: 12px;
  border-top: 1px solid var(--el-border-color-lighter);
}
.role-diff-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 8px;
}
.role-diff-grid p {
  margin: 0;
  padding: 8px 10px;
  background: var(--el-fill-color-light);
  border-radius: 6px;
}
@media (max-width: 1100px) {
  .role-workbench {
    grid-template-columns: 1fr;
  }
}
</style>
