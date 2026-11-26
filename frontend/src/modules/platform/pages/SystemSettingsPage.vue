<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import PageShell from '@/shared/ui/PageShell.vue'
import { useSessionStore } from '@/stores/session'
import {
  isForbidden,
  listFeatureFlags,
  listSystemParameters,
  platformErrorCopy,
  updateFeatureFlag,
  updateSystemParameter,
  type FeatureFlag,
  type SystemParameter,
} from '../api'
import {
  displayValue,
  featureFlagDescription,
  featureFlagLabel,
  parameterScopeLabel,
} from '../labels'
import { nextIdempotencyKey } from '../platformRead'

const session = useSessionStore()
const canOpen = computed(() => session.can('system.settings.manage'))
const canReadFlags = computed(() => session.can('platform.feature.manage'))
const canManageFlags = computed(() => session.can('platform.feature.manage'))
const canReadParameters = computed(() => session.can('system.parameter.manage'))
const canManageParameters = computed(() => session.can('system.parameter.manage'))

const flagsLoading = ref(true)
const parametersLoading = ref(true)
const flagsError = ref<unknown>(null)
const parametersError = ref<unknown>(null)
const flags = ref<FeatureFlag[]>([])
const parameters = ref<SystemParameter[]>([])
const parametersTotal = ref(0)
const flagsForbidden = ref(false)
const parametersForbidden = ref(false)
const partial = ref(false)
const partialReason = ref('')

const actionError = ref('')
const actionMessage = ref('')

const selectedFlag = ref<FeatureFlag | null>(null)
const flagReason = ref('')
const flagSaving = ref(false)

const editingParameter = ref<SystemParameter | null>(null)
const parameterDraft = reactive({
  value: '' as string,
  sensitive: false,
})
const parameterSaving = ref(false)
const parameterError = ref('')

const flagRows = computed(() =>
  flags.value.map((flag) => ({
    ...flag,
    label: featureFlagLabel(flag.flag),
    description: featureFlagDescription(flag.flag),
    statusLabel: flag.enabled ? '已启用' : '已停用',
    statusTone: flag.enabled ? 'plain-chip--success' : 'plain-chip--muted',
  })),
)

const flagsEmpty = computed(() => !flagsLoading.value && flagsError.value === null && flagsForbidden.value === false && flagRows.value.length === 0)
const parametersEmpty = computed(
  () =>
    !parametersLoading.value &&
    parametersError.value === null &&
    parametersForbidden.value === false &&
    parameters.value.length === 0,
)

type ParameterKind = 'number' | 'boolean' | 'text' | 'structured'

const parameterRows = computed(() =>
  parameters.value.map((parameter) => {
    const kind = parameterKind(parameter)
    return {
      ...parameter,
      label: parameterLabel(parameter.key),
      scopeLabel: parameterScopeLabel(parameter.scope),
      valueLabel: parameter.masked ? '已加密，仅管理员可设置新值' : describeParameterValue(parameter, kind),
      kind,
      editable: canManageParameters.value && kind !== 'structured',
    }
  }),
)

const editingKind = computed<ParameterKind>(() => (editingParameter.value === null ? 'text' : parameterKind(editingParameter.value)))

const editingValueLabel = computed(() => {
  if (editingParameter.value === null) return ''
  return parameterLabel(editingParameter.value.key)
})

function parameterKind(parameter: SystemParameter): ParameterKind {
  if (parameter.sensitive || parameter.masked) return 'text'
  if (typeof parameter.value === 'number') return 'number'
  if (typeof parameter.value === 'boolean') return 'boolean'
  if (parameter.value !== null && typeof parameter.value === 'object') return 'structured'
  return 'text'
}

function describeParameterValue(parameter: SystemParameter, kind: ParameterKind): string {
  if (kind === 'number') return `${displayValue(parameter.value)}（数值）`
  if (kind === 'boolean') return parameter.value === true ? '开启' : '关闭'
  if (kind === 'structured') return '结构化配置（只读展示）'
  return displayValue(parameter.value)
}

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

