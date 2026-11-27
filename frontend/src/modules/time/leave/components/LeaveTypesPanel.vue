<script setup lang="ts">
import { DISPLAY_CODES } from '@/shared/display/dictionary'
import { computed, reactive, ref } from 'vue'
import { ApiError, errorCopy } from '@/api/errors'
import {
  createLeaveType,
  formatLeaveNumber,
  newLeaveIdempotencyKey,
  updateLeaveType,
  type LeaveTypeInput,
  type LeaveTypeOption,
} from '../api'

const props = withDefaults(
  defineProps<{
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

const showInactive = ref(false)
const editorOpen = ref(false)
const editing = ref<LeaveTypeOption | null>(null)
const saving = ref(false)
const formError = ref('')
const success = ref('')

const form = reactive({
  code: '',
  name: '',
  unit: 'DAY',
  is_statutory: false,
  is_annual: false,
  requires_attachment: false,
  allow_negative: false,
})

const statusDraft = ref<Record<number, string>>({})

const STATUS_ACTIVE = DISPLAY_CODES.userStatus.active
const STATUS_INACTIVE = 'DISABLED'

const visible = computed(() =>
  props.types.filter((type) => showInactive.value || type.status === STATUS_ACTIVE),
)

function statusLabel(status: string): string {
  if (status === STATUS_INACTIVE) return '已停用'
  return '启用中'
}

function entitlementText(type: LeaveTypeOption): string {
  if (type.entitlementMinutes === null) return '—'
  const dayMinutes = 480
  return formatLeaveNumber(type.entitlementMinutes / dayMinutes) + ' 天'
}

function openCreate(): void {
  if (!props.canManage) return
  editing.value = null
  form.code = ''
  form.name = ''
  form.unit = 'DAY'
  form.is_statutory = false
  form.is_annual = false
  form.requires_attachment = false
  form.allow_negative = false
  formError.value = ''
  editorOpen.value = true
}

function openEdit(type: LeaveTypeOption): void {
  if (!props.canManage) return
  editing.value = type
  form.code = type.code
  form.name = type.name
  form.unit = type.unit
  form.is_statutory = type.isStatutory
  form.is_annual = type.isAnnual
  form.requires_attachment = type.requiresAttachment
  form.allow_negative = type.allowNegative
  formError.value = ''
  editorOpen.value = true
}

async function save(): Promise<void> {
  if (saving.value) return
  formError.value = ''

  if (form.name.trim() === '') {
    formError.value = '请填写假期类型名称。'
    return
  }
  if (editing.value === null && !/^[A-Za-z][A-Za-z0-9_]{1,31}$/.test(form.code.trim())) {
    formError.value = '编码须以字母开头，只包含字母、数字与下划线，长度 2 到 32 位。'
    return
  }

  saving.value = true
  try {
    if (editing.value === null) {
      const input: LeaveTypeInput = {
        code: form.code.trim().toUpperCase(),
        name: form.name.trim(),
        unit: form.unit,
        is_statutory: form.is_statutory,
        is_annual: form.is_annual,
        requires_attachment: form.requires_attachment,
        allow_negative: form.allow_negative,
      }
      await createLeaveType(input, newLeaveIdempotencyKey('leave-type-create'))
      success.value = '假期类型已创建。'
    } else {
      await updateLeaveType(
        editing.value.id,
        {
          name: form.name.trim(),
          is_statutory: form.is_statutory,
          is_annual: form.is_annual,
          requires_attachment: form.requires_attachment,
          allow_negative: form.allow_negative,
        },
        newLeaveIdempotencyKey('leave-type-update'),
      )
      success.value = '假期类型已更新；编码与计量单位保持不变。'
    }
    editorOpen.value = false
    emit('refresh')
    emit('saved', success.value)
  } catch (reason) {
    formError.value = reason instanceof ApiError
      ? `${errorCopy(reason)}${reason.serverReason === 'LEAVE_TYPE_CODE_CONFLICT' ? ' 编码已存在，请更换。' : ''}`
      : errorCopy(reason)
  } finally {
    saving.value = false
  }
}

async function toggleStatus(type: LeaveTypeOption): Promise<void> {
  if (!props.canManage || saving.value) return
  const next = type.status === STATUS_ACTIVE ? STATUS_INACTIVE : STATUS_ACTIVE
  statusDraft.value = { ...statusDraft.value, [type.id]: next }
  saving.value = true
  formError.value = ''
  try {
    await updateLeaveType(
      type.id,
      { status: next },
      newLeaveIdempotencyKey('leave-type-status'),
    )
    success.value = next === STATUS_ACTIVE ? '假期类型已启用。' : '假期类型已停用。'
    emit('refresh')
    emit('saved', success.value)
  } catch (reason) {
    formError.value = reason instanceof ApiError && reason.serverReason === 'LEAVE_TYPE_IN_USE'
      ? '该假期类型已有规则、账户或流水，不能停用。'
      : errorCopy(reason)
  } finally {
    saving.value = false
    const copy = { ...statusDraft.value }
    delete copy[type.id]
    statusDraft.value = copy
  }
}
</script>

<template>
  <section class="leave-types" aria-label="假期类型配置">
    <header class="leave-types__header">
      <div>
        <h2 class="leave-types__title">假期类型</h2>
        <p class="leave-types__subtitle">
          类型只维护身份与展示信息。额度、生效区间和申请约束在“假期规则”中按版本维护，编码与单位创建后不可修改。
        </p>
      </div>
      <div class="leave-types__actions">
        <el-button data-testid="leave-type-toggle-inactive" text @click="showInactive = !showInactive">
          {{ showInactive ? '只看启用类型' : '查看已停用类型' }}
        </el-button>
        <el-button
          v-if="props.canManage"
          type="primary"
          data-testid="leave-type-create"
          @click="openCreate"
        >
          新增假期类型
        </el-button>
      </div>
    </header>

    <el-alert v-if="props.error" type="error" :closable="false" show-icon :title="props.error" />
    <el-alert v-if="formError" type="error" :closable="false" show-icon :title="formError" />

    <div v-if="props.loading" class="leave-types__state">正在加载假期类型…</div>
    <p v-else-if="visible.length === 0" class="leave-types__state" data-testid="leave-types-empty">
      尚未配置假期类型。{{ props.canManage ? '可点击“新增假期类型”开始配置。' : '请联系人力资源配置。' }}
    </p>

    <el-table v-else :data="visible" row-key="id" data-testid="leave-types-table">
      <el-table-column label="编码" min-width="120">
        <template #default="{ row }"><span class="mono">{{ row.code }}</span></template>
      </el-table-column>
      <el-table-column label="名称" prop="name" min-width="140" />
      <el-table-column label="计量单位" width="100">
        <template #default="{ row }">{{ row.unit === 'HOUR' ? '小时' : '天' }}</template>
      </el-table-column>
      <el-table-column label="法定假期" width="100">
        <template #default="{ row }">{{ row.isStatutory ? '是' : '否' }}</template>
      </el-table-column>
      <el-table-column label="年假" width="80">
        <template #default="{ row }">{{ row.isAnnual ? '是' : '否' }}</template>
      </el-table-column>
      <el-table-column label="附件" width="100">
        <template #default="{ row }">{{ row.requiresAttachment ? '需要' : '不需要' }}</template>
      </el-table-column>
      <el-table-column label="额度参考" width="120">
        <template #default="{ row }">{{ entitlementText(row) }}</template>
      </el-table-column>
      <el-table-column label="规则版本" width="100">
        <template #default="{ row }">{{ row.policyVersion !== null ? `v${row.policyVersion}` : '未配置' }}</template>
      </el-table-column>
      <el-table-column label="状态" width="100">
        <template #default="{ row }">
          <el-tag :type="row.status === STATUS_ACTIVE ? 'success' : 'info'">{{ statusLabel(row.status) }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column v-if="props.canManage" label="操作" min-width="180" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" :data-testid="`leave-type-edit-${row.id}`" @click="openEdit(row)">
            编辑
          </el-button>
          <el-button
            link
            :type="row.status === STATUS_ACTIVE ? 'danger' : 'success'"
            :loading="saving && statusDraft[row.id] !== undefined"
            :data-testid="`leave-type-status-${row.id}`"
            @click="toggleStatus(row)"
          >
            {{ row.status === STATUS_ACTIVE ? '停用' : '启用' }}
          </el-button>
        </template>
      </el-table-column>
    </el-table>

    <el-dialog
      v-model="editorOpen"
      :title="editing === null ? '新增假期类型' : '编辑假期类型'"
      width="560px"
      data-testid="leave-type-dialog"
    >
      <el-form label-position="top" @submit.prevent="save">
        <el-form-item label="假期编码" required>
          <el-input
            v-model="form.code"
            :disabled="editing !== null"
            maxlength="32"
            data-testid="leave-type-code"
            placeholder="例如 ANNUAL"
          />
          <p class="leave-types__hint">创建后不可修改，用于接口与流水稳定引用。</p>
        </el-form-item>
        <el-form-item label="中文名称" required>
          <el-input v-model="form.name" maxlength="128" data-testid="leave-type-name" />
        </el-form-item>
        <el-form-item label="计量单位">
          <el-select v-model="form.unit" :disabled="editing !== null" data-testid="leave-type-unit">
            <el-option label="天" value="DAY" />
            <el-option label="小时" value="HOUR" />
          </el-select>
          <p class="leave-types__hint">单位影响余额与流水展示，创建后不可修改。</p>
        </el-form-item>
        <div class="leave-types__checks">
          <el-checkbox v-model="form.is_statutory">法定假期</el-checkbox>
          <el-checkbox v-model="form.is_annual">年假</el-checkbox>
          <el-checkbox v-model="form.requires_attachment">需要附件</el-checkbox>
          <el-checkbox v-model="form.allow_negative">允许负余额</el-checkbox>
        </div>
        <el-alert v-if="formError" type="error" :closable="false" show-icon :title="formError" />
      </el-form>
      <template #footer>
        <el-button :disabled="saving" @click="editorOpen = false">取消</el-button>
        <el-button type="primary" :loading="saving" data-testid="leave-type-save" @click="save">保存</el-button>
      </template>
    </el-dialog>
  </section>
</template>

<style scoped>
.leave-types {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 16px;
  background: #fff;
  border: 1px solid var(--color-line);
  border-radius: var(--radius-md);
}

.leave-types__header {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}

.leave-types__title {
  margin: 0;
  font-size: 15px;
  font-weight: 680;
}

.leave-types__subtitle {
  max-width: 760px;
  margin: 4px 0 0;
  color: var(--color-ink-muted);
  font-size: 11.5px;
  line-height: 1.55;
}

.leave-types__actions {
  display: flex;
  gap: 6px;
}

.leave-types__state {
  margin: 0;
  padding: 24px 16px;
  color: var(--color-ink-muted);
  font-size: 12px;
  text-align: center;
  background: var(--color-canvas);
  border: 1px dashed var(--color-line-strong);
  border-radius: 6px;
}

.leave-types__hint {
  margin: 4px 0 0;
  color: var(--color-ink-muted);
  font-size: 10.5px;
  line-height: 1.45;
}

.leave-types__checks {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 16px;
  margin-bottom: 12px;
}
</style>
