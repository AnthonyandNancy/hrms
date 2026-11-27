<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import axios, { AxiosError } from 'axios'
import { ApiError, errorCopy } from '@/api/errors'
import { resourceDelete, resourceGet } from '@/api/resourceClient'
import { requestIdFromError } from '@/utils/renderErrors'
import ActionGate from '@/components/ActionGate.vue'
import DataTable, { type DataTableColumn } from '@/components/DataTable.vue'
import ErrorState from '@/components/ErrorState.vue'
import { nextIdempotencyKey } from '@/modules/people/peopleRead'
import { useSessionStore } from '@/stores/session'

const props = defineProps<{ employmentId: number }>()

defineOptions({ name: 'EmployeeFileList' })

const MAX_UPLOAD_BYTES = 20 * 1024 * 1024
const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx'] as const
const ALLOWED_MIME = new Set([
  'application/pdf',
  'image/jpeg',
  'image/png',
  'application/msword',
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
  'application/vnd.ms-excel',
  'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
  'application/octet-stream',
])

const DOCUMENT_CATEGORIES = [
  { value: 'CONTRACT', label: '合同' },
  { value: 'ID', label: '身份证明' },
  { value: 'EDUCATION', label: '教育材料' },
  { value: 'CERTIFICATE', label: '证照材料' },
  { value: 'OTHER', label: '其他' },
] as const

type FileRow = {
  id: number
  original_name: string
  category: string
  version: number | null
  size_bytes: number | null
  mime: string | null
  uploaded_by: number | null
  uploaded_by_name: string | null
  uploaded_at: string | null
  legal_hold: boolean | null
}

type UploadedFileInput = {
  file: File | null
  category: string
  title: string
  retentionClass: string
  progress: number
  uploading: boolean
  localError: string
}

const files = ref<FileRow[]>([])
const total = ref(0)
const loading = ref(false)
const forbidden = ref(false)
const error = ref<string | null>(null)
const requestId = ref<string | null>(null)
const busyId = ref<number | null>(null)

const upload = ref<UploadedFileInput>({
  file: null,
  category: 'OTHER',
  title: '',
  retentionClass: 'GENERAL_2Y',
  progress: 0,
  uploading: false,
  localError: '',
})

const columns: DataTableColumn[] = [
  { key: 'display_name', label: '文件名' },
  { key: 'display_size', label: '大小' },
  { key: 'display_uploaded_by', label: '上传人' },
  { key: 'display_uploaded_at', label: '上传时间' },
  { key: 'display_actions', label: '操作', width: '160px' },
]

const HIDDEN = '无权查看'

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function nullableText(value: unknown): string | null {
  return typeof value === 'string' && value.trim() !== '' ? value : null
}

function nullableInteger(value: unknown): number | null {
  if (typeof value === 'number' && Number.isSafeInteger(value)) return value
  return null
}

function categoryLabel(value: string): string {
  return DOCUMENT_CATEGORIES.find((entry) => entry.value === value)?.label ?? '未知分类'
}

function normaliseRow(row: Record<string, unknown>): FileRow | null {
  const id = nullableInteger(row.id)
  if (id === null || id <= 0) return null
  return {
    id,
    original_name: typeof row.original_name === 'string' ? row.original_name : '',
    category: typeof row.category === 'string' ? row.category : 'OTHER',
    version: nullableInteger(row.version),
    size_bytes: nullableInteger(row.size_bytes),
    mime: nullableText(row.mime),
    uploaded_by: nullableInteger(row.uploaded_by),
    uploaded_by_name: nullableText(row.uploaded_by_name),
    // Only the explicit upload timestamp is mapped. `created_at` is a
    // different contract field and must not be presented as the upload time.
    uploaded_at: nullableText(row.uploaded_at),
    legal_hold: typeof row.legal_hold === 'boolean' ? row.legal_hold : null,
  }
}

function sizeLabel(value: number | null): string {
  if (value === null || value < 0) return HIDDEN
  if (value === 0) return '0 B'
  if (value < 1024) return `${value} B`
  if (value < 1024 * 1024) return `${(value / 1024).toFixed(1)} KB`
  return `${(value / (1024 * 1024)).toFixed(1)} MB`
}

function dateTimeLabel(value: string | null): string {
  if (value === null) return HIDDEN
  const date = value.slice(0, 10)
  if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) return HIDDEN
  const [year, month, day] = date.split('-')
  const base = `${year}年${Number(month)}月${Number(day)}日`
  const time = value.slice(11, 16)
  return /^\d{2}:\d{2}$/.test(time) ? `${base} ${time}` : base
}