function syncParameterDraft(parameter: SystemParameter): void {
  const kind = parameterKind(parameter)
  if (kind === 'boolean') {
    parameterDraft.value = parameter.value === true ? 'true' : 'false'
  } else if (kind === 'number') {
    parameterDraft.value = String(parameter.value ?? '')
  } else if (kind === 'structured') {
    parameterDraft.value = ''
  } else {
    parameterDraft.value = parameter.masked ? '' : String(parameter.value ?? '')
  }
  parameterDraft.sensitive = parameter.sensitive
  parameterError.value = ''
}

async function loadFlags(): Promise<void> {
  if (!canReadFlags.value) {
    flagsLoading.value = false
    flagsForbidden.value = true
    return
  }
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
  if (!canReadParameters.value) {
    parametersLoading.value = false
    parametersForbidden.value = true
    return
  }
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

async function loadAll(): Promise<void> {
  partial.value = false
  partialReason.value = ''
  await Promise.all([loadFlags(), loadParameters()])
  if (flagsError.value !== null || parametersError.value !== null) {
    partial.value = true
    partialReason.value = '部分配置读取失败；失败面板显示错误与重试入口，另一面板仍可继续操作。'
  }
}

function openFlagToggle(flag: FeatureFlag): void {
  if (!canManageFlags.value) return
  selectedFlag.value = flag
  flagReason.value = ''
  actionError.value = ''
}

function closeFlagToggle(): void {
  selectedFlag.value = null
  flagReason.value = ''
}

async function confirmFlagToggle(): Promise<void> {
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
    actionMessage.value = `${featureFlagLabel(flag.flag)}已${nextEnabled ? '启用' : '停用'}。`
    ElMessage.success(actionMessage.value)
    closeFlagToggle()
    await loadFlags()
  } catch (error) {
    actionError.value = platformErrorCopy(error)
    ElMessage.error(actionError.value)
  } finally {
    flagSaving.value = false
  }
}

function openParameterEdit(parameter: SystemParameter): void {
  if (!canManageParameters.value) return
  editingParameter.value = parameter
  syncParameterDraft(parameter)
  actionError.value = ''
}

function closeParameterEdit(): void {
  editingParameter.value = null
  parameterError.value = ''
}

async function saveParameter(): Promise<void> {
  if (editingParameter.value === null) return
  parameterError.value = ''

  const kind = editingKind.value
  let value: unknown
  if (kind === 'boolean') {
    value = parameterDraft.value === 'true'
  } else if (kind === 'number') {
    if (!/^-?\d+(\.\d+)?$/.test(parameterDraft.value.trim())) {
      parameterError.value = '请输入有效数值。'
      return
    }
    value = Number(parameterDraft.value.trim())
  } else {
    if (parameterDraft.value === '') {
      parameterError.value = editingParameter.value.masked
        ? '已加密参数需要填写新的值才会覆盖。'
        : '请输入参数值。'
      return
    }
    value = parameterDraft.value
  }

  parameterSaving.value = true
  actionMessage.value = ''
  try {
    await updateSystemParameter(
      {
        key: editingParameter.value.key,
        scope: editingParameter.value.scope,
        value,
        sensitive: parameterDraft.sensitive,
      },
      nextIdempotencyKey(`parameter-${editingParameter.value.key}`),
    )
    actionMessage.value = `${parameterLabel(editingParameter.value.key)}已更新，并已写入审计记录。`
    ElMessage.success(actionMessage.value)
    closeParameterEdit()
    await loadParameters()
  } catch (error) {
    parameterError.value = platformErrorCopy(error)
    ElMessage.error(parameterError.value)
  } finally {
    parameterSaving.value = false
  }
}

onMounted(() => {
  if (!canOpen.value) {
    flagsLoading.value = false
    parametersLoading.value = false
    return
  }
  void loadAll()
})
</script>

