<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { useSessionStore } from '@/stores/session'
import {
  createWorkflowTemplate,
  errorCopy,
  isForbidden as apiIsForbidden,
  listWorkflowTemplates,
  type WorkflowTemplate,
} from '../api'
import { formatDateTimeInTimezone } from '@/utils/format'
import { nextIdempotencyKey } from '../../platformRead'
import { caseTypeLabel } from '../../labels'

const session = useSessionStore()
const canManage = computed(() => session.can('workflow.case.admin'))

const loading = ref(true)
const loadError = ref<unknown>(null)
const templates = ref<WorkflowTemplate[]>([])
const drawerOpen = ref(false)
const saving = ref(false)
const actionError = ref('')
const actionMessage = ref('')

const draft = reactive({
  code: '',
  name: '',
  version: 1,
  status: 'DRAFT',
  nodeSummary: 'start -> approval -> end',
  effectSummary: 'workflow.noop',
})

const forbidden = computed(() => loadError.value !== null && apiIsForbidden(loadError.value))
const isEmpty = computed(() => !loading.value && loadError.value === null && templates.value.length === 0)

const grouped = computed(() => {
  const map = new Map<string, WorkflowTemplate[]>()
  for (const template of templates.value) {
    const group = map.get(template.code) ?? []
    group.push(template)
    map.set(template.code, group)
  }

  return [...map.entries()]
    .map(([code, versions]) => ({
      code,
      label: versions[0]?.name ?? caseTypeLabel(code),
      versions: [...versions].sort((left, right) => right.version - left.version),
    }))
    .sort((left, right) => left.code.localeCompare(right.code))
})

function templateStatusLabel(status: string): string {
  if (status === 'PUBLISHED') return '已发布'
  if (status === 'ARCHIVED') return '已归档'
  if (status === 'DRAFT') return '草稿'
  return '其他状态'
}

function templateStatusTone(status: string): string {
  if (status === 'PUBLISHED') return 'plain-chip--success'
  if (status === 'DRAFT') return 'plain-chip--warning'
  return 'plain-chip--muted'
}

async function load(): Promise<void> {
  loading.value = true
  loadError.value = null
  try {
    templates.value = await listWorkflowTemplates()
  } catch (error) {
    templates.value = []
    loadError.value = error
  } finally {
    loading.value = false
  }
}

function openCreate(): void {
  if (!canManage.value) return
  draft.code = ''
  draft.name = ''
  draft.version = latestVersion(draft.code) + 1
  draft.status = 'DRAFT'
  draft.nodeSummary = 'start -> approval -> end'
  draft.effectSummary = 'workflow.noop'
  actionError.value = ''
  actionMessage.value = ''
  drawerOpen.value = true
}

function latestVersion(code: string): number {
  return templates.value
    .filter((template) => template.code === code.trim())
    .reduce((max, template) => Math.max(max, template.version), 0)
}

function onCodeChange(): void {
  const code = draft.code.trim()
  const existing = templates.value.find((template) => template.code === code)
  if (existing !== undefined && draft.name.trim() === '') {
    draft.name = existing.name
  }
  draft.version = latestVersion(code) + 1
}

function definitionForDraft(): Record<string, unknown> {
  const code = draft.code.trim()
  // Versioned declarative definition only; no scripts, SQL or arbitrary BPMN.
  return {
    nodes: [
      { key: 'start', name: '发起环节', type: 'START', sequence: 1 },
      {
        key: 'approval',
        name: '审批处理',
        type: 'APPROVAL',
        sequence: 2,
        assignee_rule: { type: 'ROLE', role_code: 'HR_ADMIN' },
        required_count: 1,
        sla_hours: 24,
      },
      { key: 'end', name: '结束环节', type: 'END', sequence: 3 },
    ],
    effects: draft.effectSummary.trim() === '' || draft.effectSummary.trim() === '无'
      ? []
      : [{
          type: draft.effectSummary.trim(),
          execution: 'LOCAL',
          aggregate_type: 'case',
          aggregate_id_field: 'case_id',
        }],
    metadata: { code },
  }
}

