<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { errorCopy } from '@/api/errors'
import {
  createOvertimePolicy,
  newIdempotencyKey,
  overtimeAllocationLabel,
  overtimePolicyStatusLabel,
  overtimeRoundingLabel,
  retireOvertimePolicy,
  stableSubmissionKey,
  type OvertimePoliciesResult,
  type OvertimePolicy,
  type StableSubmissionKey,
} from '../api'

/**
 * Policy browser for the single overtime workspace.
 *
 * The editor sends only the server-owned fields. Version allocation, overlap
 * validation, effective-date checks and retirement rules stay on the backend.
 */
const props = defineProps<{
  policies: OvertimePoliciesResult | null
  canManage: boolean
}>()

const emit = defineEmits<{
  changed: [message: string]
  error: [message: string]
}>()

const TIME_UNITS = [1, 5, 10, 15, 30, 60]
const ALLOCATIONS = ['EMPLOYEE_CHOICE', 'PAY', 'TIMEOFF', 'EMPLOYER_DECIDES']
const ROUNDINGS = ['HALF_UP', 'HALF_EVEN', 'DOWN', 'UP']
const MULTIPLIER = /^\d{1,6}\.\d{6,18}$/

const editorOpen = ref(false)
const submitting = ref(false)
const validation = ref('')
const submissionKey = ref<StableSubmissionKey | null>(null)
const retiringId = ref<number | null>(null)
const retireReason = ref('')
const retireSubmitting = ref(false)

const form = reactive({
  effectiveFrom: '',
  effectiveTo: '',
  timeUnitMinutes: 30,
  multiplier: '1.500000',
  statutoryHolidayMultiplier: '3.000000',
  allowPay: true,
  allowTimeoff: true,
  allocation: 'EMPLOYEE_CHOICE',
  rounding: 'HALF_UP',
  legalBasis: '',
})

const items = computed(() => props.policies?.items ?? [])
const current = computed(() => props.policies?.current ?? null)

function openEditor(): void {
  if (!props.canManage) return
  editorOpen.value = true
  validation.value = ''
  submissionKey.value = null
}

function closeEditor(): void {
  if (submitting.value) return
  editorOpen.value = false
}

function startRetire(policy: OvertimePolicy): void {
  if (!props.canManage || policy.id === null || policy.status === 'RETIRED') return
  retiringId.value = policy.id
  retireReason.value = ''
}

function cancelRetire(): void {
  if (retireSubmitting.value) return
  retiringId.value = null
  retireReason.value = ''
}

function validate(): boolean {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(form.effectiveFrom) || !/^\d{4}-\d{2}-\d{2}$/.test(form.effectiveTo)) {
    validation.value = '请选择有效的开始和结束日期。'
    return false
  }
  if (form.effectiveTo < form.effectiveFrom) {
    validation.value = '结束日期不得早于开始日期。'
    return false
  }
  if (!MULTIPLIER.test(form.multiplier) || !MULTIPLIER.test(form.statutoryHolidayMultiplier)) {
    validation.value = '倍率必须是至少 6 位小数的定点字符串。'
    return false
  }
  if (!form.allowPay && !form.allowTimeoff) {
    validation.value = '加班费与调休至少允许一种补偿方式。'
    return false
  }
  validation.value = ''
  return true
}

async function submit(): Promise<void> {
  if (!props.canManage || !validate()) return
  const payload = {
    effective_from: form.effectiveFrom,
    effective_to: form.effectiveTo,
    time_unit_minutes: form.timeUnitMinutes,
    multiplier: form.multiplier,
    statutory_holiday_multiplier: form.statutoryHolidayMultiplier,
    allow_pay: form.allowPay,
    allow_timeoff: form.allowTimeoff,
    allocation: form.allocation,
    rounding: form.rounding,
    legal_basis: form.legalBasis.trim(),
  }
  submissionKey.value = stableSubmissionKey(submissionKey.value, 'overtime-policy', payload)
  submitting.value = true
  try {
    const created = await createOvertimePolicy({
      effectiveFrom: form.effectiveFrom,
      effectiveTo: form.effectiveTo,
      timeUnitMinutes: form.timeUnitMinutes,
      multiplier: form.multiplier,
      statutoryHolidayMultiplier: form.statutoryHolidayMultiplier,
      allowPay: form.allowPay,
      allowTimeoff: form.allowTimeoff,
      allocation: form.allocation,
      rounding: form.rounding,
      legalBasis: form.legalBasis,
    }, submissionKey.value.key)
    emit('changed', `公司加班规则 v${created.version ?? '新'} 已发布。`)
    editorOpen.value = false
    submissionKey.value = null
  } catch (reason) {
    emit('error', errorCopy(reason))
  } finally {
    submitting.value = false
  }
}