<template>
  <PageShell
    data-testid="system-settings-page"
    title="字典、参数与文件"
    description="维护运行开关与已登记的类型化参数；每个开关变更都需要原因，参数值按原类型编辑并写入审计。"
  >
    <template #actions>
      <el-button plain :disabled="flagsLoading || parametersLoading" @click="loadAll">刷新</el-button>
    </template>

    <section v-if="!canOpen" class="panel" data-testid="settings-forbidden">
      <div class="panel__body">
        <h2 class="panel__title">无权访问系统设置</h2>
        <p class="muted">当前账号没有系统设置管理权限。请联系系统管理员授予相应能力。</p>
      </div>
    </section>

    <template v-else>
      <p v-if="actionMessage" class="inline-success" data-testid="settings-action-message">{{ actionMessage }}</p>
      <p v-if="actionError" class="inline-error" role="alert" data-testid="settings-action-error">{{ actionError }}</p>
      <p v-if="partial" class="inline-warning" data-testid="settings-partial">{{ partialReason }}</p>

      <section class="panel" data-testid="settings-feature-flags">
        <div class="panel__header">
          <div>
            <h2 class="panel__title">功能开关</h2>
            <p class="panel__subtitle">开关由服务端按代码目录返回；未登记的业务开关不会在页面出现。</p>
          </div>
          <span class="muted small">{{ flagRows.length }} 个开关</span>
        </div>

        <div v-if="flagsLoading" class="panel__body" data-testid="settings-flags-loading">
          <el-skeleton :rows="3" animated />
          <span class="visually-hidden">正在加载功能开关</span>
        </div>

        <div v-else-if="flagsForbidden" class="panel__body" data-testid="settings-flags-forbidden">
          <h3 class="panel__title">功能开关不可查看</h3>
          <p class="muted">当前账号没有平台功能管理能力。可查看系统参数（如已授权），但无法读取或变更开关。</p>
        </div>

        <div v-else-if="flagsError !== null" class="panel__body" data-testid="settings-flags-error">
          <h3 class="panel__title">功能开关加载失败</h3>
          <p class="muted">{{ platformErrorCopy(flagsError) }}</p>
          <el-button type="primary" plain @click="loadFlags">重试</el-button>
        </div>

        <div v-else-if="flagsEmpty" class="panel__body" data-testid="settings-flags-empty">
          <h3 class="panel__title">没有可配置的功能开关</h3>
          <p class="muted">服务端当前未返回任何运行开关。</p>
        </div>

        <ul v-else class="settings-list">
          <li
            v-for="flag in flagRows"
            :key="flag.flag"
            class="settings-row"
            :data-testid="`settings-flag-${flag.flag}`"
          >
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
                @click="openFlagToggle(flags.find((item) => item.flag === flag.flag) ?? flag)"
              />
              <span v-if="!canManageFlags" class="muted small">只读</span>
            </div>
          </li>
        </ul>
      </section>

      <section class="panel" data-testid="settings-parameters">
        <div class="panel__header">
          <div>
            <h2 class="panel__title">类型化参数</h2>
            <p class="panel__subtitle">
              参数列表由服务端登记；页面只编辑已有参数的当前值，不提供任意键值写入。
              共 {{ parametersTotal }} 项。
            </p>
          </div>
        </div>

        <div v-if="parametersLoading" class="panel__body" data-testid="settings-parameters-loading">
          <el-skeleton :rows="4" animated />
          <span class="visually-hidden">正在加载系统参数</span>
        </div>

        <div v-else-if="parametersForbidden" class="panel__body" data-testid="settings-parameters-forbidden">
          <h3 class="panel__title">系统参数不可查看</h3>
          <p class="muted">当前账号没有系统参数管理能力。</p>
        </div>

        <div v-else-if="parametersError !== null" class="panel__body" data-testid="settings-parameters-error">
          <h3 class="panel__title">系统参数加载失败</h3>
          <p class="muted">{{ platformErrorCopy(parametersError) }}</p>
          <el-button type="primary" plain @click="loadParameters">重试</el-button>
        </div>

        <div v-else-if="parametersEmpty" class="panel__body" data-testid="settings-parameters-empty">
          <h3 class="panel__title">尚未登记系统参数</h3>
          <p class="muted">服务端当前没有可展示的参数行；页面不会创建未登记的参数。</p>
        </div>

        <ul v-else class="settings-list">
          <li
            v-for="parameter in parameterRows"
            :key="parameter.id"
            class="settings-row"
            :data-testid="`settings-parameter-${parameter.id}`"
          >
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
                @click="openParameterEdit(parameters.find((item) => item.id === parameter.id) ?? parameter)"
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
        @close="closeFlagToggle"
      >
        <div v-if="selectedFlag !== null" class="settings-dialog">
          <p>
            将{{ selectedFlag.enabled ? '停用' : '启用' }}
            <strong>{{ featureFlagLabel(selectedFlag.flag) }}</strong>。该变更立即生效并写入审计。
          </p>
          <label class="settings-dialog__field">
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
          <el-button @click="closeFlagToggle">取消</el-button>
          <el-button type="primary" :loading="flagSaving" data-testid="settings-flag-confirm" @click="confirmFlagToggle">
            确认变更
          </el-button>
        </template>
      </el-dialog>

      <el-dialog
        :model-value="editingParameter !== null"
        title="编辑系统参数"
        width="520px"
        :close-on-click-modal="false"
        data-testid="parameter-dialog"
        @close="closeParameterEdit"
      >
        <div v-if="editingParameter !== null" class="settings-dialog">
          <p class="muted small">{{ editingParameter.key }} · {{ parameterScopeLabel(editingParameter.scope) }}</p>
          <p class="settings-dialog__title">{{ editingValueLabel }}</p>
          <p v-if="parameterError" class="inline-error" role="alert" data-testid="parameter-error">{{ parameterError }}</p>

          <label v-if="editingKind === 'boolean'" class="settings-dialog__field">
            <span>参数值</span>
            <el-switch
              :model-value="parameterDraft.value === 'true'"
              active-text="开启"
              inactive-text="关闭"
              data-testid="parameter-value-boolean"
              @change="parameterDraft.value = $event ? 'true' : 'false'"
            />
          </label>
          <label v-else-if="editingKind === 'number'" class="settings-dialog__field">
            <span>参数值（数值）</span>
            <el-input v-model="parameterDraft.value" data-testid="parameter-value" inputmode="decimal" />
          </label>
          <label v-else class="settings-dialog__field">
            <span>{{ editingParameter.masked ? '设置新值（当前值已加密）' : '参数值' }}</span>
            <el-input
              v-model="parameterDraft.value"
              data-testid="parameter-value"
              :type="editingParameter.sensitive ? 'password' : 'text'"
              show-password
              autocomplete="new-password"
            />
          </label>

          <el-checkbox
            v-if="!editingParameter.sensitive"
            :model-value="parameterDraft.sensitive"
            data-testid="parameter-sensitive"
            @change="parameterDraft.sensitive = Boolean($event)"
          >
            保存为敏感参数（读取时将遮罩）
          </el-checkbox>
          <p v-else class="muted small">敏感参数保存后只返回遮罩状态，原文不会回显。</p>
        </div>
        <template #footer>
          <el-button @click="closeParameterEdit">取消</el-button>
          <el-button type="primary" :loading="parameterSaving" data-testid="parameter-save" @click="saveParameter">
            保存参数
          </el-button>
        </template>
      </el-dialog>
    </template>
  </PageShell>
