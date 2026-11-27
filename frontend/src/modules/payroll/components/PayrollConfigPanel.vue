<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { formatBusinessDate, parseYuanToCents } from '@/utils/format'
import MoneyText from '@/components/MoneyText.vue'
import DataTable, { type DataTableColumn } from '@/components/DataTable.vue'
import { useSessionStore } from '@/stores/session'
import {
  createEmployeeCompensation,
  createPayrollItem,
  createPayrollItemVersion,
  createPayrollScheme,
  listEmployeeCompensations,
  listPayrollItems,
  listPayrollSchemes,
  listSalaryAdjustments,
  retirePayrollItem,
  type EmployeeCompensationRecord,
  type PayrollItemRecord,
  type PayrollSchemeRecord,
  type SalaryAdjustmentRecord,
} from '../api'
import { errorCopy } from '@/api/errors'

type ConfigTab = 'items' | 'plans' | 'compensations'

const props = defineProps<{
  initialTab?: ConfigTab
}>()

const emit = defineEmits<{
  'open-statutory': []
}>()

const session = useSessionStore()
const canRead = computed(() => session.can('payroll.run.read'))
const canPrepare = computed(() => session.can('payroll.run.prepare'))
const canCompensate = computed(() => session.can('payroll.compensation.manage'))

const activeTab = ref<ConfigTab>(props.initialTab ?? 'items')
const loading = ref(false)
const loadError = ref('')
const actionError = ref('')
const actionMessage = ref('')
const saving = ref(false)

const items = ref<PayrollItemRecord[]>([])
const schemes = ref<PayrollSchemeRecord[]>([])
const compensations = ref<EmployeeCompensationRecord[]>([])
const adjustments = ref<SalaryAdjustmentRecord[]>([])

const itemDialogOpen = ref(false)
const versionDialogOpen = ref(false)
const retireDialogOpen = ref(false)
const schemeDialogOpen = ref(false)
const compensationDialogOpen = ref(false)
const selectedItem = ref<PayrollItemRecord | null>(null)
const itemSearch = ref('')

const itemForm = reactive({
  code: '',
  name: '',
  type: 'EARNING',
  calculation: 'FIXED',
  taxable: true,
  insurable: true,
  sortOrder: '0',
  effectiveFrom: '',
})

const versionForm = reactive({
  name: '',
  effectiveFrom: '',
  type: 'EARNING',
  calculation: 'FIXED',
  taxable: true,
  insurable: true,
})

const retireReason = ref('')

const schemeForm = reactive({
  code: '',
  name: '',
  currency: 'CNY',
  validFrom: '',
  overtimeMultiplier: '1.500000',
})

const compensationForm = reactive({
  employmentId: '',
  effectiveFrom: '',
  baseSalaryYuan: '',
  salarySchemeCode: '',
})

const filteredItems = computed(() => {
  const term = itemSearch.value.trim().toLowerCase()
  if (term === '') return items.value
  return items.value.filter(
    (item) => item.code.toLowerCase().includes(term) || item.name.toLowerCase().includes(term),
  )
})

const itemColumns: DataTableColumn[] = [
  { key: 'code', label: '项目编码', minWidth: '130px' },
  { key: 'name', label: '中文名称', minWidth: '150px' },
  { key: 'typeLabel', label: '类别', width: '100px' },
  { key: 'calculationLabel', label: '计算方式', width: '120px' },
  { key: 'flags', label: '计税 / 社保', width: '120px' },
  { key: 'versionLabel', label: '版本', width: '110px' },
  { key: 'effective', label: '生效区间', minWidth: '200px' },
  { key: 'statusLabel', label: '状态', width: '90px' },
  { key: 'actions', label: '操作', width: '200px', align: 'right' },
]

const schemeColumns: DataTableColumn[] = [
  { key: 'code', label: '方案编码', minWidth: '130px' },
  { key: 'name', label: '方案名称', minWidth: '150px' },
  { key: 'version', label: '版本', width: '80px', align: 'right' },
  { key: 'currency', label: '币种', width: '80px' },
  { key: 'roundingMode', label: '取整', width: '100px' },
  { key: 'multiplier', label: '加班倍率', width: '110px' },
  { key: 'baseSalary', label: '基数', width: '130px', align: 'right' },
  { key: 'itemCount', label: '项目数', width: '90px', align: 'right' },
  { key: 'status', label: '状态', width: '90px' },
]

