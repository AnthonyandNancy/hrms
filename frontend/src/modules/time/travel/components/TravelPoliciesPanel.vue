<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ApiError } from '@/api/errors'
import { formatBusinessDate } from '@/utils/format'
import DataTable, { type DataTableColumn } from '@/components/DataTable.vue'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import LoadingState from '@/components/LoadingState.vue'
import {
  cityTierLabel,
  formatMoney,
  newIdempotencyKey,
  parseMinorUnitsInput,
  publishTravelPolicy,
  getTravelPolicies,
  type TravelPolicy,
} from '../api'

const props = withDefaults(
  defineProps<{
    canPublish?: boolean
  }>(),
  { canPublish: false },
)

const emit = defineEmits<{ success: [message: string] }>()

const loading = ref(false)
const errorMessage = ref('')
const errorCode = ref('')
const errorRequestId = ref<string | null>(null)
const policies = ref<TravelPolicy[]>([])
const total = ref(0)

const formOpen = ref(false)
const form = reactive({
  cityTier: 'A',
  hotelCapCents: '',
  transportCapCents: '',
  effectiveFrom: '',
})

const submitting = ref(false)
const actionError = ref('')
const submissionKey = ref<string | null>(null)
const submissionFingerprint = ref('')

const columns: DataTableColumn[] = [
  { key: 'version', label: '版本', width: '80px' },
  { key: 'cityTier', label: '城市档位', minWidth: '130px' },
  { key: 'hotelCap', label: '住宿上限', width: '130px', align: 'right' },
  { key: 'transportCap', label: '交通上限', width: '130px', align: 'right' },
  { key: 'effectiveFrom', label: '生效日', width: '130px' },
  { key: 'status', label: '状态', width: '100px' },
]

const hasPolicies = computed(() => policies.value.length > 0)

function captureError(reason: unknown): void {
  if (reason instanceof ApiError) {
    errorMessage.value = reason.message || '差旅政策暂时无法加载。'
    errorCode.value = reason.code
    errorRequestId.value = reason.requestId
    return
  }
  errorMessage.value = '差旅政策暂时无法加载。'
  errorCode.value = 'UNKNOWN'
  errorRequestId.value = null
}

async function load(): Promise<void> {
  loading.value = true
  errorMessage.value = ''
  try {
    const result = await getTravelPolicies()
    policies.value = result.items
    total.value = result.total
  } catch (reason) {
    captureError(reason)
    policies.value = []
    total.value = 0
  } finally {
    loading.value = false
  }
}

function resetForm(): void {
  form.cityTier = 'A'
  form.hotelCapCents = ''
  form.transportCapCents = ''
  form.effectiveFrom = ''
  actionError.value = ''
  submissionKey.value = null
  submissionFingerprint.value = ''
}

function createPolicy(): void {
  if (!props.canPublish) return
  resetForm()
  formOpen.value = true
}

function closeForm(): void {
  if (submitting.value) return
  formOpen.value = false
}

async function submit(): Promise<void> {
  actionError.value = ''
  const hotelCap = parseMinorUnitsInput(form.hotelCapCents)
  const transportCap = form.transportCapCents.trim() === '' ? 0 : parseMinorUnitsInput(form.transportCapCents)

  if (form.cityTier.trim() === '') {
    actionError.value = '请填写城市档位。'
    return
  }
  if (hotelCap === null || transportCap === null) {
    actionError.value = '住宿与交通上限必须是非负整数分，不能输入小数或货币符号。'
    return
  }
  if (!/^\d{4}-\d{2}-\d{2}$/.test(form.effectiveFrom)) {
    actionError.value = '请选择有效的生效日期。'
    return
  }

  const payload = {
    cityTier: form.cityTier.trim().toUpperCase(),
    hotelCapCents: hotelCap,
    transportCapCents: transportCap,
    effectiveFrom: form.effectiveFrom,
  }
  const fingerprint = JSON.stringify(payload)
  if (submissionKey.value === null || submissionFingerprint.value !== fingerprint) {
    submissionKey.value = newIdempotencyKey('travel-policy')
    submissionFingerprint.value = fingerprint
  }

  submitting.value = true
  try {
    const policy = await publishTravelPolicy(payload, submissionKey.value)
    formOpen.value = false
    resetForm()
    emit('success', `差旅政策版本已发布：v${policy.version}。`)
    await load()
  } catch (reason) {
    actionError.value =
      reason instanceof ApiError
        ? `${reason.message || '发布未完成。'}${reason.requestId ? ` 请求编号：${reason.requestId}` : ''}`
        : '发布未完成，请稍后重试。'
  } finally {
    submitting.value = false
  }
}

onMounted(() => {
  void load()
})
</script>

