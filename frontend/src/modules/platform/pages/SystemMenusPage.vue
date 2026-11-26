<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ApiError, errorCopy } from '@/api/errors'
import { resourceDelete, resourceGet, resourcePatch, resourcePost } from '@/api/resourceClient'
import { PAGE_REGISTRY, type PageSpec } from '@/router/registry'
import { useSessionStore } from '@/stores/session'
import { nextIdempotencyKey, rowNumber, rowText, rowsFrom, type PlatformRow } from '../platformRead'

type MenuDraft = {
  visible: boolean
  sortOrder: number
}

type MenuRow = {
  page: PageSpec
  draft: MenuDraft
  overrideLabel: string
}

const session = useSessionStore()
const canManage = computed(() => session.can('iam.menu.manage'))
const overrides = ref<PlatformRow[]>([])
const loading = ref(false)
const error = ref<string | null>(null)
const forbidden = ref(false)
const partial = ref(false)
const message = ref('')
const actionError = ref<string | null>(null)
const resetTarget = ref<MenuRow | null>(null)

const menuPages = PAGE_REGISTRY
  .filter((page): page is PageSpec & { menu: NonNullable<PageSpec['menu']> } => page.menu !== null)
  .sort((left, right) => left.menu.order - right.menu.order || left.name.localeCompare(right.name))

const rows = ref<MenuRow[]>(
  menuPages.map((page) => ({
    page,
    draft: { visible: true, sortOrder: page.menu.order },
    overrideLabel: '',
  })),
)

const overrideByPage = computed(() => {
  const map = new Map<string, PlatformRow>()
  for (const row of overrides.value) {
    const code = rowText(row, 'page_code', '')
    if (code !== '') map.set(code, row)
  }
  return map
})

const groups = computed(() => {
  const grouping = new Map<string, { key: string; label: string; order: number; rows: MenuRow[] }>()
  for (const row of rows.value) {
    const menu = row.page.menu
    if (menu === null) continue
    const group = grouping.get(menu.group) ?? {
      key: menu.group,
      label: menu.group,
      order: menu.order,
      rows: [],
    }
    group.order = Math.min(group.order, menu.order)
    group.rows.push(row)
    grouping.set(menu.group, group)
  }
  return [...grouping.values()].sort((left, right) => left.order - right.order || left.label.localeCompare(right.label))
})

function applyOverrides(): void {
  const byPage = overrideByPage.value
  for (const row of rows.value) {
    const override = byPage.get(row.page.name)
    if (override === undefined) {
      row.draft.visible = true
      row.draft.sortOrder = row.page.menu?.order ?? 0
      row.overrideLabel = ''
      continue
    }
    row.draft.visible = override.visible === true
    row.draft.sortOrder = rowNumber(override, 'sort_order') ?? row.page.menu?.order ?? 0
    row.overrideLabel = rowText(override, 'label', '')
  }
}

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  forbidden.value = false
  partial.value = false
  try {
    const payload = await resourceGet<unknown>('/v1/system/menus', { page: 1, page_size: 100 })
    overrides.value = rowsFrom(payload)
    partial.value = payload !== null && typeof payload === 'object' && (payload as PlatformRow).partial === true
    applyOverrides()
  } catch (reason) {
    if (reason instanceof ApiError && (reason.status === 403 || reason.code === 'FORBIDDEN')) {
      forbidden.value = true
    } else {
      error.value = errorCopy(reason)
    }
  } finally {
    loading.value = false
  }
}

function resetFeedback(): void {
  message.value = ''
  actionError.value = null
}

async function saveRow(row: MenuRow): Promise<void> {
  if (!canManage.value) return
  resetFeedback()
  const existing = overrideByPage.value.get(row.page.name)
  try {
    if (existing === undefined) {
      await resourcePost<unknown>(
        '/v1/system/menus',
        {
          page_code: row.page.name,
          label: row.page.menu?.label ?? null,
          visible: row.draft.visible,
          sort_order: row.draft.sortOrder,
        },
        { idempotencyKey: nextIdempotencyKey('menu-override-create') },
      )
    } else {
      await resourcePatch<unknown>(
        `/v1/system/menus/${encodeURIComponent(row.page.name)}`,
        {
          visible: row.draft.visible,
          sort_order: row.draft.sortOrder,
        },
        { idempotencyKey: nextIdempotencyKey('menu-override-update') },
      )
    }
    message.value = '菜单覆盖已保存；只影响展示，不改变页面授权。'
    await load()
  } catch (reason) {
    if (reason instanceof ApiError && reason.code === 'VALIDATION_FAILED') {
      actionError.value = '菜单覆盖未通过校验，请确认页面编码来自代码登记表。'
      return
    }
    actionError.value = errorCopy(reason)
  }
}

function requestReset(row: MenuRow): void {
  if (!canManage.value || !overrideByPage.value.has(row.page.name)) return
  resetFeedback()
  resetTarget.value = row
}

