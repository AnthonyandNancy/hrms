<script setup lang="ts">
import { computed } from 'vue'

export type RolePageOption = {
  page_code: string
  path: string
  menu_group?: string | null
  menu_label?: string | null
  order?: number | null
}

export type RolePageGroup = {
  key: string
  label: string
  pages: RolePageOption[]
  selectedCount: number
  state: 'checked' | 'partial' | 'unchecked'
}

const props = defineProps<{
  pages: RolePageOption[]
  modelValue: string[]
  disabled?: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: string[]]
}>()

function codeOf(page: RolePageOption): string {
  return typeof page.page_code === 'string' ? page.page_code : ''
}

function groupOf(page: RolePageOption): string {
  const group = typeof page.menu_group === 'string' ? page.menu_group.trim() : ''
  return group === '' ? '其他页面' : group
}

const pageGroups = computed<RolePageGroup[]>(() => {
  const grouped = new Map<string, RolePageOption[]>()
  for (const page of props.pages) {
    const key = groupOf(page)
    const list = grouped.get(key) ?? []
    list.push(page)
    grouped.set(key, list)
  }

  return [...grouped.entries()].map(([label, groupPages]) => {
    const sorted = [...groupPages].sort((left, right) => {
      const leftOrder = typeof left.order === 'number' ? left.order : Number.MAX_SAFE_INTEGER
      const rightOrder = typeof right.order === 'number' ? right.order : Number.MAX_SAFE_INTEGER
      return leftOrder === rightOrder
        ? (left.menu_label ?? left.page_code).localeCompare(right.menu_label ?? right.page_code, 'zh-CN')
        : leftOrder - rightOrder
    })
    const selectedCount = sorted.filter((page) => props.modelValue.includes(codeOf(page))).length
    const state: RolePageGroup['state'] =
      sorted.length > 0 && selectedCount === sorted.length
        ? 'checked'
        : selectedCount > 0
          ? 'partial'
          : 'unchecked'
    return { key: label, label, pages: sorted, selectedCount, state }
  })
})

const allCodes = computed(() => props.pages.map(codeOf).filter((code) => code !== ''))
const allSelected = computed(
  () => allCodes.value.length > 0 && allCodes.value.every((code) => props.modelValue.includes(code)),
)

function emitSelection(codes: string[]): void {
  emit('update:modelValue', [...new Set(codes)].sort((left, right) => left.localeCompare(right)))
}

function togglePage(code: string, checked: boolean): void {
  if (props.disabled || code === '') return
  const current = new Set(props.modelValue)
  if (checked) current.add(code)
  else current.delete(code)
  emitSelection([...current])
}

function toggleGroup(group: RolePageGroup, checked: boolean): void {
  if (props.disabled) return
  const current = new Set(props.modelValue)
  for (const page of group.pages) {
    const code = codeOf(page)
    if (code === '') continue
    if (checked) current.add(code)
    else current.delete(code)
  }
  emitSelection([...current])
}

function toggleAll(checked: boolean): void {
  if (props.disabled) return
  emitSelection(checked ? [...allCodes.value] : [])
}

/**
 * Vue cannot bind `indeterminate` declaratively, so a small local directive
 * keeps the tri-state group checkbox in sync with the derived group state.
 */
const vIndeterminate = {
  mounted(element: HTMLInputElement, binding: { value: boolean }) {
    element.indeterminate = binding.value
  },
  updated(element: HTMLInputElement, binding: { value: boolean }) {
    element.indeterminate = binding.value
  },
}
</script>

<template>
  <section class="role-page-tree" data-testid="role-page-tree">
    <p class="inline-note" data-testid="page-action-separation-note">
      页面授权决定能否进入和查看；按钮动作必须单独授权，隐藏按钮不代替服务端校验。
    </p>

    <p v-if="props.pages.length === 0" class="empty-inline">页面目录为空。</p>

    <template v-else>
      <label class="role-page-tree__all">
        <input
          type="checkbox"
          data-testid="page-tree-toggle-all"
          :checked="allSelected"
          :disabled="props.disabled"
          @change="toggleAll(($event.target as HTMLInputElement).checked)"
        />
        全选页面（{{ allCodes.length }}）
      </label>

      <details v-for="group in pageGroups" :key="group.key" open class="role-page-tree__group" :data-testid="`page-group-${group.key}`">
        <summary>
          <label class="role-page-tree__group-label">
            <input
              v-indeterminate="group.state === 'partial'"
              type="checkbox"
              :data-group-toggle="group.label"
              :data-tristate="group.state"
              :checked="group.state === 'checked'"
              :disabled="props.disabled"
              @change="toggleGroup(group, ($event.target as HTMLInputElement).checked)"
            />
            {{ group.label }}
            <span class="muted small">{{ group.selectedCount }} / {{ group.pages.length }}</span>
          </label>
        </summary>
        <div class="role-page-tree__pages">
          <label v-for="page in group.pages" :key="page.page_code" class="platform-check">
            <input
              type="checkbox"
              :data-page-code="page.page_code"
              :checked="props.modelValue.includes(page.page_code)"
              :disabled="props.disabled"
              @change="togglePage(page.page_code, ($event.target as HTMLInputElement).checked)"
            />
            <span>
              {{ page.menu_label ?? page.page_code }}
              <span class="mono">{{ page.page_code }}</span>
              <span class="mono muted">{{ page.path }}</span>
            </span>
          </label>
        </div>
      </details>
    </template>
  </section>
</template>

<style scoped>
.role-page-tree {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.inline-note {
  border-left: 3px solid var(--el-color-primary);
  background: var(--el-color-primary-light-9);
  padding: 8px 12px;
  margin: 0;
}
.role-page-tree__all,
.role-page-tree__group-label {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  font-weight: 600;
}
.role-page-tree__pages {
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding: 8px 0 4px 24px;
}
.role-page-tree__pages .platform-check {
  display: flex;
  align-items: flex-start;
  gap: 8px;
}
.role-page-tree__pages .mono {
  margin-left: 6px;
}
</style>