const compensationColumns: DataTableColumn[] = [
  { key: 'employmentId', label: '员工关系', width: '110px' },
  { key: 'effectiveFrom', label: '生效日', width: '120px' },
  { key: 'salarySchemeCode', label: '方案编码', minWidth: '130px' },
  { key: 'baseSalary', label: '基本工资', width: '140px', align: 'right' },
  { key: 'currency', label: '币种', width: '80px' },
]

const adjustmentColumns: DataTableColumn[] = [
  { key: 'employmentId', label: '员工关系', width: '110px' },
  { key: 'effectiveFrom', label: '生效日', width: '120px' },
  { key: 'reason', label: '调薪原因', minWidth: '180px' },
  { key: 'caseId', label: '审批案例', width: '110px' },
  { key: 'baseSalary', label: '调整后工资', width: '140px', align: 'right' },
]

function typeLabel(type: string): string {
  return { EARNING: '收入', DEDUCTION: '扣减', EMPLOYER_COST: '单位成本', INFO: '信息项' }[type] ?? type
}

function calculationLabel(calculation: string): string {
  return {
    FIXED: '固定金额',
    ATTENDANCE_PRORATE: '按出勤折算',
    PERCENT: '按比例',
    FORMULA_LIMITED: '受限公式',
  }[calculation] ?? calculation
}

function capture(reason: unknown): void {
  actionError.value = errorCopy(reason)
}

async function load(): Promise<void> {
  if (!canRead.value) return
  loading.value = true
  loadError.value = ''
  try {
    const settled = await Promise.allSettled([listPayrollItems(), listPayrollSchemes()])
    if (settled[0].status === 'fulfilled') items.value = settled[0].value
    if (settled[1].status === 'fulfilled') schemes.value = settled[1].value

    const failures = settled.filter((entry) => entry.status === 'rejected').length
    if (failures === settled.length) {
      const first = settled[0]
      throw first.status === 'rejected' ? first.reason : new Error('薪资配置暂时无法加载')
    }
    if (failures > 0) {
      loadError.value = '部分配置数据暂不可用；仍展示服务端已返回的数据，不用空值补造。'
    }

    if (session.can('payroll.compensation.read')) {
      const [plans, history] = await Promise.allSettled([listEmployeeCompensations(), listSalaryAdjustments()])
      if (plans.status === 'fulfilled') compensations.value = plans.value
      if (history.status === 'fulfilled') adjustments.value = history.value
    }
  } catch (reason) {
    capture(reason)
    loadError.value = errorCopy(reason)
  } finally {
    loading.value = false
  }
}

function openCreateItem(): void {
  actionError.value = ''
  Object.assign(itemForm, {
    code: '',
    name: '',
    type: 'EARNING',
    calculation: 'FIXED',
    taxable: true,
    insurable: true,
    sortOrder: '0',
    effectiveFrom: '',
  })
  itemDialogOpen.value = true
}

async function saveItem(): Promise<void> {
  actionError.value = ''
  const sortOrder = Number(itemForm.sortOrder)
  if (!Number.isSafeInteger(sortOrder) || sortOrder < 0) {
    actionError.value = '排序必须是大于等于 0 的整数。'
    return
  }
  if (!/^[A-Za-z][A-Za-z0-9_]{1,63}$/.test(itemForm.code.trim())) {
    actionError.value = '项目编码需以字母开头，只能包含字母、数字和下划线。'
    return
  }
  if (itemForm.name.trim() === '') {
    actionError.value = '请填写项目中文名称。'
    return
  }
  saving.value = true
  try {
    await createPayrollItem({
      code: itemForm.code.trim().toUpperCase(),
      name: itemForm.name.trim(),
      type: itemForm.type,
      calculation: itemForm.calculation,
      taxable: itemForm.taxable,
      insurable: itemForm.insurable,
      sort_order: sortOrder,
      ...(itemForm.effectiveFrom ? { effective_from: itemForm.effectiveFrom } : {}),
    })
    itemDialogOpen.value = false
    actionMessage.value = '薪资项目已创建；版本由服务端置为第 1 版。'
    ElMessage.success(actionMessage.value)
    await load()
  } catch (reason) {
    capture(reason)
  } finally {
    saving.value = false
  }
}

