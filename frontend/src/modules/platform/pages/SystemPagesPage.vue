<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import PageShell from '@/shared/ui/PageShell.vue'
import DataTable, { type DataTableColumn } from '@/components/DataTable.vue'
import { ApiError, errorCopy } from '@/api/errors'
import { resourceDelete, resourceGet, resourcePatch, resourcePost } from '@/api/resourceClient'
import { useSessionStore } from '@/stores/session'
import { nextIdempotencyKey, rowNumber, rowText, rowsFrom, type PlatformRow } from '../platformRead'

type CataloguePage = {
  page_code: string
  path: string
  menu_group: string
  menu_label: string
  required_capability: string
  actions: string[]
  order: number
}

type MenuOverride = {
  page_code: string
  label: string | null
  visible: boolean
  sort_order: number
}

type MenuDraft = {
  visible: boolean
  label: string
  sortOrder: number
}

const session = useSessionStore()
const canReadCatalogue = computed(() => session.can('iam.permission.read') || session.can('iam.menu.manage'))
const canManageMenu = computed(() => session.can('iam.menu.manage'))
const canReadCapabilities = computed(() => session.can('iam.permission.read'))

const loading = ref(true)
const loadError = ref<string | null>(null)
const forbidden = ref(false)
const partial = ref(false)
const actionError = ref('')
const message = ref('')

const pages = ref<CataloguePage[]>([])
const overrides = ref<MenuOverride[]>([])
const capabilities = ref<PlatformRow[]>([])
const menuDrafts = ref<Record<string, MenuDraft>>({})
const savingPageCode = ref('')
const technicalOpen = ref(false)

const overrideByPage = computed(() => new Map(overrides.value.map((override) => [override.page_code, override])))

function stringArray(value: unknown): string[] {
  return Array.isArray(value) ? value.filter((entry): entry is string => typeof entry === 'string') : []
}

function normalisePage(row: PlatformRow): CataloguePage {
  return {
    page_code: rowText(row, 'page_code', ''),
    path: rowText(row, 'path', ''),
    menu_group: rowText(row, 'menu_group', ''),
    menu_label: rowText(row, 'menu_label', rowText(row, 'page_code', '未命名页面')),
    required_capability: rowText(row, 'required_capability', ''),
    actions: stringArray(row.actions),
    order: rowNumber(row, 'order') ?? 999,
  }
}

function setDrafts(): void {
  const next: Record<string, MenuDraft> = {}
  for (const page of pages.value) {
    const override = overrideByPage.value.get(page.page_code)
    next[page.page_code] = {
      visible: override?.visible ?? true,
      label: override?.label ?? page.menu_label,
      sortOrder: override?.sort_order ?? page.order,
    }
  }
  menuDrafts.value = next
}

async function loadCatalogue(): Promise<void> {
  const catalogue = await resourceGet<unknown>('/v1/pages/catalog')
  pages.value = rowsFrom(catalogue, 'pages').map(normalisePage).filter((page) => page.page_code !== '')
}

async function loadOverrides(): Promise<void> {
  const menuPayload = await resourceGet<unknown>('/v1/system/menus', { page: 1, page_size: 100 })
  overrides.value = rowsFrom(menuPayload).map((row) => ({
    page_code: rowText(row, 'page_code', ''),
    label: typeof row.label === 'string' ? row.label : null,
    visible: row.visible === true,
    sort_order: rowNumber(row, 'sort_order') ?? 0,
  }))
}

async function loadCapabilities(): Promise<void> {
  if (!canReadCapabilities.value) return
  const payload = await resourceGet<unknown>('/v1/capabilities')
  capabilities.value = rowsFrom(payload, 'capabilities')
}

async function load(): Promise<void> {
  if (!canReadCatalogue.value) {
    forbidden.value = true
    loading.value = false
    return
  }

  loading.value = true
  loadError.value = null
  forbidden.value = false
  partial.value = false
  actionError.value = ''

  try {
    await loadCatalogue()
  } catch (reason) {
    if (reason instanceof ApiError && (reason.status === 403 || reason.code === 'FORBIDDEN')) {
      forbidden.value = true
    } else {
      loadError.value = errorCopy(reason)
    }
    loading.value = false
    return
  }

  const [menuResult, capabilityResult] = await Promise.allSettled([loadOverrides(), loadCapabilities()])
  if (menuResult.status === 'rejected' || capabilityResult.status === 'rejected') partial.value = true
  setDrafts()
  loading.value = false
}

const overrideCount = computed(() => overrides.value.length)