async function retire(): Promise<void> {
  if (!props.canManage || retiringId.value === null) return
  if (retireReason.value.trim().length < 3) {
    validation.value = '停用原因至少需要 3 个字符。'
    return
  }
  retireSubmitting.value = true
  validation.value = ''
  try {
    const retired = await retireOvertimePolicy(retiringId.value, retireReason.value, newIdempotencyKey('overtime-policy-retire'))
    emit('changed', `公司加班规则 v${retired.version ?? retiringId.value} 已停用，历史版本保留。`)
    retiringId.value = null
    retireReason.value = ''
  } catch (reason) {
    emit('error', errorCopy(reason))
  } finally {
    retireSubmitting.value = false
  }
}
</script>

<template>
  <section
    class="overtime-policy-panel"
    data-testid="overtime-panel-policies"
    aria-labelledby="overtime-policy-title"
  >
    <header class="overtime-policy-panel__header">
      <div>
        <h2 id="overtime-policy-title">加班规则</h2>
        <p>公司规则按生效日期追加版本；停用只标记未来版本，不删除历史。倍率全程保持定点字符串。</p>
      </div>
      <button
        v-if="canManage"
        type="button"
        class="overtime-policy-panel__primary"
        data-testid="overtime-policy-create"
        @click="openEditor"
      >
        新建规则版本
      </button>
    </header>

    <p v-if="items.length === 0" class="overtime-policy-panel__empty" data-testid="overtime-policy-unconfigured" role="status">
      服务端尚未返回公司加班规则版本；页面不会套用代码内置默认值。
    </p>

    <div v-else class="overtime-policy-panel__table-wrap">
      <table class="overtime-policy-panel__table">
        <thead>
          <tr>
            <th scope="col">版本</th>
            <th scope="col">生效期间</th>
            <th scope="col">时间单位</th>
            <th scope="col">工作日倍率</th>
            <th scope="col">法定节假日倍率</th>
            <th scope="col">补偿</th>
            <th scope="col">分配 / 舍入</th>
            <th scope="col">状态</th>
            <th scope="col">操作</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="policy in items" :key="String(policy.id)" :data-testid="`overtime-policy-row-${String(policy.id)}`">
            <td class="mono">v{{ policy.version }}</td>
            <td>{{ policy.effectiveFrom ?? '—' }} 至 {{ policy.effectiveTo ?? '—' }}</td>
            <td>{{ policy.timeUnitMinutes ?? '—' }} 分钟</td>
            <td class="mono">{{ policy.multiplier }}</td>
            <td class="mono">{{ policy.statutoryHolidayMultiplier }}</td>
            <td>
              {{ policy.allowPay ? '加班费' : '' }}
              {{ policy.allowPay && policy.allowTimeoff ? ' / ' : '' }}
              {{ policy.allowTimeoff ? '调休' : '' }}
            </td>
            <td>{{ overtimeAllocationLabel(policy.allocation) }} / {{ overtimeRoundingLabel(policy.rounding) }}</td>
            <td>{{ overtimePolicyStatusLabel(policy.status) }}</td>
            <td>
              <div v-if="retiringId === policy.id" class="overtime-policy-panel__retire">
                <input
                  v-model="retireReason"
                  :data-testid="`overtime-policy-retire-reason-${String(policy.id)}`"
                  maxlength="500"
                  placeholder="停用原因"
                />
                <p v-if="validation" class="overtime-policy-panel__error" role="alert">{{ validation }}</p>
                <button type="button" class="overtime-policy-panel__secondary" :disabled="retireSubmitting" @click="cancelRetire">取消</button>
                <button
                  type="button"
                  class="overtime-policy-panel__danger"
                  :disabled="retireSubmitting"
                  :data-testid="`overtime-policy-retire-submit-${String(policy.id)}`"
                  @click="retire"
                >
                  确认停用
                </button>
              </div>
              <button
                v-else-if="canManage && policy.status !== 'RETIRED' && policy.retiredAt === null"
                type="button"
                class="overtime-policy-panel__secondary"
                :data-testid="`overtime-policy-retire-${String(policy.id)}`"
                @click="startRetire(policy)"
              >
                停用
              </button>
              <span v-else>—</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <p v-if="current !== null" class="overtime-policy-panel__current" data-testid="overtime-policy-current">
      当前适用：v{{ current.version }}，{{ current.effectiveFrom }} 起；法定节假日倍率 {{ current.statutoryHolidayMultiplier }}。
    </p>

    <div v-if="editorOpen" class="overtime-policy-panel__modal" role="dialog" aria-modal="true" aria-label="新建加班规则版本">
      <form class="overtime-policy-panel__editor" data-testid="overtime-policy-editor" @submit.prevent="submit">
        <header>
          <h3>新建加班规则版本</h3>
          <button type="button" class="overtime-policy-panel__secondary" :disabled="submitting" @click="closeEditor">关闭</button>
        </header>

        <div class="overtime-policy-panel__grid">
          <label>
            <span>生效开始</span>
            <input v-model="form.effectiveFrom" type="date" required />
          </label>
          <label>
            <span>生效结束</span>
            <input v-model="form.effectiveTo" type="date" required />
          </label>
          <label>
            <span>时间单位（分钟）</span>
            <select v-model.number="form.timeUnitMinutes">
              <option v-for="unit in TIME_UNITS" :key="unit" :value="unit">{{ unit }}</option>
            </select>
          </label>
          <label>
            <span>工作日倍率</span>
            <input v-model="form.multiplier" class="mono" required />
          </label>
          <label>
            <span>法定节假日倍率</span>
            <input v-model="form.statutoryHolidayMultiplier" class="mono" required />
          </label>
          <label>
            <span>补偿分配</span>
            <select v-model="form.allocation">
              <option v-for="allocation in ALLOCATIONS" :key="allocation" :value="allocation">{{ overtimeAllocationLabel(allocation) }}</option>
            </select>
          </label>
          <label>
            <span>舍入方式</span>
            <select v-model="form.rounding">
              <option v-for="rounding in ROUNDINGS" :key="rounding" :value="rounding">{{ overtimeRoundingLabel(rounding) }}</option>
            </select>
          </label>
          <label>
            <span>法律依据</span>
            <input v-model="form.legalBasis" maxlength="255" placeholder="可选" />
          </label>
        </div>

        <div class="overtime-policy-panel__checks">
          <label><input v-model="form.allowPay" type="checkbox" /> 允许折算加班费</label>
          <label><input v-model="form.allowTimeoff" type="checkbox" /> 允许调休</label>
        </div>

        <p v-if="validation" class="overtime-policy-panel__error" role="alert">{{ validation }}</p>

        <footer>
          <button type="button" class="overtime-policy-panel__secondary" :disabled="submitting" @click="closeEditor">取消</button>
          <button type="submit" class="overtime-policy-panel__primary" :disabled="submitting" data-testid="overtime-policy-submit">
            {{ submitting ? '发布中…' : '发布版本' }}
          </button>
        </footer>
      </form>
    </div>
  </section>
