<script setup lang="ts">
import { DISPLAY_CODES } from '@/shared/display/dictionary'
import { computed, reactive, ref } from 'vue'
import { ApiError, errorCopy } from '@/api/errors'
import {
  ACCRUAL_MODE_LABEL,
  createLeavePolicy,
  formatLeaveNumber,
  newLeaveIdempotencyKey,
  POLICY_STATUS_LABEL,
  retireLeavePolicy,
  type LeavePolicy,
  type LeavePolicyInput,
  type LeaveTypeOption,
} from '../api'

const props = withDefaults(
  defineProps<{
    policies: LeavePolicy[]
    types: LeaveTypeOption[]
    loading?: boolean
    canManage?: boolean
    error?: string
  }>(),
  { loading: false, canManage: false, error: '' },
)

const emit = defineEmits<{
  refresh: []
  saved: [message: string]
}>()

const showRetired = ref(false)
const drawerOpen = ref(false)
const saving = ref(false)
const formError = ref('')
const retireTarget = ref<LeavePolicy | null>(null)
const retireReason = ref('')
const retiring = ref(false)

const form = reactive({
  leaveTypeId: 0,
  effectiveFrom: '',
  effectiveTo: '9999-12-31',
  accrualMode: 'ANNUAL_FIXED',
  entitlementDays: 0,
  minNoticeDays: 0,
  maxConsecutiveDays: '' as string,
  attachmentOverDays: '' as string,
  allowHalfDay: true,
  legalBasis: '',
})

const STATUS_ACTIVE = DISPLAY_CODES.userStatus.active
const activeTypes = computed(() => props.types.filter((type) => type.status === STATUS_ACTIVE))

const STATUS_PUBLISHED = 'PUBLISHED'

const visiblePolicies = computed(() =>
  props.policies.filter((policy) => showRetired.value || policy.status === STATUS_PUBLISHED),
)

function entitlementText(policy: LeavePolicy): string {
  if (policy.entitlementMinutes === 0) return '不设固定额度'
  const dayMinutes = 480
  return `${formatLeaveNumber(policy.entitlementMinutes / dayMinutes)} 天`
}

function accrualText(policy: LeavePolicy): string {
  return ACCRUAL_MODE_LABEL[policy.accrualMode] ?? '按年固定额度'
}

function policyStatusText(policy: LeavePolicy): string {
  return POLICY_STATUS_LABEL[policy.status] ?? '已发布'
}

function openCreate(): void {
  if (!props.canManage) return
  form.leaveTypeId = activeTypes.value[0]?.id ?? 0
  form.effectiveFrom = ''
  form.effectiveTo = '9999-12-31'
  form.accrualMode = 'ANNUAL_FIXED'
  form.entitlementDays = 0
  form.minNoticeDays = 0
  form.maxConsecutiveDays = ''
  form.attachmentOverDays = ''
  form.allowHalfDay = true
  form.legalBasis = ''
  formError.value = ''
  drawerOpen.value = true
}

function openCopy(policy: LeavePolicy): void {
  if (!props.canManage) return
  form.leaveTypeId = policy.leaveTypeId
  form.effectiveFrom = ''
  form.effectiveTo = '9999-12-31'
  form.accrualMode = policy.accrualMode
  form.entitlementDays = policy.unit === 'HOUR'
    ? policy.entitlementMinutes / 480
    : policy.entitlementMinutes / 480
  form.minNoticeDays = policy.minNoticeDays
  form.maxConsecutiveDays = policy.maxConsecutiveDays !== null ? String(policy.maxConsecutiveDays) : ''
  form.attachmentOverDays = policy.requiresAttachmentOverDays !== null
    ? String(policy.requiresAttachmentOverDays)
    : ''
  form.allowHalfDay = policy.allowHalfDay
  form.legalBasis = policy.legalBasis ?? ''
  formError.value = ''
  drawerOpen.value = true
}

