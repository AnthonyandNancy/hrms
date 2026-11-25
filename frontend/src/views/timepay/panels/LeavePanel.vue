<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { resourcePost } from '@/api/resourceClient'
import { usePageError } from '@/composables/usePageError'
import type { TimePayrollPageDefinition } from '../types'
import { formatMinutes, integerValue, newIdempotencyKey, statusTone, textValue, todayIsoDate } from '../panelUtils'

const props = defineProps<{
  definition: TimePayrollPageDefinition
  rows: Record<string, unknown>[]
  related: Record<string, unknown>
  selectedRunId?: number | null
}>()

const emit = defineEmits<{ refresh: []; 'action-success': [message: string] }>()

const actionError = usePageError()
const submitting = ref(false)
const actionMessage = ref('')
const cancellable = computed(() =>
  props.rows.filter((row) => !['WITHDRAWN', 'CANCELLED', 'REJECTED', 'COMPLETED'].includes(textValue(row.state))),
)

const form = reactive({
  leaveTypeCode: 'ANNUAL',
  startDate: todayIsoDate(),
  endDate: todayIsoDate(),
  halfDay: false,
  reason: '',
})

const leaveTypes = computed(() => toRows(props.related.leaveTypes))
const balances = computed(() => {
  if (props.definition.panel === 'leave-balances' || props.definition.panel === 'leave-reports') {
    return props.rows
  }
  return toRows(props.related.balances)
})

function toRows(value: unknown): Record<string, unknown>[] {
  if (Array.isArray(value)) return value.filter(isRecord)
  if (isRecord(value) && Array.isArray(value.items)) return value.items.filter(isRecord)
  return []
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
}

function unitLabel(unit: unknown): string {
  return textValue(unit) === 'HOUR' ? '小时' : '天'
}

async function submitLeave(): Promise<void> {
  actionError.clear()
  actionMessage.value = ''
  if (form.reason.trim().length < 3) {
    actionError.reject(new Error('请假原因至少需要 3 个字符。'))
    return
  }
  submitting.value = true
  try {
    const result = await resourcePost<Record<string, unknown>>(
      '/time/leave/requests',
      {
        leaveTypeCode: form.leaveTypeCode,
        startDate: form.startDate,
        endDate: form.endDate,
        halfDay: form.halfDay,
        reason: form.reason.trim(),
      },
      { idempotencyKey: newIdempotencyKey('leave-request') },
    )
    actionMessage.value = `请假申请已提交：${textValue(result.caseNo, '已受理')}`
    form.reason = ''
    emit('action-success', actionMessage.value)
  } catch (reason) {
    actionError.capture(reason)
  } finally {
    submitting.value = false
  }
}

