<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import DataTable, { type DataTableColumn } from '@/components/DataTable.vue'
import { ApiError, errorCopy } from '@/api/errors'
import { resourceGet } from '@/api/resourceClient'
import { PAGE_REGISTRY } from '@/router/registry'
import { DISPLAY_CODES, translate } from '@/shared/display/dictionary'
import { rowText, rowsFrom, type PlatformRow } from '../platformRead'

const MODULE_LABELS: Record<string, string> = {
  iam: '身份与权限',
  org: '组织架构',
  people: '员工档案',
  payroll: '薪资核算',
  time: '考勤假期',
  talent: '人才发展',
  recruitment: '招聘管理',
  performance: '绩效管理',
  training: '培训管理',
  engagement: '员工互动',
  notification: '通知中心',
  reporting: '报表分析',
  system: '系统设置',
  benefit: '福利管理',
  workflow: '审批流程',
  lifecycle: '员工生命周期',
}

const ANCHOR_LABELS: Record<string, string> = {
  SELF: '仅本人',
  MANAGED: '下属',
  ORG_UNIT: '本部门',
  ORG_SUBTREE: '本部门及子部门',
  CASE: '指定案例',
  PAYROLL_RUN: '指定工资批次',
  COMPANY: '本公司',
}

const ACTION_LABELS: Record<string, string> = {
  read: '查看',
  create: '新增',
  update: '编辑',
  delete: '删除',
  manage: '管理',
  sort: '排序',
  export: '导出',
  import: '导入',
  approve: '审批',
  review: '复核',
  publish: '发布',
  prepare: '编制',
  close: '关账',
  correct: '更正',
  reopen: '重开',
  admin: '管理',
  assign: '分配',
  revoke: '撤销',
  submit: '提交',
  terminate: '终止',
  restore: '恢复',
  archive: '归档',
  download: '下载',
  grant: '授予',
  adjust: '调整',
  request: '申请',
  cancel: '取消',
  confirm: '确认',
  decide: '决定',
  reassign: '改派',
  transfer: '调动',
  rehire: '返聘',
  renew: '续签',
  amend: '变更',
  version: '版本化',
  publish_version: '发布版本',
  settle: '结算',
  reverse: '冲销',
  complete: '完成',
}

const capabilities = ref<PlatformRow[]>([])
const loading = ref(false)
const error = ref<string | null>(null)
const forbidden = ref(false)
const partial = ref(false)
const search = ref('')
const moduleFilter = ref('')

type CapabilityUsage = { pageCount: number; actionCount: number; pageNames: string[] }

const usage = computed(() => {
  const map = new Map<string, CapabilityUsage>()
  const touch = (code: string): CapabilityUsage => {
    const entry = map.get(code) ?? { pageCount: 0, actionCount: 0, pageNames: [] }
    map.set(code, entry)
    return entry
  }
  for (const page of PAGE_REGISTRY) {
    const codes = new Set<string>()
    if (page.capability !== '' && page.capability !== 'public') codes.add(page.capability)
    for (const action of page.actions) codes.add(action.capability)
    for (const code of codes) {
      const entry = touch(code)
      entry.pageCount += 1
      entry.pageNames.push(page.menu?.label ?? page.name)
    }
    for (const action of page.actions) {
      touch(action.capability).actionCount += 1
    }
  }
  return map
})

const rows = computed(() =>
  capabilities.value.map((row) => {
    const code = rowText(row, 'code', '')
    const module = code.split('.')[0] ?? code
    const anchorTypes = Array.isArray(row.anchor_types)
      ? row.anchor_types.filter((anchor): anchor is string => typeof anchor === 'string')
      : []
    const actions = Array.isArray(row.actions)
      ? row.actions.filter((action): action is string => typeof action === 'string')
      : []
    const count = usage.value.get(code) ?? { pageCount: 0, actionCount: 0, pageNames: [] }
    return {
      code,
      module,
      moduleLabel: MODULE_LABELS[module] ?? module,
      label: capabilityLabel(module, actions),
      anchorTypes,
      fieldProfileMax: rowText(row, 'field_profile_max', DISPLAY_CODES.fieldProfile.p0Public),
      description: rowText(row, 'description', ''),
      risk: rowText(row, 'risk', ''),
      pageCount: count.pageCount,
      actionCount: count.actionCount,
      pageNames: count.pageNames,
    }
  }),
)

const modules = computed(() => {
  const seen = new Map<string, string>()
  for (const row of rows.value) {
    if (!seen.has(row.module)) seen.set(row.module, row.moduleLabel)
  }
  return [...seen.entries()].map(([value, label]) => ({ value, label })).sort((left, right) => left.label.localeCompare(right.label))
})

const filteredRows = computed(() => {
  const query = search.value.trim().toLowerCase()
  return rows.value
    .filter((row) => moduleFilter.value === '' || row.module === moduleFilter.value)
    .filter((row) => {
      if (query === '') return true
      return [row.code, row.label, row.moduleLabel, row.description].some((value) => value.toLowerCase().includes(query))
    })
    .sort((left, right) => left.code.localeCompare(right.code))
})

