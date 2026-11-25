<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { resourcePost } from '@/api/resourceClient'
import { usePageError } from '@/composables/usePageError'
import type { TimePayrollPageDefinition } from '../types'
import { formatMinutes, integerValue, newIdempotencyKey, statusTone, textValue, todayIsoDate } from '../panelUtils'

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
const approvableRows = computed(() =>
  props.rows.filter((row) => textValue(row.status, '') === 'APPROVED'),
)
const confirmedRows = computed(() =>
  props.rows.filter((row) => textValue(row.status, '') === 'CONFIRMED'),
)
const timeoffItems = computed(() => props.rows.filter((row) => textValue(row.method, '') === 'TIMEOFF'))
const availableTimeoffMinutes = computed(() =>
  timeoffItems.value.reduce((total, row) => total + (integerValue(row.minutes) ?? 0), 0),
)

const form = reactive({
  businessDate: todayIsoDate(),
  declaredMinutes: '60',
  reason: '',
})
const confirmMinutes = reactive<Record<string, string>>({})

function minutesOf(row: Record<string, unknown>): number {
  const value = integerValue(row.minutes)
  return value ?? 0
}

function declaredMinutesOf(row: Record<string, unknown>): number {
  return integerValue(row.declared_minutes) ?? 0
}

function confirmedMinutesOf(row: Record<string, unknown>): number | null {
  return integerValue(row.confirmed_minutes)
}

function confirmValue(row: Record<string, unknown>): string {
  const id = textValue(row.id)
  if (confirmMinutes[id] === undefined) {
    confirmMinutes[id] = String(integerValue(row.declared_minutes) ?? '')
  }

  return confirmMinutes[id] ?? ''
}

async function applyOvertime(): Promise<void> {
  actionError.clear()
  actionMessage.value = ''
  const declared = Number.parseInt(form.declaredMinutes, 10)

  if (!Number.isSafeInteger(declared) || declared < 1) {
    actionError.reject(new Error('申报加班分钟必须是大于 0 的整数。'))
    return
  }

  if (form.reason.trim().length < 3) {
    actionError.reject(new Error('加班事由至少需要 3 个字符。'))
    return
  }

  submitting.value = true
  try {
    const result = await resourcePost<Record<string, unknown>>(
      '/time/overtime/requests',
      {
        business_date: form.businessDate,
        declared_minutes: declared,
        reason: form.reason.trim(),
      },
      { idempotencyKey: newIdempotencyKey('overtime-request') },
    )
    actionMessage.value = `加班申请已提交：#${textValue(result.id, '已受理')}`
    form.reason = ''
    emit('action-success', actionMessage.value)
    emit('refresh')
  } catch (reason) {
    actionError.capture(reason)
  } finally {
    submitting.value = false
  }
}