function openVersion(item: PayrollItemRecord): void {
  if (!item.isCurrent || item.isRetired) return
  selectedItem.value = item
  actionError.value = ''
  Object.assign(versionForm, {
    name: item.name,
    effectiveFrom: '',
    type: item.type,
    calculation: item.calculation,
    taxable: item.taxable,
    insurable: item.insurable,
  })
  versionDialogOpen.value = true
}

async function saveVersion(): Promise<void> {
  const item = selectedItem.value
  if (item === null) return
  if (versionForm.effectiveFrom === '') {
    actionError.value = '请填写新版本的生效日。'
    return
  }
  actionError.value = ''
  saving.value = true
  try {
    await createPayrollItemVersion(item.id, {
      name: versionForm.name.trim(),
      effective_from: versionForm.effectiveFrom,
      type: versionForm.type,
      calculation: versionForm.calculation,
      taxable: versionForm.taxable,
      insurable: versionForm.insurable,
    })
    versionDialogOpen.value = false
    actionMessage.value = '已追加新版本；历史版本保留原值。'
    ElMessage.success(actionMessage.value)
    await load()
  } catch (reason) {
    capture(reason)
  } finally {
    saving.value = false
  }
}

function openRetire(item: PayrollItemRecord): void {
  selectedItem.value = item
  retireReason.value = ''
  actionError.value = ''
  retireDialogOpen.value = true
}

async function confirmRetire(): Promise<void> {
  const item = selectedItem.value
  if (item === null) return
  if (retireReason.value.trim().length < 2) {
    actionError.value = '请填写至少 2 个字符的停用原因。'
    return
  }
  actionError.value = ''
  saving.value = true
  try {
    await retirePayrollItem(item.id, retireReason.value.trim())
    retireDialogOpen.value = false
    actionMessage.value = '薪资项目已停用；历史版本和已冻结批次不受影响。'
    ElMessage.success(actionMessage.value)
    await load()
  } catch (reason) {
    capture(reason)
  } finally {
    saving.value = false
  }
}

function openCreateScheme(): void {
  Object.assign(schemeForm, {
    code: '',
    name: '',
    currency: 'CNY',
    validFrom: '',
    overtimeMultiplier: '1.500000',
  })
  actionError.value = ''
  schemeDialogOpen.value = true
}

async function saveScheme(): Promise<void> {
  if (!/^\d{1,6}(\.\d{6,18})?$/.test(schemeForm.overtimeMultiplier)) {
    actionError.value = '加班倍率需为至少 6 位小数的十进制字符串，不能使用浮点输入。'
    return
  }
  actionError.value = ''
  saving.value = true
  try {
    await createPayrollScheme({
      code: schemeForm.code.trim().toUpperCase(),
      name: schemeForm.name.trim(),
      currency: schemeForm.currency.trim().toUpperCase(),
      overtime_multiplier: schemeForm.overtimeMultiplier,
      ...(schemeForm.validFrom ? { valid_from: schemeForm.validFrom } : {}),
    })
    schemeDialogOpen.value = false
    actionMessage.value = '薪酬方案已创建；版本号由服务端在锁内自增。'
    ElMessage.success(actionMessage.value)
    await load()
  } catch (reason) {
    capture(reason)
  } finally {
    saving.value = false
  }
}

function openCompensation(): void {
  Object.assign(compensationForm, {
    employmentId: '',
    effectiveFrom: '',
    baseSalaryYuan: '',
    salarySchemeCode: '',
  })
  actionError.value = ''
  compensationDialogOpen.value = true
}

