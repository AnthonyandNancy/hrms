<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ApiError, errorCopy } from '@/api/errors'
import {
  candidateStatusLabel,
  createApplication,
  listApplications,
  listRequisitions,
  type CandidateApplication,
  type Requisition,
} from '../api'

const props = defineProps<{
  candidateId: number
  canManage: boolean
}>()

const emit = defineEmits<{
  changed: []
}>()

const applications = ref<CandidateApplication[]>([])
const requisitions = ref<Requisition[]>([])
const loading = ref(false)
const error = ref('')
const formError = ref('')
const saving = ref(false)
const form = reactive({ requisitionId: '', openingId: '', source: '' })

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const [items, requisitionPage] = await Promise.all([
      listApplications(props.candidateId),
      listRequisitions({}, 1, 100),
    ])
    applications.value = items
    requisitions.value = requisitionPage.items
  } catch (failure) {
    applications.value = []
    error.value = failure instanceof ApiError && failure.message !== '' ? failure.message : '应聘申请加载失败。'
  } finally {
    loading.value = false
  }
}

async function submit(): Promise<void> {
  formError.value = ''
  const requisitionId = Number(form.requisitionId)
  if (!Number.isSafeInteger(requisitionId) || requisitionId <= 0) {
    formError.value = '请选择招聘需求。'
    return
  }

  saving.value = true
  try {
    const openingId = form.openingId === '' ? null : Number(form.openingId)
    await createApplication(props.candidateId, {
      requisitionId,
      openingId: openingId !== null && Number.isSafeInteger(openingId) ? openingId : null,
      source: form.source.trim() || null,
    })
    form.requisitionId = ''
    form.openingId = ''
    form.source = ''
    await load()
    emit('changed')
  } catch (failure) {
    formError.value = failure instanceof ApiError && failure.message !== '' ? failure.message : errorCopy(failure)
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <section data-testid="candidate-tab-applications">
    <h3>应聘申请</h3>
    <p v-if="loading" class="panel-muted">正在加载应聘申请。</p>
    <p v-else-if="error !== ''" class="panel-error" role="alert">{{ error }}</p>
    <p v-else-if="applications.length === 0" class="panel-muted">该候选人还没有应聘申请；每个候选人每个需求只能有一条申请。</p>
    <table v-else class="panel-table">
      <thead><tr><th>申请</th><th>需求</th><th>阶段</th><th>状态</th><th>来源</th></tr></thead>
      <tbody>
        <tr v-for="application in applications" :key="application.id" :data-testid="`application-row-${application.id}`">
          <td>#{{ application.id }}</td>
          <td>{{ application.requisitionNo ?? application.requisitionId }} · {{ application.requisitionTitle ?? '' }}</td>
          <td>{{ application.stage }}</td>
          <td>{{ candidateStatusLabel(application.status) }}</td>
          <td>{{ application.source ?? '—' }}</td>
        </tr>
      </tbody>
    </table>

    <form v-if="canManage" class="panel-form" @submit.prevent="submit">
      <h4>新增应聘申请</h4>
      <label>
        <span>招聘需求</span>
        <el-select v-model="form.requisitionId" data-testid="application-requisition">
          <el-option value="" label="请选择已批准需求" />
          <el-option
            v-for="requisition in requisitions"
            :key="requisition.id"
            :value="String(requisition.id)"
            :label="`${requisition.requisitionNo} · ${requisition.title}（${requisition.statusLabel}）`"
            :disabled="requisition.status !== 'APPROVED'"
          />
        </el-select>
      </label>
      <label>
        <span>岗位开放编号（可选）</span>
        <el-input v-model="form.openingId" data-testid="application-opening" inputmode="numeric" />
      </label>
      <label>
        <span>来源（可选）</span>
        <el-input v-model="form.source" data-testid="application-source" maxlength="32" />
      </label>
      <p v-if="formError !== ''" class="panel-error" role="alert">{{ formError }}</p>
      <button type="submit" class="panel-primary" :disabled="saving" data-testid="application-submit">
        {{ saving ? '提交中…' : '新增申请' }}
      </button>
    </form>
  </section>
</template>

<style scoped>
.panel-muted { color: #64748b; font-size: 12px; }
.panel-error { color: #b91c1c; font-size: 12px; }
.panel-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.panel-table th, .panel-table td { padding: 7px 8px; border-bottom: 1px solid #e2e8f0; text-align: left; }
.panel-form { display: flex; flex-direction: column; gap: 10px; max-width: 460px; margin-top: 16px; }
.panel-form h4 { margin: 0; font-size: 13px; }
.panel-form label { display: flex; flex-direction: column; gap: 4px; color: #475569; font-size: 12px; }
.panel-primary { align-self: flex-start; height: 32px; padding: 0 12px; border: 1px solid #1d4ed8; border-radius: 7px; background: #1d4ed8; color: #fff; cursor: pointer; }
.panel-primary:disabled { opacity: 0.55; cursor: not-allowed; }
</style>