function validate(): boolean {
  if (form.leaveTypeId <= 0) {
    formError.value = '请选择关联假期类型。'
    return false
  }
  if (!/^\d{4}-\d{2}-\d{2}$/.test(form.effectiveFrom) || !/^\d{4}-\d{2}-\d{2}$/.test(form.effectiveTo)) {
    formError.value = '请填写生效日与失效日。'
    return false
  }
  if (form.effectiveTo < form.effectiveFrom) {
    formError.value = '失效日不能早于生效日。'
    return false
  }
  if (!Number.isFinite(form.entitlementDays) || form.entitlementDays < 0) {
    formError.value = '额度必须为非负数。'
    return false
  }
  return true
}

async function save(): Promise<void> {
  if (saving.value) return
  formError.value = ''
  if (!validate()) return

  const input: LeavePolicyInput = {
    leave_type_id: form.leaveTypeId,
    effective_from: form.effectiveFrom,
    effective_to: form.effectiveTo,
    accrual: {
      mode: form.accrualMode,
      minutes_per_year: form.accrualMode === 'NONE'
        ? 0
        : Math.round(form.entitlementDays * 480),
    },
    carryover: null,
    min_notice_days: form.minNoticeDays,
    max_consecutive_days: form.maxConsecutiveDays === '' ? null : Number(form.maxConsecutiveDays),
    requires_attachment_over_days: form.attachmentOverDays === '' ? null : Number(form.attachmentOverDays),
    allow_half_day: form.allowHalfDay,
    legal_basis: form.legalBasis.trim() === '' ? null : form.legalBasis.trim(),
    applies_to: { scope: 'COMPANY' },
  }

  saving.value = true
  try {
    await createLeavePolicy(input, newLeaveIdempotencyKey('leave-policy-create'))
    drawerOpen.value = false
    emit('refresh')
    emit('saved', '假期规则新版本已发布。')
  } catch (reason) {
    formError.value = reason instanceof ApiError && reason.serverReason === 'LEAVE_POLICY_RANGE_OVERLAP'
      ? '生效区间与已发布规则重叠，请先停用未生效版本或调整日期。'
      : errorCopy(reason)
  } finally {
    saving.value = false
  }
}

function openRetire(policy: LeavePolicy): void {
  if (!props.canManage) return
  retireTarget.value = policy
  retireReason.value = ''
}

async function confirmRetire(): Promise<void> {
  const target = retireTarget.value
  if (target === null || retiring.value) return
  if (retireReason.value.trim().length < 3) {
    formError.value = '请填写至少 3 个字的停用原因。'
    return
  }

  retiring.value = true
  formError.value = ''
  try {
    await retireLeavePolicy(
      target.id,
      retireReason.value.trim(),
      newLeaveIdempotencyKey('leave-policy-retire'),
    )
    retireTarget.value = null
    retireReason.value = ''
    emit('refresh')
    emit('saved', '假期规则版本已停用，历史版本保留。')
  } catch (reason) {
    formError.value = reason instanceof ApiError && reason.serverReason === 'LEAVE_POLICY_ALREADY_EFFECTIVE'
      ? '该规则已生效，不能直接停用；请新增后续生效版本以覆盖。'
      : errorCopy(reason)
  } finally {
    retiring.value = false
  }
}
</script>

