<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import LoadingState from '@/components/LoadingState.vue'
import PageShell from '@/shared/ui/PageShell.vue'
import { ApiError, errorCopy } from '@/api/errors'
import { useSessionStore } from '@/stores/session'
import { requestIdFromError } from '@/utils/renderErrors'
import { formatBusinessDate, todayBusinessDate } from '@/utils/format'
import ContractFormDrawer from '../components/ContractFormDrawer.vue'
import ContractVersionList from '../components/ContractVersionList.vue'
import {
  CONTRACT_STATUS_TONES,
  activateContract,
  amendContract,
  contractTypeLabel,
  getContract,
  isForbidden,
  listContractAttachments,
  listContractVersions,
  signedDownloadUrl,
  terminateContract,
  type Contract,
  type ContractAmendmentInput,
  type ContractAttachment,
  type ContractVersion,
} from '../api'

defineOptions({ name: 'ContractDetailPage' })

type LoadState = 'idle' | 'loading' | 'ready' | 'error' | 'forbidden' | 'empty'

const route = useRoute()
const router = useRouter()
const session = useSessionStore()

const canAmend = computed(() => session.can('people.contract.amend'))
const canTerminate = computed(() => session.can('people.contract.terminate'))
const canActivate = computed(
  () =>
    session.can('people.contract.manage') &&
    contract.value !== null &&
    contract.value.display_status === 'DRAFT',
)

const state = ref<LoadState>('idle')
const contract = ref<Contract | null>(null)
const versions = ref<ContractVersion[]>([])
const versionsLoading = ref(false)
const versionsError = ref('')
const versionsPartial = ref('')
const attachments = ref<ContractAttachment[]>([])
const attachmentsLoading = ref(false)
const attachmentsError = ref('')
const attachmentsPartial = ref('')
const pageError = ref<unknown>(null)
const asOf = ref(todayBusinessDate())

const editOpen = ref(false)
const saving = ref(false)
const saveError = ref<ApiError | null>(null)

const terminateOpen = ref(false)
const terminateDate = ref('')
const terminateReason = ref('')
const terminating = ref(false)
const terminateError = ref('')

const activateOpen = ref(false)
const activateDate = ref('')
const activateReason = ref('')
const activating = ref(false)
const activateError = ref('')

let requestSequence = 0

const contractId = computed(() => {
  const raw = route.params.id
  if (typeof raw !== 'string' || !/^\d+$/.test(raw)) return null
  const id = Number(raw)
  return Number.isSafeInteger(id) && id > 0 ? id : null
})

const loading = computed(() => state.value === 'loading')
const forbidden = computed(() => state.value === 'forbidden')
const errorMessage = computed(() =>
  pageError.value === null ? '劳动合同详情暂时无法加载。' : errorCopy(pageError.value),
)
const errorRequestId = computed(() =>
  pageError.value === null ? null : requestIdFromError(pageError.value),
)

const employeeIdentity = computed(() => {
  if (contract.value === null) return ''
  const name = contract.value.employee_name ?? `员工 #${contract.value.employment_id}`
  return contract.value.employee_no === null ? name : `${name}（${contract.value.employee_no}）`
})

const periodLabel = computed(() =>
  contract.value === null
    ? '—'
    : `${formatBusinessDate(contract.value.start_date)} 至 ${
        contract.value.end_date === null ? '无固定期限' : formatBusinessDate(contract.value.end_date)
      }`,
)

const typeLabel = computed(() =>
  contract.value === null ? '—' : contractTypeLabel(contract.value.contract_type),
)

const isTerminable = computed(() => {
  const current = contract.value
  return current !== null && (current.display_status === 'EFFECTIVE' || current.display_status === 'EXPIRING')
})

const canEditCurrent = computed(() => canAmend.value && contract.value !== null && isTerminable.value)

async function load(): Promise<void> {
  const id = contractId.value
  if (id === null) {
    state.value = 'empty'
    return
  }

  const sequence = ++requestSequence
  state.value = 'loading'
  pageError.value = null
  contract.value = null
  versions.value = []
  attachments.value = []

  try {
    const employmentIdRaw = route.query.employment_id
    const employmentId = typeof employmentIdRaw === 'string' && /^\d+$/.test(employmentIdRaw)
      ? Number(employmentIdRaw)
      : null
    if (employmentId === null) {
      // The live API is employment-scoped. A deep link without the employee
      // relation cannot identify the contract safely; never scan a ledger page.
      state.value = 'empty'
      return
    }
    const found = await getContract(employmentId, id, asOf.value)
    if (sequence !== requestSequence) return
    if (found === null) {
      state.value = 'empty'
      return
    }
    contract.value = found
    state.value = 'ready'
    void loadVersions(id)
    void loadAttachments(found.employment_id)
  } catch (error) {
    if (sequence !== requestSequence) return
    if (isForbidden(error)) {
      state.value = 'forbidden'
      return
    }
    pageError.value = error
    state.value = 'error'
  }
}