async function confirmReset(): Promise<void> {
  const row = resetTarget.value
  if (row === null || !canManage.value) return
  resetFeedback()
  try {
    await resourceDelete<unknown>(`/v1/system/menus/${encodeURIComponent(row.page.name)}`, {
      idempotencyKey: nextIdempotencyKey('menu-override-reset'),
    })
    resetTarget.value = null
    message.value = '菜单覆盖已重置，恢复代码拥有的默认展示。页面授权未发生变化。'
    await load()
  } catch (reason) {
    actionError.value = errorCopy(reason)
  }
}

onMounted(load)
</script>

<template>
  <div class="page-stack" data-testid="system-menus-page">
    <header class="page-header">
      <div>
        <h1>菜单 / 页面管理</h1>
        <p class="muted">菜单树来自代码页面登记表；此页只调整当前公司的展示名称、排序与可见性，不改变能力判定。</p>
      </div>
      <div class="platform-inline-actions">
        <span class="plain-chip">{{ overrides.length }} 条覆盖</span>
        <button type="button" class="plain-chip" :disabled="loading" @click="load">刷新</button>
      </div>
    </header>

    <p v-if="!canManage" class="inline-warning">只读：当前账号缺少 iam.menu.manage，不能保存或重置菜单覆盖。</p>
    <p v-if="forbidden" class="inline-error" role="alert">你没有读取菜单治理数据的权限。如需访问，请提交授权申请。</p>
    <p v-if="error" class="inline-error" role="alert">{{ error }}</p>
    <p v-if="partial" class="inline-warning">部分数据读取失败，已保留成功返回的菜单覆盖。</p>
    <p v-if="message" class="inline-success">{{ message }}</p>
    <p v-if="actionError" class="inline-error" role="alert">{{ actionError }}</p>
    <p v-if="loading" class="muted" role="status">加载菜单与页面登记表…</p>

    <p v-if="!loading && !forbidden && !error && groups.length === 0" class="empty-inline">暂无已登记的菜单页面。</p>

    <section v-for="group in groups" :key="group.key" class="panel" :data-testid="`menu-group-${group.key}`">
      <div class="panel__header">
        <h2 class="panel__title">{{ group.label }}</h2>
        <span class="plain-chip">{{ group.rows.length }} 个页面</span>
      </div>
      <div class="platform-table-wrap">
        <table class="platform-table">
          <caption class="visually-hidden">{{ group.label }}菜单项</caption>
          <thead>
            <tr>
              <th scope="col">页面编码</th>
              <th scope="col">页面 / 覆盖标签</th>
              <th scope="col">所需能力</th>
              <th scope="col">可见</th>
              <th scope="col">排序</th>
              <th scope="col">操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in group.rows" :key="row.page.name" :data-testid="`menu-row-${row.page.name}`">
              <td class="mono">{{ row.page.name }}</td>
              <td>
                <span>{{ row.page.menu?.label ?? row.page.name }}</span>
                <span v-if="row.overrideLabel !== '' && row.overrideLabel !== row.page.menu?.label" class="muted"> · 覆盖：{{ row.overrideLabel }}</span>
              </td>
              <td class="mono small">{{ row.page.capability }}</td>
              <td>
                <label class="platform-check">
                  <input
                    v-model="row.draft.visible"
                    type="checkbox"
                    :disabled="!canManage"
                    :data-testid="`menu-visible-${row.page.name}`"
                  />
                  {{ row.draft.visible ? '显示' : '隐藏' }}
                </label>
              </td>
              <td>
                <input
                  v-model.number="row.draft.sortOrder"
                  type="number"
                  min="0"
                  max="100000"
                  :disabled="!canManage"
                  :data-testid="`menu-order-${row.page.name}`"
                />
              </td>
              <td>
                <div class="platform-inline-actions">
                  <button
                    type="button"
                    :disabled="!canManage"
                    :data-testid="`menu-save-${row.page.name}`"
                    @click="saveRow(row)"
                  >
                    保存覆盖
                  </button>
                  <button
                    type="button"
                    :disabled="!canManage || !overrideByPage.has(row.page.name)"
                    :data-testid="`menu-reset-${row.page.name}`"
                    @click="requestReset(row)"
                  >
                    恢复默认
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <section v-if="resetTarget" class="panel" :data-testid="`menu-reset-confirm-${resetTarget.page.name}`">
      <div class="panel__header"><h2 class="panel__title">确认恢复默认?</h2></div>
      <div class="panel__body stack">
        <p>将删除页面 <span class="mono">{{ resetTarget.page.name }}</span> 的菜单覆盖，恢复代码登记表的默认标签、排序与可见性。页面能力和 API 授权不会改变。</p>
        <div class="platform-inline-actions">
          <button type="button" class="danger-button" :data-testid="`menu-reset-confirm-button-${resetTarget.page.name}`" @click="confirmReset">
            确认恢复默认
          </button>
          <button type="button" @click="resetTarget = null">取消</button>
        </div>
      </div>
    </section>
  </div>
</template>
