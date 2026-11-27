<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import DataTable, { type DataTableColumn } from '@/components/DataTable.vue'
import { useSessionStore } from '@/stores/session'
import {
  formatBusinessDate,
  formatMoney,
  parseYuanToCents,
  todayBusinessDate,
} from '@/utils/format'
import { requestIdFromError } from '@/utils/renderErrors'
import {
  createStatutoryParameterVersion,
  isForbidden,
  listStatutoryParameters,
  platformErrorCopy,
  type StatutoryBracketInput,
  type StatutoryParameter,
  type StatutoryParameterSet,
} from '../api/settings'
import { nextIdempotencyKey } from '../platformRead'

const session = useSessionStore()
const canRead = computed(() => session.can('payroll.parameters.read'))
const canManage = computed(() => session.can('payroll.parameters.manage'))

const PARAMETER_SET_LABELS: Record<StatutoryParameterSet, string> = {
  TAX: '个人所得税',
  SOCIAL_INSURANCE: '社会保险',
  HOUSING_FUND: '住房公积金',
  ROUNDING: '取整规则',
}

const PARAMETER_KIND_LABELS: Record<StatutoryParameterSet, string> = {
  TAX: '计税参数',
  SOCIAL_INSURANCE: '社保参数',
  HOUSING_FUND: '公积金参数',
  ROUNDING: '计算规则',
}

const CODE_LABELS: Record<string, string> = {
  THRESHOLD_CENTS: '起征点',
  MIN_BASE_CENTS: '缴费基数下限',
  MAX_BASE_CENTS: '缴费基数上限',
  EMPLOYEE_RATE: '个人比例',
  EMPLOYER_RATE: '公司比例',
  MODE: '取整方式',
}

const MODE_LABELS: Record<string, string> = {
  HALF_UP: '四舍五入',
  HALF_EVEN: '银行家舍入',
  DOWN: '向下取整',
  UP: '向上取整',
}

const PARAMETER_SET_OPTIONS = (Object.keys(PARAMETER_SET_LABELS) as StatutoryParameterSet[]).map((value) => ({
  value,
  label: PARAMETER_SET_LABELS[value],
}))

const loading = ref(true)
const loadError = ref<unknown>(null)
const forbidden = ref(false)
const parameters = ref<StatutoryParameter[]>([])

const editorOpen = ref(false)
const saving = ref(false)
const actionError = ref('')
const actionMessage = ref('')

const form = reactive({
  parameterSet: 'SOCIAL_INSURANCE' as StatutoryParameterSet,
  version: '',
  effectiveFrom: todayBusinessDate(),
  effectiveTo: '',
  evidenceRef: '',
  isDemo: false,
  thresholdYuan: '',
  minBaseYuan: '',
  maxBaseYuan: '',
  employeePercent: '',
  employerPercent: '',
  mode: 'HALF_UP',
})

const bracketDraft = reactive({
  lowerBoundYuan: '',
  upperBoundYuan: '',
  ratePercent: '',
  quickDeductionYuan: '0',
})

const brackets = ref<StatutoryBracketInput[]>([])

const groupedRows = computed(() =>
  parameters.value.map((parameter) => ({
    ...parameter,
    parameterSetLabel: PARAMETER_SET_LABELS[parameter.parameterSet] ?? '法定参数',
    parameterKindLabel: PARAMETER_KIND_LABELS[parameter.parameterSet] ?? '参数项',
    scopeLabel: '全公司',
    codeLabel: CODE_LABELS[parameter.code] ?? '其他参数',
    valueLabel: parameterValueLabel(parameter),
    effectiveFromLabel: formatBusinessDate(parameter.effectiveFrom),
    effectiveToLabel: parameter.effectiveTo === null || parameter.effectiveTo === '9999-12-31'
      ? '长期有效'
      : formatBusinessDate(parameter.effectiveTo),
    demoLabel: parameter.isDemo ? '演示数据' : '正式登记',
    modifiedLabel: '仅追加版本',
    approvedByLabel: `审批人编号 ${parameter.approvedBy}`,
  })),
)

const bracketRows = computed(() =>
  brackets.value.map((bracket, index) => ({
    id: index,
    lowerBoundLabel: formatMoney(bracket.lowerBoundCents),
    upperBoundLabel: bracket.upperBoundCents === null ? '无上限' : formatMoney(bracket.upperBoundCents),
    rateLabel: rateBpsPercent(bracket.rateBps),
    quickDeductionLabel: formatMoney(bracket.quickDeductionCents),
  })),
)