async function saveCompensation(): Promise<void> {
  const employmentId = Number(compensationForm.employmentId)
  if (!Number.isSafeInteger(employmentId) || employmentId <= 0) {
    actionError.value = '请填写有效的员工关系编号。'
    return
  }

  let baseSalaryCents = 0
  try {
    baseSalaryCents = parseYuanToCents(compensationForm.baseSalaryYuan)
  } catch {
    actionError.value = '基本工资需为元，最多两位小数。'
    return
  }
  if (baseSalaryCents < 0) {
    actionError.value = '基本工资不能为负数。'
    return
  }

  actionError.value = ''
  saving.value = true
  try {
    await createEmployeeCompensation({
      employment_id: employmentId,
      effective_from: compensationForm.effectiveFrom,
      base_salary_cents: baseSalaryCents,
      currency: 'CNY',
      ...(compensationForm.salarySchemeCode
        ? { salary_scheme_code: compensationForm.salarySchemeCode.trim().toUpperCase() }
        : {}),
    })
    compensationDialogOpen.value = false
    actionMessage.value = '员工薪资档案已追加新版本。'
    ElMessage.success(actionMessage.value)
    await load()
  } catch (reason) {
    capture(reason)
  } finally {
    saving.value = false
  }
}

function itemStatusLabel(item: PayrollItemRecord): string {
  if (item.isRetired) return '已停用'
  return item.isCurrent ? '当前版本' : '历史版本'
}

onMounted(() => {
  void load()
})
</script>

