<script setup lang="ts">
import { computed, ref } from 'vue'
import type { DepartmentNode } from '../api'
import { departmentTypeLabel } from '../api'

const props = defineProps<{
  nodes: DepartmentNode[]
  selectedId: number | null
}>()

const emit = defineEmits<{
  select: [id: number]
}>()

defineOptions({ name: 'DepartmentTree' })

type VisibleNode = {
  node: DepartmentNode
  depth: number
}

const query = ref('')
const expanded = ref<Set<number>>(new Set())

const normalizedQuery = computed(() => query.value.trim().toLowerCase())

function flatten(nodes: readonly DepartmentNode[], depth = 0): VisibleNode[] {
  const rows: VisibleNode[] = []
  for (const node of nodes) {
    rows.push({ node, depth })
    if (node.children.length > 0) rows.push(...flatten(node.children, depth + 1))
  }
  return rows
}

const rows = computed<VisibleNode[]>(() => {
  const term = normalizedQuery.value
  if (term !== '') {
    return flatten(props.nodes)
      .filter(({ node }) => `${node.name} ${node.code}`.toLowerCase().includes(term))
      .map(({ node }) => ({ node, depth: 0 }))
  }

  const visible: VisibleNode[] = []
  const walk = (nodes: readonly DepartmentNode[], depth: number): void => {
    for (const node of nodes) {
      visible.push({ node, depth })
      if (expanded.value.has(node.id) && node.children.length > 0) {
        walk(node.children, depth + 1)
      }
    }
  }
  walk(props.nodes, 0)
  return visible
})

const allMatchedIds = computed(() => new Set(rows.value.map(({ node }) => node.id)))

function toggle(id: number): void {
  const next = new Set(expanded.value)
  if (next.has(id)) next.delete(id)
  else next.add(id)
  expanded.value = next
}

function isExpanded(id: number): boolean {
  return expanded.value.has(id) && allMatchedIds.value.has(id) && normalizedQuery.value === ''
}

function headcountLabel(value: number | null): string {
  return value === null ? '在编 —' : `在编 ${value} 人`
}
</script>

<template>
  <section class="department-tree" aria-label="组织架构树">
    <label class="department-tree__search">
      <span>搜索部门</span>
      <input
        v-model="query"
        type="search"
        data-testid="dept-search"
        placeholder="按部门名称或编码搜索"
      />
    </label>

    <div v-if="normalizedQuery !== ''" class="department-tree__hint" data-testid="dept-search-hint">
      全树匹配 {{ rows.length }} 个节点；清空搜索后按层级展开。
    </div>

    <ul v-if="rows.length > 0" class="department-tree__list" role="tree">
      <li
        v-for="row in rows"
        :key="row.node.id"
        role="treeitem"
        :aria-expanded="row.node.children.length > 0 ? isExpanded(row.node.id) : undefined"
      >
        <div
          class="department-tree__row"
          :class="{ 'department-tree__row--selected': selectedId === row.node.id }"
          :style="{ paddingLeft: `${10 + row.depth * 18}px` }"
          :data-testid="`dept-node-${row.node.id}`"
          @click="emit('select', row.node.id)"
        >
          <button
            v-if="row.node.children.length > 0"
            type="button"
            class="department-tree__toggle"
            :aria-label="`${isExpanded(row.node.id) ? '收起' : '展开'}${row.node.name}`"
            :data-testid="`dept-toggle-${row.node.id}`"
            @click.stop="toggle(row.node.id)"
          >
            {{ isExpanded(row.node.id) ? '▾' : '▸' }}
          </button>
          <span v-else class="department-tree__toggle department-tree__toggle--empty" aria-hidden="true" />

          <span class="department-tree__copy">
            <span class="department-tree__name">{{ row.node.name }}</span>
            <span class="department-tree__meta">
              {{ row.node.code }} · {{ departmentTypeLabel(row.node.type) }}
            </span>
          </span>
          <span class="department-tree__headcount" data-testid="dept-headcount">
            {{ headcountLabel(row.node.headcount) }}
          </span>
        </div>
      </li>
    </ul>

    <p v-else class="department-tree__empty" data-testid="dept-tree-empty">
      没有匹配“{{ query }}”的部门。可按部门名称或编码重试。
    </p>
  </section>
</template>

<style scoped>
.department-tree {
  min-width: 0;
}
.department-tree__search {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 0 12px 10px;
  color: var(--color-ink-muted);
  font-size: 11px;
}
.department-tree__search > span {
  font-weight: 600;
}
.department-tree__search input {
  width: 100%;
  padding: 7px 9px;
  color: var(--color-ink);
  font-size: 12px;
  background: #fff;
  border: 1px solid var(--color-line-strong);
  border-radius: var(--radius-sm);
}
.department-tree__hint {
  margin: 0 12px 8px;
  padding: 6px 8px;
  color: var(--color-ink-muted);
  font-size: 11px;
  background: var(--color-brand-soft);
  border-radius: var(--radius-sm);
}
.department-tree__list {
  max-height: 560px;
  margin: 0;
  padding: 0 8px 12px;
  overflow: auto;
  list-style: none;
}
.department-tree__row {
  display: flex;
  gap: 6px;
  align-items: center;
  padding-top: 7px;
  padding-right: 8px;
  padding-bottom: 7px;
  cursor: pointer;
  border-radius: var(--radius-sm);
}
.department-tree__row:hover,
.department-tree__row--selected {
  background: var(--color-brand-soft);
}
.department-tree__toggle {
  width: 18px;
  height: 18px;
  padding: 0;
  color: var(--color-brand);
  font-size: 12px;
  background: transparent;
  border: 0;
  cursor: pointer;
}
.department-tree__toggle--empty {
  cursor: default;
}
.department-tree__copy {
  display: flex;
  flex: 1;
  flex-direction: column;
  min-width: 0;
}
.department-tree__name {
  overflow: hidden;
  color: var(--color-ink);
  font-size: 12px;
  font-weight: 600;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.department-tree__meta {
  color: var(--color-ink-muted);
  font-size: 10px;
}
.department-tree__headcount {
  flex: none;
  color: var(--color-ink-muted);
  font-size: 10px;
  white-space: nowrap;
}
.department-tree__empty {
  padding: 16px 12px;
  color: var(--color-ink-muted);
  font-size: 12px;
}
</style>