const requestId = computed(() => requestIdFromError(loadError.value))
const isEmpty = computed(() => !loading.value && !forbidden.value && loadError.value === null && parameters.value.length === 0)
const isTax = computed(() => form.parameterSet === 'TAX')
const isRounding = computed(() => form.parameterSet === 'ROUNDING')
const isInsurance = computed(() => !isTax.value && !isRounding.value)
const selectedSetLabel = computed(() => PARAMETER_SET_LABELS[form.parameterSet] ?? '法定参数')

const parameterColumns: DataTableColumn[] = [
  { key: 'parameterSetLabel', label: '参数集', width: '110px' },
  { key: 'parameterKindLabel', label: '参数类型', width: '100px' },
  { key: 'scopeLabel', label: '适用范围', width: '110px' },
  { key: 'codeLabel', label: '参数项', width: '140px' },
  { key: 'valueLabel', label: '数值', width: '150px', align: 'right' },
  { key: 'version', label: '版本', width: '110px' },
  { key: 'effectiveFromLabel', label: '生效日', width: '125px' },
  { key: 'effectiveToLabel', label: '失效日', width: '125px' },
  { key: 'evidenceRef', label: '证据引用', minWidth: '180px' },
  { key: 'demoLabel', label: '数据属性', width: '100px' },
  { key: 'approvedByLabel', label: '审批人', width: '100px' },
  { key: 'modifiedLabel', label: '变更方式', width: '120px' },
]

const bracketColumns: DataTableColumn[] = [
  { key: 'lowerBoundLabel', label: '下限', width: '130px', align: 'right' },
  { key: 'upperBoundLabel', label: '上限', width: '130px', align: 'right' },
  { key: 'rateLabel', label: '税率', width: '110px', align: 'right' },
  { key: 'quickDeductionLabel', label: '速算扣除数', width: '140px', align: 'right' },
]

function parameterValueLabel(parameter: StatutoryParameter): string {
  if (parameter.code === 'MODE') {
    const mode = parameter.valueDecimal ?? ''
    return MODE_LABELS[mode] ?? '未识别取整方式'
  }

  if (parameter.code.endsWith('_RATE')) {
    const rate = parameter.valueDecimal
    if (rate === null) return '—'
    return /^\d+(?:\.\d+)?$/.test(rate) ? `${rate.replace(/\.0+$/, '')}%` : '—'
  }

  return parameter.valueInt === null ? '—' : formatMoney(parameter.valueInt)
}

/** Convert integer basis points to a percentage without floating arithmetic. */
function rateBpsPercent(bps: number): string {
  const whole = Math.floor(bps / 100)
  const fraction = String(bps % 100).padStart(2, '0').replace(/0+$/, '')
  return fraction === '' ? `${whole}%` : `${whole}.${fraction}%`
}

function rateBpsFromPercent(percent: string): number | null {
  const value = percent.trim()
  const match = /^(\d{1,3})(?:\.(\d{1,2}))?$/.exec(value)
  if (match === null) return null
  const whole = Number(match[1])
  const fraction = (match[2] ?? '').padEnd(2, '0')
  const bps = whole * 100 + Number(fraction)
  return bps <= 10_000 ? bps : null
}

function resetEditor(): void {
  form.parameterSet = 'SOCIAL_INSURANCE'
  form.version = ''
  form.effectiveFrom = todayBusinessDate()
  form.effectiveTo = ''
  form.evidenceRef = ''
  form.isDemo = false
  form.thresholdYuan = ''
  form.minBaseYuan = ''
  form.maxBaseYuan = ''
  form.employeePercent = ''
  form.employerPercent = ''
  form.mode = 'HALF_UP'
  bracketDraft.lowerBoundYuan = ''
  bracketDraft.upperBoundYuan = ''
  bracketDraft.ratePercent = ''
  bracketDraft.quickDeductionYuan = '0'
  brackets.value = []
  actionError.value = ''
}

function openEditor(): void {
  resetEditor()
  editorOpen.value = true
}

