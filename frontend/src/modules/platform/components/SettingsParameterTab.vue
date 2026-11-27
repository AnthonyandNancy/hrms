<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import {
  isForbidden,
  listFeatureFlags,
  listSystemParameters,
  platformErrorCopy,
  updateFeatureFlag,
  updateSystemParameter,
  type FeatureFlag,
  type SystemParameter,
} from '../api/settings'
import { featureFlagDescription, featureFlagLabel, parameterScopeLabel } from '../labels'
import { nextIdempotencyKey } from '../platformRead'
import { requestIdFromError } from '@/utils/renderErrors'
import { useSessionStore } from '@/stores/session'

const session = useSessionStore()
const canManageFlags = computed(() => session.can('platform.feature.manage'))
const canManageParameters = computed(() => session.can('system.parameter.manage'))

const flagsLoading = ref(true)
const flagsError = ref<unknown>(null)
const flagsForbidden = ref(false)
const flags = ref<FeatureFlag[]>([])
const selectedFlag = ref<FeatureFlag | null>(null)
const flagReason = ref('')
const flagSaving = ref(false)

const parametersLoading = ref(true)
const parametersError = ref<unknown>(null)
const parametersForbidden = ref(false)
const parameters = ref<SystemParameter[]>([])
const parametersTotal = ref(0)
const editing = ref<SystemParameter | null>(null)
const parameterDraft = reactive({ value: '', sensitive: false })
const parameterSaving = ref(false)

const actionError = ref('')
const actionMessage = ref('')

type ParameterKind = 'number' | 'boolean' | 'text' | 'structured'

const PARAMETER_LABELS: Record<string, string> = {
  'pagination.max_page_size': '分页每页上限',
  'mail.host': '邮件服务器地址',
  'mail.port': '邮件服务器端口',
  'mail.username': '邮件账号',
  'mail.password': '邮件账号密码',
  'mail.encryption': '邮件连接加密方式',
  'mail.from_address': '通知发件地址',
  'mail.from_name': '通知发件名称',
  'password.min_length': '密码最小长度',
  'session.idle_minutes': '会话空闲分钟数',
}

function parameterLabel(key: string): string {
  return PARAMETER_LABELS[key] ?? '平台运行参数'
}

function parameterKind(parameter: SystemParameter): ParameterKind {
  if (parameter.sensitive || parameter.masked) return 'text'
  if (typeof parameter.value === 'number') return 'number'
  if (typeof parameter.value === 'boolean') return 'boolean'
  if (parameter.value !== null && typeof parameter.value === 'object') return 'structured'
  return 'text'
}

function parameterValueLabel(parameter: SystemParameter): string {
  if (parameter.masked) return '已加密，仅管理员可设置新值'
  const kind = parameterKind(parameter)
  if (kind === 'boolean') return parameter.value === true ? '开启' : '关闭'
  if (kind === 'structured') return '结构化配置（只读展示）'
  return String(parameter.value ?? '—')
}

const flagRows = computed(() => flags.value.map((flag) => ({
  ...flag,
  label: featureFlagLabel(flag.flag),
  description: featureFlagDescription(flag.flag),
  statusLabel: flag.enabled ? '已启用' : '已停用',
  statusTone: flag.enabled ? 'plain-chip--success' : 'plain-chip--muted',
})))

const parameterRows = computed(() => parameters.value.map((parameter) => ({
  ...parameter,
  label: parameterLabel(parameter.key),
  scopeLabel: parameterScopeLabel(parameter.scope),
  valueLabel: parameterValueLabel(parameter),
  kind: parameterKind(parameter),
  editable: canManageParameters.value && parameterKind(parameter) !== 'structured',
})))

const editingKind = computed<ParameterKind>(() => editing.value === null ? 'text' : parameterKind(editing.value))
const requestId = computed(() => requestIdFromError(flagsError.value) ?? requestIdFromError(parametersError.value))

async function loadFlags(): Promise<void> {
  flagsLoading.value = true
  flagsError.value = null
  flagsForbidden.value = false
  try {
    flags.value = await listFeatureFlags()
  } catch (error) {
    flags.value = []
    if (isForbidden(error)) flagsForbidden.value = true
    else flagsError.value = error
  } finally {
    flagsLoading.value = false
  }
}

async function loadParameters(): Promise<void> {
  parametersLoading.value = true
  parametersError.value = null
  parametersForbidden.value = false
  try {
    const result = await listSystemParameters()
    parameters.value = result.items
    parametersTotal.value = result.total
  } catch (error) {
    parameters.value = []
    parametersTotal.value = 0
    if (isForbidden(error)) parametersForbidden.value = true
    else parametersError.value = error
  } finally {
    parametersLoading.value = false
  }
}