async function loadVersions(id: number): Promise<void> {
  versionsLoading.value = true
  versionsError.value = ''
  versionsPartial.value = ''
  try {
    const page = await listContractVersions(id)
    versions.value = page.items
    versionsPartial.value = page.partial ? page.partial_reason ?? '版本历史只返回了部分记录。' : ''
  } catch (error) {
    versions.value = []
    versionsError.value = isForbidden(error) ? '无权查看版本历史。' : errorCopy(error)
  } finally {
    versionsLoading.value = false
  }
}

async function loadAttachments(employmentId: number): Promise<void> {
  attachmentsLoading.value = true
  attachmentsError.value = ''
  attachmentsPartial.value = ''
  try {
    const page = await listContractAttachments(employmentId)
    attachments.value = page.items
    attachmentsPartial.value = page.partial ? page.partial_reason ?? '附件列表只返回了部分记录。' : ''
  } catch (error) {
    attachments.value = []
    attachmentsError.value = isForbidden(error) ? '无权查看合同附件。' : errorCopy(error)
  } finally {
    attachmentsLoading.value = false
  }
}

function backToList(): void {
  void router.push('/hr/contracts')
}

function openEdit(): void {
  if (!canEditCurrent.value) return
  saveError.value = null
  editOpen.value = true
}

async function amend(input: { effectiveDate: string; reason: string; changes: ContractAmendmentInput }): Promise<void> {
  if (contract.value === null) return
  saving.value = true
  saveError.value = null
  try {
    await amendContract(contract.value.id, input.effectiveDate, input.reason, input.changes)
    ElMessage.success('合同变更已追加为新的版本，历史记录保留。')
    editOpen.value = false
    await load()
    await loadVersions(contract.value.id)
  } catch (error) {
    saveError.value = error instanceof ApiError ? error : null
    if (saveError.value === null) ElMessage.error(errorCopy(error))
  } finally {
    saving.value = false
  }
}

function openTerminate(): void {
  if (!canTerminate.value || contract.value === null || !isTerminable.value) return
  terminateDate.value = asOf.value
  terminateReason.value = ''
  terminateError.value = ''
  terminateOpen.value = true
}

function openActivate(): void {
  if (!canActivate.value) return
  activateDate.value = asOf.value
  activateReason.value = ''
  activateError.value = ''
  activateOpen.value = true
}

async function confirmActivate(): Promise<void> {
  if (contract.value === null) return
  activateError.value = ''
  if (!/^\d{4}-\d{2}-\d{2}$/.test(activateDate.value)) {
    activateError.value = '请填写激活生效日期。'
    return
  }
  if (activateReason.value.trim() !== '' && activateReason.value.trim().length < 3) {
    activateError.value = '激活原因至少需要 3 个字符，或留空。'
    return
  }

  const contractId = contract.value.id
  activating.value = true
  try {
    await activateContract(contractId, activateDate.value, activateReason.value)
    ElMessage.success('合同已激活，生效日期与版本历史已写入。')
    activateOpen.value = false
    await load()
    await loadVersions(contractId)
  } catch (error) {
    activateError.value = errorCopy(error)
  } finally {
    activating.value = false
  }
}

async function confirmTerminate(): Promise<void> {
  if (contract.value === null) return
  terminateError.value = ''
  if (!/^\d{4}-\d{2}-\d{2}$/.test(terminateDate.value)) {
    terminateError.value = '请填写终止生效日期。'
    return
  }
  if (terminateReason.value.trim().length < 3) {
    terminateError.value = '请填写终止原因（至少 3 个字符）。'
    return
  }

  terminating.value = true
  try {
    await terminateContract(contract.value.id, terminateDate.value, terminateReason.value)
    ElMessage.success('合同已终止，终止版本已写入历史。')
    terminateOpen.value = false
    await load()
    await loadVersions(contract.value.id)
  } catch (error) {
    terminateError.value = errorCopy(error)
  } finally {
    terminating.value = false
  }
}

