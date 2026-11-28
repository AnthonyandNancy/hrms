<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import { errorCopy } from '@/api/errors'
import DataTable, { type DataTableColumn } from '@/components/DataTable.vue'
import { useSessionStore } from '@/stores/session'
import { formatMoney, parseYuanToCents } from '@/utils/format'
import {
  approvePerformanceBonusMapping,
  createPerformanceBonusMapping,
  listPerformanceBonusMappings,
  type PerformanceBonusMapping,
} from '../api'

defineOptions({ name: 'PerformanceBonusMappingDialog' })

const props = defineProps<{ modelValue: boolean }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>()

const session = useSessionStore()
const canApprove = computed(() => session.can('performance.result.publish'))
const loading = ref(false)
const saving = ref(false)
const errorMessage = ref('')
const mappings = ref<PerformanceBonusMapping[]>([])
const form = reactive({
  snapshotId: '',
  payrollItemCode: 'PERFORMANCE_BONUS',
  period: '',
  amountYuan: '',
  mappingVersion: 'V1',
})

const columns: DataTableColumn[] = [
  { key: 'snapshotLabel', label: '绩效快照', width: '110px' },
  { key: 'employmentId', label: '任职编号', width: '100px' },
  { key: 'period', label: '计薪期间', width: '100px' },
  { key: 'payrollItemCode', label: '工资项', minWidth: '150px' },
  { key: 'amountLabel', label: '奖金金额', width: '120px', align: 'right' },
  { key: 'mappingVersion', label: '版本', width: '80px' },
  { key: 'statusLabel', label: '状态', width: '100px' },
  { key: 'actions', label: '操作', width: '100px', align: 'right' },
]

const rows = computed(() => mappings.value.map((mapping) => ({
  ...mapping,
  snapshotLabel: `#${mapping.performanceSnapshotId}`,
  amountLabel: formatMoney(mapping.amountCents),
  statusLabel: mapping.status === 'APPROVED' ? '已批准' : mapping.status === 'REVOKED' ? '已撤回' : '草稿',
  approvable: mapping.status === 'DRAFT' && canApprove.value,
})))

function close(): void {
  emit('update:modelValue', false)
}

async function load(): Promise<void> {
  if (!canApprove.value) return
  loading.value = true
  errorMessage.value = ''
  try {
    const page = await listPerformanceBonusMappings({}, 1, 100)
    mappings.value = page.items
  } catch (reason) {
    errorMessage.value = errorCopy(reason)
  } finally {
    loading.value = false
  }
}

async function submit(): Promise<void> {
  errorMessage.value = ''
  const snapshotId = Number(form.snapshotId)
  if (!Number.isSafeInteger(snapshotId) || snapshotId < 1) {
    errorMessage.value = '请输入有效的绩效快照编号。'
    return
  }
  if (!/^\d{4}-(0[1-9]|1[0-2])$/.test(form.period.trim())) {
    errorMessage.value = '计薪期间必须使用 YYYY-MM 格式。'
    return
  }
  let amountCents = 0
  try {
    amountCents = parseYuanToCents(form.amountYuan)
  } catch {
    errorMessage.value = '奖金金额必须是最多两位小数的非负金额。'
    return
  }
  if (amountCents < 0) {
    errorMessage.value = '奖金金额不能为负数。'
    return
  }
  if (form.payrollItemCode.trim() === '') {
    errorMessage.value = '请输入工资项编码。'
    return
  }

  saving.value = true
  try {
    await createPerformanceBonusMapping({
      snapshotId,
      payrollItemCode: form.payrollItemCode,
      period: form.period,
      amountCents,
      mappingVersion: form.mappingVersion,
    })
    ElMessage.success('绩效奖金映射草稿已创建。')
    form.snapshotId = ''
    form.period = ''
    form.amountYuan = ''
    await load()
  } catch (reason) {
    errorMessage.value = errorCopy(reason)
  } finally {
    saving.value = false
  }
}

async function approve(mapping: PerformanceBonusMapping): Promise<void> {
  saving.value = true
  errorMessage.value = ''
  try {
    const approved = await approvePerformanceBonusMapping(mapping.id)
    mappings.value = mappings.value.map((row) => row.id === approved.id ? approved : row)
    ElMessage.success('绩效奖金映射已批准。')
  } catch (reason) {
    errorMessage.value = errorCopy(reason)
  } finally {
    saving.value = false
  }
}

onMounted(() => {
  if (props.modelValue) void load()
})

watch(() => props.modelValue, (open) => {
  if (open) void load()
})

watch(canApprove, (allowed) => {
  if (allowed && props.modelValue) void load()
})
</script>

<template>
  <el-dialog
    :model-value="modelValue"
    title="绩效奖金映射"
    width="960px"
    :close-on-click-modal="false"
    data-testid="performance-bonus-mapping-dialog"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <p class="bonus-mapping__note">
      只有已发布绩效快照且状态为 APPROVED 的映射会进入对应计薪期间的工资快照；草稿和跨期映射不会计薪。
    </p>

    <div v-if="canApprove" class="bonus-mapping__form">
      <label>
        <span>绩效快照编号</span>
        <el-input v-model="form.snapshotId" inputmode="numeric" data-testid="bonus-mapping-snapshot" />
      </label>
      <label>
        <span>工资项编码</span>
        <el-input v-model="form.payrollItemCode" maxlength="64" data-testid="bonus-mapping-item" />
      </label>
      <label>
        <span>计薪期间</span>
        <el-input v-model="form.period" maxlength="7" placeholder="YYYY-MM" data-testid="bonus-mapping-period" />
      </label>
      <label>
        <span>奖金金额（元）</span>
        <el-input v-model="form.amountYuan" inputmode="decimal" data-testid="bonus-mapping-amount" />
      </label>
      <label>
        <span>映射版本</span>
        <el-input v-model="form.mappingVersion" maxlength="32" data-testid="bonus-mapping-version" />
      </label>
      <el-button type="primary" :loading="saving" data-testid="bonus-mapping-submit" @click="submit">
        创建草稿
      </el-button>
    </div>
    <p v-else class="bonus-mapping__note">当前账号没有 performance.result.publish 能力，只读显示。</p>

    <p v-if="errorMessage" class="bonus-mapping__error" role="alert" data-testid="bonus-mapping-error">{{ errorMessage }}</p>

    <DataTable
      :columns="columns"
      :rows="rows as unknown as Array<Record<string, unknown>>"
      :total="rows.length"
      :page="1"
      :page-size="Math.max(1, rows.length)"
      row-key="id"
      data-testid="bonus-mapping-table"
      empty-title="尚无绩效奖金映射"
    >
      <template #cell-actions="{ row }">
        <el-button
          v-if="row.approvable"
          type="primary"
          link
          :loading="saving"
          :data-testid="`bonus-mapping-approve-${row.id}`"
          @click="approve(row as PerformanceBonusMapping)"
        >
          批准
        </el-button>
      </template>
    </DataTable>

    <template #footer>
      <el-button @click="close">关闭</el-button>
    </template>
  </el-dialog>
</template>

<style scoped>
.bonus-mapping__note {
  margin: 0 0 12px;
  color: var(--color-muted);
  font-size: 12px;
}

.bonus-mapping__form {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  align-items: flex-end;
  margin-bottom: 14px;
}

.bonus-mapping__form label {
  display: flex;
  flex: 1 1 160px;
  flex-direction: column;
  gap: 5px;
  font-size: 12px;
  font-weight: 600;
}

.bonus-mapping__error {
  color: var(--color-danger);
  font-size: 12px;
}
</style>
