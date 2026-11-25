<script setup lang="ts">
import { reactive, ref } from 'vue'
import { resourcePost } from '@/api/resourceClient'
import { usePageError } from '@/composables/usePageError'
import type { TimePayrollPageDefinition } from '../types'
import { integerValue, newIdempotencyKey, statusTone, textValue, todayIsoDate } from '../panelUtils'

defineProps<{
  definition: TimePayrollPageDefinition
  rows: Record<string, unknown>[]
  related?: Record<string, unknown>
  selectedRunId?: number | null
}>()

const emit = defineEmits<{ refresh: []; 'action-success': [message: string] }>()

const actionError = usePageError()
const submitting = ref(false)
const actionMessage = ref('')

const form = reactive({
  from_date: todayIsoDate(),
  to_date: todayIsoDate(),
  destination: '',
  purpose: '',
})

async function createTravel(): Promise<void> {
  actionError.clear()
  actionMessage.value = ''
  if (form.destination.trim() === '' || form.purpose.trim().length < 3) {
    actionError.reject(new Error('请填写出差目的地与至少 3 个字符的出差事由。'))
    return
  }
  submitting.value = true
  try {
    await resourcePost<Record<string, unknown>>(
      '/travel/requests',
      {
        from_date: form.from_date,
        to_date: form.to_date,
        destination: form.destination.trim(),
        purpose: form.purpose.trim(),
      },
      { idempotencyKey: newIdempotencyKey('travel-create') },
    )
    actionMessage.value = '出差申请已提交'
    form.destination = ''
    form.purpose = ''
    emit('action-success', actionMessage.value)
  } catch (reason) {
    actionError.capture(reason)
  } finally {
    submitting.value = false
  }
}

async function cancelTravel(travelId: unknown): Promise<void> {
  const id = integerValue(travelId)
  if (id === null) return
  actionError.clear()
  actionMessage.value = ''
  submitting.value = true
  try {
    await resourcePost<Record<string, unknown>>(
      `/travel/requests/${id}/cancel`,
      { reason: '页面取消出差申请' },
      { idempotencyKey: newIdempotencyKey('travel-cancel') },
    )
    actionMessage.value = '出差申请已取消'
    emit('action-success', actionMessage.value)
  } catch (reason) {
    actionError.capture(reason)
  } finally {
    submitting.value = false
  }
}

function cancellable(status: unknown): boolean {
  return !['CANCELLED', 'COMPLETED'].includes(textValue(status))
}
</script>

<template>
  <div class="timepay-panel" :data-testid="definition.testId">
    <section v-if="definition.panel === 'travel-apply'" class="panel timepay-form">
      <h2>新建出差申请</h2>
      <div class="timepay-form__grid">
        <label>开始日期
          <input v-model="form.from_date" data-testid="travel-from-date" type="date" />
        </label>
        <label>结束日期
          <input v-model="form.to_date" data-testid="travel-to-date" type="date" />
        </label>
        <label>目的地
          <input v-model="form.destination" data-testid="travel-destination" maxlength="160" />
        </label>
      </div>
      <label class="timepay-form__wide">出差事由
        <textarea v-model="form.purpose" data-testid="travel-purpose" rows="3" maxlength="500" />
      </label>
      <el-button type="primary" :loading="submitting" :disabled="submitting" data-testid="travel-create-submit" @click="createTravel">
        提交出差申请
      </el-button>
    </section>

    <section v-else-if="definition.panel === 'travel-reports'" class="panel">
      <h2>出差状态汇总</h2>
      <div class="timepay-metrics">
        <div><span>申请总数</span><strong>{{ rows.length }}</strong></div>
        <div><span>审批中</span><strong>{{ rows.filter((row) => textValue(row.status) === 'PENDING_APPROVAL').length }}</strong></div>
        <div><span>已批准</span><strong>{{ rows.filter((row) => textValue(row.status) === 'APPROVED').length }}</strong></div>
        <div><span>已取消</span><strong>{{ rows.filter((row) => textValue(row.status) === 'CANCELLED').length }}</strong></div>
      </div>
      <p class="small muted">统计仅按服务端返回的真实申请状态计数，不推算天数或费用。</p>
    </section>

    <section v-else class="panel">
      <h2>出差申请台账</h2>
      <table class="timepay-table">
        <caption class="sr-only">出差申请台账</caption>
        <thead><tr><th scope="col">单号</th><th scope="col">目的地</th><th scope="col">开始</th><th scope="col">结束</th><th scope="col">事由</th><th scope="col">状态</th><th scope="col">操作</th></tr></thead>
        <tbody>
          <tr v-for="row in rows" :key="textValue(row.travel_id)">
            <td class="mono">#{{ textValue(row.travel_id) }}</td>
            <td>{{ textValue(row.destination) }}</td>
            <td>{{ textValue(row.from_date) }}</td>
            <td>{{ textValue(row.to_date) }}</td>
            <td>{{ textValue(row.purpose) }}</td>
            <td><span class="timepay-status" :class="`timepay-status--${statusTone(textValue(row.status))}`">{{ textValue(row.status) }}</span></td>
            <td>
              <el-button
                v-if="cancellable(row.status)"
                text
                size="small"
                :loading="submitting"
                :data-testid="`cancel-travel-${textValue(row.travel_id)}`"
                @click="cancelTravel(row.travel_id)"
              >
                取消
              </el-button>
              <span v-else class="small muted">不可取消</span>
            </td>
          </tr>
        </tbody>
      </table>
      <p class="small muted">变更与销单通过取消接口完成，并保留服务端审计记录；审批决策接口待补。</p>
    </section>

    <p v-if="actionMessage" class="timepay-action-success" role="status">{{ actionMessage }}</p>
    <p v-if="actionError.message.value" class="timepay-action-error" role="alert">
      {{ actionError.message.value }}
      <span v-if="actionError.requestId.value">（请求编号：{{ actionError.requestId.value }}）</span>
    </p>
  </div>
</template>