async function downloadAttachment(attachment: ContractAttachment): Promise<void> {
  attachmentsError.value = ''
  try {
    const url = await signedDownloadUrl(attachment.id)
    if (url === null) {
      attachmentsError.value = '下载链接未返回，请联系管理员检查文件服务。'
      return
    }
    window.open(url, '_blank', 'noopener')
  } catch (error) {
    attachmentsError.value = errorCopy(error)
  }
}

function formatBytes(value: number | null): string {
  if (value === null || value < 0) return '—'
  if (value < 1024) return `${value} B`
  if (value < 1024 * 1024) return `${(value / 1024).toFixed(1)} KB`
  return `${(value / 1024 / 1024).toFixed(1)} MB`
}

watch(
  () => route.params.id,
  () => {
    void load()
  },
)

onMounted(load)
onBeforeUnmount(() => {
  requestSequence += 1
})
</script>

<template>
  <PageShell
    :title="contract === null ? '劳动合同详情' : contract.contract_no"
    :description="contract === null ? '查看合同版本时间线与附件。' : `${employeeIdentity} · ${typeLabel} · ${periodLabel}`"
  >
    <template #actions>
      <el-button data-testid="contract-back" @click="backToList">返回台账</el-button>
      <el-button
        v-if="canActivate"
        type="primary"
        data-testid="contract-activate"
        @click="openActivate"
      >
        激活合同
      </el-button>
      <el-button v-if="canEditCurrent" data-testid="contract-amend" @click="openEdit">变更合同</el-button>
      <el-button
        v-if="canTerminate && isTerminable"
        type="danger"
        plain
        data-testid="contract-terminate"
        @click="openTerminate"
      >
        终止合同
      </el-button>
    </template>

    <section
      v-if="forbidden"
      class="state-panel error-state"
      role="alert"
      data-testid="contract-detail-forbidden"
    >
      <div class="state-panel__mark" aria-hidden="true">!</div>
      <div class="state-panel__content">
        <h2 class="state-panel__title">无权限查看该劳动合同</h2>
        <p class="state-panel__body">当前账号缺少 people.contract.read，或该合同不在你的数据范围内。</p>
      </div>
      <el-button type="primary" plain @click="load">重试</el-button>
    </section>

    <ErrorState
      v-else-if="state === 'error'"
      data-testid="contract-detail-error"
      title="劳动合同详情暂时无法加载"
      :description="errorMessage"
      :request-id="errorRequestId"
      @retry="load"
    />

    <LoadingState v-else-if="loading || state === 'idle'" :rows="5" label="正在加载劳动合同详情" />

    <EmptyState
      v-else-if="state === 'empty'"
      data-testid="contract-detail-empty"
      title="未找到该劳动合同"
      description="该合同可能已归档、被移出你的数据范围，或链接缺少员工关系编号。"
      action-label="返回台账"
      @action="backToList"
    />

    <template v-else-if="contract !== null">
      <el-alert
        v-if="contract.display_status === 'EXPIRING'"
        type="warning"
        :closable="false"
        show-icon
        data-testid="contract-expiry-alert"
      >
        <template #title>
          合同将在 {{ contract.days_to_expiry ?? '—' }} 天后到期（{{ formatBusinessDate(contract.end_date) }}）。请评估续签。
        </template>
      </el-alert>
      <el-alert
        v-if="contract.display_status === 'EXPIRED'"
        type="error"
        :closable="false"
        show-icon
        data-testid="contract-expired-alert"
      >
        <template #title>合同已于 {{ formatBusinessDate(contract.end_date) }} 到期，当前没有有效版本。</template>
      </el-alert>

      <section class="panel contract-detail" data-testid="contract-detail">
        <header class="panel__header">
          <div>
            <p class="page-header__breadcrumb">劳动合同</p>
            <h2 class="panel__title">{{ contract.employee_name ?? `员工 #${contract.employment_id}` }}</h2>
            <p class="panel__subtitle">{{ employeeIdentity }}</p>
          </div>
          <span
            class="status-badge status-badge--sm"
            :class="`status-badge--${CONTRACT_STATUS_TONES[contract.display_status]}`"
            data-testid="contract-detail-status"
          >
            {{ contract.display_status_label }}
          </span>
        </header>
        <div class="panel__body">
          <dl class="contract-detail__facts">
            <div>
              <dt>合同编号</dt>
              <dd class="mono">{{ contract.contract_no }}</dd>
            </div>
            <div>
              <dt>合同类型</dt>
              <dd>{{ typeLabel }}</dd>
            </div>
            <div>
              <dt>起止日期</dt>
              <dd>{{ periodLabel }}</dd>
            </div>
            <div>
              <dt>签署日期</dt>
              <dd>{{ formatBusinessDate(contract.signed_on) }}</dd>
            </div>
            <div>
              <dt>版本</dt>
              <dd>第 {{ contract.version }} 版 · {{ contract.renewal_count > 0 ? `续签 ${contract.renewal_count} 次` : '首次签订' }}</dd>
            </div>
            <div>
              <dt>上一版本</dt>
              <dd>{{ contract.previous_contract_id === null ? '无' : `#${contract.previous_contract_id}` }}</dd>
            </div>
            <div>
              <dt>工作地点</dt>
              <dd>{{ contract.work_location ?? '见版本快照' }}</dd>
            </div>
            <div>
              <dt>试用期结束</dt>
              <dd>{{ formatBusinessDate(contract.probation_end_date) }}</dd>
            </div>
            <div class="contract-detail__facts-wide">
              <dt>条款摘要</dt>
              <dd>{{ contract.terms_summary ?? '未填写' }}</dd>
            </div>
          </dl>
        </div>
      </section>

      <ContractVersionList
        :versions="versions"
        :loading="versionsLoading"
        :error="versionsError"
        :partial-reason="versionsPartial"
      />

      <section class="panel contract-attachments" data-testid="contract-attachments">
        <header class="panel__header">
          <div>
            <h2 class="panel__title">合同附件</h2>
            <p class="panel__subtitle">仅展示员工文档中类别为“合同”的附件；下载使用短期签名链接。</p>
          </div>
          <span class="status-badge status-badge--sm status-badge--neutral">{{ attachments.length }} 个附件</span>
        </header>
        <div class="panel__body">
          <p v-if="attachmentsLoading" class="contract-attachments__state" role="status">正在加载附件…</p>
          <p v-else-if="attachmentsError !== ''" class="contract-attachments__state contract-attachments__state--error" role="alert">
            {{ attachmentsError }}
          </p>
          <p v-else-if="attachmentsPartial !== ''" class="contract-attachments__state contract-attachments__state--partial">
            部分附件：{{ attachmentsPartial }}
          </p>
          <p v-else-if="attachments.length === 0" class="contract-attachments__state" data-testid="contract-attachments-empty">
            暂无合同附件。接口未返回附件时不会在此生成占位文件。
          </p>
          <ul v-else class="contract-attachments__list">
            <li v-for="attachment in attachments" :key="attachment.id" class="contract-attachments__item">
              <div>
                <strong>{{ attachment.original_name }}</strong>
                <small>
                  第 {{ attachment.version }} 版 · {{ formatBytes(attachment.size_bytes) }}
                  <template v-if="attachment.legal_hold"> · 法律保留</template>
                </small>
              </div>
              <button type="button" class="contract-attachments__download" @click="downloadAttachment(attachment)">
                下载
              </button>
            </li>
          </ul>
        </div>
      </section>

      <p class="contract-detail__note">
        终止是不可撤销的合同动作：提交后合同状态变为已终止，终止日期写入当前记录并追加只读版本；历史版本与附件继续保留。
      </p>
    </template>

    <ContractFormDrawer
      v-if="contract !== null"
      v-model="editOpen"
      mode="edit"
      :contract="contract"
      :saving="saving"
      :server-error="saveError"
      @amend="amend"
    />

    <el-dialog
      v-model="activateOpen"
      title="激活劳动合同"
      width="480px"
      :close-on-click-modal="false"
      data-testid="contract-activate-dialog"
    >
      <div v-if="contract !== null" class="contract-terminate">
        <p>
          即将激活 <strong>{{ contract.employee_name ?? `员工 #${contract.employment_id}` }}</strong>
          （合同编号 {{ contract.contract_no }}）。
        </p>
        <p class="contract-terminate__warning">
          激活后合同进入生效流程，生效日期会写入版本历史；请确认与签署起始日期一致。
        </p>
        <label class="contract-terminate__field">
          <span>激活生效日期 *</span>
          <el-date-picker v-model="activateDate" data-testid="activate-date" type="date" value-format="YYYY-MM-DD" aria-label="激活生效日期" />
        </label>
        <label class="contract-terminate__field">
          <span>激活原因</span>
          <el-input v-model="activateReason" data-testid="activate-reason" type="textarea" :rows="3" maxlength="500" aria-label="激活原因" />
        </label>
        <p v-if="activateError !== ''" class="contract-terminate__error" role="alert">{{ activateError }}</p>
      </div>
      <template #footer>
        <el-button @click="activateOpen = false">取消</el-button>
        <el-button
          type="primary"
          :loading="activating"
          data-testid="activate-confirm"
          @click="confirmActivate"
        >
          确认激活
        </el-button>
      </template>
    </el-dialog>

    <el-dialog
      v-model="terminateOpen"
      title="终止劳动合同"
      width="480px"
      :close-on-click-modal="false"
      data-testid="contract-terminate-dialog"
    >
      <div v-if="contract !== null" class="contract-terminate">
        <p>
          即将终止 <strong>{{ contract.employee_name ?? `员工 #${contract.employment_id}` }}</strong>
          （合同编号 {{ contract.contract_no }}）的劳动合同。
        </p>
        <p class="contract-terminate__warning">
          终止不可撤销。提交后该合同状态变为“已终止”，历史版本会保留，不会删除既有签署记录。
        </p>
        <label class="contract-terminate__field">
          <span>终止生效日期 *</span>
          <el-date-picker v-model="terminateDate" data-testid="terminate-date" type="date" value-format="YYYY-MM-DD" aria-label="终止生效日期" />
        </label>
        <label class="contract-terminate__field">
          <span>终止原因 *</span>
          <el-input v-model="terminateReason" data-testid="terminate-reason" type="textarea" :rows="3" maxlength="500" aria-label="终止原因" />
        </label>
        <p v-if="terminateError !== ''" class="contract-terminate__error" role="alert">{{ terminateError }}</p>
      </div>
      <template #footer>
        <el-button @click="terminateOpen = false">取消</el-button>
        <el-button
          type="danger"
          :loading="terminating"
          data-testid="terminate-confirm"
          @click="confirmTerminate"
        >
          确认终止（不可撤销）
        </el-button>
      </template>
    </el-dialog>
  </PageShell>