const displayRows = computed<Record<string, unknown>[]>(() =>
  files.value.map((file) => ({
    id: file.id,
    display_name: file.original_name.trim() === '' ? HIDDEN : file.original_name,
    display_size: sizeLabel(file.size_bytes),
    display_uploaded_by:
      file.uploaded_by_name !== null
        ? file.uploaded_by_name
        : file.uploaded_by !== null
          ? `用户 #${file.uploaded_by}`
          : HIDDEN,
    display_uploaded_at: dateTimeLabel(file.uploaded_at),
    display_actions: '',
  })),
)

function rejectState(reason: unknown): void {
  if (reason instanceof ApiError && (reason.status === 403 || reason.code === 'FORBIDDEN')) {
    forbidden.value = true
    return
  }
  error.value = errorCopy(reason)
  requestId.value = requestIdFromError(reason)
}

async function reload(showLoading = true): Promise<void> {
  if (showLoading) loading.value = true
  forbidden.value = false
  error.value = null
  requestId.value = null

  try {
    const payload = await resourceGet<unknown>(
      `/v1/employments/${props.employmentId}/document-files`,
      { page: 1, page_size: 20 },
    )
    const items = isRecord(payload) && Array.isArray(payload.items) ? payload.items : []
    files.value = items
      .filter(isRecord)
      .map(normaliseRow)
      .filter((row): row is FileRow => row !== null)
    total.value = isRecord(payload) && typeof payload.total === 'number' ? payload.total : files.value.length
  } catch (reason) {
    files.value = []
    total.value = 0
    rejectState(reason)
  } finally {
    loading.value = false
  }
}

function extensionOf(name: string): string {
  const index = name.lastIndexOf('.')
  return index < 0 ? '' : name.slice(index + 1).toLowerCase()
}

function validateFile(file: File): string {
  const extension = extensionOf(file.name)
  if (!ALLOWED_EXTENSIONS.includes(extension as (typeof ALLOWED_EXTENSIONS)[number])) {
    return '不支持该文件类型。仅允许 PDF、图片（JPG/PNG）、Word 和 Excel 文件。'
  }
  if (file.type !== '' && !ALLOWED_MIME.has(file.type)) {
    return '不支持该文件类型。仅允许 PDF、图片（JPG/PNG）、Word 和 Excel 文件。'
  }
  if (file.size > MAX_UPLOAD_BYTES) {
    return '文件超过 20 MB 上限，请压缩或拆分后再上传。'
  }
  if (file.size === 0) {
    return '不能上传空文件。'
  }
  return ''
}

function chooseFile(event: Event): void {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0] ?? null
  upload.value.file = file
  upload.value.progress = 0
  upload.value.localError = file === null ? '' : validateFile(file)
}

function uploadFailure(error: unknown): ApiError {
  const axiosError = error as AxiosError<{
    code?: string
    message?: string
    data?: { reason?: string; fields?: Record<string, string[]> }
    request_id?: string
  }>
  if (!axiosError.response) {
    return new ApiError('NETWORK_ERROR', '网络连接失败', { status: 0 })
  }
  const body = axiosError.response.data
  return new ApiError((body?.code ?? 'INTERNAL') as ApiError['code'], body?.message ?? '附件上传未完成', {
    status: axiosError.response.status,
    requestId: body?.request_id ?? null,
    fieldErrors: body?.data?.fields ?? {},
    details: body?.data ?? {},
  })
}

/**
 * Upload uses multipart directly because the shared JSON resource client
 * cannot stream a browser File. The short-lived UI progress is read from the
 * real XHR upload events; the server remains authoritative for validation.
 */
async function performUpload(file: File): Promise<void> {
  const token = useSessionStore().csrfToken
  const client = axios.create({
    baseURL: import.meta.env.VITE_API_BASE_URL ?? '/v1',
    withCredentials: true,
    timeout: 120_000,
    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
  })
  const body = new FormData()
  body.append('category', upload.value.category)
  body.append('retention_class', upload.value.retentionClass)
  body.append('file', file)

  try {
    await client.post('/employments/' + props.employmentId + '/document-files', body, {
      headers: {
        ...(token ? { 'X-CSRF-Token': token } : {}),
        'Idempotency-Key': nextIdempotencyKey('document-upload'),
      },
      onUploadProgress: (event) => {
        if (typeof event.total === 'number' && event.total > 0) {
          upload.value.progress = Math.min(100, Math.round((event.loaded / event.total) * 100))
        }
      },
    })
    upload.value.progress = 100
  } catch (error) {
    throw uploadFailure(error)
  }
}