<template>
  <section class="leave-policies" aria-label="假期规则配置">
    <header class="leave-policies__header">
      <div>
        <h2 class="leave-policies__title">假期规则</h2>
        <p class="leave-policies__subtitle">
          规则按生效日追加版本，已发布历史不可原地修改。额度、提前申请天数、连续上限和附件要求均从当前生效版本读取。
        </p>
      </div>
      <div class="leave-policies__actions">
        <el-button data-testid="leave-policy-toggle-retired" text @click="showRetired = !showRetired">
          {{ showRetired ? '只看已发布' : '显示已停用版本' }}
        </el-button>
        <el-button
          v-if="props.canManage"
          type="primary"
          data-testid="leave-policy-create"
          @click="openCreate"
        >
          新建规则版本
        </el-button>
      </div>
    </header>

    <el-alert v-if="props.error" type="error" :closable="false" show-icon :title="props.error" />
    <el-alert v-if="formError" type="error" :closable="false" show-icon :title="formError" />

    <div v-if="props.loading" class="leave-policies__state">正在加载假期规则…</div>
    <p v-else-if="visiblePolicies.length === 0" class="leave-policies__state" data-testid="leave-policies-empty">
      尚未配置假期规则。无规则的假期类型不能用于申请。
      {{ props.canManage ? '可点击“新建规则版本”开始配置。' : '请联系人力资源配置。' }}
    </p>

    <el-table v-else :data="visiblePolicies" row-key="id" data-testid="leave-policies-table">
      <el-table-column label="关联假别" min-width="120">
        <template #default="{ row }">{{ row.leaveTypeName || '未命名假别' }}</template>
      </el-table-column>
      <el-table-column label="版本" width="80">
        <template #default="{ row }">v{{ row.version }}</template>
      </el-table-column>
      <el-table-column label="额度" width="140">
        <template #default="{ row }">{{ entitlementText(row) }}</template>
      </el-table-column>
      <el-table-column label="发放周期" width="130">
        <template #default="{ row }">{{ accrualText(row) }}</template>
      </el-table-column>
      <el-table-column label="提前申请" width="100">
        <template #default="{ row }">{{ row.minNoticeDays }} 天</template>
      </el-table-column>
      <el-table-column label="连续上限" width="110">
        <template #default="{ row }">{{ row.maxConsecutiveDays === null ? '不限' : `${row.maxConsecutiveDays} 天` }}</template>
      </el-table-column>
      <el-table-column label="生效日" width="120" prop="effectiveFrom" />
      <el-table-column label="失效日" width="120">
        <template #default="{ row }">{{ row.effectiveTo === '9999-12-31' ? '长期' : row.effectiveTo }}</template>
      </el-table-column>
      <el-table-column label="状态" width="100">
        <template #default="{ row }">
          <el-tag :type="row.status === STATUS_PUBLISHED ? 'success' : 'info'">{{ policyStatusText(row) }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="创建时间" min-width="160">
        <template #default="{ row }">{{ row.publishedAt.replace('T', ' ').replace('.000000Z', '') || '—' }}</template>
      </el-table-column>
      <el-table-column v-if="props.canManage" label="操作" min-width="180" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" :data-testid="`leave-policy-copy-${row.id}`" @click="openCopy(row)">
            复制为新版本
          </el-button>
          <el-button
            v-if="row.status === STATUS_PUBLISHED"
            link
            type="danger"
            :data-testid="`leave-policy-retire-${row.id}`"
            @click="openRetire(row)"
          >
            停用
          </el-button>
        </template>
      </el-table-column>
    </el-table>

    <el-drawer v-model="drawerOpen" :title="'新建假期规则版本'" size="520px" data-testid="leave-policy-drawer">
      <el-form label-position="top">
        <el-form-item label="关联假期类型" required>
          <el-select v-model="form.leaveTypeId" data-testid="leave-policy-type" placeholder="请选择假期类型">
            <el-option v-for="type in activeTypes" :key="type.id" :label="type.name" :value="type.id" />
          </el-select>
        </el-form-item>
        <div class="leave-policies__row">
          <el-form-item label="生效日" required>
            <el-date-picker
              v-model="form.effectiveFrom"
              type="date"
              value-format="YYYY-MM-DD"
              data-testid="leave-policy-effective-from"
            />
          </el-form-item>
          <el-form-item label="失效日" required>
            <el-date-picker
              v-model="form.effectiveTo"
              type="date"
              value-format="YYYY-MM-DD"
              data-testid="leave-policy-effective-to"
            />
          </el-form-item>
        </div>
        <el-form-item label="发放周期">
          <el-select v-model="form.accrualMode" data-testid="leave-policy-accrual-mode">
            <el-option v-for="(label, mode) in ACCRUAL_MODE_LABEL" :key="mode" :label="label" :value="mode" />
          </el-select>
        </el-form-item>
        <el-form-item label="年度额度（天）" required>
          <el-input-number
            v-model="form.entitlementDays"
            :min="0"
            :step="0.5"
            :precision="2"
            :disabled="form.accrualMode === 'NONE'"
            data-testid="leave-policy-entitlement"
          />
          <p class="leave-policies__hint">以天录入，保存为整数分钟；服务端按公司标准工作日折算。</p>
        </el-form-item>
        <div class="leave-policies__row">
          <el-form-item label="提前申请天数">
            <el-input-number v-model="form.minNoticeDays" :min="0" :max="365" data-testid="leave-policy-notice" />
          </el-form-item>
          <el-form-item label="连续请假上限（天）">
            <el-input-number
              v-model="form.maxConsecutiveDays"
              :min="1"
              :max="366"
              :value-on-clear="''"
              data-testid="leave-policy-max-consecutive"
            />
          </el-form-item>
        </div>
        <el-form-item label="超过多少天需附件">
          <el-input-number
            v-model="form.attachmentOverDays"
            :min="1"
            :max="366"
            :value-on-clear="''"
            data-testid="leave-policy-attachment-over"
          />
        </el-form-item>
        <el-form-item>
          <el-checkbox v-model="form.allowHalfDay">允许半天请假</el-checkbox>
        </el-form-item>
        <el-form-item label="备注 / 法律依据">
          <el-input v-model="form.legalBasis" type="textarea" :rows="3" maxlength="255" />
        </el-form-item>
        <el-alert v-if="formError" type="error" :closable="false" show-icon :title="formError" />
      </el-form>
      <template #footer>
        <el-button :disabled="saving" @click="drawerOpen = false">取消</el-button>
        <el-button type="primary" :loading="saving" data-testid="leave-policy-save" @click="save">发布版本</el-button>
      </template>
    </el-drawer>

    <el-dialog v-model="retireTarget" title="停用假期规则版本" width="480px">
      <p>停用只适用于尚未生效的版本；已生效规则需新增后续版本覆盖。</p>
      <el-input
        v-model="retireReason"
        type="textarea"
        :rows="3"
        maxlength="500"
        placeholder="请填写停用原因（至少 3 个字）"
        data-testid="leave-policy-retire-reason"
      />
      <template #footer>
        <el-button :disabled="retiring" @click="retireTarget = null">取消</el-button>
        <el-button type="danger" :loading="retiring" data-testid="leave-policy-retire-confirm" @click="confirmRetire">
          确认停用
        </el-button>
      </template>
    </el-dialog>
  </section>
</template>

<style scoped>
.leave-policies {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 16px;
  background: #fff;
  border: 1px solid var(--color-line);
  border-radius: var(--radius-md);
}

.leave-policies__header {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}

.leave-policies__title {
  margin: 0;
  font-size: 15px;
  font-weight: 680;
}

.leave-policies__subtitle {
  max-width: 760px;
  margin: 4px 0 0;
  color: var(--color-ink-muted);
  font-size: 11.5px;
  line-height: 1.55;
}

.leave-policies__actions {
  display: flex;
  gap: 6px;
}

.leave-policies__state {
  margin: 0;
  padding: 24px 16px;
  color: var(--color-ink-muted);
  font-size: 12px;
  text-align: center;
  background: var(--color-canvas);
  border: 1px dashed var(--color-line-strong);
  border-radius: 6px;
}

.leave-policies__row {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}

.leave-policies__hint {
  margin: 4px 0 0;
  color: var(--color-ink-muted);
  font-size: 10.5px;
}
</style>