<template>
  <section class="panel payroll-config" data-testid="payroll-config-panel">
    <div class="panel__header">
      <div>
        <h2 class="panel__title">薪酬配置</h2>
        <p class="panel__subtitle">
          项目、方案与员工档案均为生效日版本；历史版本只读，冻结批次不会因新配置而改写。
        </p>
      </div>
      <el-button text :disabled="loading" @click="load">刷新</el-button>
    </div>

    <div class="payroll-config__tabs" role="tablist" aria-label="薪酬配置分类">
      <button
        v-for="tab in [
          { key: 'items' as const, label: '薪资项目' },
          { key: 'plans' as const, label: '薪酬方案' },
          { key: 'compensations' as const, label: '员工薪资档案' },
        ]"
        :key="tab.key"
        type="button"
        role="tab"
        :aria-selected="activeTab === tab.key"
        :data-testid="`payroll-config-tab-${tab.key}`"
        @click="activeTab = tab.key"
      >
        {{ tab.label }}
      </button>
    </div>

    <p v-if="loadError !== ''" class="payroll-config__warning" role="status">{{ loadError }}</p>
    <p v-if="actionError !== ''" class="payroll-config__error" role="alert">{{ actionError }}</p>
    <p v-if="actionMessage !== ''" class="payroll-config__success" role="status">{{ actionMessage }}</p>

    <template v-if="activeTab === 'items'">
      <div class="payroll-config__toolbar">
        <el-button
          type="primary"
          :disabled="!canPrepare || loading"
          data-testid="payroll-item-create"
          @click="openCreateItem"
        >
          新增薪资项目
        </el-button>
        <el-button data-testid="payroll-statutory-link" @click="emit('open-statutory')">
          社保公积金参数（前往福利）
        </el-button>
      </div>

      <DataTable
        v-if="filteredItems.length > 0"
        v-model:search-value="itemSearch"
        :columns="itemColumns"
        :rows="filteredItems as unknown as Array<Record<string, unknown>>"
        :total="filteredItems.length"
        :page="1"
        :page-size="Math.max(1, filteredItems.length)"
        row-key="itemVersionId"
        data-testid="payroll-item-table"
        show-search
        search-placeholder="搜索项目编码或名称"
      >
        <template #cell-typeLabel="{ row }">{{ typeLabel(String(row.type)) }}</template>
        <template #cell-calculationLabel="{ row }">{{ calculationLabel(String(row.calculation)) }}</template>
        <template #cell-flags="{ row }">{{ row.taxable ? '计税' : '不计税' }} / {{ row.insurable ? '计社保' : '不计社保' }}</template>
        <template #cell-versionLabel="{ row }">v{{ row.version }}</template>
        <template #cell-effective="{ row }">
          {{ formatBusinessDate(String(row.effectiveFrom)) }} 至
          {{ row.effectiveTo === '9999-12-31' ? '长期' : formatBusinessDate(String(row.effectiveTo)) }}
        </template>
        <template #cell-statusLabel="{ row }">{{ itemStatusLabel(row as unknown as PayrollItemRecord) }}</template>
        <template #cell-actions="{ row }">
          <template v-if="canPrepare && row.isCurrent && !row.isRetired">
            <el-button text size="small" :data-testid="`payroll-item-version-${row.id}`" @click="openVersion(row as unknown as PayrollItemRecord)">
              复制为新版本
            </el-button>
            <el-button text size="small" type="danger" :data-testid="`payroll-item-retire-${row.id}`" @click="openRetire(row as unknown as PayrollItemRecord)">
              停用
            </el-button>
          </template>
          <span v-else class="payroll-config__muted">只读历史</span>
        </template>
      </DataTable>

      <div v-else-if="!loading" class="payroll-config__empty" data-testid="payroll-item-empty">
        <h3>{{ canPrepare ? '还没有薪资项目' : '暂无薪资项目' }}</h3>
        <p v-if="canPrepare">新增第一个项目后，方案与工资批次才能引用稳定的项目编码和生效版本。</p>
        <p v-else>薪资专员完成项目配置后，这里会展示真实项目版本。</p>
        <el-button v-if="canPrepare" type="primary" @click="openCreateItem">新增薪资项目</el-button>
      </div>
    </template>

    <template v-else-if="activeTab === 'plans'">
      <div class="payroll-config__toolbar">
        <el-button type="primary" :disabled="!canPrepare || loading" data-testid="payroll-scheme-create" @click="openCreateScheme">
          新建薪酬方案
        </el-button>
      </div>
      <DataTable
        :columns="schemeColumns"
        :rows="schemes as unknown as Array<Record<string, unknown>>"
        :total="schemes.length"
        :page="1"
        :page-size="Math.max(1, schemes.length)"
        data-testid="payroll-scheme-table"
        empty-title="还没有薪酬方案"
      >
        <template #cell-version="{ row }">v{{ row.version }}</template>
        <template #cell-multiplier="{ row }">{{ row.overtimeMultiplier }}</template>
        <template #cell-baseSalary="{ row }">
          <MoneyText :cents="Number(row.baseSalaryCents)" size="sm" />
        </template>
        <template #cell-itemCount="{ row }">{{ (row.items as unknown[]).length }}</template>
      </DataTable>
    </template>

    <template v-else>
      <div class="payroll-config__toolbar">
        <el-button type="primary" :disabled="!canCompensate || loading" data-testid="payroll-compensation-create" @click="openCompensation">
          新增薪资档案版本
        </el-button>
      </div>
      <p v-if="!session.can('payroll.compensation.read')" class="payroll-config__warning" role="status">
        当前账号缺少 payroll.compensation.read，员工薪资档案与调薪历史不会发起请求，也不会显示空工资或零元占位。
      </p>
      <DataTable
        :columns="compensationColumns"
        :rows="compensations as unknown as Array<Record<string, unknown>>"
        :total="compensations.length"
        :page="1"
        :page-size="Math.max(1, compensations.length)"
        data-testid="payroll-compensation-table"
        empty-title="还没有员工薪资档案"
      >
        <template #cell-employmentId="{ row }">员工关系 #{{ row.employmentId }}</template>
        <template #cell-baseSalary="{ row }">
          <MoneyText v-if="row.baseSalaryCents !== undefined" :cents="Number(row.baseSalaryCents)" size="sm" />
          <span v-else class="payroll-config__muted">已按字段权限隐藏</span>
        </template>
      </DataTable>

      <h3 class="payroll-config__subtitle">调薪历史（由已批准审批案例产生）</h3>
      <DataTable
        :columns="adjustmentColumns"
        :rows="adjustments as unknown as Array<Record<string, unknown>>"
        :total="adjustments.length"
        :page="1"
        :page-size="Math.max(1, adjustments.length)"
        data-testid="payroll-adjustment-table"
        empty-title="还没有调薪记录"
      >
        <template #cell-employmentId="{ row }">员工关系 #{{ row.employmentId }}</template>
        <template #cell-caseId="{ row }">案例 #{{ row.caseId }}</template>
        <template #cell-baseSalary="{ row }">
          <MoneyText v-if="row.baseSalaryCents !== undefined" :cents="Number(row.baseSalaryCents)" size="sm" />
          <span v-else class="payroll-config__muted">已按字段权限隐藏</span>
        </template>
      </DataTable>
    </template>

    <el-dialog v-model="itemDialogOpen" title="新增薪资项目" width="520px" :close-on-click-modal="false" data-testid="payroll-item-dialog">
      <label class="payroll-config__field">项目编码
        <el-input v-model="itemForm.code" data-testid="payroll-item-code" placeholder="例如 PERFORMANCE_BONUS" maxlength="64" />
      </label>
      <label class="payroll-config__field">中文名称
        <el-input v-model="itemForm.name" data-testid="payroll-item-name" maxlength="160" />
      </label>
      <label class="payroll-config__field">类别
        <el-select v-model="itemForm.type" data-testid="payroll-item-type">
          <el-option label="收入" value="EARNING" />
          <el-option label="扣减" value="DEDUCTION" />
          <el-option label="单位成本" value="EMPLOYER_COST" />
          <el-option label="信息项" value="INFO" />
        </el-select>
      </label>
      <label class="payroll-config__field">计算方式
        <el-select v-model="itemForm.calculation" data-testid="payroll-item-calculation">
          <el-option label="固定金额" value="FIXED" />
          <el-option label="按出勤折算" value="ATTENDANCE_PRORATE" />
          <el-option label="按比例" value="PERCENT" />
          <el-option label="受限公式" value="FORMULA_LIMITED" />
        </el-select>
      </label>
      <label class="payroll-config__field">生效日
        <el-input v-model="itemForm.effectiveFrom" type="date" data-testid="payroll-item-effective-from" />
      </label>
      <div class="payroll-config__checks">
        <el-checkbox v-model="itemForm.taxable">计税</el-checkbox>
        <el-checkbox v-model="itemForm.insurable">计社保</el-checkbox>
      </div>
      <p class="payroll-config__muted">项目金额、工资与费率不在本表单保存；金额只以整数分由服务端持有。</p>
      <template #footer>
        <el-button @click="itemDialogOpen = false">取消</el-button>
        <el-button type="primary" :loading="saving" data-testid="payroll-item-save" @click="saveItem">保存</el-button>
      </template>
    </el-dialog>

    <el-dialog v-model="versionDialogOpen" title="复制为新版本" width="520px" :close-on-click-modal="false" data-testid="payroll-item-version-dialog">
      <p class="payroll-config__muted">当前版本：{{ selectedItem?.code }} v{{ selectedItem?.version }}。保存会追加新版本，旧版本业务字段不改写。</p>
      <label class="payroll-config__field">中文名称
        <el-input v-model="versionForm.name" data-testid="payroll-item-version-name" maxlength="160" />
      </label>
      <label class="payroll-config__field">生效日
        <el-input v-model="versionForm.effectiveFrom" type="date" data-testid="payroll-item-version-effective-from" />
      </label>
      <div class="payroll-config__checks">
        <el-checkbox v-model="versionForm.taxable">计税</el-checkbox>
        <el-checkbox v-model="versionForm.insurable">计社保</el-checkbox>
      </div>
      <template #footer>
        <el-button @click="versionDialogOpen = false">取消</el-button>
        <el-button type="primary" :loading="saving" data-testid="payroll-item-version-save" @click="saveVersion">追加版本</el-button>
      </template>
    </el-dialog>

    <el-dialog v-model="retireDialogOpen" title="停用薪资项目" width="480px" :close-on-click-modal="false" data-testid="payroll-item-retire-dialog">
      <p class="payroll-config__muted">
        停用后该项目不能用于新方案或新批次；历史工资条与已冻结批次保持原值。被未来方案引用时服务端会拒绝。
      </p>
      <label class="payroll-config__field">停用原因
        <el-input v-model="retireReason" type="textarea" data-testid="payroll-item-retire-reason" maxlength="500" />
      </label>
      <template #footer>
        <el-button @click="retireDialogOpen = false">取消</el-button>
        <el-button type="danger" :loading="saving" data-testid="payroll-item-retire-confirm" @click="confirmRetire">确认停用</el-button>
      </template>
    </el-dialog>

    <el-dialog v-model="schemeDialogOpen" title="新建薪酬方案" width="520px" :close-on-click-modal="false" data-testid="payroll-scheme-dialog">
      <label class="payroll-config__field">方案编码
        <el-input v-model="schemeForm.code" data-testid="payroll-scheme-code" maxlength="64" />
      </label>
      <label class="payroll-config__field">方案名称
        <el-input v-model="schemeForm.name" data-testid="payroll-scheme-name" maxlength="160" />
      </label>
      <label class="payroll-config__field">币种
        <el-input v-model="schemeForm.currency" data-testid="payroll-scheme-currency" maxlength="3" />
      </label>
      <label class="payroll-config__field">生效日
        <el-input v-model="schemeForm.validFrom" type="date" data-testid="payroll-scheme-valid-from" />
      </label>
      <label class="payroll-config__field">加班倍率（十进制字符串）
        <el-input v-model="schemeForm.overtimeMultiplier" data-testid="payroll-scheme-multiplier" />
      </label>
      <p class="payroll-config__muted">版本号由服务端锁内自增；客户端不提交金额浮点。</p>
      <template #footer>
        <el-button @click="schemeDialogOpen = false">取消</el-button>
        <el-button type="primary" :loading="saving" data-testid="payroll-scheme-save" @click="saveScheme">保存</el-button>
      </template>
    </el-dialog>

    <el-dialog v-model="compensationDialogOpen" title="新增员工薪资档案版本" width="520px" :close-on-click-modal="false" data-testid="payroll-compensation-dialog">
      <label class="payroll-config__field">员工关系编号
        <el-input v-model="compensationForm.employmentId" data-testid="payroll-compensation-employment" />
      </label>
      <label class="payroll-config__field">生效日
        <el-input v-model="compensationForm.effectiveFrom" type="date" data-testid="payroll-compensation-effective-from" />
      </label>
      <label class="payroll-config__field">基本工资（元）
        <el-input v-model="compensationForm.baseSalaryYuan" data-testid="payroll-compensation-salary" placeholder="例如 8000.00" />
      </label>
      <label class="payroll-config__field">方案编码（可选）
        <el-input v-model="compensationForm.salarySchemeCode" data-testid="payroll-compensation-scheme" />
      </label>
      <p class="payroll-config__muted">页面只把元转换为整数分提交；调薪必须走已批准审批案例，不在此直接改历史工资。</p>
      <template #footer>
        <el-button @click="compensationDialogOpen = false">取消</el-button>
        <el-button type="primary" :loading="saving" data-testid="payroll-compensation-save" @click="saveCompensation">保存</el-button>
      </template>
    </el-dialog>
  </section>