const permissionColumns: DataTableColumn[] = [
  { key: 'code', label: '能力码 / 名称', minWidth: '220px' },
  { key: 'anchorTypes', label: '锚点类型', minWidth: '150px' },
  { key: 'fieldProfileMax', label: '字段档位上限', width: '130px' },
  { key: 'usage', label: '使用情况', minWidth: '220px' },
  { key: 'description', label: '说明', minWidth: '160px' },
]

const groups = computed(() => {
  const grouping = new Map<string, { value: string; label: string; rows: typeof filteredRows.value }>()
  for (const row of filteredRows.value) {
    const group = grouping.get(row.module) ?? { value: row.module, label: row.moduleLabel, rows: [] }
    group.rows.push(row)
    grouping.set(row.module, group)
  }
  return [...grouping.values()].sort((left, right) => left.label.localeCompare(right.label))
})

function capabilityLabel(module: string, actions: string[]): string {
  const resource = module === 'people' ? '员工数据' : (MODULE_LABELS[module] ?? module)
  const actionText = actions.map((action) => ACTION_LABELS[action] ?? action).join(' / ')
  return actionText === '' ? `${resource}访问` : `${resource} · ${actionText}`
}

function anchorText(anchor: string): string {
  return ANCHOR_LABELS[anchor] ?? anchor
}

function profileText(profile: string): string {
  return translate('platform', 'fieldProfile', profile, '未知字段档位').label
}

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  forbidden.value = false
  partial.value = false
  try {
    const payload = await resourceGet<unknown>('/v1/capabilities')
    capabilities.value = rowsFrom(payload, 'capabilities')
    partial.value = payload !== null && typeof payload === 'object' && (payload as PlatformRow).partial === true
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

onMounted(load)
</script>

<template>
  <div class="page-stack" data-testid="system-permissions-page">
    <header class="page-header">
      <div>
        <h1>操作权限目录</h1>
        <p class="muted">能力码由代码拥有，本页只读。页面与动作覆盖计数来自代码页面登记表；不在此页授予或撤销权限。</p>
      </div>
      <span class="plain-chip">{{ rows.length }} 项能力 · 只读</span>
    </header>

    <p v-if="forbidden" class="inline-error" role="alert">你没有读取权限目录的权限。如需访问，请提交授权申请。</p>
    <p v-if="error" class="inline-error" role="alert">{{ error }}</p>
    <p v-if="partial" class="inline-warning">部分能力数据读取失败，已保留成功返回的目录项。</p>
    <p v-if="loading" class="muted" role="status">加载能力目录…</p>

    <section class="panel">
      <div class="panel__body platform-form-grid">
        <label class="platform-field">
          <span>搜索能力码、名称或说明</span>
          <el-input v-model="search" data-testid="permission-search" placeholder="例如 iam.permission.read" />
        </label>
        <label class="platform-field">
          <span>模块</span>
          <el-select v-model="moduleFilter" data-testid="permission-module-filter">
            <el-option value="" label="全部模块" />
            <el-option
              v-for="module in modules"
              :key="module.value"
              :value="module.value"
              :label="module.label"
            />
          </el-select>
        </label>
      </div>
    </section>

    <p v-if="!loading && !forbidden && !error && filteredRows.length === 0" class="empty-inline">
      {{ rows.length === 0 ? '能力目录没有返回记录。' : '当前筛选条件没有匹配的能力。' }}
    </p>

    <section v-for="group in groups" :key="group.value" class="panel" :data-testid="`permission-group-${group.value}`">
      <div class="panel__header">
        <h2 class="panel__title">{{ group.label }}</h2>
        <span class="plain-chip">{{ group.rows.length }} 项</span>
      </div>
      <DataTable
        :columns="permissionColumns"
        :rows="group.rows as unknown as Array<Record<string, unknown>>"
        :total="group.rows.length"
        :page="1"
        :page-size="Math.max(1, group.rows.length)"
        data-testid="permission-table"
        empty-title="当前筛选条件没有匹配的能力"
      >
        <template #cell-code="{ row }">
          <div class="mono">{{ row.code }}</div>
          <div class="small">{{ row.label }}</div>
        </template>
        <template #cell-anchorTypes="{ row }">
          <span v-for="anchor in row.anchorTypes as string[]" :key="anchor" class="plain-chip">{{ anchorText(anchor) }}</span>
          <span v-if="(row.anchorTypes as string[]).length === 0" class="muted">—</span>
        </template>
        <template #cell-fieldProfileMax="{ row }">{{ profileText(String(row.fieldProfileMax)) }}</template>
        <template #cell-usage="{ row }">
          <div :data-testid="`permission-usage-${String(row.code)}`">
            {{ row.pageCount }} 个页面 / {{ row.actionCount }} 个动作
            <div class="small muted">
              {{ (row.pageNames as string[]).length === 0 ? '暂无页面使用' : `使用页面：${(row.pageNames as string[]).join('、')}` }}
            </div>
          </div>
        </template>
        <template #cell-description="{ row }">
          <span class="small">{{ row.description === '' ? '—' : row.description }}</span>
        </template>
      </DataTable>
    </section>

    <p class="small muted">
      页面授权在角色管理中维护；能力码从代码目录读取，不能在前端新建。
    </p>
  </div>
</template>
