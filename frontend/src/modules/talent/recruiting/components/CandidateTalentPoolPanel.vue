<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ApiError, errorCopy } from '@/api/errors'
import { formatBusinessDate } from '@/utils/format'
import { createTalentPoolTag, listTalentPoolTags, type TalentPoolTag } from '../api'

const props = defineProps<{
  candidateId: number
  canManage: boolean
}>()

const rows = ref<TalentPoolTag[]>([])
const loading = ref(false)
const error = ref('')
const formError = ref('')
const saving = ref(false)
const form = reactive({ tag: '', reason: '', retentionUntil: '' })

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    rows.value = await listTalentPoolTags(props.candidateId)
  } catch (failure) {
    rows.value = []
    error.value = failure instanceof ApiError && failure.message !== '' ? failure.message : '人才库标签加载失败。'
  } finally {
    loading.value = false
  }
}

async function submit(): Promise<void> {
  formError.value = ''
  if (form.tag.trim() === '') {
    formError.value = '请填写人才库标签。'
    return
  }

  saving.value = true
  try {
    await createTalentPoolTag(props.candidateId, {
      tag: form.tag.trim(),
      reason: form.reason.trim() || null,
      retentionUntil: form.retentionUntil.trim() || null,
    })
    form.tag = ''
    form.reason = ''
    form.retentionUntil = ''
    await load()
  } catch (failure) {
    formError.value = failure instanceof ApiError && failure.message !== '' ? failure.message : errorCopy(failure)
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <section data-testid="candidate-tab-talent-pool">
    <h3>人才库与标签</h3>
    <p v-if="loading" class="panel-muted">正在加载人才库标签。</p>
    <p v-else-if="error !== ''" class="panel-error" role="alert">{{ error }}</p>
    <p v-else-if="rows.length === 0" class="panel-muted">该候选人还没有人才库标签；同一标签不能重复。</p>
    <ul v-else class="panel-list">
      <li v-for="row in rows" :key="row.id" :data-testid="`talent-pool-row-${row.id}`">
        <strong>{{ row.tag }}</strong>
        <span>{{ row.reason ?? '未填写原因' }}</span>
        <span class="panel-muted">
          {{ row.status }}
          <template v-if="row.retentionUntil !== null"> · 保留至 {{ formatBusinessDate(row.retentionUntil) }}</template>
        </span>
      </li>
    </ul>

    <form v-if="canManage" class="panel-form" @submit.prevent="submit">
      <h4>加入人才库</h4>
      <label>
        <span>标签</span>
        <el-input v-model="form.tag" data-testid="talent-pool-tag" maxlength="64" />
      </label>
      <label>
        <span>原因（可选）</span>
        <el-input v-model="form.reason" data-testid="talent-pool-reason" type="textarea" :rows="2" maxlength="500" />
      </label>
      <label>
        <span>保留至（可选）</span>
        <el-input v-model="form.retentionUntil" data-testid="talent-pool-retention" placeholder="2027-12-31" />
      </label>
      <p v-if="formError !== ''" class="panel-error" role="alert">{{ formError }}</p>
      <button type="submit" class="panel-primary" :disabled="saving" data-testid="talent-pool-submit">
        {{ saving ? '提交中…' : '保存标签' }}
      </button>
    </form>
  </section>
</template>

<style scoped>
.panel-muted { color: #64748b; font-size: 12px; }
.panel-error { color: #b91c1c; font-size: 12px; }
.panel-list { display: flex; flex-direction: column; gap: 8px; margin: 0; padding: 0; list-style: none; }
.panel-list li { display: flex; flex-direction: column; gap: 3px; padding: 9px 10px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 12.5px; }
.panel-form { display: flex; flex-direction: column; gap: 10px; max-width: 460px; margin-top: 16px; }
.panel-form h4 { margin: 0; font-size: 13px; }
.panel-form label { display: flex; flex-direction: column; gap: 4px; color: #475569; font-size: 12px; }
.panel-primary { align-self: flex-start; height: 32px; padding: 0 12px; border: 1px solid #1d4ed8; border-radius: 7px; background: #1d4ed8; color: #fff; cursor: pointer; }
.panel-primary:disabled { opacity: 0.55; cursor: not-allowed; }
</style>