async function submitUpload(): Promise<void> {
  upload.value.localError = ''
  const file = upload.value.file
  if (file === null) {
    upload.value.localError = '请选择要上传的文件。'
    return
  }
  const invalid = validateFile(file)
  if (invalid !== '') {
    upload.value.localError = invalid
    return
  }

  upload.value.uploading = true
  upload.value.progress = 0
  try {
    await performUpload(file)
    ElMessage.success('附件已上传。')
    upload.value = {
      file: null,
      category: 'OTHER',
      title: '',
      retentionClass: 'GENERAL_2Y',
      progress: 0,
      uploading: false,
      localError: '',
    }
    await reload(false)
  } catch (reason) {
    upload.value.localError = errorCopy(reason)
  } finally {
    upload.value.uploading = false
  }
}

type DownloadTicket = {
  url: string
  expiresInMinutes: number | null
}

async function downloadById(id: number): Promise<void> {
  const file = files.value.find((item) => item.id === id)
  if (file === undefined) return
  if (busyId.value !== null) return
  busyId.value = file.id
  try {
    const ticket = await resourceGet<DownloadTicket>(`/v1/documents/${file.id}/signed-download`)
    // Never synthesise a download address. If the API did not return a usable
    // URL, the action is reported as unavailable instead of guessing one.
    if (typeof ticket.url !== 'string' || ticket.url.trim() === '') {
      ElMessage.error('接口未返回可用的下载地址，本次下载未开始。')
      return
    }
    const anchor = document.createElement('a')
    anchor.href = ticket.url
    anchor.rel = 'noopener'
    anchor.download = file.original_name
    document.body.append(anchor)
    anchor.click()
    anchor.remove()
    ElMessage.success('已通过短效签名地址开始下载。')
  } catch (reason) {
    ElMessage.error(errorCopy(reason))
  } finally {
    busyId.value = null
  }
}

async function removeById(id: number): Promise<void> {
  const file = files.value.find((item) => item.id === id)
  if (file === undefined) return
  if (busyId.value !== null) return
  try {
    await ElMessageBox.confirm(
      `确认删除附件「${file.original_name}」？删除后不可恢复，受法律保留保护的附件会被服务端拒绝。`,
      '删除附件',
      { confirmButtonText: '确认删除', cancelButtonText: '取消', type: 'warning' },
    )
  } catch {
    return
  }

  busyId.value = file.id
  try {
    await resourceDelete(`/v1/documents/${file.id}`, {
      idempotencyKey: nextIdempotencyKey('document-delete'),
    })
    ElMessage.success('附件已删除。')
    await reload(false)
  } catch (reason) {
    ElMessage.error(errorCopy(reason))
  } finally {
    busyId.value = null
  }
}

onMounted(() => {
  void reload()
})

watch(
  () => props.employmentId,
  () => {
    upload.value.localError = ''
    void reload()
  },
)
</script>

<template>
  <section class="file-list" data-testid="employee-file-list">
    <header class="file-list__header">
      <div>
        <h3>员工附件</h3>
        <p>下载仅使用接口返回的短效签名地址；文件名、上传人与上传时间缺省时显示“无权查看”。</p>
      </div>
    </header>

    <section v-if="forbidden" class="file-list__state file-list__state--forbidden" data-testid="file-forbidden">
      <h4>无权查看</h4>
      <p>你没有查看员工附件的权限；员工摘要与其他档案区域仍可使用。</p>
    </section>

    <ErrorState
      v-else-if="error"
      title="员工附件暂时无法加载"
      :description="error"
      :request-id="requestId"
      @retry="reload()"
    />

    <template v-else>
      <DataTable
        :columns="columns"
        :rows="displayRows"
        :total="total"
        :page="1"
        :page-size="20"
        :loading="loading"
        empty-title="暂无员工附件"
      >
        <template #cell-display_actions="{ row }">
          <span class="file-list__actions">
            <ActionGate capability="people.document.download">
              <button
                type="button"
                class="file-list__link"
                :disabled="busyId === Number(row.id)"
                :data-testid="`file-download-${String(row.id)}`"
                @click="downloadById(Number(row.id))"
              >
                下载
              </button>
            </ActionGate>
            <ActionGate capability="people.employee.manage">
              <button
                type="button"
                class="file-list__link file-list__link--danger"
                :disabled="busyId === Number(row.id)"
                :data-testid="`file-delete-${String(row.id)}`"
                @click="removeById(Number(row.id))"
              >
                删除
              </button>
            </ActionGate>
          </span>
        </template>
      </DataTable>

      <ActionGate capability="people.document.read">
        <section class="file-upload" data-testid="file-upload">
          <h4>上传附件</h4>
          <div class="file-upload__row">
            <label class="file-upload__field">
              <span>文件 *</span>
              <input
                type="file"
                data-testid="file-upload-input"
                accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx"
                :disabled="upload.uploading"
                @change="chooseFile"
              >
            </label>
            <label class="file-upload__field">
              <span>分类</span>
              <select v-model="upload.category" data-testid="file-upload-category" :disabled="upload.uploading">
                <option v-for="category in DOCUMENT_CATEGORIES" :key="category.value" :value="category.value">
                  {{ category.label }}
                </option>
              </select>
            </label>
            <label class="file-upload__field">
              <span>保留类别</span>
              <select v-model="upload.retentionClass" data-testid="file-upload-retention" :disabled="upload.uploading">
                <option value="GENERAL_2Y">通用 2 年</option>
                <option value="CONTRACT_7Y">合同 7 年</option>
                <option value="IDENTITY_10Y">身份证明 10 年</option>
              </select>
            </label>
            <button
              type="button"
              class="file-upload__submit"
              :disabled="upload.uploading"
              data-testid="file-upload-submit"
              @click="submitUpload"
            >
              {{ upload.uploading ? '上传中…' : '上传' }}
            </button>
          </div>
          <p v-if="upload.file" class="file-upload__hint">
            已选择：{{ upload.file.name }}（{{ sizeLabel(upload.file.size) }}）
          </p>
          <div v-if="upload.uploading" class="file-upload__progress" role="status" data-testid="file-upload-progress">
            <label>
              上传进度
              <progress :value="upload.progress" max="100">{{ upload.progress }}%</progress>
            </label>
            <span>{{ upload.progress }}%</span>
          </div>
          <p v-if="upload.localError" class="file-upload__error" role="alert" data-testid="file-upload-error">
            {{ upload.localError }}
          </p>
        </section>
      </ActionGate>

      <p class="file-list__retention-note">
        法律保留、保留期限与分类以服务端返回为准；任意分类（{{ categoryLabel('OTHER') }} 等）不会在客户端推断。
      </p>
    </template>
  </section>
