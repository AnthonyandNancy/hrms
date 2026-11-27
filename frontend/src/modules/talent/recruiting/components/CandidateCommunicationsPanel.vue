<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ApiError, errorCopy } from '@/api/errors'
import { formatDateTimeInTimezone } from '@/utils/format'
import { createCommunication, listCommunications, type Communication } from '../api'

const props = defineProps<{
  candidateId: number
  canManage: boolean
}>()

const rows = ref<Communication[]>([])
const loading = ref(false)
const error = ref('')
const formError = ref('')
const saving = ref(false)
const form = reactive({
  channel: 'EMAIL',
  direction: 'OUTBOUND',
  subject: '',
  body: '',
  consentBasis: '',
})

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    rows.value = await listCommunications(props.candidateId)
  } catch (failure) {
    rows.value = []
    error.value = failure instanceof ApiError && failure.message !== '' ? failure.message : '沟通记录加载失败。'
  } finally {
    loading.value = false
  }
}

async function submit(): Promise<void> {
  formError.value = ''
  if (form.body.trim() === '' || form.consentBasis.trim() === '') {
    formError.value = '请填写沟通正文和同意依据。'
    return
  }

  saving.value = true
  try {
    await createCommunication(props.candidateId, {
      channel: form.channel,
      direction: form.direction,
      subject: form.subject.trim() || null,
      body: form.body.trim(),
      consentBasis: form.consentBasis.trim(),
    })
    form.subject = ''
    form.body = ''
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
  <section data-testid="candidate-tab-communications">
    <h3>沟通记录</h3>
    <p v-if="loading" class="panel-muted">正在加载沟通记录。</p>
    <p v-else-if="error !== ''" class="panel-error" role="alert">{{ error }}</p>
    <p v-else-if="rows.length === 0" class="panel-muted">还没有沟通记录；正文属于敏感字段，读取会记录敏感访问审计。</p>
    <ul v-else class="panel-list">
      <li v-for="row in rows" :key="row.id" :data-testid="`communication-row-${row.id}`">
        <strong>{{ row.channel }} · {{ row.direction }}</strong>
        <span v-if="row.subject !== null">{{ row.subject }}</span>
        <span>{{ row.body }}</span>
        <span class="panel-muted">同意依据：{{ row.consentBasis }} · {{ formatDateTimeInTimezone(row.recordedAt) }}</span>
      </li>
    </ul>

    <form v-if="canManage" class="panel-form" @submit.prevent="submit">
      <h4>记录沟通</h4>
      <label>
        <span>渠道</span>
        <el-select v-model="form.channel" data-testid="communication-channel">
          <el-option value="EMAIL" label="邮件" />
          <el-option value="PHONE" label="电话" />
          <el-option value="SMS" label="短信" />
          <el-option value="WECHAT" label="微信" />
          <el-option value="IN_PERSON" label="面谈" />
          <el-option value="OTHER" label="其他" />
        </el-select>
      </label>
      <label>
        <span>方向</span>
        <el-select v-model="form.direction" data-testid="communication-direction">
          <el-option value="OUTBOUND" label="发出" />
          <el-option value="INBOUND" label="收到" />
        </el-select>
      </label>
      <label>
        <span>主题（可选）</span>
        <el-input v-model="form.subject" data-testid="communication-subject" maxlength="200" />
      </label>
      <label>
        <span>正文</span>
        <el-input v-model="form.body" data-testid="communication-body" type="textarea" :rows="3" maxlength="10000" />
      </label>
      <label>
        <span>同意依据</span>
        <el-input v-model="form.consentBasis" data-testid="communication-consent" maxlength="64" placeholder="例如 APPLICATION" />
      </label>
      <p v-if="formError !== ''" class="panel-error" role="alert">{{ formError }}</p>
      <button type="submit" class="panel-primary" :disabled="saving" data-testid="communication-submit">
        {{ saving ? '提交中…' : '保存沟通记录' }}
      </button>
    </form>
  </section>
</template>

<style scoped>
.panel-muted { color: #64748b; font-size: 12px; }
.panel-error { color: #b91c1c; font-size: 12px; }
.panel-list { display: flex; flex-direction: column; gap: 8px; margin: 0; padding: 0; list-style: none; }
.panel-list li { display: flex; flex-direction: column; gap: 3px; padding: 9px 10px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 12.5px; }
.panel-form { display: flex; flex-direction: column; gap: 10px; max-width: 520px; margin-top: 16px; }
.panel-form h4 { margin: 0; font-size: 13px; }
.panel-form label { display: flex; flex-direction: column; gap: 4px; color: #475569; font-size: 12px; }
.panel-primary { align-self: flex-start; height: 32px; padding: 0 12px; border: 1px solid #1d4ed8; border-radius: 7px; background: #1d4ed8; color: #fff; cursor: pointer; }
.panel-primary:disabled { opacity: 0.55; cursor: not-allowed; }
</style>
