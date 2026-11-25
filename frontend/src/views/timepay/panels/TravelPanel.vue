<script setup lang="ts">
import { reactive, ref } from 'vue'
import { resourcePost } from '@/api/resourceClient'
import { usePageError } from '@/composables/usePageError'
import MoneyText from '@/components/MoneyText.vue'
import type { TimePayrollPageDefinition } from '../types'
import { integerValue, moneyField, newIdempotencyKey, statusTone, textValue, todayIsoDate } from '../panelUtils'

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

const policyForm = reactive({
  legal_entity_id: '1',
  city_tier: 'A',
  hotel_cap_cents: '',
  transport_cap_cents: '',
  effective_from: todayIsoDate(),
})

const expenseForm = reactive({
  travel_id: '',
  amount_cents: '',
  category: 'TRANSPORT',
  note: '',
})

const decisionComment = reactive<Record<string, string>>({})

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

async function publishPolicy(): Promise<void> {
  actionError.clear()
  actionMessage.value = ''

  const legalEntityId = positiveInteger(policyForm.legal_entity_id)
  const hotelCents = minorUnits(policyForm.hotel_cap_cents)
  const transportCents =
    policyForm.transport_cap_cents.trim() === '' ? 0 : minorUnits(policyForm.transport_cap_cents)
  const effectiveFrom = policyForm.effective_from.trim()
  const cityTier = policyForm.city_tier.trim().toUpperCase()

  if (legalEntityId === null) {
    actionError.reject(new Error('法人 ID 必须是正整数。'))
    return
  }
  if (cityTier === '' || cityTier.length > 8) {
    actionError.reject(new Error('城市级别不能为空且不超过 8 个字符。'))
    return
  }
  if (hotelCents === null || transportCents === null) {
    actionError.reject(new Error('住宿与交通上限必须是非负整数分，不能输入小数或货币符号。'))
    return
  }
  if (!/^\d{4}-\d{2}-\d{2}$/.test(effectiveFrom)) {
    actionError.reject(new Error('请选择有效的生效日期。'))
    return
  }

  submitting.value = true
  try {
    const result = await resourcePost<Record<string, unknown>>(
      '/travel/policies',
      {
        legal_entity_id: legalEntityId,
        city_tier: cityTier,
        hotel_cap_cents: hotelCents,
        transport_cap_cents: transportCents,
        effective_from: effectiveFrom,
      },
      { idempotencyKey: newIdempotencyKey('travel-policy') },
    )
    actionMessage.value = `差旅政策版本已发布：v${textValue(result.version, '1')}`
    policyForm.hotel_cap_cents = ''
    policyForm.transport_cap_cents = ''
    emit('action-success', actionMessage.value)
    emit('refresh')
  } catch (reason) {
    actionError.capture(reason)
  } finally {
    submitting.value = false
  }
}

async function decideTravel(row: Record<string, unknown>, decision: 'APPROVE' | 'REJECT'): Promise<void> {
  const id = integerValue(row.travel_id)
  if (id === null) return

  actionError.clear()
  actionMessage.value = ''
  submitting.value = true
  try {
    await resourcePost<Record<string, unknown>>(
      `/travel/requests/${id}/approve`,
      {
        decision,
        comment: decisionComment[String(id)]?.trim() ?? '',
      },
      { idempotencyKey: newIdempotencyKey(`travel-${decision.toLowerCase()}`) },
    )
    actionMessage.value = decision === 'APPROVE' ? '出差申请已批准并写入工作流决策' : '出差申请已驳回并写入工作流决策'
    emit('action-success', actionMessage.value)
    emit('refresh')
  } catch (reason) {
    actionError.capture(reason)
  } finally {
    submitting.value = false
  }
}

