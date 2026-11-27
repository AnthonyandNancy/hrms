<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ApiError, errorCode, errorCopy } from '@/api/errors'
import { useSessionStore } from '@/stores/session'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import LoadingState from '@/components/LoadingState.vue'
import {
  businessDate,
  createPlan,
  listPlans,
  programStatusLabel,
  trainingActionErrorCopy,
  type PlanDraft,
  type TrainingPlan,
} from '../api'

defineOptions({ name: 'TrainingPlansPanel' })

const session = useSessionStore()
const canManage = computed(() => session.can('training.plan.manage'))

const loading = ref(false)
const loaded = ref(false)
const errorMessage = ref('')
const errorCodeValue = ref('')
const errorRequestId = ref<string | null>(null)
const plans = ref<TrainingPlan[]>([])

const formOpen = ref(false)
const submitting = ref(false)
const formError = ref('')
const draft = ref<PlanDraft>({
  code: '',
  title: '',
  year: new Date().getUTCFullYear(),
  budgetCents: 0,
  currency: 'CNY',
})

const isEmpty = computed(() => loaded.value && !loading.value && errorMessage.value === '' && plans.value.length === 0)

function captureError(reason: unknown): void {
  errorCodeValue.value = errorCode(reason)
  errorRequestId.value = reason instanceof ApiError ? reason.requestId : null
  errorMessage.value =
    reason instanceof ApiError && reason.code === 'FORBIDDEN'
      ? '无权读取培训计划。请确认已获得 training.plan.manage 能力。'
      : trainingActionErrorCopy(reason) || errorCopy(reason)
}

async function load(): Promise<void> {
  loading.value = true
  loaded.value = false
  errorMessage.value = ''
  errorCodeValue.value = ''
  errorRequestId.value = null

  try {
    plans.value = await listPlans(businessDate())
  } catch (reason) {
    plans.value = []
    captureError(reason)
  } finally {
    loading.value = false
    loaded.value = true
  }
}

function openCreate(): void {
  if (!canManage.value) return
  draft.value = {
    code: '',
    title: '',
    year: new Date().getUTCFullYear(),
    budgetCents: 0,
    currency: 'CNY',
  }
  formError.value = ''
  formOpen.value = true
}

async function submit(): Promise<void> {
  formError.value = ''
  const code = draft.value.code.trim()
  const title = draft.value.title.trim()

  if (code === '' || title === '') {
    formError.value = '请填写计划编码与名称。'
    return
  }
  if (!Number.isSafeInteger(draft.value.year) || draft.value.year < 2000 || draft.value.year > 2100) {
    formError.value = '计划年度必须在 2000 到 2100 之间。'
    return
  }
  if (!Number.isSafeInteger(draft.value.budgetCents) || draft.value.budgetCents < 0) {
    formError.value = '预算必须为不小于 0 的整数分。'
    return
  }

  submitting.value = true
  try {
    const created = await createPlan({ ...draft.value, code, title })
    plans.value = [created, ...plans.value.filter((row) => row.id !== created.id)]
    formOpen.value = false
  } catch (reason) {
    formError.value = trainingActionErrorCopy(reason)
  } finally {
    submitting.value = false
  }
}

onMounted(load)
</script>

<template>
  <section class="training-panel" data-testid="training-plans-panel">
    <header class="training-panel__header">
      <div>
        <h2>年度培训计划</h2>
        <p>计划、课程与场次全部来自实时台账；预算以整数分保存和展示。</p>
      </div>
      <button v-if="canManage" type="button" class="training-panel__primary" data-testid="training-plan-create" @click="openCreate">
        新建计划
      </button>
    </header>

    <LoadingState v-if="loading" :rows="5" label="正在加载培训计划" />
    <ErrorState
      v-else-if="errorMessage !== ''"
      title="培训计划暂时无法加载"
      :description="errorMessage"
      :code="errorCodeValue"
      :request-id="errorRequestId"
      @retry="load"
    />
    <EmptyState
      v-else-if="isEmpty"
      title="还没有培训计划"
      description="当前数据范围内没有计划。具备 training.plan.manage 的账号可以新建第一个计划。"
    />
    <div v-else class="training-panel__table-wrap">
      <table class="training-panel__table">
        <thead>
          <tr>
            <th>计划编码</th>
            <th>名称</th>
            <th>年度</th>
            <th>预算（整数分）</th>
            <th>状态</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="plan in plans" :key="plan.id" :data-testid="`training-plan-row-${plan.id}`">
            <td class="mono">{{ plan.code }}</td>
            <td>{{ plan.title }}</td>
            <td>{{ plan.year }}</td>
            <td>{{ plan.budgetCents }}<span class="training-panel__hint">{{ plan.currency }}</span></td>
            <td>{{ programStatusLabel(plan.status) }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <el-dialog v-model="formOpen" title="新建培训计划" width="480px" data-testid="training-plan-dialog">
      <form class="training-panel__form" @submit.prevent="submit">
        <label>计划编码<el-input v-model="draft.code" maxlength="64" data-testid="training-plan-code" /></label>
        <label>计划名称<el-input v-model="draft.title" maxlength="160" data-testid="training-plan-title" /></label>
        <label>年度<el-input v-model.number="draft.year" type="number" data-testid="training-plan-year" /></label>
        <label>预算（整数分）<el-input v-model.number="draft.budgetCents" type="number" data-testid="training-plan-budget" /></label>
        <label>币种<el-input v-model="draft.currency" maxlength="3" data-testid="training-plan-currency" /></label>
        <p v-if="formError !== ''" class="training-panel__error" role="alert" data-testid="training-plan-error">{{ formError }}</p>
      </form>
      <template #footer>
        <el-button @click="formOpen = false">取消</el-button>
        <el-button type="primary" :loading="submitting" data-testid="training-plan-submit" @click="submit">保存</el-button>
      </template>
    </el-dialog>
  </section>
</template>

<style scoped>
.training-panel { display: flex; flex-direction: column; gap: 12px; }
.training-panel__header { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; }
.training-panel__header h2 { margin: 0 0 4px; font-size: 15px; color: #0f172a; }
.training-panel__header p { margin: 0; color: #64748b; font-size: 12px; line-height: 1.6; }
.training-panel__primary { padding: 7px 12px; border: 1px solid #1d4ed8; border-radius: 8px; background: #1d4ed8; color: #fff; font-size: 12px; cursor: pointer; }
.training-panel__table-wrap { overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 10px; }
.training-panel__table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.training-panel__table th, .training-panel__table td { padding: 9px 11px; text-align: left; border-bottom: 1px solid #e2e8f0; }
.training-panel__table th { background: #f8fafc; color: #64748b; font-weight: 600; white-space: nowrap; }
.training-panel__hint { display: block; color: #94a3b8; font-size: 10.5px; }
.training-panel__form { display: flex; flex-direction: column; gap: 10px; }
.training-panel__form label { display: flex; flex-direction: column; gap: 5px; font-size: 12.5px; color: #334155; }
.training-panel__error { margin: 0; color: #b91c1c; font-size: 12px; }
.mono { font-variant-numeric: tabular-nums; }
</style>
