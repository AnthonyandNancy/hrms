<script setup lang="ts">
import { computed, ref } from 'vue'
import { resourcePost } from '@/api/resourceClient'
import { usePageError } from '@/composables/usePageError'
import type { TimePayrollPageDefinition } from '../types'
import { formatMinutes, integerValue, newIdempotencyKey, statusTone, textValue } from '../panelUtils'

const props = defineProps<{
  definition: TimePayrollPageDefinition
  rows: Record<string, unknown>[]
  related?: Record<string, unknown>
  selectedRunId?: number | null
}>()

const emit = defineEmits<{ refresh: []; 'action-success': [message: string] }>()

const actionError = usePageError()
const submitting = ref(false)
const actionMessage = ref('')

const settledRows = computed(() =>
  props.rows.filter((row) => textValue(row.settlement_method, '') !== ''),
)
const pendingRows = computed(() =>
  props.rows.filter((row) => textValue(row.settlement_method, '') === ''),
)

function minutesOf(row: Record<string, unknown>): number {
  const value = integerValue(row.minutes)
  return value ?? 0
}

async function settle(row: Record<string, unknown>, method: 'PAY' | 'TIMEOFF'): Promise<void> {
  const id = integerValue(row.id)
  if (id === null) return
  actionError.clear()
  actionMessage.value = ''
  submitting.value = true
  try {
    await resourcePost<Record<string, unknown>>(
      `/overtime/records/${id}/settlements`,
      { method, reason: '页面结算操作' },
      { idempotencyKey: newIdempotencyKey(`overtime-settlement-${method}`) },
    )
    actionMessage.value = method === 'PAY' ? '加班结算已完成：工资补偿' : '加班结算已完成：调休补偿'
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
    <section v-if="definition.panel === 'overtime-policies'" class="panel">
      <h2>当前加班规则</h2>
      <dl class="timepay-facts">
        <div><dt>加班倍率</dt><dd>{{ textValue(rows[0]?.multiplier) }}</dd></div>
        <div><dt>法定节假日倍率</dt><dd>{{ textValue(rows[0]?.statutory_holiday_multiplier) }}</dd></div>
        <div><dt>允许工资补偿</dt><dd>{{ rows[0]?.allow_pay === true ? '是' : '否' }}</dd></div>
        <div><dt>允许调休</dt><dd>{{ rows[0]?.allow_timeoff === true ? '是' : '否' }}</dd></div>
        <div><dt>规则来源</dt><dd>{{ textValue(rows[0]?.source) }}</dd></div>
      </dl>
      <p class="small muted">{{ textValue(rows[0]?.note) }}</p>
    </section>

    <section v-else-if="definition.panel === 'overtime-timeoff'" class="panel">
      <h2>调休结算记录</h2>
      <table v-if="settledRows.length > 0" class="timepay-table">
        <caption class="sr-only">调休结算记录</caption>
        <thead><tr><th scope="col">加班日期</th><th scope="col">工时</th><th scope="col">结算方式</th><th scope="col">状态</th></tr></thead>
        <tbody>
          <tr v-for="row in settledRows" :key="textValue(row.id)">
            <td>{{ textValue(row.business_date) }}</td>
            <td>{{ formatMinutes(row.minutes) }}</td>
            <td>{{ textValue(row.settlement_method) }}</td>
            <td>{{ textValue(row.status) }}</td>
          </tr>
        </tbody>
      </table>
      <p v-else class="small muted">暂无已结算记录；调休余额专用投影接口待补，不在前端自行累计余额。</p>
    </section>

    <section v-else-if="definition.panel === 'overtime-settlement'" class="panel">
      <h2>可结算加班记录</h2>
      <table class="timepay-table">
        <caption class="sr-only">可结算加班记录</caption>
        <thead><tr><th scope="col">加班日期</th><th scope="col">工时</th><th scope="col">审批案例</th><th scope="col">当前结算</th><th scope="col">操作</th></tr></thead>
        <tbody>
          <tr v-for="row in rows" :key="textValue(row.id)">
            <td>{{ textValue(row.business_date) }}</td>
            <td>{{ formatMinutes(row.minutes) }}</td>
            <td class="mono">{{ row.case_id === null ? '—' : `#${textValue(row.case_id)}` }}</td>
            <td>
              <span v-if="textValue(row.settlement_method, '') !== ''">{{ textValue(row.settlement_method) }}</span>
              <span v-else>未结算</span>
            </td>
            <td>
              <template v-if="textValue(row.settlement_method, '') === ''">
                <el-button
                  text
                  size="small"
                  :loading="submitting"
                  :data-testid="`settle-PAY-${textValue(row.id)}`"
                  @click="settle(row, 'PAY')"
                >
                  工资结算
                </el-button>
                <el-button
                  text
                  size="small"
                  :loading="submitting"
                  :data-testid="`settle-TIMEOFF-${textValue(row.id)}`"
                  @click="settle(row, 'TIMEOFF')"
                >
                  调休结算
                </el-button>
              </template>
              <span v-else class="small muted">已锁定</span>
            </td>
          </tr>
        </tbody>
      </table>
      <p class="small muted">同一小时只能获得工资或调休一种补偿；已锁定记录需走冲正流程。</p>
    </section>

    <section v-else class="panel">
      <h2>{{ definition.title }}</h2>
      <div class="timepay-metrics">
        <div><span>记录数</span><strong>{{ rows.length }}</strong></div>
        <div><span>已结算</span><strong>{{ settledRows.length }}</strong></div>
        <div><span>待结算</span><strong>{{ pendingRows.length }}</strong></div>
        <div><span>累计申报</span><strong>{{ formatMinutes(rows.reduce((total, row) => total + minutesOf(row), 0)) }}</strong></div>
      </div>
      <table class="timepay-table">
        <caption class="sr-only">加班记录</caption>
        <thead><tr><th scope="col">加班日期</th><th scope="col">工时</th><th scope="col">状态</th><th scope="col">审批案例</th><th scope="col">结算方式</th></tr></thead>
        <tbody>
          <tr v-for="row in rows" :key="textValue(row.id)">
            <td>{{ textValue(row.business_date) }}</td>
            <td>{{ formatMinutes(row.minutes) }}</td>
            <td><span class="timepay-status" :class="`timepay-status--${statusTone(textValue(row.status))}`">{{ textValue(row.status) }}</span></td>
            <td class="mono">{{ row.case_id === null ? '—' : `#${textValue(row.case_id)}` }}</td>
            <td>{{ textValue(row.settlement_method) }}</td>
          </tr>
        </tbody>
      </table>
    </section>

    <p v-if="actionMessage" class="timepay-action-success" role="status">{{ actionMessage }}</p>
    <p v-if="actionError.message.value" class="timepay-action-error" role="alert">
      {{ actionError.message.value }}
      <span v-if="actionError.requestId.value">（请求编号：{{ actionError.requestId.value }}）</span>
    </p>
  </div>
</template>