async function submit(): Promise<void> {
  if (!canManage.value) return
  const code = draft.code.trim()
  const name = draft.name.trim()

  if (!/^[a-z][a-z0-9_.-]{1,63}$/.test(code)) {
    actionError.value = '模板代码需以小写字母开头，仅包含小写字母、数字、点、下划线或连字符。'
    return
  }
  if (name === '') {
    actionError.value = '请填写模板名称。'
    return
  }
  if (!Number.isSafeInteger(draft.version) || draft.version < 1) {
    actionError.value = '版本号必须是大于 0 的整数。'
    return
  }

  saving.value = true
  actionError.value = ''
  actionMessage.value = ''
  try {
    await createWorkflowTemplate({
      code,
      name,
      version: draft.version,
      status: draft.status,
      definition: definitionForDraft(),
    }, nextIdempotencyKey(`workflow-template-${code}-${draft.version}`))
    actionMessage.value = `已保存模板 ${code} 第 ${draft.version} 版；历史案例仍使用原版本快照。`
    ElMessage.success(actionMessage.value)
    drawerOpen.value = false
    await load()
  } catch (error) {
    actionError.value = errorCopy(error)
    ElMessage.error(actionError.value)
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <section class="workflow-template-panel" data-testid="workflow-templates-panel" aria-labelledby="workflow-templates-title">
    <div class="workflow-template-panel__header">
      <div>
        <h2 id="workflow-templates-title" class="panel__title">模板设置</h2>
        <p class="panel__subtitle">
          版本化流程模板与节点规则；创建新版本不会改写历史案例快照。共 {{ templates.length }} 个版本。
        </p>
      </div>
      <div class="workflow-template-panel__actions">
        <el-button plain :loading="loading" @click="load">刷新</el-button>
        <el-button
          v-if="canManage"
          type="primary"
          data-testid="workflow-template-create"
          @click="openCreate"
        >
          新建版本
        </el-button>
      </div>
    </div>

    <p v-if="actionMessage" class="inline-success" data-testid="workflow-template-message">{{ actionMessage }}</p>
    <p v-if="actionError" class="inline-error" role="alert" data-testid="workflow-template-error">{{ actionError }}</p>

    <div v-if="loading" class="workflow-template-panel__body" data-testid="workflow-templates-loading">
      <el-skeleton :rows="3" animated />
      <span class="visually-hidden">正在加载流程模板</span>
    </div>
    <div v-else-if="forbidden" class="workflow-template-panel__body" data-testid="workflow-templates-forbidden">
      <p class="muted">当前账号没有查看流程模板的能力。</p>
    </div>
    <div v-else-if="loadError !== null" class="workflow-template-panel__body" data-testid="workflow-templates-error">
      <p class="muted">{{ errorCopy(loadError) }}</p>
      <el-button type="primary" plain @click="load">重试</el-button>
    </div>
    <div v-else-if="isEmpty" class="workflow-template-panel__body" data-testid="workflow-templates-empty">
      <p class="muted">服务端尚未发布任何流程模板；创建版本前请确认节点与生效声明。</p>
    </div>
    <ul v-else class="workflow-template-groups">
      <li v-for="group in grouped" :key="group.code" class="workflow-template-group">
        <div class="workflow-template-group__heading">
          <strong>{{ group.label }}</strong>
          <span class="plain-chip plain-chip--info mono">{{ group.code }}</span>
        </div>
        <ul class="workflow-template-versions">
          <li
            v-for="template in group.versions"
            :key="template.id"
            class="workflow-template-version"
            data-testid="workflow-template-version"
          >
            <div class="workflow-template-version__main">
              <span class="plain-chip" :class="templateStatusTone(template.status)">
                {{ templateStatusLabel(template.status) }}
              </span>
              <strong>第 {{ template.version }} 版</strong>
              <span class="muted small">{{ template.name }}</span>
            </div>
            <p class="workflow-template-version__facts">
              节点 {{ template.nodeCount }} 个 · 生效声明 {{ template.effectCount }} 个 ·
              创建于 {{ formatDateTimeInTimezone(template.createdAt) }}
            </p>
          </li>
        </ul>
      </li>
    </ul>

    <el-drawer
      v-model="drawerOpen"
      title="新建模板版本"
      size="520px"
      :close-on-click-modal="false"
      data-testid="workflow-template-drawer"
    >
      <div class="workflow-template-form">
        <label class="workflow-template-field">
          <span>模板代码 *</span>
          <el-input
            v-model="draft.code"
            data-testid="workflow-template-code"
            maxlength="64"
            placeholder="例如 leave.request"
            @change="onCodeChange"
          />
        </label>
        <label class="workflow-template-field">
          <span>模板名称 *</span>
          <el-input v-model="draft.name" data-testid="workflow-template-name" maxlength="128" />
        </label>
        <label class="workflow-template-field">
          <span>版本号 *</span>
          <el-input-number v-model="draft.version" :min="1" :max="100000" data-testid="workflow-template-version-input" />
        </label>
        <label class="workflow-template-field">
          <span>状态</span>
          <el-select v-model="draft.status" data-testid="workflow-template-status">
            <el-option label="草稿" value="DRAFT" />
            <el-option label="已发布" value="PUBLISHED" />
            <el-option label="已归档" value="ARCHIVED" />
          </el-select>
        </label>
        <label class="workflow-template-field">
          <span>节点结构（只读摘要）</span>
          <el-input v-model="draft.nodeSummary" disabled />
        </label>
        <label class="workflow-template-field">
          <span>生效声明类型</span>
          <el-input v-model="draft.effectSummary" maxlength="64" placeholder="留空表示无生效声明" />
        </label>
        <p class="muted small">
          新版本创建后只对新案例生效；相同代码与版本的重复提交按幂等键返回原结果。业务用户看不到原始 JSON。
        </p>
      </div>
      <template #footer>
        <el-button @click="drawerOpen = false">取消</el-button>
        <el-button type="primary" :loading="saving" data-testid="workflow-template-submit" @click="submit">
          保存版本
        </el-button>
      </template>
    </el-drawer>
  </section>
</template>

<style scoped>
.workflow-template-panel { display: flex; flex-direction: column; gap: 14px; }
.workflow-template-panel__header { display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: 14px; padding: 16px; }
.workflow-template-panel__actions { display: flex; flex-wrap: wrap; gap: 8px; }
.workflow-template-panel__body { padding: 0 16px 16px; }
.workflow-template-groups { display: flex; flex-direction: column; gap: 14px; padding: 0 16px 16px; margin: 0; list-style: none; }
.workflow-template-group { border: 1px solid var(--color-line); border-radius: 8px; }
.workflow-template-group__heading { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; padding: 11px 12px; background: var(--color-canvas); border-bottom: 1px solid var(--color-line); }
.workflow-template-versions { display: flex; flex-direction: column; padding: 0; margin: 0; list-style: none; }
.workflow-template-version { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px; padding: 10px 12px; border-top: 1px solid var(--color-line); }
.workflow-template-version:first-child { border-top: 0; }
.workflow-template-version__main { display: flex; flex-wrap: wrap; align-items: center; gap: 7px; font-size: 13px; }
.workflow-template-version__facts { width: 100%; margin: 0; color: var(--color-ink-muted); font-size: 11px; }
.workflow-template-form { display: flex; flex-direction: column; gap: 12px; }
.workflow-template-field { display: flex; flex-direction: column; gap: 6px; font-size: 12px; font-weight: 600; }
.inline-success, .inline-error { padding: 9px 12px; margin: 0 16px; font-size: 12px; border-radius: 7px; }
.inline-success { color: #146c43; background: #e8f6ee; border: 1px solid #b7dcc7; }
.inline-error { color: #a12828; background: #fdecec; border: 1px solid #efc2c2; }
.plain-chip--success { color: #146c43; background: #e8f6ee; border-color: #b7dcc7; }
.plain-chip--warning { color: #8a5a00; background: #fff6e5; border-color: #ead49f; }
.plain-chip--info { color: #0f5466; background: #eaf4f7; border-color: #c4dde4; }
.plain-chip--muted { color: var(--color-ink-muted); }
</style>
