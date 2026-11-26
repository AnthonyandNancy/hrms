<script setup lang="ts">
import { computed, ref } from 'vue'
import { payrollActionLabel, type PayrollAction, type PayrollRunStatus } from '../api'

type ActionRule = {
  action: PayrollAction
  capability: string
  allowed: (status: PayrollRunStatus) => boolean
  reason: (status: PayrollRunStatus) => string
  tone: 'primary' | 'default' | 'danger'
  confirmTitle: string
  confirmBody: (period: string) => string
}

const RULES: ActionRule[] = [
  {
    action: 'lock-inputs',
    capability: 'payroll.run.prepare',
    allowed: (status) => status === 'DRAFT',
    reason: (status) => (status === 'DRAFT' ? '' : '仅草稿批次可以锁定输入。'),
    tone: 'primary',
    confirmTitle: '锁定计薪输入',
    confirmBody: (period) => `锁定 ${period} 之后输入快照不可再修改，试算将使用锁定的数据。确认继续？`,
  },
  {
    action: 'calculate',
    capability: 'payroll.run.prepare',
    allowed: (status) => status === 'INPUT_LOCKED' || status === 'CALCULATED',
    reason: () => '仅输入已锁定的批次可以试算。',
    tone: 'primary',
    confirmTitle: '试算工资',
    confirmBody: (period) => `将为 ${period} 的全部在册员工试算工资，金额写入整数分。确认试算？`,
  },
  {
    action: 'review',
    capability: 'payroll.run.review',
    allowed: (status) => status === 'CALCULATED',
    reason: () => '仅已试算的批次可以提交复核。',
    tone: 'default',
    confirmTitle: '提交复核',
    confirmBody: (period) => `提交 ${period} 批次复核后，复核结果将作为批准依据。确认提交？`,
  },
  {
    action: 'approve',
    capability: 'payroll.run.approve',
    allowed: (status) => status === 'REVIEWED',
    reason: () => '仅已复核的批次可以批准。',
    tone: 'primary',
    confirmTitle: '批准工资批次',
    confirmBody: (period) => `批准 ${period} 后不可回退到草稿；后续修正必须新建更正批次。确认批准？`,
  },
  {
    action: 'publish',
    capability: 'payroll.run.publish',
    allowed: (status) => status === 'APPROVED',
    reason: () => '仅已批准的批次可以发布。',
    tone: 'primary',
    confirmTitle: '发布工资批次',
    confirmBody: (period) => `发布 ${period} 后工资条对员工可见，批次转为只读。确认发布？`,
  },
  {
    action: 'mark-paid',
    capability: 'payroll.run.close',
    allowed: (status) => status === 'PUBLISHED',
    reason: () => '仅已发布的批次可以标记发放。',
    tone: 'default',
    confirmTitle: '标记已发放',
    confirmBody: (period) => `确认 ${period} 的银行付款已完成？此操作仅登记发放结果，不会发起真实付款。`,
  },
  {
    action: 'close',
    capability: 'payroll.run.close',
    allowed: (status) => status === 'PAID',
    reason: () => '仅已发放的批次可以关闭。',
    tone: 'default',
    confirmTitle: '关闭工资批次',
    confirmBody: (period) => `关闭 ${period} 后该批次归档为只读。确认关闭？`,
  },
]

const props = defineProps<{
  status: PayrollRunStatus
  period: string
  capabilities: string[]
  submitting?: PayrollAction | null
  readOnly?: boolean
}>()

const emit = defineEmits<{
  action: [action: PayrollAction]
}>()

type ActionView = {
  action: PayrollAction
  label: string
  disabled: boolean
  reason: string
  tone: ActionRule['tone']
  confirmTitle: string
  confirmBody: string
}

