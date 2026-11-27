<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ApiError, errorCopy } from '@/api/errors'
import { resourceGet } from '@/api/resourceClient'
import { useSessionStore } from '@/stores/session'
import { rowNumber, rowText, rowsFrom, type PlatformRow } from '../platformRead'

const session = useSessionStore()
const canManage = computed(() => session.can('iam.scope.manage'))

const ANCHOR_LABELS: Record<string, string> = {
  SELF: '仅本人',
  MANAGED: '下属',
  ORG_UNIT: '本部门',
  ORG_SUBTREE: '本部门及子部门',
  CASE: '指定案例',
  PAYROLL_RUN: '指定工资批次',
  COMPANY: '本公司',
}

const templates = ref<PlatformRow[]>([])
const orgUnits = ref<PlatformRow[]>([])
const selectedTemplate = ref('')
const loading = ref(false)
const error = ref<string | null>(null)
const forbidden = ref(false)
const partial = ref(false)
const orgTreeUnavailable = ref(false)

const selected = computed(() => templates.value.find((row) => rowText(row, 'template', '') === selectedTemplate.value) ?? null)
const selectedAnchor = computed(() => (selected.value === null ? '' : rowText(selected.value, 'anchor_type', '')))

const inclusionRules = computed<string[]>(() => {
  const anchor = selectedAnchor.value
  switch (anchor) {
    case 'COMPANY':
      return ['本公司全部数据', '单公司部署：数据范围不跨法人实体', '无需再选择租户或法人实体']
    case 'ORG_UNIT':
      return ['仅所选组织单元内的数据', '不包含下级组织单元', '组织单元变更按业务日期解析']
    case 'ORG_SUBTREE':
      return ['所选组织单元及其全部下级', '包含本部门与各级子部门', '组织单元变更按业务日期解析']
    case 'SELF':
      return ['仅当前账号绑定的员工记录', '不包含下属或部门数据']
    case 'MANAGED':
      return ['仅当前管理者的直接与间接下属', '按组织汇报关系解析']
    case 'CASE':
      return ['仅被指派案例的成员范围', '不存在全局案例视图']
    case 'PAYROLL_RUN':
      return ['仅指定工资批次的成员范围', '批次结束后不再扩大范围']
    default:
      return ['该模板没有额外包含规则。']
  }
})

const showsOrgTree = computed(() => selectedAnchor.value === 'ORG_UNIT' || selectedAnchor.value === 'ORG_SUBTREE')

const flatOrgUnits = computed<Array<{ unit: PlatformRow; depth: number }>>(() => {
  const byParent = new Map<number | null, PlatformRow[]>()
  for (const unit of orgUnits.value) {
    const parent = unit.parent_id
    const key = typeof parent === 'number' ? parent : null
    const list = byParent.get(key) ?? []
    list.push(unit)
    byParent.set(key, list)
  }

  const flattened: Array<{ unit: PlatformRow; depth: number }> = []
  const visit = (parentId: number | null, depth: number): void => {
    for (const unit of byParent.get(parentId) ?? []) {
      flattened.push({ unit, depth })
      visit(rowNumber(unit, 'id'), depth + 1)
    }
  }
  visit(null, 0)
  return flattened
})

function unitLabel(unit: PlatformRow): string {
  const type = rowText(unit, 'type', '')
  return `${rowText(unit, 'name', rowText(unit, 'code'))}${type === '' ? '' : ` · ${type}`}`
}

