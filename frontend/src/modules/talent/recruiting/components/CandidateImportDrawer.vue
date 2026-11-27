<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { ApiError, errorCopy } from '@/api/errors'
import { importCandidates, type CandidateImportResult, type Requisition } from '../api'

const props = defineProps<{
  modelValue: boolean
  requisitions: Requisition[]
  canImport: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [open: boolean]
  imported: [result: CandidateImportResult]
}>()

const form = reactive({
  requisitionId: '',
  source: 'CSV',
  rows: '',
})
const saving = ref(false)
const error = ref('')
const result = ref<CandidateImportResult | null>(null)

const previewRows = computed(() =>
  form.rows
    .split('\n')
    .map((line) => line.trim())
    .filter((line) => line !== '')
    .map((line) => {
      const [displayName = '', email = '', mobile = '', source = ''] = line.split(',').map((part) => part.trim())
      return {
        display_name: displayName,
        ...(email !== '' ? { email } : {}),
        ...(mobile !== '' ? { mobile } : {}),
        ...(source !== '' ? { source } : {}),
      }
    }),
)

function reset(): void {
  form.requisitionId = ''
  form.source = 'CSV'
  form.rows = ''
  error.value = ''
  result.value = null
}

async function submit(): Promise<void> {
  if (!props.canImport) return
  error.value = ''

  if (previewRows.value.length === 0) {
    error.value = '请至少填写一行候选人：姓名,邮箱,手机,来源。'
    return
  }

  saving.value = true
  try {
    result.value = await importCandidates({
      requisitionId: form.requisitionId === '' ? null : Number(form.requisitionId),
      source: form.source.trim() || 'CSV',
      rows: previewRows.value,
    })
    emit('imported', result.value)
  } catch (failure) {
    error.value = failure instanceof ApiError && failure.message !== '' ? failure.message : errorCopy(failure)
  } finally {
    saving.value = false
  }
}

function close(): void {
  if (saving.value) return
  emit('update:modelValue', false)
  reset()
}
</script>

<template>
  <el-drawer
    :model-value="modelValue"
    size="620px"
    title="导入简历"
    data-testid="candidate-import-drawer"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <form class="import-form" @submit.prevent="submit">
      <p class="import-form__hint">
        每行一名候选人，格式：姓名,邮箱,手机,来源。空字段留空；服务端逐行返回接受或拒绝原因，不会静默丢弃。
      </p>
      <label>
        <span>关联招聘需求（可选）</span>
        <el-select v-model="form.requisitionId" data-testid="candidate-import-requisition">
          <el-option value="" label="不关联需求" />
          <el-option
            v-for="requisition in requisitions"
            :key="requisition.id"
            :value="String(requisition.id)"
            :label="`${requisition.requisitionNo} · ${requisition.title}`"
          />
        </el-select>
      </label>
      <label>
        <span>来源</span>
        <el-input v-model="form.source" data-testid="candidate-import-source" maxlength="32" />
      </label>
      <label>
        <span>候选人行</span>
        <el-input
          v-model="form.rows"
          data-testid="candidate-import-rows"
          type="textarea"
          :rows="6"
          placeholder="合成候选人, synthetic@example.test, , CSV"
        />
      </label>
      <p v-if="error !== ''" class="import-form__error" role="alert">{{ error }}</p>
      <footer>
        <button type="button" class="import-form__secondary" :disabled="saving" @click="close">取消</button>
        <button type="submit" class="import-form__primary" :disabled="saving || !canImport" data-testid="candidate-import-submit">
          {{ saving ? '导入中…' : '开始导入' }}
        </button>
      </footer>
    </form>

    <section v-if="result !== null" class="import-result" data-testid="candidate-import-result">
      <h3>导入结果</h3>
      <p>共 {{ result.rowCount }} 行，接受 {{ result.acceptedCount }} 行，拒绝 {{ result.rejectedCount }} 行。</p>
      <table>
        <thead><tr><th>行号</th><th>状态</th><th>原因</th><th>候选人</th></tr></thead>
        <tbody>
          <tr v-for="row in result.rows" :key="row.rowNumber">
            <td>{{ row.rowNumber }}</td>
            <td>{{ row.status }}</td>
            <td>{{ row.reason ?? '—' }}</td>
            <td>{{ row.candidateId ?? '—' }}</td>
          </tr>
        </tbody>
      </table>
    </section>
  </el-drawer>
</template>

<style scoped>
.import-form { display: flex; flex-direction: column; gap: 12px; }
.import-form label { display: flex; flex-direction: column; gap: 5px; color: #334155; font-size: 12.5px; }
.import-form__hint { margin: 0; color: #64748b; font-size: 12px; line-height: 1.6; }
.import-form footer { display: flex; justify-content: flex-end; gap: 8px; }
.import-form__primary, .import-form__secondary { height: 32px; padding: 0 12px; border: 1px solid #cbd5e1; border-radius: 7px; background: #fff; cursor: pointer; }
.import-form__primary { border-color: #1d4ed8; background: #1d4ed8; color: #fff; }
.import-form__error { color: #b91c1c; font-size: 12px; }
.import-result { margin-top: 18px; font-size: 12px; }
.import-result table { width: 100%; border-collapse: collapse; }
.import-result th, .import-result td { padding: 6px 8px; border-bottom: 1px solid #e2e8f0; text-align: left; }
</style>
