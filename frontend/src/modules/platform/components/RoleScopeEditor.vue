<script setup lang="ts">
import { computed } from 'vue'

export type ScopeGrant = {
  capability: string
  anchor_type: string
  anchor_id?: number | null
  field_profile: string
}

export type ScopeCapability = {
  code: string
  anchor_types?: string[]
}

export type OrgUnitOption = {
  id: number
  parent_id?: number | null
  code?: string | null
  name?: string | null
  type?: string | null
}

const ANCHOR_ORDER = ['SELF', 'MANAGED', 'ORG_UNIT', 'ORG_SUBTREE', 'COMPANY', 'CASE', 'PAYROLL_RUN'] as const
const ANCHOR_LABELS: Record<string, string> = {
  SELF: '仅本人',
  MANAGED: '下属',
  ORG_UNIT: '本部门',
  ORG_SUBTREE: '本部门及子部门',
  COMPANY: '本公司',
  CASE: '指定案例',
  PAYROLL_RUN: '指定工资批次',
}
const ORG_ANCHORS = new Set(['ORG_UNIT', 'ORG_SUBTREE'])
const ID_ANCHORS = new Set(['CASE', 'PAYROLL_RUN'])
const EXPLANATION_ANCHORS = new Set(['CASE', 'PAYROLL_RUN'])

const props = defineProps<{
  grants: ScopeGrant[]
  capabilities: ScopeCapability[]
  orgUnits?: OrgUnitOption[]
  orgUnitsUnavailable?: boolean
  disabled?: boolean
}>()

const emit = defineEmits<{
  'update-anchor': [payload: { capability: string; anchor_type: string; anchor_id: number | null }]
}>()

const capabilityByCode = computed(() => new Map(props.capabilities.map((row) => [row.code, row])))

const flatOrgUnits = computed<Array<{ unit: OrgUnitOption; depth: number }>>(() => {
  const byParent = new Map<number | null, OrgUnitOption[]>()
  for (const unit of props.orgUnits ?? []) {
    const parent = typeof unit.parent_id === 'number' ? unit.parent_id : null
    const list = byParent.get(parent) ?? []
    list.push(unit)
    byParent.set(parent, list)
  }

  const flattened: Array<{ unit: OrgUnitOption; depth: number }> = []
  const visit = (parentId: number | null, depth: number): void => {
    for (const unit of byParent.get(parentId) ?? []) {
      flattened.push({ unit, depth })
      visit(unit.id, depth + 1)
    }
  }
  visit(null, 0)
  return flattened
})

function anchorsFor(capability: string): string[] {
  const declared = capabilityByCode.value.get(capability)?.anchor_types ?? []
  return ANCHOR_ORDER.filter((anchor) => declared.includes(anchor))
}

function showsPicker(anchorType: string): boolean {
  return ORG_ANCHORS.has(anchorType)
}

function showsExplanation(anchorType: string): boolean {
  return EXPLANATION_ANCHORS.has(anchorType)
}

function showsIdInput(anchorType: string): boolean {
  return ID_ANCHORS.has(anchorType)
}

function explanation(anchorType: string): string {
  if (anchorType === 'CASE') {
    return '指定案例：授权范围由被指派案例的成员范围决定，此处不选择组织单元。'
  }
  return '指定工资批次：授权范围由所选批次锁定，批次结束后不再扩大，此处不选择组织单元。'
}

function capabilityLabel(code: string): string {
  const parts = code.split('.')
  const resource = parts.length > 1 ? (parts[parts.length - 2] ?? code) : code
  const action = parts[parts.length - 1] ?? ''
  const resources: Record<string, string> = {
    user: '用户', role: '角色', employee: '员工', contract: '合同', department: '部门',
    position: '岗位', leave: '请假', overtime: '加班', travel: '出差', payroll: '薪资',
    report: '报表', notice: '公告', permission: '权限', menu: '菜单', field: '字段',
    scope: '数据范围', audit: '审计', session: '登录会话', setting: '系统设置',
  }
  const actions: Record<string, string> = {
    read: '查看', manage: '管理', create: '新增', update: '编辑', delete: '删除',
    export: '导出', approve: '审批', publish: '发布', assign: '分配', request: '申请',
  }
  return `${resources[resource] ?? '业务数据'} · ${actions[action] ?? '操作'}`
}

function unitLabel(unit: OrgUnitOption): string {
  const name = unit.name ?? unit.code ?? String(unit.id)
  return unit.type === null || unit.type === undefined || unit.type === ''
    ? name
    : `${name} · ${unit.type}`
}