function selectTemplate(row: PlatformRow): void {
  selectedTemplate.value = rowText(row, 'template', '')
}

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  forbidden.value = false
  partial.value = false
  orgTreeUnavailable.value = false
  try {
    const payload = await resourceGet<unknown>('/v1/system/data-scopes', { page: 1, page_size: 100 })
    templates.value = rowsFrom(payload)
    partial.value = payload !== null && typeof payload === 'object' && (payload as PlatformRow).partial === true
    if (selectedTemplate.value === '' && templates.value[0] !== undefined) {
      selectedTemplate.value = rowText(templates.value[0], 'template', '')
    }
  } catch (reason) {
    if (reason instanceof ApiError && (reason.status === 403 || reason.code === 'FORBIDDEN')) {
      forbidden.value = true
    } else {
      error.value = errorCopy(reason)
    }
    loading.value = false
    return
  }

  try {
    const payload = await resourceGet<unknown>('/v1/org-units/tree')
    orgUnits.value = rowsFrom(payload)
  } catch (reason) {
    // The scope catalogue is still usable; the org tree is an explanatory
    // enrichment and must fail visibly instead of being replaced by fake rows.
    orgTreeUnavailable.value = true
    if (reason instanceof ApiError && (reason.status === 403 || reason.code === 'FORBIDDEN')) {
      partial.value = true
    }
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="page-stack" data-testid="system-data-scopes-page">
    <header class="page-header">
      <div>
        <h1>数据范围模板</h1>
        <p class="muted">数据范围模板由代码拥有，只能从白名单中选择；单公司部署，不提供租户或法人实体选择器。</p>
      </div>
      <div class="platform-inline-actions">
        <span class="plain-chip">{{ templates.length }} 个模板</span>
        <button type="button" class="plain-chip" :disabled="loading" @click="load">刷新</button>
      </div>
    </header>

    <p v-if="forbidden" class="inline-error" role="alert">你没有读取数据范围模板的权限。如需访问，请提交授权申请。</p>
    <p v-if="error" class="inline-error" role="alert">{{ error }}</p>
    <p v-if="partial" class="inline-warning">部分数据读取失败，已保留成功返回的模板。</p>
    <p v-if="loading" class="muted" role="status">加载数据范围模板…</p>

    <div class="platform-panel-grid">
      <section class="panel">
        <div class="panel__header"><h2 class="panel__title">代码拥有的范围模板</h2></div>
        <p v-if="!loading && !forbidden && !error && templates.length === 0" class="panel__body empty-inline">
          没有返回数据范围模板。
        </p>
        <ul v-else class="platform-menu-list panel__body">
          <li v-for="template in templates" :key="rowText(template, 'template')">
            <button
              type="button"
              class="plain-chip"
              :class="{ 'plain-chip--active': rowText(template, 'template') === selectedTemplate }"
              :data-testid="`data-scope-item-${rowText(template, 'template')}`"
              @click="selectTemplate(template)"
            >
              <span class="mono">{{ rowText(template, 'template') }}</span>
              · {{ rowText(template, 'label') }}
              <span v-if="template.active !== true" class="muted">（停用）</span>
            </button>
          </li>
        </ul>
      </section>

      <section v-if="selected" class="panel" data-testid="data-scope-detail">
        <div class="panel__header">
          <div>
            <h2 class="panel__title">{{ rowText(selected, 'label') }}</h2>
            <p class="panel__subtitle mono">{{ rowText(selected, 'template') }} · {{ ANCHOR_LABELS[selectedAnchor] ?? selectedAnchor }}</p>
          </div>
          <span class="plain-chip">代码拥有 · 只读</span>
        </div>
        <div class="panel__body stack">
          <h3>包含规则</h3>
          <ul>
            <li v-for="rule in inclusionRules" :key="rule">{{ rule }}</li>
          </ul>

          <template v-if="showsOrgTree">
            <h3>组织单元范围</h3>
            <p v-if="orgTreeUnavailable" class="inline-warning">组织树暂时不可用，范围规则仍按模板定义展示。</p>
            <p v-else-if="orgUnits.length === 0" class="empty-inline">组织树没有返回组织单元。</p>
            <ul v-else data-testid="data-scope-org-tree">
              <li v-for="entry in flatOrgUnits" :key="rowText(entry.unit, 'id')" :style="{ paddingLeft: `${entry.depth * 16}px` }">
                {{ unitLabel(entry.unit) }}
              </li>
            </ul>
          </template>

          <p class="small muted">{{ rowText(selected, 'description', '') }}</p>

          <div class="platform-inline-actions">
            <button type="button" disabled data-testid="data-scope-edit-disabled">编辑范围</button>
          </div>
          <p class="small muted" data-testid="data-scope-code-owned-hint">
            该数据范围模板由代码拥有，页面不提供编辑。需要新增范围时，必须通过代码评审发布新的白名单模板，不能在前端输入任意条件。
            <span v-if="!canManage">当前账号也没有 iam.scope.manage，写操作一律禁用。</span>
          </p>
        </div>
      </section>
    </div>
  </div>
</template>