function openFlag(flag: FeatureFlag): void {
  if (!canManageFlags.value) return
  selectedFlag.value = flag
  flagReason.value = ''
  actionError.value = ''
}

async function confirmFlag(): Promise<void> {
  if (selectedFlag.value === null) return
  if (flagReason.value.trim().length < 8) {
    actionError.value = '变更原因至少需要 8 个字符，并会写入审计记录。'
    return
  }
  flagSaving.value = true
  actionError.value = ''
  actionMessage.value = ''
  const flag = selectedFlag.value
  const nextEnabled = !flag.enabled
  try {
    await updateFeatureFlag(flag.flag, nextEnabled, flagReason.value.trim())
    actionMessage.value = `${featureFlagLabel(flag.flag)}已${nextEnabled ? '启用' : '停用'}，并已写入审计记录。`
    ElMessage.success(actionMessage.value)
    selectedFlag.value = null
    await loadFlags()
  } catch (error) {
    actionError.value = platformErrorCopy(error)
    ElMessage.error(actionError.value)
  } finally {
    flagSaving.value = false
  }
}

function openParameter(parameter: SystemParameter): void {
  if (!canManageParameters.value) return
  editing.value = parameter
  const kind = parameterKind(parameter)
  parameterDraft.value = kind === 'boolean'
    ? (parameter.value === true ? 'true' : 'false')
    : kind === 'structured'
      ? ''
      : parameter.masked
        ? ''
        : String(parameter.value ?? '')
  parameterDraft.sensitive = parameter.sensitive
  actionError.value = ''
}

async function saveParameter(): Promise<void> {
  if (editing.value === null) return
  actionError.value = ''
  const kind = editingKind.value
  let value: unknown
  if (kind === 'boolean') {
    value = parameterDraft.value === 'true'
  } else if (kind === 'number') {
    if (!/^-?\d+(\.\d+)?$/.test(parameterDraft.value.trim())) {
      actionError.value = '请输入有效数值。'
      return
    }
    value = Number(parameterDraft.value.trim())
  } else {
    if (parameterDraft.value === '') {
      actionError.value = editing.value.masked ? '已加密参数需要填写新的值才会覆盖。' : '请输入参数值。'
      return
    }
    value = parameterDraft.value
  }

  parameterSaving.value = true
  actionMessage.value = ''
  try {
    await updateSystemParameter({
      key: editing.value.key,
      scope: editing.value.scope,
      value,
      sensitive: parameterDraft.sensitive,
    }, nextIdempotencyKey(`parameter-${editing.value.key}`))
    actionMessage.value = `${parameterLabel(editing.value.key)}已更新，并已写入审计记录。`
    ElMessage.success(actionMessage.value)
    editing.value = null
    await loadParameters()
  } catch (error) {
    actionError.value = platformErrorCopy(error)
    ElMessage.error(actionError.value)
  } finally {
    parameterSaving.value = false
  }
}

function reload(): void {
  void Promise.all([loadFlags(), loadParameters()])
}

onMounted(reload)
</script>