</template>

<style scoped>
.settings-list {
  display: flex;
  flex-direction: column;
  gap: 0;
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
  padding: 13px 16px;
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
  line-height: 1.6;
}
.settings-row__actions {
  display: flex;
  align-items: center;
  gap: 8px;
}
.settings-dialog {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.settings-dialog__field {
  display: flex;
  flex-direction: column;
  gap: 6px;
  font-size: 12px;
  font-weight: 600;
}
.settings-dialog__title {
  margin: 0;
  color: var(--color-ink);
  font-size: 13px;
  font-weight: 650;
}
.settings-dialog__check {
  display: flex;
  gap: 6px;
  align-items: center;
  font-size: 12px;
}
.plain-chip--success {
  color: #146c43;
  background: #e8f6ee;
  border-color: #b7dcc7;
}
.plain-chip--warning {
  color: #8a5a00;
  background: #fff6e5;
  border-color: #ead49f;
}
.plain-chip--info {
  color: #0f5466;
  background: #eaf4f7;
  border-color: #c4dde4;
}
.plain-chip--muted {
  color: var(--color-ink-muted);
}
.inline-warning {
  padding: 9px 12px;
  color: #8a5a00;
  font-size: 12px;
  background: #fff6e5;
  border: 1px solid #ead49f;
  border-radius: 7px;
}
</style>