async function confirmOvertime(row: Record<string, unknown>): Promise<void> {
  const id = integerValue(row.id)
  if (id === null) return
  const value = Number.parseInt(confirmValue(row), 10)

  if (!Number.isSafeInteger(value) || value < 1) {
    actionError.reject(new Error('确认加班分钟必须是大于 0 的整数。'))
    return
  }

  actionError.clear()
  actionMessage.value = ''
  submitting.value = true
  try {
    const result = await resourcePost<Record<string, unknown>>(
      `/time/overtime/requests/${id}/confirm`,
      { confirmed_minutes: value },
      { idempotencyKey: newIdempotencyKey('overtime-confirm') },
    )
    actionMessage.value = `加班工时已确认：#${textValue(result.id, String(id))}，加班记录 #${textValue(result.overtime_record_id)}`
    emit('action-success', actionMessage.value)
    emit('refresh')
  } catch (reason) {
    actionError.capture(reason)
  } finally {
    submitting.value = false
  }
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
      <h2>调休余额</h2>
      <div class="timepay-metrics">
        <div><span>可用调休</span><strong>{{ formatMinutes(availableTimeoffMinutes) }}</strong></div>
        <div><span>调休结算笔数</span><strong>{{ timeoffItems.length }}</strong></div>
      </div>
      <table v-if="timeoffItems.length > 0" class="timepay-table">
        <caption class="sr-only">调休结算记录</caption>
        <thead><tr><th scope="col">加班日期</th><th scope="col">调休分钟</th><th scope="col">加班记录</th><th scope="col">结算方式</th><th scope="col">事由</th></tr></thead>
        <tbody>
          <tr v-for="row in timeoffItems" :key="textValue(row.settlement_id, textValue(row.id))">
            <td>{{ textValue(row.business_date) }}</td>
            <td>{{ formatMinutes(row.minutes) }}</td>
            <td class="mono">#{{ textValue(row.overtime_record_id) }}</td>
            <td>{{ textValue(row.method) }}</td>
            <td>{{ textValue(row.reason) }}</td>
          </tr>
        </tbody>
      </table>
      <p v-else class="small muted">没有 TIMEOFF 结算记录；调休余额由服务端从已确认加班结算聚合。</p>
    </section>

    <section v-else-if="definition.panel === 'overtime-confirm'" class="panel">
      <h2>加班工时确认</h2>
      <p class="small muted">申报分钟与实际确认分钟分开保存；确认只允许发生一次。</p>
      <table v-if="approvableRows.length > 0" class="timepay-table">
        <caption class="sr-only">待确认加班申请</caption>
        <thead><tr><th scope="col">加班日期</th><th scope="col">申报分钟</th><th scope="col">事由</th><th scope="col">审批状态</th><th scope="col">实际分钟</th><th scope="col">操作</th></tr></thead>
        <tbody>
          <tr v-for="row in approvableRows" :key="textValue(row.id)">
            <td>{{ textValue(row.business_date) }}</td>
            <td>{{ formatMinutes(row.declared_minutes) }}</td>
            <td>{{ textValue(row.reason) }}</td>
            <td><span class="timepay-status" :class="`timepay-status--${statusTone(textValue(row.status))}`">{{ textValue(row.status) }}</span></td>
            <td>
              <input
                :data-testid="`confirm-minutes-${textValue(row.id)}`"
                type="number"
                min="1"
                step="1"
                :value="confirmValue(row)"
                @input="confirmMinutes[textValue(row.id)] = ($event.target as HTMLInputElement).value"
              />
            </td>
            <td>
              <el-button
                text
                size="small"
                :loading="submitting"
                :data-testid="`confirm-${textValue(row.id)}`"
                @click="confirmOvertime(row)"
              >
                确认实际工时
              </el-button>
            </td>
          </tr>
        </tbody>
      </table>
      <p v-else class="small muted">没有待确认的加班申请。</p>
      <table v-if="confirmedRows.length > 0" class="timepay-table">
        <caption class="sr-only">已确认加班申请</caption>
        <thead><tr><th scope="col">加班日期</th><th scope="col">申报分钟</th><th scope="col">确认分钟</th><th scope="col">加班记录</th></tr></thead>
        <tbody>
          <tr v-for="row in confirmedRows" :key="`confirmed-${textValue(row.id)}`">
            <td>{{ textValue(row.business_date) }}</td>
            <td>{{ formatMinutes(row.declared_minutes) }}</td>
            <td>{{ formatMinutes(row.confirmed_minutes) }}</td>
            <td class="mono">#{{ textValue(row.overtime_record_id) }}</td>
          </tr>
        </tbody>
      </table>
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

    <section v-else-if="definition.panel === 'overtime-apply'" class="panel timepay-form">
      <h2>新建加班申请</h2>
      <p class="small muted">申报只记录计划分钟；实际分钟由具备确认能力的角色单独确认。</p>
      <div class="timepay-form__grid">
        <label>加班日期
          <input v-model="form.businessDate" data-testid="overtime-business-date" type="date" />
        </label>
        <label>申报分钟
          <input v-model="form.declaredMinutes" data-testid="overtime-declared-minutes" type="number" min="1" step="1" />
        </label>
      </div>
      <label class="timepay-form__wide">加班事由
        <textarea v-model="form.reason" data-testid="overtime-reason" rows="3" maxlength="500" />
      </label>
      <el-button type="primary" :loading="submitting" :disabled="submitting" data-testid="overtime-submit" @click="applyOvertime">
        提交加班申请
      </el-button>
      <div class="timepay-metrics">
        <div><span>申请数</span><strong>{{ rows.length }}</strong></div>
        <div><span>待确认</span><strong>{{ approvableRows.length }}</strong></div>
        <div><span>已确认</span><strong>{{ confirmedRows.length }}</strong></div>
        <div><span>累计申报</span><strong>{{ formatMinutes(rows.reduce((total, row) => total + declaredMinutesOf(row), 0)) }}</strong></div>
      </div>
      <table class="timepay-table">
        <caption class="sr-only">加班申请</caption>
        <thead><tr><th scope="col">加班日期</th><th scope="col">申报分钟</th><th scope="col">确认分钟</th><th scope="col">状态</th><th scope="col">加班记录</th></tr></thead>
        <tbody>
          <tr v-for="row in rows" :key="textValue(row.id)">
            <td>{{ textValue(row.business_date) }}</td>
            <td>{{ formatMinutes(row.declared_minutes) }}</td>
            <td>{{ confirmedMinutesOf(row) === null ? '—' : formatMinutes(confirmedMinutesOf(row)) }}</td>
            <td><span class="timepay-status" :class="`timepay-status--${statusTone(textValue(row.status))}`">{{ textValue(row.status) }}</span></td>
            <td class="mono">{{ row.overtime_record_id === null ? '—' : `#${textValue(row.overtime_record_id)}` }}</td>
          </tr>
        </tbody>
      </table>
    </section>

    <section v-else class="panel">
      <h2>{{ definition.title }}</h2>
      <div class="timepay-metrics">
        <div><span>记录数</span><strong>{{ rows.length }}</strong></div>
        <div><span>已结算</span><strong>{{ settledRows.length }}</strong></div>
        <div><span>待结算</span><strong>{{ pendingRows.length }}</strong></div>
        <div><span>累计小时</span><strong>{{ formatMinutes(rows.reduce((total, row) => total + minutesOf(row), 0)) }}</strong></div>
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
