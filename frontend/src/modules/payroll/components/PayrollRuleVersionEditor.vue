<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { errorCopy } from '@/api/errors'
import { useSessionStore } from '@/stores/session'
import DataTable, { type DataTableColumn } from '@/components/DataTable.vue'
import {
  createPayrollRuleVersion,
  listPayrollRuleVersions,
  publishPayrollRuleVersion,
  retirePayrollRuleVersion,
  type PayrollRuleVersionRecord,
} from '../api'

defineOptions({ name: 'PayrollRuleVersionEditor' })

const props = defineProps<{ modelValue: boolean }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>()

const session = useSessionStore()
const canPrepare = computed(() => session.can('payroll.run.prepare'))
const schemeItemId = ref('')
const astText = ref(JSON.stringify({
  type: 'if',
  condition: {
    type: 'compare',
    op: 'GTE',
    left: { type: 'var', name: 'base_salary_cents' },
    right: { type: 'const', value: '900000' },
  },
  then: { type: 'const', value: '50000' },
  else: { type: 'const', value: '0' },
}, null, 2))
const dependenciesText = ref('')
const stage = ref('EARNING')
const roundingMode = ref('HALF_UP')
const rules = ref<PayrollRuleVersionRecord[]>([])
const loading = ref(false)
const saving = ref(false)
const errorMessage = ref('')
const successMessage = ref('')
const retireReason = ref('')

const columns: DataTableColumn[] = [
  { key: 'versionLabel', label: '版本', width: '80px' },
  { key: 'statusLabel', label: '状态', width: '100px' },
  { key: 'stage', label: '阶段', width: '100px' },
  { key: 'dependenciesLabel', label: '依赖', minWidth: '110px' },
  { key: 'hashLabel', label: 'AST 摘要', minWidth: '150px' },
  { key: 'actions', label: '操作', width: '180px', align: 'right' },
]

const rows = computed(() => rules.value.map((rule) => ({
  ...rule,
  versionLabel: `v${rule.version}`,
  statusLabel: rule.status === 'PUBLISHED' ? '已发布' : rule.status === 'RETIRED' ? '已退役' : '草稿',
  dependenciesLabel: rule.dependencies.length === 0 ? '无' : rule.dependencies.join(', '),
  hashLabel: rule.hash.slice(0, 12),
})))

function close(): void {
  emit('update:modelValue', false)
}

function parsedSchemeItemId(): number {
  const id = Number(schemeItemId.value)
  if (!Number.isSafeInteger(id) || id < 1) throw new Error('请输入有效的方案项目编号。')
  return id
}

function parsedAst(): Record<string, unknown> {
  let value: unknown
  try {
    value = JSON.parse(astText.value)
  } catch {
    throw new Error('AST 必须是合法 JSON；不接受任意 JavaScript 表达式。')
  }
  if (value === null || typeof value !== 'object' || Array.isArray(value)) {
    throw new Error('AST 根节点必须是 JSON 对象。')
  }
  return value as Record<string, unknown>
}

function parsedDependencies(): number[] {
  if (dependenciesText.value.trim() === '') return []
  return dependenciesText.value.split(',').map((part) => {
    const value = Number(part.trim())
    if (!Number.isSafeInteger(value) || value < 1) {
      throw new Error('依赖必须是逗号分隔的正整数规则版本编号。')
    }
    return value
  })
}

async function load(): Promise<void> {
  if (!canPrepare.value) return
  errorMessage.value = ''
  loading.value = true
  try {
    rules.value = await listPayrollRuleVersions(parsedSchemeItemId())
  } catch (reason) {
    errorMessage.value = reason instanceof Error ? reason.message : errorCopy(reason)
  } finally {
    loading.value = false
  }
}

async function create(): Promise<void> {
  errorMessage.value = ''
  successMessage.value = ''
  saving.value = true
  try {
    const created = await createPayrollRuleVersion(
      parsedSchemeItemId(),
      parsedAst(),
      parsedDependencies(),
      stage.value,
      roundingMode.value,
    )
    rules.value = [created, ...rules.value.filter((rule) => rule.id !== created.id)]
    successMessage.value = `规则 v${created.version} 已创建草稿，AST 摘要 ${created.hash.slice(0, 12)}。`
  } catch (reason) {
    errorMessage.value = reason instanceof Error ? reason.message : errorCopy(reason)
  } finally {
    saving.value = false
  }
}

async function publish(rule: PayrollRuleVersionRecord): Promise<void> {
  saving.value = true
  errorMessage.value = ''
  try {
    const published = await publishPayrollRuleVersion(rule.id)
    rules.value = rules.value.map((item) => item.id === published.id ? published : item)
    successMessage.value = `规则 v${published.version} 已发布；新快照只会冻结已发布版本。`
  } catch (reason) {
    errorMessage.value = errorCopy(reason)
  } finally {
    saving.value = false
  }
}