<template>
  <section class="travel-policies" data-testid="travel-policies-panel">
    <header class="travel-policies__header">
      <div>
        <h2>差旅政策</h2>
        <p class="travel-policies__muted">
          政策按城市档位追加版本，生效日必须晚于当前版本；上限金额以整数分保存。
        </p>
      </div>
      <div class="travel-policies__header-actions">
        <span class="travel-policies__badge">{{ total }} 个版本</span>
        <button type="button" class="travel-policies__secondary" data-testid="travel-policies-refresh" @click="load">刷新</button>
        <button v-if="props.canPublish" type="button" class="travel-policies__primary" data-testid="travel-policy-publish" @click="createPolicy">
          发布新版本
        </button>
      </div>
    </header>

    <div v-if="loading" data-testid="travel-policies-loading">
      <LoadingState :rows="4" label="正在加载差旅政策" />
    </div>
    <ErrorState
      v-else-if="errorMessage !== ''"
      data-testid="travel-policies-error"
      title="差旅政策暂时无法加载"
      :description="errorMessage"
      :code="errorCode"
      :request-id="errorRequestId"
      @retry="load"
    />
    <EmptyState
      v-else-if="!hasPolicies"
      data-testid="travel-policies-unconfigured"
      title="差旅政策尚未配置"
      description="服务端没有返回任何政策版本。页面不会伪造限额；请由具备政策管理能力的角色发布首版。"
    />
    <DataTable
      v-else
      :columns="columns"
      :rows="policies as unknown as Array<Record<string, unknown>>"
      :total="total"
      :page="1"
      :page-size="Math.max(1, policies.length)"
      row-key="id"
      data-testid="travel-policy-table"
      empty-title="差旅政策尚未配置"
    >
      <template #cell-version="{ row }">v{{ row.version }}</template>
      <template #cell-cityTier="{ row }">{{ cityTierLabel(String(row.cityTier)) }}</template>
      <template #cell-hotelCap="{ row }">
        <span class="mono">{{ formatMoney(Number(row.hotelCapCents)) }}</span>
      </template>
      <template #cell-transportCap="{ row }">
        <span class="mono">{{ formatMoney(Number(row.transportCapCents)) }}</span>
      </template>
      <template #cell-effectiveFrom="{ row }">{{ formatBusinessDate(String(row.effectiveFrom)) }}</template>
      <template #cell-status="{ row }">{{ String(row.status) }}</template>
    </DataTable>

    <div v-if="formOpen" class="travel-policies__drawer" role="dialog" aria-modal="true" data-testid="travel-policy-form">
      <section class="travel-policies__drawer-panel">
        <header class="travel-policies__drawer-header">
          <h3>发布差旅政策版本</h3>
          <button type="button" class="travel-policies__tertiary" data-testid="travel-policy-close" @click="closeForm">关闭</button>
        </header>
        <form class="travel-policies__form" @submit.prevent="submit">
          <label>
            <span>城市档位</span>
            <input v-model="form.cityTier" data-testid="travel-policy-city-tier" maxlength="8" required />
          </label>
          <label>
            <span>生效日期</span>
            <input v-model="form.effectiveFrom" data-testid="travel-policy-effective-from" type="date" required />
          </label>
          <label>
            <span>住宿上限（整数分）</span>
            <input v-model="form.hotelCapCents" data-testid="travel-policy-hotel-cents" inputmode="numeric" required />
          </label>
          <label>
            <span>交通上限（整数分）</span>
            <input v-model="form.transportCapCents" data-testid="travel-policy-transport-cents" inputmode="numeric" />
          </label>
          <p v-if="actionError" class="travel-policies__error" role="alert" data-testid="travel-policy-error">{{ actionError }}</p>
          <footer class="travel-policies__form-actions">
            <button type="button" class="travel-policies__secondary" :disabled="submitting" @click="closeForm">取消</button>
            <button type="submit" class="travel-policies__primary" :disabled="submitting" data-testid="travel-policy-submit">
              {{ submitting ? '发布中…' : '发布版本' }}
            </button>
          </footer>
        </form>
      </section>
    </div>
  </section>
</template>

<style scoped>
.travel-policies { display: grid; gap: 14px; }
.travel-policies__header { display: flex; justify-content: space-between; gap: 16px; align-items: flex-start; }
.travel-policies__header h2 { margin: 0; font-size: 18px; }
.travel-policies__muted { margin: 3px 0 0; color: #64748b; font-size: 11.5px; }
.travel-policies__header-actions { display: flex; gap: 8px; align-items: center; }
.travel-policies__badge { padding: 3px 8px; color: #1d4ed8; font-size: 10.5px; background: #eff6ff; border-radius: 999px; }
.travel-policies__drawer { position: fixed; inset: 0; z-index: 34; display: flex; justify-content: flex-end; background: rgb(15 23 42 / 35%); }
.travel-policies__drawer-panel { width: min(480px, 100vw); height: 100%; overflow-y: auto; background: #fff; box-shadow: -8px 0 24px rgb(15 23 42 / 18%); }
.travel-policies__drawer-header { display: flex; justify-content: space-between; gap: 14px; padding: 18px 20px; border-bottom: 1px solid #e2e8f0; }
.travel-policies__drawer-header h3 { margin: 0; font-size: 17px; }
.travel-policies__form { display: grid; gap: 12px; padding: 18px 20px; }
.travel-policies__form label { display: flex; flex-direction: column; gap: 4px; font-size: 12px; }
.travel-policies__form input { padding: 8px 10px; font: inherit; border: 1px solid #cbd5e1; border-radius: 7px; }
.travel-policies__form-actions { display: flex; justify-content: flex-end; gap: 8px; }
.travel-policies__primary, .travel-policies__secondary, .travel-policies__tertiary { padding: 6px 11px; font-size: 11.5px; border-radius: 6px; cursor: pointer; }
.travel-policies__primary { color: #fff; background: #1d4ed8; border: 1px solid #1d4ed8; }
.travel-policies__secondary { color: #334155; background: #fff; border: 1px solid #cbd5e1; }
.travel-policies__tertiary { color: #1d4ed8; background: transparent; border: 1px solid #bfdbfe; }
.travel-policies__error { margin: 0; padding: 9px 11px; color: #991b1b; font-size: 11.5px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; }
.mono { font-family: ui-monospace, monospace; }
</style>