</template>

<style scoped>
.payroll-config {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.payroll-config__tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  padding: 0 16px;
}

.payroll-config__tabs button {
  padding: 6px 12px;
  color: #475569;
  font-size: 12px;
  font-weight: 600;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 999px;
  cursor: pointer;
}

.payroll-config__tabs button[aria-selected='true'] {
  color: #fff;
  background: #1d4ed8;
  border-color: #1d4ed8;
}

.payroll-config__toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  padding: 0 16px;
}

.payroll-config__empty {
  margin: 0 16px 16px;
  padding: 24px;
  text-align: center;
  background: #f8fafc;
  border: 1px dashed #cbd5e1;
  border-radius: 10px;
}

.payroll-config__empty h3 {
  margin: 0 0 6px;
  font-size: 15px;
}

.payroll-config__empty p {
  margin: 0 0 12px;
  color: #475569;
  font-size: 12px;
}

.payroll-config__field {
  display: flex;
  flex-direction: column;
  gap: 4px;
  margin-bottom: 12px;
  color: #475569;
  font-size: 12px;
}

.payroll-config__checks {
  display: flex;
  gap: 16px;
  margin-bottom: 12px;
}

.payroll-config__muted {
  color: #64748b;
  font-size: 11.5px;
}

.payroll-config__warning,
.payroll-config__error,
.payroll-config__success {
  margin: 0 16px;
  padding: 8px 12px;
  font-size: 11.5px;
  border-radius: 8px;
}

.payroll-config__warning {
  color: #92400e;
  background: #fffbeb;
  border: 1px solid #fde68a;
}

.payroll-config__error {
  color: #b91c1c;
  background: #fef2f2;
  border: 1px solid #fecaca;
}

.payroll-config__success {
  color: #166534;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
}

.payroll-config__subtitle {
  margin: 8px 16px 0;
  font-size: 14px;
}
</style>