</template>

<style scoped>
.overtime-policy-panel {
  display: grid;
  gap: 12px;
  padding: 16px;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
}

.overtime-policy-panel__header {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  align-items: flex-start;
}

.overtime-policy-panel__header h2 {
  margin: 0;
  font-size: 16px;
}

.overtime-policy-panel__header p {
  margin: 4px 0 0;
  color: #64748b;
  font-size: 11.5px;
}

.overtime-policy-panel__primary,
.overtime-policy-panel__secondary,
.overtime-policy-panel__danger {
  padding: 6px 11px;
  font-size: 11.5px;
  border-radius: 6px;
  cursor: pointer;
}

.overtime-policy-panel__primary {
  color: #fff;
  background: #1d4ed8;
  border: 1px solid #1d4ed8;
}

.overtime-policy-panel__secondary {
  color: #334155;
  background: #fff;
  border: 1px solid #cbd5e1;
}

.overtime-policy-panel__danger {
  color: #fff;
  background: #b91c1c;
  border: 1px solid #b91c1c;
}

.overtime-policy-panel__table-wrap {
  overflow-x: auto;
}

.overtime-policy-panel__table {
  width: 100%;
  font-size: 11.5px;
  border-collapse: collapse;
}

.overtime-policy-panel__table th,
.overtime-policy-panel__table td {
  padding: 8px 9px;
  text-align: left;
  vertical-align: top;
  border-bottom: 1px solid #f1f5f9;
}

.overtime-policy-panel__table th {
  color: #64748b;
  font-weight: 500;
  background: #f8fafc;
}

.overtime-policy-panel__empty,
.overtime-policy-panel__current {
  margin: 0;
  padding: 10px 12px;
  color: #64748b;
  font-size: 12px;
  background: #f8fafc;
  border-radius: 7px;
}

.overtime-policy-panel__retire {
  display: grid;
  gap: 5px;
  min-width: 190px;
}

.overtime-policy-panel__retire input {
  padding: 5px 7px;
  font: inherit;
  font-size: 11px;
  border: 1px solid #cbd5e1;
  border-radius: 5px;
}

.overtime-policy-panel__modal {
  position: fixed;
  inset: 0;
  z-index: 50;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
  background: rgb(15 23 42 / 45%);
}

.overtime-policy-panel__editor {
  display: grid;
  gap: 12px;
  width: min(720px, 100%);
  max-height: 90vh;
  overflow-y: auto;
  padding: 18px;
  background: #fff;
  border-radius: 10px;
}

.overtime-policy-panel__editor header,
.overtime-policy-panel__editor footer {
  display: flex;
  justify-content: space-between;
  gap: 10px;
}

.overtime-policy-panel__editor h3 {
  margin: 0;
  font-size: 15px;
}

.overtime-policy-panel__grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 10px;
}

.overtime-policy-panel__grid label {
  display: flex;
  flex-direction: column;
  gap: 3px;
  font-size: 11px;
}

.overtime-policy-panel__grid input,
.overtime-policy-panel__grid select {
  padding: 7px 8px;
  font: inherit;
  font-size: 12px;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
}

.overtime-policy-panel__checks {
  display: flex;
  gap: 14px;
  font-size: 11.5px;
}

.overtime-policy-panel__error {
  margin: 0;
  color: #b91c1c;
  font-size: 11px;
}

.mono {
  font-family: ui-monospace, monospace;
}
</style>