function addBracket(): void {
  actionError.value = ''
  const lower = centsFromYuanInput(bracketDraft.lowerBoundYuan)
  const upper = bracketDraft.upperBoundYuan.trim() === '' ? null : centsFromYuanInput(bracketDraft.upperBoundYuan)
  const rate = rateBpsFromPercent(bracketDraft.ratePercent)
  const quick = bracketDraft.quickDeductionYuan.trim() === '' ? 0 : centsFromYuanInput(bracketDraft.quickDeductionYuan)

  if (lower === null) {
    actionError.value = '请输入有效的税率级距下限金额，最多两位小数。'
    return
  }
  if (bracketDraft.upperBoundYuan.trim() !== '' && upper === null) {
    actionError.value = '请输入有效的税率级距上限金额，或留空表示无上限。'
    return
  }
  if (upper !== null && upper <= lower) {
    actionError.value = '税率级距上限必须大于下限。'
    return
  }
  if (rate === null) {
    actionError.value = '税率必须是 0% 至 100% 的百分比，最多两位小数。'
    return
  }
  if (quick === null || quick < 0) {
    actionError.value = '速算扣除数必须是非负金额，最多两位小数。'
    return
  }

  brackets.value = [...brackets.value, {
    lowerBoundCents: lower,
    upperBoundCents: upper,
    rateBps: rate,
    quickDeductionCents: quick,
  }].sort((left, right) => left.lowerBoundCents - right.lowerBoundCents)

  bracketDraft.lowerBoundYuan = ''
  bracketDraft.upperBoundYuan = ''
  bracketDraft.ratePercent = ''
  bracketDraft.quickDeductionYuan = '0'
}

function removeBracket(index: number): void {
  brackets.value = brackets.value.filter((_, current) => current !== index)
}

function centsFromYuanInput(value: string): number | null {
  try {
    return parseYuanToCents(value)
  } catch {
    return null
  }
}

function submit(): void {
  actionError.value = ''
  actionMessage.value = ''

  if (!canManage.value) {
    actionError.value = '当前账号没有法定参数写入权限。'
    return
  }

  const version = form.version.trim()
  const evidenceRef = form.evidenceRef.trim()
  if (!/^[A-Za-z0-9][A-Za-z0-9._-]{1,63}$/.test(version)) {
    actionError.value = '参数版本必须为 2-64 位字母、数字、点、下划线或连字符。'
    return
  }
  if (!/^\d{4}-\d{2}-\d{2}$/.test(form.effectiveFrom)) {
    actionError.value = '请选择有效的生效日。'
    return
  }
  if (form.effectiveTo !== '' && form.effectiveTo < form.effectiveFrom) {
    actionError.value = '失效日不得早于生效日；留空表示长期有效。'
    return
  }
  if (evidenceRef === '') {
    actionError.value = '请填写审批或公司内部决议的证据引用。'
    return
  }

  const input = {
    parameterSet: form.parameterSet,
    version,
    effectiveFrom: form.effectiveFrom,
    effectiveTo: form.effectiveTo === '' ? null : form.effectiveTo,
    evidenceRef,
    isDemo: form.isDemo,
  }

  if (isInsurance.value) {
    const minBase = centsFromYuanInput(form.minBaseYuan)
    const maxBase = centsFromYuanInput(form.maxBaseYuan)
    const employeeRate = percentToParameterRate(form.employeePercent)
    const employerRate = percentToParameterRate(form.employerPercent)

    if (minBase === null || minBase < 0 || maxBase === null || maxBase < 0) {
      actionError.value = '缴费基数上下限必须是有效的非负金额，最多两位小数。'
      return
    }
    if (minBase > maxBase) {
      actionError.value = '缴费基数下限不得高于上限。'
      return
    }
    if (employeeRate === null || employerRate === null) {
      actionError.value = '个人比例和公司比例必须是 0% 至 100% 的百分比，最多六位小数。'
      return
    }

    void persist({
      ...input,
      minBaseCents: minBase,
      maxBaseCents: maxBase,
      employeeRate,
      employerRate,
    })
    return
  }

  if (isRounding.value) {
    if (!Object.prototype.hasOwnProperty.call(MODE_LABELS, form.mode)) {
      actionError.value = '请选择有效的取整方式。'
      return
    }
    void persist({ ...input, mode: form.mode as 'HALF_UP' | 'HALF_EVEN' | 'DOWN' | 'UP' })
    return
  }

  const threshold = centsFromYuanInput(form.thresholdYuan)
  if (threshold === null || threshold < 0) {
    actionError.value = '起征点必须是有效的非负金额，最多两位小数。'
    return
  }
  if (brackets.value.length === 0) {
    actionError.value = '个人所得税参数至少需要一个税率级距。'
    return
  }

  for (let index = 0; index < brackets.value.length; index += 1) {
    const bracket = brackets.value[index]
    if (bracket === undefined) continue
    if (index < brackets.value.length - 1 && bracket.upperBoundCents === null) {
      actionError.value = '只有最后一个税率级距可以无上限。'
      return
    }
  }

  void persist({ ...input, thresholdCents: threshold, brackets: brackets.value })
}

