<script setup lang="ts">
import { computed } from 'vue'
import { translate } from '@/shared/display/dictionary'

export type PreviewPage = {
  page_code?: string
  path?: string
  menu_label?: string
  required_capability?: string
  field_profile_max?: string
}

export type PreviewAction = {
  capability: string
  anchor_type?: string
  field_profile?: string
}

export type PreviewDenied = {
  page_code: string
  path: string
  reason: string
}

export type PreviewScopeLabel = {
  anchor_type: string
  label: string
}

const props = withDefaults(
  defineProps<{
    preview?: {
      roles?: string[]
      pages?: PreviewPage[]
      actions?: PreviewAction[]
      action_codes?: string[]
      field_capabilities?: string[]
      scope_labels?: PreviewScopeLabel[]
      denied?: PreviewDenied[]
      wildcard?: boolean
    } | null
    loading?: boolean
  }>(),
  { preview: null, loading: false },
)

const MODULES: Array<{ key: string; label: string; description: string }> = [
  { key: 'iam', label: '账号与权限', description: '账号、角色、会话与审计' },
  { key: 'people', label: '人事', description: '员工档案、合同与文档' },
  { key: 'org', label: '组织与岗位', description: '组织树、岗位与编制' },
  { key: 'lifecycle', label: '入转调离', description: '入职、转正、调动与离职' },
  { key: 'workflow', label: '流程与审批', description: '审批案例与流程模板' },
  { key: 'time', label: '考勤与假勤', description: '考勤、请假、加班与出差' },
  { key: 'payroll', label: '薪酬与福利', description: '工资、福利与个税' },
  { key: 'talent', label: '招聘与绩效', description: '招聘、绩效与培训' },
  { key: 'engagement', label: '公告与通知', description: '公告、确认与消息通知' },
  { key: 'reporting', label: '报表与导出', description: '报表定义、订阅与导出' },
  { key: 'platform', label: '平台设置', description: '字典、参数与功能开关' },
]

const ANCHOR_LABELS: Record<string, string> = {
  SELF: '仅本人',
  MANAGED: '下属',
  ORG_UNIT: '本部门',
  ORG_SUBTREE: '本部门及子部门',
  CASE: '指定审批案例',
  PAYROLL_RUN: '指定工资批次',
  COMPANY: '本公司',
}

const DENIED_REASONS: Record<string, string> = {
  PAGE_NOT_GRANTED: '角色未授予该页面',
  CAPABILITY_NOT_GRANTED: '缺少动作能力',
  MENU_HIDDEN: '菜单被覆盖隐藏',
  OUT_OF_COMPANY_SCOPE: '超出公司数据范围',
}

function moduleOf(capability: string): string {
  const prefix = capability.split('.')[0] ?? ''
  return MODULES.some((module) => module.key === prefix) ? prefix : 'other'
}

function moduleLabel(key: string): string {
  return MODULES.find((module) => module.key === key)?.label ?? '其他'
}

function moduleDescription(key: string): string {
  return MODULES.find((module) => module.key === key)?.description ?? '其他系统能力'
}

function actionLabel(capability: string): string {
  const parts = capability.split('.')
  const resource: string = parts.length > 1 ? (parts[parts.length - 2] ?? capability) : capability
  const action: string = parts[parts.length - 1] ?? capability
  const resourceLabels: Record<string, string> = {
    user: '用户',
    role: '角色',
    binding: '授权绑定',
    session: '登录会话',
    audit: '审计记录',
    employee: '员工',
    contract: '合同',
    document: '员工文档',
    assignment: '任职',
    unit: '组织单元',
    tree: '组织树',
    position: '岗位',
    leave: '请假',
    overtime: '加班',
    travel: '出差',
    attendance: '考勤',
    payroll: '工资',
    payslip: '工资条',
    notice: '公告',
    report: '报表',
  }
  const actionLabels: Record<string, string> = {
    read: '查看',
    create: '新增',
    update: '编辑',
    delete: '删除',
    manage: '管理',
    export: '导出',
    approve: '审批',
    publish: '发布',
    prepare: '准备',
    review: '复核',
    execute: '执行',
    download: '下载',
    assign: '分配',
    revoke: '撤销',
    archive: '归档',
    restore: '恢复',
    submit: '提交',
    request: '申请',
    confirm: '确认',
    settle: '结算',
  }
  return `${resourceLabels[resource] ?? '数据'}：${actionLabels[action] ?? '操作'}`
}

const groups = computed(() => {
  if (props.preview === null) return []
  const grouped = new Map<string, Array<{ capability: string; label: string; anchor: string; profile: string }>>()
  for (const action of props.preview.actions ?? []) {
    if (typeof action.capability !== 'string' || action.capability === '') continue
    const module = moduleOf(action.capability)
    const rows = grouped.get(module) ?? []
    rows.push({
      capability: action.capability,
      label: actionLabel(action.capability),
      anchor: ANCHOR_LABELS[action.anchor_type ?? ''] ?? '未声明范围',
      profile: action.field_profile === undefined || action.field_profile === ''
        ? '未声明字段档位'
        : translate('platform', 'fieldProfile', action.field_profile, '未声明字段档位').label,
    })
    grouped.set(module, rows)
  }

  const orderedModules: string[] = [
    ...MODULES.map((module) => module.key).filter((key) => grouped.has(key)),
    ...[...grouped.keys()].filter((key) => !MODULES.some((module) => module.key === key)),
  ]

  return orderedModules.map((key) => ({
    key,
    label: moduleLabel(key),
    description: moduleDescription(key),
    rows: (grouped.get(key) ?? []).sort((a, b) => a.capability.localeCompare(b.capability)),
  }))
})