async function retire(rule: PayrollRuleVersionRecord): Promise<void> {
  if (retireReason.value.trim().length < 5) {
    errorMessage.value = '退役原因至少 5 个字符。'
    return
  }
  saving.value = true
  errorMessage.value = ''
  try {
    const retired = await retirePayrollRuleVersion(rule.id, retireReason.value.trim())
    rules.value = rules.value.map((item) => item.id === retired.id ? retired : item)
    retireReason.value = ''
    successMessage.value = `规则 v${retired.version} 已退役。`
  } catch (reason) {
    errorMessage.value = errorCopy(reason)
  } finally {
    saving.value = false
  }
}

watch(() => props.modelValue, (open) => {
  if (open && schemeItemId.value !== '') void load()
})
</script>

<template>
  <el-dialog
    :model-value="modelValue"
    title="受限薪酬规则版本"
    width="920px"
    :close-on-click-modal="false"
    data-testid="payroll-rule-editor"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <p class="rule-editor__note">
      只接受白名单 AST JSON（const/var/算术/比较/IF/MIN/MAX/ROUND/TIER），不执行任意 JavaScript。
      DRAFT 不进入工资快照；发布前所有依赖规则版本必须已发布。
    </p>
    <div class="rule-editor__toolbar">
      <label>方案项目编号<el-input v-model="schemeItemId" inputmode="numeric" data-testid="payroll-rule-item-id" /></label>
      <el-button :loading="loading" :disabled="!canPrepare" data-testid="payroll-rule-load" @click="load">加载版本</el-button>
    </div>

    <div v-if="canPrepare" class="rule-editor__form">
      <div class="rule-editor__form-row">
        <label>阶段<el-input v-model="stage" maxlength="24" data-testid="payroll-rule-stage" /></label>
        <label>取整<el-select v-model="roundingMode" data-testid="payroll-rule-rounding">
          <el-option label="HALF_UP" value="HALF_UP" />
          <el-option label="HALF_EVEN" value="HALF_EVEN" />
          <el-option label="DOWN" value="DOWN" />
          <el-option label="UP" value="UP" />
        </el-select></label>
        <label>依赖规则版本编号（逗号分隔）<el-input v-model="dependenciesText" data-testid="payroll-rule-dependencies" /></label>
      </div>
      <label>AST JSON<el-input v-model="astText" type="textarea" :rows="10" data-testid="payroll-rule-ast" /></label>
      <el-button type="primary" :loading="saving" data-testid="payroll-rule-create" @click="create">创建草稿</el-button>
    </div>
    <p v-else class="rule-editor__note">当前账号没有 payroll.run.prepare 能力，只读显示。</p>

    <p v-if="errorMessage" class="rule-editor__error" role="alert" data-testid="payroll-rule-error">{{ errorMessage }}</p>
    <p v-if="successMessage" class="rule-editor__success" role="status" data-testid="payroll-rule-success">{{ successMessage }}</p>

    <DataTable
      :columns="columns"
      :rows="rows as unknown as Array<Record<string, unknown>>"
      :total="rows.length"
      :page="1"
      :page-size="Math.max(1, rows.length)"
      row-key="id"
      data-testid="payroll-rule-table"
      empty-title="尚无规则版本"
    >
      <template #cell-actions="{ row }">
        <el-button
          v-if="row.status === 'DRAFT' && canPrepare"
          type="primary"
          link
          :loading="saving"
          :data-testid="`payroll-rule-publish-${row.id}`"
          @click="publish(row as PayrollRuleVersionRecord)"
        >
          发布
        </el-button>
        <el-button
          v-if="row.status === 'PUBLISHED' && canPrepare"
          type="danger"
          link
          :loading="saving"
          :data-testid="`payroll-rule-retire-${row.id}`"
          @click="retire(row as PayrollRuleVersionRecord)"
        >
          退役
        </el-button>
      </template>
    </DataTable>
    <label v-if="canPrepare" class="rule-editor__retire">退役原因
      <el-input v-model="retireReason" maxlength="500" data-testid="payroll-rule-retire-reason" />
    </label>

    <template #footer>
      <el-button @click="close">关闭</el-button>
    </template>
  </el-dialog>
</template>

<style scoped>
.rule-editor__note { margin: 0 0 12px; color: #64748b; font-size: 12px; line-height: 1.6; }
.rule-editor__toolbar { display: flex; align-items: flex-end; gap: 10px; margin-bottom: 12px; }
.rule-editor__toolbar label, .rule-editor__form label, .rule-editor__retire { display: flex; flex-direction: column; gap: 5px; font-size: 12px; font-weight: 600; }
.rule-editor__toolbar label { flex: 1; }
.rule-editor__form { display: flex; flex-direction: column; gap: 12px; margin-bottom: 14px; }
.rule-editor__form-row { display: flex; flex-wrap: wrap; gap: 10px; }
.rule-editor__form-row label { flex: 1 1 150px; }
.rule-editor__error { color: #b91c1c; font-size: 12px; }
.rule-editor__success { color: #166534; font-size: 12px; }
.rule-editor__retire { margin-top: 10px; }
</style>