const capabilityMatrix = computed(() =>
  pages.value.flatMap((page) =>
    page.actions.map((capability) => ({
      id: `${page.page_code}:${capability}`,
      pageLabel: page.menu_label,
      capability,
      definition: capabilities.value.find((entry) => rowText(entry, 'code', '') === capability) ?? null,
    })),
  ),
)

const catalogueColumns: DataTableColumn[] = [
  { key: 'menu_label', label: '页面名称', minWidth: '150px' },
  { key: 'menu_group', label: '菜单分组', minWidth: '130px' },
  { key: 'path', label: '访问路径', minWidth: '200px' },
  { key: 'actions', label: '可授权动作', minWidth: '220px' },
  { key: 'menu', label: '菜单覆盖', minWidth: '360px' },
]

const matrixColumns: DataTableColumn[] = [
  { key: 'pageLabel', label: '页面', minWidth: '150px' },
  { key: 'capability', label: '动作能力', minWidth: '220px' },
  { key: 'risk', label: '风险级别', width: '100px' },
  { key: 'anchors', label: '可用数据范围', minWidth: '180px' },
  { key: 'profile', label: '字段上限', width: '120px' },
]

const ANCHOR_LABELS: Record<string, string> = {
  SELF: '仅本人',
  MANAGED: '下属',
  ORG_UNIT: '本部门',
  ORG_SUBTREE: '本部门及子部门',
  COMPANY: '本公司',
  CASE: '指定案例',
  PAYROLL_RUN: '指定工资批次',
}
const RISK_LABELS: Record<string, string> = { low: '低', medium: '中', high: '高', critical: '极高' }

const CAPABILITY_RESOURCES: Record<string, string> = {
  user: '用户', role: '角色', binding: '授权绑定', session: '登录会话', audit: '审计',
  employee: '员工', contract: '合同', document: '员工文档', assignment: '任职', unit: '组织单元',
  tree: '组织树', position: '岗位', leave: '请假', overtime: '加班', travel: '出差',
  attendance: '考勤', payroll: '薪资', payslip: '工资条', notice: '公告', report: '报表',
  permission_resource: '权限资源', menu: '菜单', data_scope: '数据范围', field_policy: '字段策略',
  system_parameter: '系统参数', dictionary: '字典',
}
const CAPABILITY_ACTIONS: Record<string, string> = {
  read: '查看', create: '新增', update: '编辑', delete: '删除', manage: '管理', export: '导出',
  approve: '审批', publish: '发布', prepare: '准备', review: '复核', execute: '执行',
  download: '下载', assign: '分配', revoke: '撤销', archive: '归档', restore: '恢复',
  submit: '提交', request: '申请', confirm: '确认', settle: '结算', sort: '排序',
}

function capabilityLabel(code: string): string {
  const parts = code.split('.')
  const resource = parts.length > 1 ? parts[parts.length - 2] ?? '' : code
  const action = parts[parts.length - 1] ?? ''
  return `${CAPABILITY_RESOURCES[resource] ?? '业务数据'} · ${CAPABILITY_ACTIONS[action] ?? '操作'}`
}

function anchorsOf(entry: PlatformRow | null): string {
  if (entry === null) return '以能力目录为准'
  const anchors = stringArray(entry.anchor_types)
  return anchors.length === 0 ? '以能力目录为准' : anchors.map((anchor) => ANCHOR_LABELS[anchor] ?? anchor).join('、')
}

function riskOf(entry: PlatformRow | null): string {
  const value = entry === null ? '' : rowText(entry, 'risk', '')
  return RISK_LABELS[value] ?? (value === '' ? '未标注' : value)
}

function draftFor(pageCode: string): MenuDraft {
  return menuDrafts.value[pageCode] ?? { visible: true, label: '', sortOrder: 0 }
}

function hasOverride(pageCode: string): boolean {
  return overrideByPage.value.has(pageCode)
}

async function saveMenu(pageCode: string): Promise<void> {
  if (!canManageMenu.value || savingPageCode.value !== '') return
  const draft = draftFor(pageCode)
  const page = pages.value.find((entry) => entry.page_code === pageCode)
  if (page === undefined) return
  savingPageCode.value = pageCode
  actionError.value = ''
  message.value = ''
  try {
    const label = draft.label.trim() === '' ? null : draft.label.trim()
    if (hasOverride(pageCode)) {
      await resourcePatch<unknown>(
        `/v1/system/menus/${encodeURIComponent(pageCode)}`,
        { label, visible: draft.visible, sort_order: draft.sortOrder },
        { idempotencyKey: nextIdempotencyKey(`menu-override-${pageCode}`) },
      )
    } else {
      await resourcePost<unknown>(
        '/v1/system/menus',
        { page_code: pageCode, label, visible: draft.visible, sort_order: draft.sortOrder },
        { idempotencyKey: nextIdempotencyKey(`menu-override-${pageCode}`) },
      )
    }
    message.value = `“${page.menu_label}”的菜单展示已保存；页面授权与动作能力未发生变化。`
    await loadOverrides()
    setDrafts()
  } catch (reason) {
    actionError.value = errorCopy(reason)
  } finally {
    savingPageCode.value = ''
  }
}