const actionViews = computed<ActionView[]>(() => {
  const isReadOnly = props.readOnly === true
  return RULES.map((rule) => {
    const permitted = props.capabilities.includes(rule.capability)
    const transitionAllowed = rule.allowed(props.status)
    const disabled = isReadOnly || !permitted || !transitionAllowed || props.submitting != null
    let reason = ''
    if (isReadOnly) reason = '已发布批次为只读；导出仍可用，如需更正请新建更正批次。'
    else if (!permitted) reason = `缺少能力 ${rule.capability}，此操作仅对应角色可执行。`
    else if (!transitionAllowed) reason = rule.reason(props.status)
    return {
      action: rule.action,
      label: payrollActionLabel(rule.action),
      disabled,
      reason,
      tone: rule.tone,
      confirmTitle: rule.confirmTitle,
      confirmBody: rule.confirmBody(props.period),
    }
  })
})

const confirmOpen = ref(false)
const pending = ref<ActionView | null>(null)

function requestAction(view: ActionView): void {
  if (view.disabled) return
  pending.value = view
  confirmOpen.value = true
}

function confirm(): void {
  const target = pending.value
  confirmOpen.value = false
  pending.value = null
  if (target !== null) emit('action', target.action)
}

function cancelConfirm(): void {
  confirmOpen.value = false
  pending.value = null
}
</script>

<template>
  <section class="payroll-actions panel" data-testid="payroll-action-bar">
    <div class="panel__header">
      <div>
        <h2 class="panel__title">批次操作</h2>
        <p class="panel__subtitle">
          每个动作独立授权；按钮禁用时会说明原因，服务端仍会再次校验状态与职责分离。
        </p>
      </div>
    </div>

    <div class="payroll-actions__grid">
      <div v-for="view in actionViews" :key="view.action" class="payroll-actions__item">
        <button
          type="button"
          class="payroll-actions__button"
          :class="`payroll-actions__button--${view.tone}`"
          :disabled="view.disabled"
          :aria-describedby="view.reason !== '' ? `payroll-action-reason-${view.action}` : undefined"
          :data-testid="`payroll-action-${view.action}`"
          @click="requestAction(view)"
        >
          {{ view.label }}
        </button>
        <p
          v-if="view.reason !== ''"
          :id="`payroll-action-reason-${view.action}`"
          class="payroll-actions__reason"
          :data-testid="`payroll-action-reason-${view.action}`"
        >
          {{ view.reason }}
        </p>
      </div>
    </div>

    <el-dialog
      v-model="confirmOpen"
      :title="pending?.confirmTitle ?? '确认操作'"
      width="460px"
      :close-on-click-modal="false"
      data-testid="payroll-action-confirm"
    >
      <p class="payroll-actions__confirm-body">{{ pending?.confirmBody }}</p>
      <p class="payroll-actions__confirm-period">批次期间：<strong>{{ period }}</strong></p>
      <template #footer>
        <el-button :disabled="submitting != null" data-testid="payroll-action-cancel" @click="cancelConfirm">
          取消
        </el-button>
        <el-button
          :type="pending?.tone === 'danger' ? 'danger' : 'primary'"
          :loading="submitting != null"
          data-testid="payroll-action-confirm-submit"
          @click="confirm"
        >
          确认执行
        </el-button>
      </template>
    </el-dialog>
  </section>
</template>

<style scoped>
.payroll-actions__grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(168px, 1fr));
  gap: 10px;
  padding: 0 16px 16px;
}

.payroll-actions__item {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.payroll-actions__button {
  padding: 9px 12px;
  font-size: 12.5px;
  font-weight: 600;
  border-radius: 8px;
  border: 1px solid var(--color-line);
  background: #fff;
  cursor: pointer;
}

.payroll-actions__button--primary {
  color: #fff;
  background: #1d4ed8;
  border-color: #1d4ed8;
}

.payroll-actions__button--danger {
  color: #b91c1c;
  background: #fff;
  border-color: #fecaca;
}

.payroll-actions__button:disabled {
  color: #94a3b8;
  background: #f1f5f9;
  border-color: #e2e8f0;
  cursor: not-allowed;
}

.payroll-actions__reason {
  margin: 0;
  color: #64748b;
  font-size: 11px;
  line-height: 1.45;
}

.payroll-actions__confirm-body {
  margin: 0 0 8px;
  font-size: 13px;
  line-height: 1.6;
}

.payroll-actions__confirm-period {
  margin: 0;
  color: #475569;
  font-size: 12px;
}
</style>