<template>
  <section class="settings-tab" data-testid="settings-parameters" aria-labelledby="settings-parameter-title">
    <div class="settings-tab__header">
      <div>
        <h2 id="settings-parameter-title" class="panel__title">参数与功能开关</h2>
        <p class="panel__subtitle">只编辑服务端已登记的类型化参数与运行开关；敏感参数读取时始终遮罩。共 {{ parametersTotal }} 项参数。</p>
      </div>
      <el-button plain :disabled="flagsLoading || parametersLoading" @click="reload">刷新</el-button>
    </div>

    <p v-if="actionMessage" class="inline-success" data-testid="settings-action-message">{{ actionMessage }}</p>
    <p v-if="actionError" class="inline-error" role="alert" data-testid="settings-action-error">{{ actionError }}</p>
    <p v-if="requestId" class="muted small" data-testid="settings-request-id">请求编号：<span class="mono">{{ requestId }}</span></p>

    <section class="settings-subpanel" data-testid="settings-feature-flags">
      <div class="settings-tab__header">
        <div>
          <h3 class="panel__title">功能开关</h3>
          <p class="panel__subtitle">开关由服务端按代码目录返回；未登记的业务开关不会出现。</p>
        </div>
        <span class="muted small">{{ flagRows.length }} 个开关</span>
      </div>
      <div v-if="flagsLoading" class="panel__body" data-testid="settings-flags-loading">
        <el-skeleton :rows="2" animated />
        <span class="visually-hidden">正在加载功能开关</span>
      </div>
      <div v-else-if="flagsForbidden" class="panel__body" data-testid="settings-flags-forbidden">
        <p class="muted">当前账号没有平台功能管理能力。</p>
      </div>
      <div v-else-if="flagsError !== null" class="panel__body" data-testid="settings-flags-error">
        <p class="muted">{{ platformErrorCopy(flagsError) }}</p>
        <el-button type="primary" plain @click="loadFlags">重试</el-button>
      </div>
      <div v-else-if="flagRows.length === 0" class="panel__body" data-testid="settings-flags-empty">
        <p class="muted">服务端当前未返回任何运行开关。</p>
      </div>
      <ul v-else class="settings-list">
        <li v-for="flag in flagRows" :key="flag.flag" class="settings-row" :data-testid="`settings-flag-${flag.flag}`">
          <div class="settings-row__main">
            <div class="settings-row__heading">
              <strong>{{ flag.label }}</strong>
              <span class="plain-chip" :class="flag.statusTone">{{ flag.statusLabel }}</span>
              <span v-if="flag.risky" class="plain-chip plain-chip--warning">高风险领域</span>
            </div>
            <p class="settings-row__description">{{ flag.description }}</p>
          </div>
          <div class="settings-row__actions">
            <el-switch
              :model-value="flag.enabled"
              :disabled="!canManageFlags"
              :aria-label="`${flag.label}开关`"
              @click="openFlag(flags.find((item) => item.flag === flag.flag) ?? flag)"
            />
            <span v-if="!canManageFlags" class="muted small">只读</span>
          </div>
        </li>
      </ul>
    </section>

    <section class="settings-subpanel" data-testid="settings-parameter-list">
      <div class="settings-tab__header">
        <div>
          <h3 class="panel__title">类型化参数</h3>
          <p class="panel__subtitle">页面只编辑已有参数的当前值，不提供任意键值写入。</p>
        </div>
      </div>
      <div v-if="parametersLoading" class="panel__body" data-testid="settings-parameters-loading">
        <el-skeleton :rows="3" animated />
        <span class="visually-hidden">正在加载系统参数</span>
      </div>
      <div v-else-if="parametersForbidden" class="panel__body" data-testid="settings-parameters-forbidden">
        <p class="muted">当前账号没有系统参数管理能力。</p>
      </div>
      <div v-else-if="parametersError !== null" class="panel__body" data-testid="settings-parameters-error">
        <p class="muted">{{ platformErrorCopy(parametersError) }}</p>
        <el-button type="primary" plain @click="loadParameters">重试</el-button>
      </div>
      <div v-else-if="parameterRows.length === 0" class="panel__body" data-testid="settings-parameters-empty">
        <p class="muted">服务端当前没有可展示的参数行；页面不会创建未登记的参数。</p>
      </div>
      <ul v-else class="settings-list">
        <li v-for="parameter in parameterRows" :key="parameter.id" class="settings-row" :data-testid="`settings-parameter-${parameter.id}`">
          <div class="settings-row__main">
            <div class="settings-row__heading">
              <strong>{{ parameter.label }}</strong>
              <span class="plain-chip plain-chip--info">{{ parameter.scopeLabel }}</span>
              <span v-if="parameter.sensitive" class="plain-chip plain-chip--warning">敏感参数</span>
            </div>
            <p class="settings-row__description">{{ parameter.valueLabel }}</p>
          </div>
          <div class="settings-row__actions">
            <el-button
              v-if="parameter.editable"
              text
              size="small"
              :data-testid="`settings-parameter-edit-${parameter.id}`"
              @click="openParameter(parameters.find((item) => item.id === parameter.id) ?? parameter)"
            >
              编辑
            </el-button>
            <span v-else-if="parameter.kind === 'structured'" class="muted small">结构化只读</span>
            <span v-else class="muted small">只读</span>
          </div>
        </li>
      </ul>
    </section>

    <el-dialog
      :model-value="selectedFlag !== null"
      title="变更功能开关"
      width="480px"
      :close-on-click-modal="false"
      data-testid="settings-flag-dialog"
      @close="selectedFlag = null"
    >
      <div v-if="selectedFlag !== null" class="settings-dialog">
        <p>
          将{{ selectedFlag.enabled ? '停用' : '启用' }}
          <strong>{{ featureFlagLabel(selectedFlag.flag) }}</strong>。该变更立即生效并写入审计。
        </p>
        <label class="settings-field">
          <span>变更原因 *</span>
          <el-input
            v-model="flagReason"
            data-testid="settings-flag-reason"
            type="textarea"
            :rows="3"
            maxlength="255"
            show-word-limit
            placeholder="至少 8 个字符，例如：季度发布窗口已批准"
          />
        </label>
      </div>
      <template #footer>
        <el-button @click="selectedFlag = null">取消</el-button>
        <el-button type="primary" :loading="flagSaving" data-testid="settings-flag-confirm" @click="confirmFlag">确认变更</el-button>
      </template>
    </el-dialog>

    <el-dialog
      :model-value="editing !== null"
      title="编辑系统参数"
      width="520px"
      :close-on-click-modal="false"
      data-testid="parameter-dialog"
      @close="editing = null"
    >
      <div v-if="editing !== null" class="settings-dialog">
        <p class="muted small">{{ editing.key }} · {{ parameterScopeLabel(editing.scope) }}</p>
        <p class="settings-dialog__title">{{ parameterLabel(editing.key) }}</p>
        <label v-if="editingKind === 'boolean'" class="settings-field">
          <span>参数值</span>
          <el-switch
            :model-value="parameterDraft.value === 'true'"
            active-text="开启"
            inactive-text="关闭"
            data-testid="parameter-value-boolean"
            @change="parameterDraft.value = $event ? 'true' : 'false'"
          />
        </label>
        <label v-else-if="editingKind === 'number'" class="settings-field">
          <span>参数值（数值）</span>
          <el-input v-model="parameterDraft.value" data-testid="parameter-value" inputmode="decimal" />
        </label>
        <label v-else class="settings-field">
          <span>{{ editing.masked ? '设置新值（当前值已加密）' : '参数值' }}</span>
          <el-input
            v-model="parameterDraft.value"
            data-testid="parameter-value"
            :type="editing.sensitive ? 'password' : 'text'"
            show-password
            autocomplete="new-password"
          />
        </label>
        <el-checkbox
          v-if="!editing.sensitive"
          :model-value="parameterDraft.sensitive"
          data-testid="parameter-sensitive"
          @change="parameterDraft.sensitive = Boolean($event)"
        >
          保存为敏感参数（读取时将遮罩）
        </el-checkbox>
        <p v-else class="muted small">敏感参数保存后只返回遮罩状态，原文不会回显。</p>
      </div>
      <template #footer>
        <el-button @click="editing = null">取消</el-button>
        <el-button type="primary" :loading="parameterSaving" data-testid="parameter-save" @click="saveParameter">保存参数</el-button>
      </template>
    </el-dialog>
  </section>