function percentToParameterRate(percent: string): string | null {
  const value = percent.trim()
  const match = /^(\d{1,3})(?:\.(\d{1,6}))?$/.exec(value)
  if (match === null) return null
  const whole = Number(match[1])
  const fraction = (match[2] ?? '').padEnd(6, '0')
  if (whole > 100 || (whole === 100 && /[1-9]/.test(fraction))) return null
  return `${match[1]}.${fraction}`
}

async function persist(input: Parameters<typeof createStatutoryParameterVersion>[0]): Promise<void> {
  saving.value = true
  try {
    const result = await createStatutoryParameterVersion(input, nextIdempotencyKey('statutory'))
    actionMessage.value = result.isDemo
      ? `参数版本 ${result.version} 已登记为演示数据；请替换为经公司确认的正式参数。`
      : `参数版本 ${result.version} 已登记，共 ${result.rows} 项参数。`
    ElMessage.success(actionMessage.value)
    editorOpen.value = false
    resetEditor()
    await load()
  } catch (error) {
    actionError.value = platformErrorCopy(error)
    ElMessage.error(actionError.value)
  } finally {
    saving.value = false
  }
}

async function load(): Promise<void> {
  loading.value = true
  loadError.value = null
  forbidden.value = false
  try {
    const result = await listStatutoryParameters()
    parameters.value = result.items
  } catch (error) {
    parameters.value = []
    if (isForbidden(error)) forbidden.value = true
    else loadError.value = error
  } finally {
    loading.value = false
  }
}

function retry(): void {
  void load()
}

onMounted(() => {
  if (!canRead.value) {
    loading.value = false
    return
  }
  void load()
})
</script>