async function claimExpense(): Promise<void> {
  actionError.clear()
  actionMessage.value = ''

  const travelId = positiveInteger(expenseForm.travel_id)
  const amountCents = minorUnits(expenseForm.amount_cents)

  if (travelId === null) {
    actionError.reject(new Error('出差单 ID 必须是正整数。'))
    return
  }
  if (amountCents === null) {
    actionError.reject(new Error('费用金额必须是非负整数分，不能输入小数或货币符号。'))
    return
  }

  submitting.value = true
  try {
    const result = await resourcePost<Record<string, unknown>>(
      '/travel/expenses',
      {
        travel_id: travelId,
        amount_cents: amountCents,
        category: expenseForm.category,
        ...(expenseForm.note.trim() === '' ? {} : { note: expenseForm.note.trim() }),
      },
      { idempotencyKey: newIdempotencyKey('travel-expense') },
    )
    actionMessage.value = `出差费用已登记：${textValue(result.id, '已受理')}`
    expenseForm.amount_cents = ''
    expenseForm.note = ''
    emit('action-success', actionMessage.value)
    emit('refresh')
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

function decidable(status: unknown): boolean {
  return textValue(status) === 'PENDING_APPROVAL'
}

function positiveInteger(raw: string): number | null {
  const trimmed = raw.trim()
  if (!/^[1-9]\d*$/.test(trimmed)) return null
  const value = Number(trimmed)
  return Number.isSafeInteger(value) ? value : null
}

function minorUnits(raw: string): number | null {
  const trimmed = raw.trim()
  if (!/^(0|[1-9]\d*)$/.test(trimmed)) return null
  const value = Number(trimmed)
  return Number.isSafeInteger(value) ? value : null
}

function hotelCents(row: Record<string, unknown>): number | null {
  return moneyField(row, 'hotel_cap_cents')
}

function expenseCents(row: Record<string, unknown>): number | null {
  return moneyField(row, 'amount_cents')
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
        <textarea v-model="form.purpose" data-testid="travel-purpose" rows="3" maxlength="500"></textarea>
      </label>
      <el-button type="primary" :loading="submitting" :disabled="submitting" data-testid="travel-create-submit" @click="createTravel">
        提交出差申请
      </el-button>
      <p class="small muted">提交后创建审批案例；申请状态不能手工改写。</p>
    </section>

    <section v-else-if="definition.panel === 'travel-policies'" class="panel">
      <h2>差旅政策版本</h2>
      <table v-if="rows.length > 0" class="timepay-table">
        <caption class="sr-only">差旅政策版本</caption>
        <thead><tr><th scope="col">版本</th><th scope="col">城市级别</th><th scope="col" class="align-right">住宿上限</th><th scope="col" class="align-right">交通上限</th><th scope="col">生效日期</th><th scope="col">状态</th></tr></thead>
        <tbody>
          <tr v-for="row in rows" :key="textValue(row.id)">
            <td class="mono">v{{ textValue(row.version) }}</td>
            <td>{{ textValue(row.city_tier) }}</td>
            <td class="align-right"><MoneyText :cents="hotelCents(row)" /></td>
            <td class="align-right"><MoneyText :cents="moneyField(row, 'transport_cap_cents')" /></td>
            <td>{{ textValue(row.effective_from) }}</td>
            <td><span class="timepay-status" :class="`timepay-status--${statusTone(textValue(row.status))}`">{{ textValue(row.status) }}</span></td>
          </tr>
        </tbody>
      </table>
      <p v-else class="small muted">暂无差旅政策版本。</p>

      <form class="timepay-form" data-testid="travel-policy-form" @submit.prevent="publishPolicy">
        <h3>发布新版本</h3>
        <div class="timepay-form__grid">
          <label>法人 ID
            <input v-model="policyForm.legal_entity_id" data-testid="travel-policy-legal-entity" inputmode="numeric" />
          </label>
          <label>城市级别
            <input v-model="policyForm.city_tier" data-testid="travel-policy-city-tier" maxlength="8" />
          </label>
          <label>生效日期
            <input v-model="policyForm.effective_from" data-testid="travel-policy-effective-from" type="date" />
          </label>
          <label>住宿上限（分）
            <input v-model="policyForm.hotel_cap_cents" data-testid="travel-policy-hotel-cents" inputmode="numeric" />
          </label>
          <label>交通上限（分）
            <input v-model="policyForm.transport_cap_cents" data-testid="travel-policy-transport-cents" inputmode="numeric" />
          </label>
        </div>
        <el-button type="primary" native-type="submit" :loading="submitting" :disabled="submitting" data-testid="travel-policy-submit">
          发布政策版本
        </el-button>
      </form>
    </section>

    <section v-else-if="definition.panel === 'travel-approval'" class="panel">
      <h2>待审批出差申请</h2>
      <table v-if="rows.length > 0" class="timepay-table">
        <caption class="sr-only">待审批出差申请</caption>
        <thead><tr><th scope="col">单号</th><th scope="col">目的地</th><th scope="col">开始</th><th scope="col">结束</th><th scope="col">状态</th><th scope="col">审批意见</th><th scope="col">操作</th></tr></thead>
        <tbody>
          <tr v-for="row in rows" :key="textValue(row.travel_id)">
            <td class="mono">#{{ textValue(row.travel_id) }}</td>
            <td>{{ textValue(row.destination) }}</td>
            <td>{{ textValue(row.from_date) }}</td>
            <td>{{ textValue(row.to_date) }}</td>
            <td>
              <span class="timepay-status" :class="`timepay-status--${statusTone(textValue(row.application_status, textValue(row.status)))}`">
                {{ textValue(row.application_status, textValue(row.status)) }}
              </span>
            </td>
            <td>
              <input
                :data-testid="`travel-decision-comment-${textValue(row.travel_id)}`"
                :value="decisionComment[textValue(row.travel_id)] ?? ''"
                @input="decisionComment[textValue(row.travel_id)] = ($event.target as HTMLInputElement).value"
              />
            </td>
            <td>
              <template v-if="decidable(row.status)">
                <el-button text size="small" :loading="submitting" :data-testid="`travel-approve-${textValue(row.travel_id)}`" @click="decideTravel(row, 'APPROVE')">批准</el-button>
                <el-button text size="small" :loading="submitting" :data-testid="`travel-reject-${textValue(row.travel_id)}`" @click="decideTravel(row, 'REJECT')">驳回</el-button>
              </template>
              <span v-else class="small muted">已处理</span>
            </td>
          </tr>
        </tbody>
      </table>
      <p v-else class="small muted">没有待审批的出差申请。</p>
    </section>

    <section v-else-if="definition.panel === 'travel-expenses'" class="panel">
      <h2>出差费用申请</h2>
      <p class="small muted">费用仅登记为报销申请，不触发支付；仅已批准且审批案例为 APPROVED 的出差可登记。</p>
      <table v-if="rows.length > 0" class="timepay-table">
        <caption class="sr-only">出差费用申请</caption>
        <thead><tr><th scope="col">单号</th><th scope="col">出差单</th><th scope="col">类别</th><th scope="col" class="align-right">金额</th><th scope="col">状态</th><th scope="col">支付状态</th><th scope="col">备注</th></tr></thead>
        <tbody>
          <tr v-for="row in rows" :key="textValue(row.id)">
            <td class="mono">#{{ textValue(row.id) }}</td>
            <td class="mono">#{{ textValue(row.travel_id) }}</td>
            <td>{{ textValue(row.category) }}</td>
            <td class="align-right"><MoneyText :cents="expenseCents(row)" /></td>
            <td>{{ textValue(row.status) }}</td>
            <td>{{ textValue(row.payment_status) }}</td>
            <td>{{ textValue(row.note) }}</td>
          </tr>
        </tbody>
      </table>
      <p v-else class="small muted">暂无出差费用申请。</p>

      <form class="timepay-form" data-testid="travel-expense-form" @submit.prevent="claimExpense">
        <h3>登记费用</h3>
        <div class="timepay-form__grid">
          <label>出差单 ID
            <input v-model="expenseForm.travel_id" data-testid="travel-expense-travel-id" inputmode="numeric" />
          </label>
          <label>金额（分）
            <input v-model="expenseForm.amount_cents" data-testid="travel-expense-amount-cents" inputmode="numeric" />
          </label>
          <label>类别
            <select v-model="expenseForm.category" data-testid="travel-expense-category">
              <option value="TRANSPORT">TRANSPORT</option>
              <option value="LODGING">LODGING</option>
              <option value="MEALS">MEALS</option>
              <option value="OTHER">OTHER</option>
            </select>
          </label>
          <label>备注
            <input v-model="expenseForm.note" data-testid="travel-expense-note" maxlength="500" />
          </label>
        </div>
        <el-button type="primary" native-type="submit" :loading="submitting" :disabled="submitting" data-testid="travel-expense-submit">
          登记费用申请
        </el-button>
      </form>
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
        <thead><tr><th scope="col">单号</th><th scope="col">目的地</th><th scope="col">开始</th><th scope="col">结束</th><th scope="col">事由</th><th scope="col">状态</th><th scope="col">审批案例</th><th scope="col">操作</th></tr></thead>
        <tbody>
          <tr v-for="row in rows" :key="textValue(row.travel_id)">
            <td class="mono">#{{ textValue(row.travel_id) }}</td>
            <td>{{ textValue(row.destination) }}</td>
            <td>{{ textValue(row.from_date) }}</td>
            <td>{{ textValue(row.to_date) }}</td>
            <td>{{ textValue(row.purpose) }}</td>
            <td><span class="timepay-status" :class="`timepay-status--${statusTone(textValue(row.status))}`">{{ textValue(row.status) }}</span></td>
            <td class="mono">{{ row.case_id === null || row.case_id === undefined ? '—' : `CASE-${textValue(row.case_id)}` }}</td>
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
      <p class="small muted">变更与销单通过取消接口完成，并保留服务端审计记录；审批只能经工作流决策接口完成。</p>
    </section>

    <p v-if="actionMessage" class="timepay-action-success" role="status">{{ actionMessage }}</p>
    <p v-if="actionError.message.value" class="timepay-action-error" role="alert">
      {{ actionError.message.value }}
      <span v-if="actionError.requestId.value">（请求编号：{{ actionError.requestId.value }}）</span>
    </p>
  </div>
</template>