</template>

<style scoped>
.settings-tab {
  display: flex;
  flex-direction: column;
  gap: 16px;
}
.settings-tab__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
}
.settings-subpanel {
  display: flex;
  flex-direction: column;
  padding: 14px 16px;
  border: 1px solid var(--color-line);
  border-radius: var(--radius-md);
}
.settings-list {
  display: flex;
  flex-direction: column;
  padding: 0;
  margin: 0;
  list-style: none;
}
.settings-row {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 14px;
  padding: 13px 4px;
  border-top: 1px solid var(--color-line);
}
.settings-row__main {
  min-width: 260px;
  flex: 1 1 360px;
}
.settings-row__heading {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 7px;
  font-size: 13px;
}
.settings-row__description {
  margin: 5px 0 0;
  color: var(--color-ink-secondary);
  font-size: 12px;
}
.settings-row__actions {
  display: flex;
  gap: 8px;
  align-items: center;
}
.settings-dialog {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.settings-dialog__title {
  margin: 0;
  color: var(--color-ink);
  font-size: 13px;
  font-weight: 650;
}
.settings-field {
  display: flex;
  flex-direction: column;
  gap: 6px;
  font-size: 12px;
  font-weight: 600;
}
.inline-success,
.inline-error {
  padding: 9px 12px;
  margin: 0;
  font-size: 12px;
  border-radius: 7px;
}
.inline-success {
  color: #146c43;
  background: #e8f6ee;
  border: 1px solid #b7dcc7;
}
.inline-error {
  color: #a12828;
  background: #fdecec;
  border: 1px solid #efc2c2;
}
.plain-chip--success {
  color: #146c43;
  background: #e8f6ee;
  border-color: #b7dcc7;
}
.plain-chip--info {
  color: #0f5466;
  background: #eaf4f7;
  border-color: #c4dde4;
}
.plain-chip--warning {
  color: #8a5a00;
  background: #fff6e5;
  border-color: #ead49f;
}
.plain-chip--muted {
  color: var(--color-ink-muted);
}
</style>