<template>
  <section class="statutory-panel" data-testid="statutory-parameters-panel" aria-labelledby="statutory-title">
    <div class="statutory-panel__header">
      <div>
        <h2 id="statutory-title" class="panel__title">法定参数版本</h2>
        <p class="panel__subtitle">
          社保、公积金、个税与取整规则以这里的版本为唯一来源；福利方案不复制费率或基数。
          历史版本只读，变更请追加新版本并填写证据引用。
        </p>
      </div>
      <div class="statutory-panel__actions">
        <span class="muted small">{{ parameters.length }} 项参数</span>
        <el-button v-if="canManage" type="primary" data-testid="statutory-version-open" @click="openEditor">
          新增参数版本
        </el-button>
      </div>
    </div>

    <section v-if="!canRead" class="panel" data-testid="statutory-parameters-forbidden">
      <div class="panel__body">
        <h2 class="panel__title">无权查看法定参数</h2>
        <p class="muted">参数列表由薪资参数读取权限控制，请联系管理员授予相应能力。</p>
      </div>
    </section>

    <template v-else>
      <p v-if="actionMessage" class="inline-success" data-testid="statutory-action-message">{{ actionMessage }}</p>
      <p v-if="actionError && !editorOpen" class="inline-error" role="alert" data-testid="statutory-action-error">{{ actionError }}</p>

      <section v-if="loading" class="panel" data-testid="statutory-parameters-loading">
        <div class="panel__body">
          <el-skeleton :rows="6" animated />
          <span class="visually-hidden">正在加载法定参数版本</span>
        </div>
      </section>

      <section v-else-if="forbidden" class="panel" data-testid="statutory-parameters-forbidden">
        <div class="panel__body">
          <h2 class="panel__title">法定参数不可查看</h2>
          <p class="muted">服务端拒绝了本次读取。参数按公司范围授权，不会跨公司返回。</p>
        </div>
      </section>

      <section v-else-if="loadError" class="panel" data-testid="statutory-parameters-error">
        <div class="panel__body">
          <h2 class="panel__title">法定参数加载失败</h2>
          <p class="muted">{{ platformErrorCopy(loadError) }}</p>
          <p v-if="requestId" class="muted small">请求编号：<span class="mono">{{ requestId }}</span></p>
          <el-button type="primary" plain @click="retry">重试</el-button>
        </div>
      </section>

      <section v-else-if="isEmpty" class="panel" data-testid="statutory-parameters-empty">
        <div class="panel__body">
          <h2 class="panel__title">尚未配置法定参数</h2>
          <p class="muted">
            当前公司没有已登记的社保、公积金、个税或取整参数版本。
            <template v-if="canManage">新增版本时页面不会预填任何法定费率。</template>
          </p>
        </div>
      </section>

      <template v-else>
        <DataTable
          :columns="parameterColumns"
          :rows="groupedRows as unknown as Array<Record<string, unknown>>"
          :total="groupedRows.length"
          :page="1"
          :page-size="100"
          row-key="id"
          data-testid="statutory-parameter-table"
          empty-title="当前没有法定参数行"
          :row-test-id="(row) => `statutory-parameter-row-${String(row.id)}`"
        >
          <template #cell-valueLabel="{ row }">
            <span class="mono">{{ row.valueLabel }}</span>
          </template>
          <template #cell-version="{ row }">
            <span class="plain-chip mono">{{ row.version }}</span>
          </template>
          <template #cell-demoLabel="{ row }">
            <span :class="row.isDemo ? 'plain-chip' : 'muted'">{{ row.demoLabel }}</span>
          </template>
        </DataTable>
      </template>
    </template>

    <el-drawer
      v-model="editorOpen"
      :title="`新增${selectedSetLabel}参数版本`"
      size="640px"
      data-testid="statutory-version-drawer"
    >
      <div class="statutory-form">
        <p class="muted small">
          版本提交后会同时写入参数行、审计记录与生效日期。页面不会根据福利方案或历史数据推断费率。
        </p>

        <label class="statutory-field">
          <span>参数集</span>
          <el-select v-model="form.parameterSet" data-testid="statutory-parameter-set" aria-label="参数集">
            <el-option v-for="option in PARAMETER_SET_OPTIONS" :key="option.value" :label="option.label" :value="option.value" />
          </el-select>
        </label>

        <label class="statutory-field">
          <span>版本标识</span>
          <el-input v-model="form.version" data-testid="statutory-version" maxlength="64" placeholder="例如 SI-2026.2" />
        </label>

        <div class="statutory-form__pair">
          <label class="statutory-field">
            <span>生效日</span>
            <el-date-picker
              v-model="form.effectiveFrom"
              data-testid="statutory-effective-from"
              type="date"
              value-format="YYYY-MM-DD"
              aria-label="生效日"
            />
          </label>
          <label class="statutory-field">
            <span>失效日（可选）</span>
            <el-date-picker
              v-model="form.effectiveTo"
              data-testid="statutory-effective-to"
              type="date"
              value-format="YYYY-MM-DD"
              aria-label="失效日"
            />
          </label>
        </div>

        <label class="statutory-field">
          <span>证据引用</span>
          <el-input
            v-model="form.evidenceRef"
            data-testid="statutory-evidence-ref"
            maxlength="255"
            placeholder="例如公司薪酬委员会 2026 年 6 月决议编号"
          />
        </label>

        <el-checkbox v-model="form.isDemo" data-testid="statutory-is-demo">这是演示数据，不可作为正式参数</el-checkbox>

        <template v-if="isInsurance">
          <div class="statutory-form__pair">
            <label class="statutory-field">
              <span>缴费基数下限（元）</span>
              <el-input v-model="form.minBaseYuan" data-testid="statutory-min-base" inputmode="decimal" placeholder="0.00" />
            </label>
            <label class="statutory-field">
              <span>缴费基数上限（元）</span>
              <el-input v-model="form.maxBaseYuan" data-testid="statutory-max-base" inputmode="decimal" placeholder="0.00" />
            </label>
          </div>
          <div class="statutory-form__pair">
            <label class="statutory-field">
              <span>个人比例（%）</span>
              <el-input v-model="form.employeePercent" data-testid="statutory-employee-rate" inputmode="decimal" placeholder="例如 8" />
            </label>
            <label class="statutory-field">
              <span>公司比例（%）</span>
              <el-input v-model="form.employerPercent" data-testid="statutory-employer-rate" inputmode="decimal" placeholder="例如 16" />
            </label>
          </div>
        </template>

        <template v-else-if="isRounding">
          <label class="statutory-field">
            <span>取整方式</span>
            <el-select v-model="form.mode" data-testid="statutory-mode" aria-label="取整方式">
              <el-option v-for="(label, value) in MODE_LABELS" :key="value" :label="label" :value="value" />
            </el-select>
          </label>
        </template>

        <template v-else>
          <label class="statutory-field">
            <span>起征点（元）</span>
            <el-input v-model="form.thresholdYuan" data-testid="statutory-threshold" inputmode="decimal" placeholder="0.00" />
          </label>

          <section class="statutory-brackets" aria-labelledby="statutory-brackets-title">
            <div class="statutory-brackets__header">
              <h3 id="statutory-brackets-title">税率级距</h3>
              <span class="muted small">{{ brackets.length }} 个级距</span>
            </div>
            <DataTable
              v-if="bracketRows.length > 0"
              :columns="bracketColumns"
              :rows="bracketRows as unknown as Array<Record<string, unknown>>"
              :total="bracketRows.length"
              :page="1"
              :page-size="100"
              data-testid="statutory-bracket-table"
              empty-title="尚未添加税率级距"
            >
              <template #toolbar>
                <el-button
                  v-for="(bracket, index) in brackets"
                  :key="`remove-${bracket.lowerBoundCents}-${index}`"
                  text
                  size="small"
                  @click="removeBracket(index)"
                >
                  移除第 {{ index + 1 }} 级
                </el-button>
              </template>
            </DataTable>

            <div class="statutory-form__pair">
              <label class="statutory-field">
                <span>级距下限（元）</span>
                <el-input v-model="bracketDraft.lowerBoundYuan" data-testid="statutory-bracket-lower" inputmode="decimal" />
              </label>
              <label class="statutory-field">
                <span>级距上限（元，留空表示无上限）</span>
                <el-input v-model="bracketDraft.upperBoundYuan" data-testid="statutory-bracket-upper" inputmode="decimal" />
              </label>
            </div>
            <div class="statutory-form__pair">
              <label class="statutory-field">
                <span>税率（%）</span>
                <el-input v-model="bracketDraft.ratePercent" data-testid="statutory-bracket-rate" inputmode="decimal" />
              </label>
              <label class="statutory-field">
                <span>速算扣除数（元）</span>
                <el-input v-model="bracketDraft.quickDeductionYuan" data-testid="statutory-bracket-quick-deduction" inputmode="decimal" />
              </label>
            </div>
            <el-button data-testid="statutory-bracket-add" @click="addBracket">添加税率级距</el-button>
          </section>
        </template>

        <p v-if="actionError" class="inline-error" role="alert" data-testid="statutory-action-error">{{ actionError }}</p>
      </div>

      <template #footer>
        <div class="statutory-drawer-footer">
          <el-button :disabled="saving" @click="editorOpen = false">取消</el-button>
          <el-button
            type="primary"
            :loading="saving"
            data-testid="statutory-version-submit"
            @click="submit"
          >
            登记参数版本
          </el-button>
        </div>
      </template>
    </el-drawer>
  </section>
</template>

<style scoped>
.statutory-panel {
  display: flex;
  flex-direction: column;
  gap: 14px;
}
.statutory-panel__header {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  align-items: flex-start;
  justify-content: space-between;
}
.statutory-panel__actions {
  display: flex;
  gap: 10px;
  align-items: center;
}
.statutory-form {
  display: flex;
  flex-direction: column;
  gap: 14px;
}
.statutory-form__pair {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}
.statutory-field {
  display: flex;
  flex-direction: column;
  gap: 6px;
  font-size: 12px;
  font-weight: 600;
}
.statutory-brackets {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 14px;
  background: var(--color-surface-muted, #f7f8fa);
  border: 1px solid var(--color-line);
  border-radius: 8px;
}
.statutory-brackets__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.statutory-brackets__header h3 {
  margin: 0;
  font-size: 14px;
}
.statutory-drawer-footer {
  display: flex;
  gap: 10px;
  justify-content: flex-end;
}
@media (max-width: 720px) {
  .statutory-form__pair {
    grid-template-columns: 1fr;
  }
}
</style>