async function cancelLeave(caseId: unknown): Promise<void> {
  const id = integerValue(caseId)
  if (id === null) return
  actionError.clear()
  actionMessage.value = ''
  submitting.value = true
  try {
    await resourcePost<Record<string, unknown>>(
      `/time/leave/requests/${id}/cancel`,
      { reason: '页面撤回请假申请' },
      { idempotencyKey: newIdempotencyKey('leave-cancel') },
    )
    actionMessage.value = '请假申请已撤回'
    emit('action-success', actionMessage.value)
  } catch (reason) {
    actionError.capture(reason)
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="timepay-panel" :data-testid="definition.testId">
    <section v-if="definition.panel === 'leave-apply'" class="panel timepay-form">
      <h2>新建请假申请</h2>
      <div class="timepay-form__grid">
        <label>假期类型
          <select v-model="form.leaveTypeCode" data-testid="leave-type">
            <option v-for="type in leaveTypes" :key="textValue(type.code)" :value="textValue(type.code)">
              {{ textValue(type.name) }}
            </option>
            <option v-if="leaveTypes.length === 0" value="ANNUAL">年假（以类型目录为准）</option>
          </select>
        </label>
        <label>开始日期
          <input v-model="form.startDate" data-testid="leave-start" type="date" />
        </label>
        <label>结束日期
          <input v-model="form.endDate" data-testid="leave-end" type="date" />
        </label>
        <label class="timepay-check">
          <input v-model="form.halfDay" data-testid="leave-half-day" type="checkbox" />半天
        </label>
      </div>
      <label class="timepay-form__wide">请假原因
        <textarea v-model="form.reason" data-testid="leave-reason" rows="3" maxlength="500" />
      </label>
      <el-button type="primary" :loading="submitting" :disabled="submitting" data-testid="leave-submit" @click="submitLeave">
        提交请假申请
      </el-button>
    </section>

    <section
      v-if="definition.panel === 'leave-balances' || definition.panel === 'leave-reports'"
      class="timepay-cards"
      data-testid="leave-balances"
    >
      <article v-for="balance in balances" :key="textValue(balance.leaveTypeCode)" class="timepay-card">
        <header>
          <h2>{{ textValue(balance.leaveTypeName, textValue(balance.leaveTypeCode)) }}</h2>
          <span class="plain-chip">{{ unitLabel(balance.unit) }}</span>
        </header>
        <p class="timepay-card__value">{{ textValue(balance.available) }}<small>{{ unitLabel(balance.unit) }}可用</small></p>
        <dl>
          <div><dt>发放</dt><dd>{{ textValue(balance.granted) }}</dd></div>
          <div><dt>已用</dt><dd>{{ textValue(balance.used) }}</dd></div>
          <div><dt>待占用</dt><dd>{{ textValue(balance.pending) }}</dd></div>
        </dl>
        <p class="small muted">到期日 {{ textValue(balance.expiresOn) }}</p>
      </article>
    </section>

    <section v-else-if="definition.panel === 'leave-types'" class="panel">
      <h2>假期类型目录</h2>
      <table class="timepay-table">
        <caption class="sr-only">假期类型目录</caption>
        <thead><tr><th scope="col">编码</th><th scope="col">名称</th><th scope="col">单位</th><th scope="col">性质</th><th scope="col">需附件</th><th scope="col">允许负余额</th><th scope="col">版本</th></tr></thead>
        <tbody>
          <tr v-for="row in rows" :key="textValue(row.code)">
            <td class="mono">{{ textValue(row.code) }}</td>
            <td>{{ textValue(row.name) }}</td>
            <td>{{ unitLabel(row.unit) }}</td>
            <td>{{ row.is_statutory === true ? '法定' : '公司' }}{{ row.is_annual === true ? ' / 年假' : '' }}</td>
            <td>{{ row.requires_attachment === true ? '是' : '否' }}</td>
            <td>{{ row.allow_negative === true ? '是' : '否' }}</td>
            <td>{{ textValue(row.version) }}</td>
          </tr>
        </tbody>
      </table>
    </section>

    <section v-else-if="definition.panel === 'leave-policies'" class="panel">
      <h2>假期规则投影</h2>
      <table class="timepay-table">
        <caption class="sr-only">假期规则</caption>
        <thead><tr><th scope="col">假期编码</th><th scope="col">单位</th><th scope="col">到期规则</th><th scope="col">法律依据</th><th scope="col">版本</th></tr></thead>
        <tbody>
          <tr v-for="row in rows" :key="textValue(row.leave_type_code)">
            <td class="mono">{{ textValue(row.leave_type_code) }}</td>
            <td>{{ unitLabel(row.unit) }}</td>
            <td class="small">{{ row.expiry_rule === null ? '—' : '已配置（版本化）' }}</td>
            <td>{{ textValue(row.legal_basis) }}</td>
            <td>{{ textValue(row.version) }}</td>
          </tr>
        </tbody>
      </table>
    </section>

    <section v-else-if="definition.panel === 'leave-calendar'" class="panel">
      <h2>假期日历</h2>
      <table class="timepay-table">
        <caption class="sr-only">假期日历</caption>
        <thead><tr><th scope="col">申请编号</th><th scope="col">标题</th><th scope="col">开始</th><th scope="col">结束</th><th scope="col">时长</th><th scope="col">状态</th></tr></thead>
        <tbody>
          <tr v-for="row in rows" :key="textValue(row.caseId, textValue(row.id))">
            <td class="mono">{{ textValue(row.caseNo) }}</td>
            <td>{{ textValue(row.title) }}</td>
            <td>{{ textValue(row.fromDate) }}</td>
            <td>{{ textValue(row.toDate) }}</td>
            <td>{{ formatMinutes(row.minutes) }}</td>
            <td><span class="timepay-status" :class="`timepay-status--${statusTone(textValue(row.applicationStatus))}`">{{ textValue(row.applicationStatus) }}</span></td>
          </tr>
        </tbody>
      </table>
    </section>

    <section v-else class="panel">
      <h2>{{ definition.panel === 'leave-cancellations' ? '可撤销的请假申请' : '请假台账' }}</h2>
      <table v-if="rows.length > 0" class="timepay-table">
        <caption class="sr-only">请假台账</caption>
        <thead><tr><th scope="col">申请编号</th><th scope="col">标题</th><th scope="col">开始</th><th scope="col">结束</th><th scope="col">时长</th><th scope="col">审批状态</th><th scope="col">生效状态</th><th scope="col">操作</th></tr></thead>
        <tbody>
          <tr
            v-for="row in definition.panel === 'leave-cancellations' ? cancellable : rows"
            :key="textValue(row.caseId, textValue(row.id))"
          >
            <td class="mono">{{ textValue(row.caseNo) }}</td>
            <td>{{ textValue(row.title) }}</td>
            <td>{{ textValue(row.fromDate) }}</td>
            <td>{{ textValue(row.toDate) }}</td>
            <td>{{ formatMinutes(row.minutes) }}</td>
            <td><span class="timepay-status" :class="`timepay-status--${statusTone(textValue(row.applicationStatus))}`">{{ textValue(row.applicationStatus) }}</span></td>
            <td>{{ textValue(row.effectStatus) }}</td>
            <td>
              <el-button
                text
                size="small"
                :loading="submitting"
                :data-testid="`cancel-leave-${textValue(row.caseId, textValue(row.id))}`"
                @click="cancelLeave(row.caseId ?? row.id)"
              >
                撤回
              </el-button>
            </td>
          </tr>
        </tbody>
      </table>
      <p v-else class="small muted">当前筛选条件下没有请假记录。</p>
    </section>

    <p v-if="actionMessage" class="timepay-action-success" role="status">{{ actionMessage }}</p>
    <p v-if="actionError.message.value" class="timepay-action-error" role="alert">
      {{ actionError.message.value }}
      <span v-if="actionError.requestId.value">（请求编号：{{ actionError.requestId.value }}）</span>
    </p>
  </div>
</template>