</template>

<style scoped>
.contract-detail__facts {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 12px;
  margin: 0;
}
.contract-detail__facts-wide {
  grid-column: 1 / -1;
}
.contract-detail__facts dt {
  color: var(--color-ink-muted);
  font-size: 10.5px;
}
.contract-detail__facts dd {
  margin: 2px 0 0;
  font-size: 12.5px;
  overflow-wrap: anywhere;
}
.contract-attachments__state {
  padding: 8px 0;
  color: var(--color-ink-muted);
  font-size: 12px;
}
.contract-attachments__state--error {
  color: var(--color-danger);
}
.contract-attachments__state--partial {
  color: #8a5a00;
}
.contract-attachments__list {
  margin: 0;
  padding: 0;
  list-style: none;
}
.contract-attachments__item {
  display: flex;
  gap: 12px;
  align-items: center;
  justify-content: space-between;
  padding: 9px 0;
  border-bottom: 1px solid var(--color-line);
}
.contract-attachments__item div {
  display: flex;
  flex-direction: column;
  gap: 1px;
  min-width: 0;
  font-size: 12px;
}
.contract-attachments__item small {
  color: var(--color-ink-muted);
  font-size: 10.5px;
}
.contract-attachments__download {
  padding: 4px 10px;
  color: var(--color-brand);
  font-size: 11px;
  background: #fff;
  border: 1px solid var(--color-brand);
  border-radius: var(--radius-sm);
  cursor: pointer;
}
.contract-detail__note {
  margin: 0;
  padding: 9px 10px;
  color: var(--color-ink-muted);
  font-size: 11px;
  background: var(--color-canvas);
  border-radius: var(--radius-sm);
}
.contract-terminate {
  display: flex;
  flex-direction: column;
  gap: 10px;
  font-size: 12px;
}
.contract-terminate__warning {
  margin: 0;
  padding: 8px 10px;
  color: var(--color-danger);
  background: var(--color-danger-soft);
  border-radius: var(--radius-sm);
}
.contract-terminate__field {
  display: flex;
  flex-direction: column;
  gap: 4px;
  color: var(--color-ink-muted);
  font-size: 11px;
}
.contract-terminate__field > span {
  font-weight: 600;
}
.contract-terminate__field input,
.contract-terminate__field textarea {
  width: 100%;
  padding: 7px 9px;
  color: var(--color-ink);
  font-size: 12px;
  border: 1px solid var(--color-line-strong);
  border-radius: var(--radius-sm);
}
.contract-terminate__error {
  margin: 0;
  color: var(--color-danger);
  font-size: 12px;
}
@media (max-width: 900px) {
  .contract-detail__facts {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
</style>
