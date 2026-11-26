<script setup lang="ts">
import { computed } from 'vue'
import { actionsForPage, type PageActionSpec } from '@/router/registry'

export type RoleActionGrant = {
  capability: string
  anchor_type: string
  anchor_id?: number | null
  field_profile: string
}

export type RoleActionCapability = {
  code: string
  anchor_types?: string[]
  field_profile_max?: string
}

const ANCHOR_LABELS: Record<string, string> = {
  SELF: '仅本人',
  MANAGED: '下属',
  ORG_UNIT: '本部门',
  ORG_SUBTREE: '本部门及子部门',
  COMPANY: '本公司',
  CASE: '指定案例',
  PAYROLL_RUN: '指定工资批次',
}

const ANCHOR_ORDER = ['SELF', 'MANAGED', 'ORG_UNIT', 'ORG_SUBTREE', 'COMPANY', 'CASE', 'PAYROLL_RUN']
const PROFILE_ORDER = ['P0_PUBLIC', 'P1_INTERNAL', 'P2_SENSITIVE', 'P3_RESTRICTED']
const PROFILE_LABELS: Record<string, string> = {
  P0_PUBLIC: 'P0 公开',
  P1_INTERNAL: 'P1 内部',
  P2_SENSITIVE: 'P2 敏感',
  P3_RESTRICTED: 'P3 受限',
}

const props = defineProps<{
  pageCode: string
  selectedCapabilities: string[]
  grants: RoleActionGrant[]
  capabilities: RoleActionCapability[]
  disabled?: boolean
}>()

const emit = defineEmits<{
  toggle: [action: PageActionSpec, checked: boolean, pageCode: string]
  'update-anchor': [payload: { capability: string; anchor_type: string }]
  'update-field-profile': [payload: { capability: string; field_profile: string }]
}>()

const rows = computed<PageActionSpec[]>(() => (props.pageCode === '' ? [] : actionsForPage(props.pageCode)))
const capabilityByCode = computed(() => new Map(props.capabilities.map((row) => [row.code, row])))
const grantByCapability = computed(() => new Map(props.grants.map((row) => [row.capability, row])))

function anchorsFor(capability: string): string[] {
  const declared = capabilityByCode.value.get(capability)?.anchor_types ?? []
  return ANCHOR_ORDER.filter((anchor) => declared.includes(anchor))
}

function profilesFor(capability: string): string[] {
  const max = capabilityByCode.value.get(capability)?.field_profile_max ?? 'P0_PUBLIC'
  const maxRank = PROFILE_ORDER.indexOf(max)
  if (maxRank < 0) return PROFILE_ORDER
  return PROFILE_ORDER.filter((_profile, index) => index <= maxRank)
}

function anchorOf(capability: string): string {
  return grantByCapability.value.get(capability)?.anchor_type ?? anchorsFor(capability)[0] ?? 'COMPANY'
}

function fieldProfileOf(capability: string): string {
  return grantByCapability.value.get(capability)?.field_profile ?? 'P0_PUBLIC'
}

function onToggle(action: PageActionSpec, checked: boolean): void {
  if (props.disabled === true) return
  emit('toggle', action, checked, props.pageCode)
}

function onAnchor(action: PageActionSpec, event: Event): void {
  if (props.disabled === true) return
  emit('update-anchor', {
    capability: action.capability,
    anchor_type: (event.target as HTMLSelectElement).value,
  })
}

function onFieldProfile(action: PageActionSpec, event: Event): void {
  if (props.disabled === true) return
  emit('update-field-profile', {
    capability: action.capability,
    field_profile: (event.target as HTMLSelectElement).value,
  })
}
</script>

<template>
  <section class="role-action-matrix" data-testid="role-action-matrix">
    <p class="muted small">
      动作来自只读页面注册表（ADR-0036）。勾选动作会自动补选其所属页面；取消页面时会询问是否同时清除动作。
    </p>

    <p v-if="props.pageCode === ''" class="empty-inline">请选择页面后查看可授权的动作。</p>
    <p v-else-if="rows.length === 0" class="empty-inline">该页面没有声明可独立授权的按钮动作。</p>

    <div v-else class="platform-table-wrap">
      <table class="platform-table">
        <caption class="visually-hidden">页面 {{ props.pageCode }} 的动作授权矩阵</caption>
        <thead>
          <tr>
            <th scope="col">动作</th>
            <th scope="col">能力码</th>
            <th scope="col">数据锚点</th>
            <th scope="col">字段档位</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="action in rows" :key="action.key" :data-action-key="action.key">
            <td>
              <label class="platform-check">
                <input
                  type="checkbox"
                  :data-testid="`action-toggle-${action.key}`"
                  :checked="props.selectedCapabilities.includes(action.capability)"
                  :disabled="props.disabled"
                  @change="onToggle(action, ($event.target as HTMLInputElement).checked)"
                />
                {{ action.label }}
              </label>
              <div class="small muted">{{ action.ui }} · {{ action.api }}</div>
            </td>
            <td class="mono">{{ action.capability }}</td>
            <td>
              <select
                :value="anchorOf(action.capability)"
                :disabled="props.disabled"
                :aria-label="`${action.label} 的数据锚点`"
                @change="onAnchor(action, $event)"
              >
                <option v-for="anchor in anchorsFor(action.capability)" :key="anchor" :value="anchor">
                  {{ ANCHOR_LABELS[anchor] ?? anchor }}
                </option>
              </select>
            </td>
            <td>
              <select
                :value="fieldProfileOf(action.capability)"
                :disabled="props.disabled"
                :aria-label="`${action.label} 的字段档位`"
                @change="onFieldProfile(action, $event)"
              >
                <option v-for="profile in profilesFor(action.capability)" :key="profile" :value="profile">
                  {{ PROFILE_LABELS[profile] ?? profile }}
                </option>
              </select>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>

<style scoped>
.role-action-matrix {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.platform-check {
  display: inline-flex;
  align-items: center;
  gap: 8px;
}
</style>