async function resetMenu(pageCode: string): Promise<void> {
  if (!canManageMenu.value || !hasOverride(pageCode) || savingPageCode.value !== '') return
  savingPageCode.value = pageCode
  actionError.value = ''
  message.value = ''
  try {
    await resourceDelete<unknown>(`/v1/system/menus/${encodeURIComponent(pageCode)}`, {
      idempotencyKey: nextIdempotencyKey(`menu-override-reset-${pageCode}`),
    })
    message.value = '菜单展示已恢复为代码目录默认值；页面授权与动作能力未发生变化。'
    await loadOverrides()
    setDrafts()
  } catch (reason) {
    actionError.value = errorCopy(reason)
  } finally {
    savingPageCode.value = ''
  }
}

function resetDraft(pageCode: string): void {
  const page = pages.value.find((entry) => entry.page_code === pageCode)
  if (page === undefined) return
  const override = overrideByPage.value.get(pageCode)
  menuDrafts.value = {
    ...menuDrafts.value,
    [pageCode]: {
      visible: override?.visible ?? true,
      label: override?.label ?? page.menu_label,
      sortOrder: override?.sort_order ?? page.order,
    },
  }
}

onMounted(load)
</script>

<template>
  <PageShell
    data-testid="system-pages-page"
    title="页面目录"
    description="页面与动作由代码注册表维护；此处可维护菜单显示名称、排序与可见性。展示设置不会授予或撤销任何权限。"
  >
    <template #actions>
      <el-button plain :disabled="loading" @click="load">刷新</el-button>
      <span class="plain-chip">{{ pages.length }} 个页面 · {{ overrideCount }} 条展示覆盖</span>
    </template>

    <section v-if="forbidden" class="panel" data-testid="system-pages-forbidden">
      <div class="panel__body">
        <h2 class="panel__title">无权访问页面目录</h2>
        <p class="muted">当前账号缺少页面目录读取权限，请联系系统管理员。菜单维护需要“页面目录维护”权限。</p>
      </div>
    </section>

    <p v-else-if="loadError" class="inline-error" role="alert" data-testid="system-pages-error">{{ loadError }}</p>
    <p v-if="partial" class="inline-warning" data-testid="system-pages-partial">
      页面目录已加载，但菜单覆盖或能力目录读取失败；目录内容仍可查看，请稍后刷新。
    </p>
    <p v-if="!canManageMenu && !forbidden" class="inline-warning" data-testid="system-pages-readonly">
      当前账号仅可查看页面目录；菜单覆盖为只读。
    </p>
    <p v-if="message" class="inline-success" data-testid="system-pages-message">{{ message }}</p>
    <p v-if="actionError" class="inline-error" role="alert" data-testid="system-pages-action-error">{{ actionError }}</p>

    <section v-if="loading" class="panel" data-testid="system-pages-loading">
      <div class="panel__body">
        <el-skeleton :rows="6" animated />
        <span class="visually-hidden">正在加载页面目录</span>
      </div>
    </section>

    <section v-else-if="!forbidden && loadError === null && pages.length === 0" class="panel" data-testid="system-pages-empty">
      <div class="panel__body">
        <h2 class="panel__title">页面目录为空</h2>
        <p class="muted">代码注册表尚未返回页面，请检查后端目录或联系系统管理员。</p>
      </div>
    </section>

    <template v-else-if="!forbidden && loadError === null">
      <section class="panel" data-testid="page-catalog">
        <div class="panel__header">
          <div>
            <h2 class="panel__title">页面目录与菜单覆盖</h2>
            <p class="panel__subtitle">仅调整菜单显示；页面访问仍由角色授权和服务端能力校验决定。</p>
          </div>
        </div>
        <DataTable
          :columns="catalogueColumns"
          :rows="pages as unknown as Array<Record<string, unknown>>"
          :total="pages.length"
          :page="1"
          :page-size="Math.max(1, pages.length)"
          row-key="page_code"
          data-testid="page-catalog-table"
          empty-title="页面目录为空"
        >
          <template #cell-menu_label="{ row }">
            <div>{{ row.menu_label }}</div>
            <el-collapse v-if="technicalOpen" class="small muted">
              <el-collapse-item title="管理员技术明细">
                <span class="mono">{{ row.page_code }}</span>
              </el-collapse-item>
            </el-collapse>
          </template>
          <template #cell-menu_group="{ row }">
            <span>{{ row.menu_group === '' ? '其他页面' : row.menu_group }}</span>
          </template>
          <template #cell-path="{ row }">
            <span class="mono">{{ row.path }}</span>
          </template>
          <template #cell-actions="{ row }">
            <span v-if="(row.actions as string[]).length === 0" class="muted">仅页面访问</span>
            <template v-else>
              <span v-for="capability in row.actions as string[]" :key="capability" class="plain-chip mono">{{ capability }}</span>
            </template>
          </template>
          <template #cell-menu="{ row }">
            <div class="menu-override-grid">
              <el-input
                v-model="draftFor(String(row.page_code)).label"
                :disabled="!canManageMenu"
                :data-testid="`menu-label-${String(row.page_code)}`"
                maxlength="160"
                placeholder="显示名称"
              />
              <el-checkbox
                v-model="draftFor(String(row.page_code)).visible"
                :disabled="!canManageMenu"
                :data-testid="`menu-visible-${String(row.page_code)}`"
              >
                {{ draftFor(String(row.page_code)).visible ? '显示' : '隐藏' }}
              </el-checkbox>
              <el-input-number
                v-model="draftFor(String(row.page_code)).sortOrder"
                :min="0"
                :max="100000"
                :disabled="!canManageMenu"
                controls-position="right"
                :data-testid="`menu-order-${String(row.page_code)}`"
              />
              <el-button
                type="primary"
                size="small"
                :loading="savingPageCode === String(row.page_code)"
                :disabled="!canManageMenu || savingPageCode !== ''"
                :data-testid="`menu-override-save-${String(row.page_code)}`"
                @click="saveMenu(String(row.page_code))"
              >
                保存展示
              </el-button>
              <el-button
                size="small"
                plain
                :disabled="!canManageMenu || !hasOverride(String(row.page_code)) || savingPageCode !== ''"
                :data-testid="`menu-override-reset-${String(row.page_code)}`"
                @click="resetMenu(String(row.page_code))"
              >
                恢复默认
              </el-button>
              <el-button size="small" text :disabled="savingPageCode !== ''" @click="resetDraft(String(row.page_code))">
                撤销未保存修改
              </el-button>
            </div>
          </template>
        </DataTable>
        <div class="panel__body">
          <el-checkbox v-model="technicalOpen" data-testid="page-catalog-technical-toggle">显示管理员技术明细</el-checkbox>
        </div>
      </section>

      <section class="panel" data-testid="capability-matrix">
        <div class="panel__header">
          <div>
            <h2 class="panel__title">能力动作矩阵</h2>
            <p class="panel__subtitle">只读展示页面可独立授权的动作能力。授予与撤回请在角色管理的“按钮动作”中完成。</p>
          </div>
        </div>
        <p v-if="!canReadCapabilities" class="inline-warning">当前账号没有能力目录读取权限，矩阵仅展示页面声明的动作。</p>
        <p v-else-if="capabilityMatrix.length === 0" class="empty-inline">当前页面目录没有声明可独立授权的动作。</p>
        <DataTable
          v-else
          :columns="matrixColumns"
          :rows="capabilityMatrix as unknown as Array<Record<string, unknown>>"
          :total="capabilityMatrix.length"
          :page="1"
          :page-size="Math.max(1, capabilityMatrix.length)"
          row-key="id"
          data-testid="capability-matrix-table"
          empty-title="没有可显示的动作能力"
        >
          <template #cell-capability="{ row }">
            <span>{{ capabilityLabel(String(row.capability)) }}</span>
            <el-collapse class="small muted">
              <el-collapse-item title="管理员技术明细">
                <span class="mono">{{ row.capability }}</span>
              </el-collapse-item>
            </el-collapse>
          </template>
          <template #cell-risk="{ row }">
            <span>{{ riskOf(row.definition as PlatformRow | null) }}</span>
          </template>
          <template #cell-anchors="{ row }">
            <span>{{ anchorsOf(row.definition as PlatformRow | null) }}</span>
          </template>
          <template #cell-profile="{ row }">
            <span>{{ (row.definition as PlatformRow | null) === null ? '以能力目录为准' : rowText(row.definition as PlatformRow, 'field_profile_max', '以能力目录为准') }}</span>
          </template>
        </DataTable>
      </section>
    </template>
  </PageShell>
</template>

<style scoped>
.menu-override-grid {
  display: grid;
  grid-template-columns: minmax(150px, 1fr) auto minmax(100px, 120px);
  gap: 8px;
  align-items: center;
}
@media (max-width: 900px) {
  .menu-override-grid {
    grid-template-columns: 1fr;
  }
}
</style>