const fieldProfiles = computed(() => {
  const values = props.preview?.field_capabilities ?? []
  return values
    .filter((value): value is string => typeof value === 'string')
    .map((value) => translate('platform', 'fieldProfile', value, '未声明字段档位').label)
})

const scopes = computed(() => {
  const values = props.preview?.scope_labels
  if (Array.isArray(values) && values.length > 0) {
    return values
      .filter((row): row is PreviewScopeLabel => typeof row === 'object' && row !== null && typeof row.label === 'string')
      .map((row) => row.label)
  }

  const anchors = new Set<string>()
  for (const action of props.preview?.actions ?? []) {
    if (typeof action.anchor_type === 'string' && action.anchor_type !== '') {
      anchors.add(ANCHOR_LABELS[action.anchor_type] ?? action.anchor_type)
    }
  }
  return [...anchors]
})

const pages = computed(() => props.preview?.pages ?? [])
const denied = computed(() => props.preview?.denied ?? [])
const roleCodes = computed(() => (props.preview?.roles ?? []).filter((role): role is string => typeof role === 'string'))
</script>

<template>
  <section class="permission-preview" data-testid="permission-preview-panel">
    <p v-if="props.loading" class="muted">正在计算有效权限…</p>
    <p v-else-if="props.preview === null" class="muted">尚未加载权限预览。</p>
    <template v-else>
      <p class="muted small">只读预览。有效权限由服务端依据角色、数据范围与字段档位实时计算；此处修改不会影响授权。</p>

      <div class="preview-summary">
        <div>
          <strong>角色</strong>
          <p v-if="roleCodes.length === 0" class="muted">未分配角色</p>
          <p v-else>{{ roleCodes.join('、') }}</p>
        </div>
        <div>
          <strong>有效页面</strong>
          <p>{{ pages.length }} 个</p>
        </div>
        <div>
          <strong>有效动作</strong>
          <p>{{ groups.reduce((total, group) => total + group.rows.length, 0) }} 项</p>
        </div>
        <div>
          <strong>数据范围</strong>
          <p v-if="scopes.length === 0" class="muted">未声明</p>
          <p v-else>{{ scopes.join('、') }}</p>
        </div>
        <div>
          <strong>字段档位</strong>
          <p v-if="fieldProfiles.length === 0" class="muted">仅公开字段</p>
          <p v-else>{{ fieldProfiles.join('、') }}</p>
        </div>
      </div>

      <p v-if="props.preview.wildcard === true" class="inline-warning">该账号持有系统管理员通配授权，权限覆盖全部模块；仍需按最小权限原则定期复核。</p>

      <section>
        <h3 class="section-title">按模块的有效能力</h3>
        <p v-if="groups.length === 0" class="muted">该用户没有任何有效动作能力。</p>
        <div v-for="group in groups" :key="group.key" class="preview-group" :data-testid="`preview-group-${group.key}`">
          <header class="preview-group__header">
            <strong>{{ group.label }}</strong>
            <span class="muted small">{{ group.description }} · {{ group.rows.length }} 项</span>
          </header>
          <ul class="preview-group__list">
            <li v-for="row in group.rows" :key="`${row.capability}-${row.anchor}`" class="preview-group__row">
              <span>{{ row.label }}</span>
              <span class="preview-group__meta">{{ row.anchor }} · {{ row.profile }}</span>
            </li>
          </ul>
        </div>
      </section>

      <section>
        <h3 class="section-title">可访问页面</h3>
        <p v-if="pages.length === 0" class="muted">没有可访问页面。</p>
        <ul v-else class="preview-page-list">
          <li v-for="page in pages" :key="page.page_code ?? page.path">
            <span>{{ page.menu_label ?? page.page_code ?? '未命名页面' }}</span>
            <span v-if="page.field_profile_max" class="plain-chip">
              {{ translate('platform', 'fieldProfile', page.field_profile_max, '未声明字段档位').label }}
            </span>
          </li>
        </ul>
      </section>

      <section data-testid="denied-list">
        <h3 class="section-title">未授予项</h3>
        <p v-if="denied.length === 0" class="muted">没有拒绝项。</p>
        <ul v-else class="preview-denied-list">
          <li v-for="item in denied" :key="item.page_code">
            <span>{{ item.page_code }}</span>
            <span class="muted small">{{ DENIED_REASONS[item.reason] ?? '当前无权访问' }}</span>
          </li>
        </ul>
      </section>
    </template>
  </section>
</template>

<style scoped>
.permission-preview {
  display: flex;
  flex-direction: column;
  gap: 16px;
}
.preview-summary {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  gap: 12px;
}
.preview-summary strong {
  display: block;
  margin-bottom: 4px;
}
.preview-group {
  border: 1px solid var(--el-border-color-lighter);
  border-radius: 6px;
  padding: 12px;
  margin-bottom: 10px;
}
.preview-group__header {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 8px;
  margin-bottom: 8px;
}
.preview-group__list,
.preview-page-list,
.preview-denied-list {
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin: 0;
  padding: 0;
  list-style: none;
}
.preview-group__row,
.preview-page-list li,
.preview-denied-list li {
  display: flex;
  justify-content: space-between;
  gap: 12px;
}
.preview-group__meta {
  color: var(--el-text-color-secondary);
  font-size: 12px;
}
</style>