function onAnchor(grant: ScopeGrant, event: Event): void {
  if (props.disabled === true) return
  const anchorType = (event.target as HTMLSelectElement).value
  emit('update-anchor', {
    capability: grant.capability,
    anchor_type: anchorType,
    anchor_id: ORG_ANCHORS.has(anchorType) || ID_ANCHORS.has(anchorType) ? (grant.anchor_id ?? null) : null,
  })
}

function onAnchorId(grant: ScopeGrant, event: Event): void {
  if (props.disabled === true) return
  const raw = (event.target as HTMLInputElement).value.trim()
  const parsed = raw === '' ? null : Number(raw)
  emit('update-anchor', {
    capability: grant.capability,
    anchor_type: grant.anchor_type,
    anchor_id: parsed !== null && Number.isSafeInteger(parsed) && parsed > 0 ? parsed : null,
  })
}

function onOrgUnit(grant: ScopeGrant, event: Event): void {
  if (props.disabled === true) return
  const raw = (event.target as HTMLSelectElement).value
  const parsed = raw === '' ? null : Number(raw)
  emit('update-anchor', {
    capability: grant.capability,
    anchor_type: grant.anchor_type,
    anchor_id: parsed !== null && Number.isFinite(parsed) ? parsed : null,
  })
}
</script>

<template>
  <section class="role-scope-editor" data-testid="role-scope-editor">
    <p class="muted small">
      每个动作只能使用能力目录声明的数据锚点；单公司部署，不提供租户或法人实体维度的锚点选项。
    </p>

    <p v-if="props.grants.length === 0" class="empty-inline">尚未选择动作，暂无数据范围可配置。</p>

    <div v-for="grant in props.grants" :key="grant.capability" class="role-scope-editor__row" :data-scope-capability="grant.capability">
      <div class="role-scope-editor__capability">{{ capabilityLabel(grant.capability) }}</div>
      <label class="platform-field">
        <span>数据锚点</span>
        <select
          :data-testid="`scope-anchor-${grant.capability}`"
          :value="grant.anchor_type"
          :disabled="props.disabled"
          @change="onAnchor(grant, $event)"
        >
          <option v-for="anchor in anchorsFor(grant.capability)" :key="anchor" :value="anchor">
            {{ ANCHOR_LABELS[anchor] ?? anchor }}
          </option>
        </select>
      </label>

      <label v-if="showsPicker(grant.anchor_type)" class="platform-field">
        <span>组织单元</span>
        <select
          :data-testid="`org-unit-picker-${grant.capability}`"
          :value="grant.anchor_id ?? ''"
          :disabled="props.disabled || props.orgUnitsUnavailable === true"
          @change="onOrgUnit(grant, $event)"
        >
          <option value="">请选择组织单元</option>
          <option v-for="entry in flatOrgUnits" :key="entry.unit.id" :value="entry.unit.id">
            {{ '· '.repeat(entry.depth) }}{{ unitLabel(entry.unit) }}
          </option>
        </select>
        <span v-if="props.orgUnitsUnavailable === true" class="inline-warning">组织树暂时不可用，请稍后重试。</span>
        <span v-else-if="flatOrgUnits.length === 0" class="muted small">组织树没有返回组织单元。</span>
      </label>

      <label v-if="showsIdInput(grant.anchor_type)" class="platform-field">
        <span>{{ grant.anchor_type === 'CASE' ? '案例编号' : '工资批次编号' }}</span>
        <input
          type="number"
          min="1"
          :data-testid="`anchor-id-${grant.capability}`"
          :value="grant.anchor_id ?? ''"
          :disabled="props.disabled"
          @input="onAnchorId(grant, $event)"
        />
      </label>

      <p
        v-if="showsExplanation(grant.anchor_type)"
        class="inline-note small"
        :data-testid="grant.anchor_type === 'CASE' ? `case-anchor-hint-${grant.capability}` : `payroll-run-anchor-hint-${grant.capability}`"
      >
        {{ explanation(grant.anchor_type) }}
      </p>
    </div>
  </section>
</template>

<style scoped>
.role-scope-editor {
  display: flex;
  flex-direction: column;
  gap: 14px;
}
.role-scope-editor__row {
  display: grid;
  grid-template-columns: minmax(180px, 1fr) minmax(180px, 240px) minmax(220px, 1fr);
  gap: 12px;
  align-items: start;
  border-bottom: 1px solid var(--el-border-color-lighter);
  padding-bottom: 12px;
}
.role-scope-editor__capability {
  padding-top: 24px;
}
.inline-note {
  grid-column: 2 / -1;
  margin: 0;
  padding: 8px 12px;
  background: var(--el-color-info-light-9);
  border-left: 3px solid var(--el-color-info);
}
</style>