</template>

<style scoped>
.file-list {
  padding: 16px 18px;
  background: var(--color-surface, #fff);
  border: 1px solid var(--color-line, #e5e7eb);
  border-radius: var(--radius-md, 10px);
}
.file-list__header h3 {
  margin: 0;
  font-size: 15px;
}
.file-list__header p {
  margin: 5px 0 12px;
  color: var(--color-ink-muted, #6b7280);
  font-size: 12px;
}
.file-list__state {
  padding: 20px;
  text-align: center;
  background: var(--color-canvas, #f9fafb);
  border-radius: 8px;
}
.file-list__state--forbidden {
  color: #991b1b;
  background: #fef2f2;
}
.file-list__actions {
  display: inline-flex;
  gap: 8px;
}
.file-list__link {
  padding: 3px 8px;
  color: var(--color-brand, #2563eb);
  font: inherit;
  font-size: 12px;
  cursor: pointer;
  background: transparent;
  border: 1px solid var(--color-line, #d1d5db);
  border-radius: 5px;
}
.file-list__link--danger {
  color: #b91c1c;
}
.file-upload {
  margin-top: 16px;
  padding: 12px;
  background: var(--color-canvas, #f9fafb);
  border-radius: 8px;
}
.file-upload h4 {
  margin: 0 0 10px;
  font-size: 13px;
}
.file-upload__row {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  align-items: flex-end;
}
.file-upload__field {
  display: flex;
  flex-direction: column;
  gap: 4px;
  color: var(--color-ink-muted, #6b7280);
  font-size: 11px;
}
.file-upload__field input[type='file'] {
  max-width: 240px;
  font-size: 12px;
}
.file-upload__field select {
  padding: 6px 8px;
  font: inherit;
  font-size: 12px;
  border: 1px solid var(--color-line-strong, #d1d5db);
  border-radius: 5px;
}
.file-upload__submit {
  padding: 7px 14px;
  color: #fff;
  font: inherit;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  background: var(--color-brand, #2563eb);
  border: 1px solid var(--color-brand, #2563eb);
  border-radius: 6px;
}
.file-upload__submit:disabled {
  cursor: not-allowed;
  opacity: 0.55;
}
.file-upload__hint,
.file-list__retention-note {
  margin: 8px 0 0;
  color: var(--color-ink-muted, #6b7280);
  font-size: 11px;
}
.file-upload__progress {
  display: flex;
  gap: 10px;
  align-items: center;
  margin-top: 8px;
  color: var(--color-ink-secondary, #4b5563);
  font-size: 12px;
}
.file-upload__progress progress {
  width: 220px;
  height: 8px;
}
.file-upload__error {
  margin: 8px 0 0;
  color: #b91c1c;
  font-size: 12px;
}
</style>
