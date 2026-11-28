<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import {
  createNoticeTemplate,
  acknowledgeNotice,
  exportNoticeReads,
  getNoticeReads,
  listNoticeTemplates,
  remindNotice,
  type Notice,
  type NoticeReadExport,
  type NoticeReadsResult,
  type NoticeReminderResult,
  type NoticeDetailTab,
  type NoticeTemplate,
  type OrgUnitOption,
  type RoleOption,
} from './noticeApi'
import { formatDateTimeInTimezone } from '@/utils/format'
import { errorCopy } from '@/api/errors'

const props = withDefaults(
  defineProps<{
    notice: Notice | null
    activeTab: NoticeDetailTab
    canManage: boolean
    canPublish: boolean
    canExport: boolean
    orgUnits?: OrgUnitOption[]
    roleOptions?: RoleOption[]
    /** Workflow case id currently assigned to the caller, if any. */
    assignedCaseId?: number | null
    busy?: boolean
    standaloneTemplates?: boolean
  }>(),
  {
    orgUnits: () => [],
    roleOptions: () => [],
    assignedCaseId: null,
    busy: false,
    standaloneTemplates: false,
  },
)

const emit = defineEmits<{
  tabChange: [tab: NoticeDetailTab]
  submitted: [notice: Notice]
  decided: [notice: Notice, action: 'APPROVE' | 'REJECT']
  published: [notice: Notice]
  archived: [notice: Notice]
  restored: [notice: Notice]
  acknowledged: [notice: Notice]
  closed: []
}>()

const reads = ref<NoticeReadsResult | null>(null)
const readsLoading = ref(false)
const readsError = ref('')
const remindResult = ref<NoticeReminderResult | null>(null)
const remindError = ref('')
const reminding = ref(false)
const acknowledging = ref(false)
const acknowledgeError = ref('')
const exporting = ref(false)
const exportError = ref('')
const exportResult = ref<NoticeReadExport | null>(null)
const templates = ref<NoticeTemplate[]>([])
const templatesLoading = ref(false)
const templatesError = ref('')
const templateSaving = ref(false)
const templateError = ref('')
const templateCode = ref('')
const templateName = ref('')
const templateBody = ref('')
const templateVariables = ref('')

const tabs: ReadonlyArray<{ key: NoticeDetailTab; label: string }> = [
  { key: 'content', label: '公告内容' },
  { key: 'approval', label: '审批' },
  { key: 'audience', label: '受众' },
  { key: 'reads', label: '阅读记录' },
  { key: 'templates', label: '模板' },
]

const unreadCount = computed(() => reads.value?.unreadCount ?? 0)
const readCount = computed(() => reads.value?.readCount ?? 0)
const recipientCount = computed(() => props.notice?.audienceCount ?? 0)
const isPendingApproval = computed(() => props.notice?.status === 'PENDING_APPROVAL')
const canDecide = computed(
  () =>
    props.canPublish
    && isPendingApproval.value
    && props.assignedCaseId !== null
    && props.notice?.approvalCaseId === props.assignedCaseId,
)
const templatesByCode = computed(() => {
  const grouped = new Map<string, NoticeTemplate[]>()
  for (const template of templates.value) {
    const rows = grouped.get(template.code) ?? []
    rows.push(template)
    grouped.set(template.code, rows)
  }
  return [...grouped.entries()].map(([code, rows]) => ({
    code,
    name: rows[0]?.name ?? code,
    versions: [...rows].sort((a, b) => b.version - a.version),
  }))
})

function setTab(tab: NoticeDetailTab): void {
  emit('tabChange', tab)
}

function statusLabel(status: string | undefined): string {
  switch (status) {
    case 'DRAFT': return '草稿'
    case 'PENDING_APPROVAL': return '待审核'
    case 'APPROVED': return '已批准'
    case 'REJECTED': return '已驳回'
    case 'PUBLISHED': return '已发布'
    case 'RETRACTED': return '已撤回'
    case 'ARCHIVED': return '已归档'
    default: return '其他状态'
  }
}

function audienceTypeLabel(type: string | undefined): string {
  if (type === 'ALL') return '全体员工'
  if (type === 'ORG') return '指定组织'
  if (type === 'ROLE') return '指定角色'
  return '指定范围'
}

function roleLabels(codes: string[]): string {
  return codes
    .map((code) => props.roleOptions.find((role) => role.code === code)?.name ?? '指定角色')
    .join('、')
}

function orgLabels(ids: number[]): string {
  return ids
    .map((id) => props.orgUnits.find((unit) => unit.id === id)?.name ?? '指定组织')
    .join('、')
}

async function loadReads(): Promise<void> {
  if (props.notice === null) return
  readsLoading.value = true
  readsError.value = ''
  try {
    if (props.notice === null) return
  reads.value = await getNoticeReads(props.notice.id)
  } catch (error) {
    reads.value = null
    readsError.value = errorCopy(error)
  } finally {
    readsLoading.value = false
  }
}

async function acknowledge(): Promise<void> {
  if (props.notice === null || acknowledging.value) return
  acknowledging.value = true
  acknowledgeError.value = ''
  try {
    const updated = await acknowledgeNotice(props.notice.id)
    ElMessage.success('已确认阅读，确认记录已保存。')
    emit('acknowledged', updated)
  } catch (error) {
    acknowledgeError.value = errorCopy(error)
  } finally {
    acknowledging.value = false
  }
}

async function sendReminder(): Promise<void> {
  if (props.notice === null || reminding.value) return
  reminding.value = true
  remindError.value = ''
  remindResult.value = null
  try {
    if (props.notice === null) return
  remindResult.value = await remindNotice(props.notice.id)
    ElMessage.success(
      remindResult.value.remindedCount > 0
        ? `已提醒 ${remindResult.value.remindedCount} 人。`
        : '没有需要提醒的未读受众。',
    )
  } catch (error) {
    remindError.value = errorCopy(error)
  } finally {
    reminding.value = false
  }
}

async function exportReads(): Promise<void> {
  if (props.notice === null || exporting.value) return
  exporting.value = true
  exportError.value = ''
  exportResult.value = null
  try {
    exportResult.value = await exportNoticeReads(
      props.notice.id,
      ['user_id', 'employee_no', 'display_name', 'acknowledged_at'],
    )
  } catch (error) {
    exportError.value = errorCopy(error)
  } finally {
    exporting.value = false
  }
}

async function loadTemplates(): Promise<void> {
  templatesLoading.value = true
  templatesError.value = ''
  try {
    templates.value = await listNoticeTemplates()
  } catch (error) {
    templates.value = []
    templatesError.value = errorCopy(error)
  } finally {
    templatesLoading.value = false
  }
}

async function saveTemplate(): Promise<void> {
  if (templateSaving.value) return
  templateError.value = ''
  const code = templateCode.value.trim().toUpperCase()
  const variables = templateVariables.value
    .split(',')
    .map((item) => item.trim())
    .filter((item) => item !== '')
  if (!/^[A-Z][A-Z0-9_]{2,63}$/.test(code)) {
    templateError.value = '模板代码需为 3-64 位大写字母、数字或下划线。'
    return
  }
  if (templateName.value.trim() === '' || templateBody.value.trim() === '') {
    templateError.value = '请填写模板名称与正文。'
    return
  }
  templateSaving.value = true
  try {
    await createNoticeTemplate({
      code,
      name: templateName.value.trim(),
      bodyTemplate: templateBody.value,
      variables,
    })
    ElMessage.success('公告模板新版本已发布。')
    templateBody.value = ''
    templateVariables.value = ''
    await loadTemplates()
  } catch (error) {
    templateError.value = errorCopy(error)
  } finally {
    templateSaving.value = false
  }
}

function exportFieldLabel(field: string): string {
  switch (field) {
    case 'user_id': return '用户编号'
    case 'employee_no': return '员工编号'
    case 'display_name': return '姓名'
    case 'acknowledged_at': return '确认时间'
    case 'employment_id': return '任职编号'
    case 'notice_id': return '公告编号'
    default: return '其他字段'
  }
}

function actionDetail(notice: Notice): void {
  emit('submitted', notice)
}

function actionDecision(notice: Notice, action: 'APPROVE' | 'REJECT' = 'APPROVE'): void {
  emit('decided', notice, action)
}

function actionPublish(notice: Notice): void {
  emit('published', notice)
}

function actionArchive(notice: Notice): void {
  emit('archived', notice)
}

function actionRestore(notice: Notice): void {
  emit('restored', notice)
}

watch(
  () => props.standaloneTemplates,
  (standalone) => {
    if (standalone) void loadTemplates()
  },
  { immediate: true },
)

watch(
  () => props.notice?.id,
  () => {
    reads.value = null
    remindResult.value = null
    exportResult.value = null
    readsError.value = ''
    remindError.value = ''
    exportError.value = ''
  },
)

watch(
  () => [props.notice?.id, props.activeTab] as const,
  ([id, tab]) => {
    if (id === undefined) return
    if (tab === 'reads' && reads.value === null) void loadReads()
    if (tab === 'templates') void loadTemplates()
  },
  { immediate: true },
)
</script>

<template>
  <section v-if="notice || standaloneTemplates" class="panel notice-detail-drawer" data-testid="notice-detail">
    <div class="panel__header notice-detail-drawer__header">
      <div>
        <p class="page-header__breadcrumb">{{ notice ? '公告详情' : '公告模板' }}</p>
        <h2 class="panel__title">{{ notice ? notice.title : '公告模板设置' }}</h2>
        <p class="panel__subtitle">
          {{ notice
            ? `${statusLabel(notice.status)} · ${audienceTypeLabel(notice.audience)} · ${notice.audienceCount} 人`
            : '模板按代码追加版本，历史版本不改写。' }}
        </p>
      </div>
      <el-button text data-testid="notice-detail-close" @click="emit('closed')">关闭</el-button>
    </div>

    <nav v-if="!standaloneTemplates" class="notice-detail-tabs" role="tablist" data-testid="notice-detail-tabs" aria-label="公告详情标签页">
      <button
        v-for="tab in tabs"
        :key="tab.key"
        type="button"
        role="tab"
        class="notice-detail-tab"
        :class="{ 'notice-detail-tab--active': activeTab === tab.key }"
        :aria-selected="activeTab === tab.key"
        :data-testid="`notice-detail-tab-${tab.key}`"
        @click="setTab(tab.key)"
      >
        {{ tab.label }}
      </button>
    </nav>

    <div class="panel__body">
      <section v-if="!standaloneTemplates && activeTab === 'content' && notice" data-testid="notice-panel-content">
        <p v-for="(line, index) in notice.body" :key="index">{{ line }}</p>
        <dl class="notice-detail-facts">
          <div><dt>发布时间</dt><dd>{{ formatDateTimeInTimezone(notice.publishedAt) }}</dd></div>
          <div v-if="notice.expiresAt"><dt>失效时间</dt><dd>{{ formatDateTimeInTimezone(notice.expiresAt) }}</dd></div>
          <div><dt>审批案例</dt><dd>{{ notice?.approvalCaseId ?? '无' }}</dd></div>
          <div><dt>模板版本</dt><dd>{{ notice?.templateCode ?? '无' }} {{ notice?.templateVersion ?? '' }}</dd></div>
        </dl>

        <div v-if="notice.requiresAck" class="notice-detail-actions">
          <span v-if="notice.acknowledged" class="notice-ack" data-testid="notice-detail-acknowledged">
            ✓ 已确认阅读
          </span>
          <el-button
            v-else
            type="primary"
            :loading="acknowledging"
            :data-testid="`notice-acknowledge-${notice.id}`"
            @click="acknowledge"
          >
            确认已读
          </el-button>
          <p v-if="acknowledgeError" class="notice-error" data-testid="notice-acknowledge-error" role="alert">
            {{ acknowledgeError }}
          </p>
        </div>
      </section>

      <section v-else-if="!standaloneTemplates && activeTab === 'approval' && notice" data-testid="notice-panel-approval">
        <p class="muted">
          公告审批由审批案例记录，公告页不会直接改写审批决定。通过后由具有发布能力的人员发布。
        </p>
        <p v-if="notice.approvalCaseId" data-testid="notice-approval-case">
          审批案例编号：{{ notice.approvalCaseId }}
          <a
            v-if="notice.approvalCaseId"
            :href="`/workflow/cases/${notice.approvalCaseId}`"
            class="notice-case-link"
            data-testid="notice-case-link"
          >查看审批案例</a>
        </p>

        <div class="notice-detail-actions">
          <el-button
            v-if="canManage && notice.status === 'DRAFT'"
            type="primary"
            :loading="busy"
            data-testid="notice-submit"
            @click="actionDetail(notice)"
          >
            提交审批
          </el-button>
          <template v-if="canDecide">
            <el-button
              type="success"
              :loading="busy"
              data-testid="notice-decision-approve"
              @click="actionDecision(notice, 'APPROVE')"
            >
              同意
            </el-button>
            <el-button
              type="danger"
              plain
              :loading="busy"
              data-testid="notice-decision-reject"
              @click="actionDecision(notice, 'REJECT')"
            >
              驳回
            </el-button>
          </template>
          <el-button
            v-if="canPublish && notice.status === 'APPROVED'"
            type="primary"
            :loading="busy"
            data-testid="notice-publish-approved"
            @click="actionPublish(notice)"
          >
            发布公告
          </el-button>
          <el-button
            v-if="canManage && notice.status === 'PUBLISHED'"
            type="warning"
            plain
            :loading="busy"
            data-testid="notice-archive"
            @click="actionArchive(notice)"
          >
            归档
          </el-button>
          <el-button
            v-if="canManage && notice.status === 'ARCHIVED'"
            type="success"
            plain
            :loading="busy"
            data-testid="notice-restore"
            @click="actionRestore(notice)"
          >
            恢复
          </el-button>
        </div>
      </section>

      <section v-else-if="!standaloneTemplates && activeTab === 'audience' && notice" data-testid="notice-panel-audience">
        <h3 class="notice-detail-section-title">发布范围</h3>
        <p data-testid="notice-audience-type">{{ audienceTypeLabel(notice.audience) }}</p>
        <p v-if="notice.audience === 'ORG'" data-testid="notice-audience-orgs">
          {{ orgLabels(notice.orgUnitIds) || '未记录组织' }}
        </p>
        <p v-if="notice.audience === 'ROLE'" data-testid="notice-audience-roles">
          {{ roleLabels(notice.roleCodes ?? []) || '未记录角色' }}
        </p>
        <p class="muted" data-testid="notice-audience-count">
          当前受众快照 {{ recipientCount }} 人。人数由服务端按业务日期实时解析，不使用客户端估算。
        </p>
      </section>

      <section v-else-if="!standaloneTemplates && activeTab === 'reads' && notice" data-testid="notice-panel-reads">
        <div class="notice-read-stats">
          <span class="plain-chip" data-testid="notice-reads-read">已读 {{ readCount }} 人</span>
          <span class="plain-chip plain-chip--warning" data-testid="notice-reads-unread">
            未读 {{ unreadCount }} 人
          </span>
        </div>
        <p class="small muted">
          已读以公告确认回执为准；消息中心的“已读”不会改变公告阅读统计。
        </p>

        <div class="notice-detail-actions">
          <el-button
            v-if="canManage && notice.status === 'PUBLISHED'"
            type="primary"
            plain
            :loading="reminding"
            data-testid="notice-remind"
            @click="sendReminder"
          >
            催阅未读受众
          </el-button>
          <el-button
            v-if="canExport"
            :loading="exporting"
            data-testid="notice-reads-export"
            @click="exportReads"
          >
            导出阅读明细
          </el-button>
        </div>

        <p v-if="remindResult" class="notice-success" data-testid="notice-remind-result" role="status">
          已提醒 {{ remindResult.remindedCount }} 人；未读受众 {{ unreadCount }} 人。
        </p>
        <p v-if="remindError" class="notice-error" data-testid="notice-remind-error" role="alert">{{ remindError }}</p>
        <p v-if="exportError" class="notice-error" data-testid="notice-export-error" role="alert">{{ exportError }}</p>

        <el-alert v-if="readsLoading" type="info" :closable="false" title="正在加载阅读记录" />
        <p v-else-if="readsError" class="notice-error" data-testid="notice-reads-error" role="alert">{{ readsError }}</p>
        <p v-else-if="reads?.items.length === 0" class="small muted" data-testid="notice-reads-empty">
          {{ recipientCount === 0 ? '该公告没有受众，暂无阅读记录。' : '暂无受众确认阅读，未读人数以上方统计为准。' }}
        </p>
        <ul v-else class="notice-read-list" data-testid="notice-reads-list">
          <li v-for="receipt in reads?.items" :key="receipt.userId" data-testid="notice-read-row">
            <span>{{ receipt.displayName ?? '未提供姓名' }}</span>
            <span class="mono small">{{ receipt.employeeNo ?? '未提供工号' }}</span>
            <span class="small muted">{{ formatDateTimeInTimezone(receipt.acknowledgedAt) }}</span>
          </li>
        </ul>

        <div v-if="exportResult" class="notice-export-result" data-testid="notice-export-result">
          <p>导出字段白名单：{{ exportResult.fields.join('、') }}</p>
          <p class="small muted">共 {{ exportResult.rowCount }} 行，数据日期 {{ exportResult.asOf }}。</p>
          <table v-if="exportResult.rows.length > 0" class="notice-export-table">
            <thead>
              <tr>
                <th v-for="field in exportResult.fields" :key="field">{{ exportFieldLabel(field) }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(row, index) in exportResult.rows" :key="index">
                <td v-for="field in exportResult.fields" :key="field">{{ row[field] ?? '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section v-else data-testid="notice-panel-templates">
        <p class="muted">
          模板按代码追加版本，旧版本不会被改写。已发布公告保留它创建时的模板版本。
        </p>
        <el-alert v-if="templatesLoading" type="info" :closable="false" title="正在加载模板" />
        <p v-else-if="templatesError" class="notice-error" data-testid="notice-templates-error" role="alert">{{ templatesError }}</p>
        <p v-else-if="templatesByCode.length === 0" class="small muted" data-testid="notice-templates-empty">尚未发布任何公告模板。</p>
        <ul v-else class="notice-template-list" data-testid="notice-template-list">
          <li v-for="group in templatesByCode" :key="group.code">
            <strong>{{ group.name }}</strong>
            <span class="small muted">{{ group.code }}</span>
            <ul>
              <li
                v-for="version in group.versions"
                :key="version.id"
                data-testid="notice-template-version"
              >
                版本 {{ version.version }} · {{ version.status === 'PUBLISHED' ? '已发布' : '其他状态' }}
                <span v-if="version.variables.length > 0" class="small muted">
                  变量：{{ version.variables.join('、') }}
                </span>
              </li>
            </ul>
          </li>
        </ul>

        <form v-if="canManage" class="notice-template-form" @submit.prevent="saveTemplate">
          <h3 class="notice-detail-section-title">追加新版本</h3>
          <label>
            <span>模板代码</span>
            <input v-model="templateCode" maxlength="64" data-testid="notice-template-code" placeholder="PAYROLL_REMINDER" />
          </label>
          <label>
            <span>模板名称</span>
            <input v-model="templateName" maxlength="160" data-testid="notice-template-name" />
          </label>
          <label>
            <span>模板正文</span>
            <textarea v-model="templateBody" rows="4" maxlength="20000" data-testid="notice-template-body" />
          </label>
          <label>
            <span>变量（逗号分隔）</span>
            <input v-model="templateVariables" data-testid="notice-template-variables" placeholder="period, amount" />
          </label>
          <p v-if="templateError" class="notice-error" data-testid="notice-template-error" role="alert">{{ templateError }}</p>
          <el-button type="primary" native-type="submit" :loading="templateSaving" data-testid="notice-template-submit">
            发布新版本
          </el-button>
        </form>
      </section>
    </div>
  </section>
</template>

<style scoped>
.notice-detail-drawer {
  display: flex;
  flex-direction: column;
  gap: 0;
}

.notice-detail-tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  padding: 0 18px;
  border-bottom: 1px solid var(--color-line);
}

.notice-detail-tab {
  padding: 9px 10px;
  font: inherit;
  font-size: 12px;
  color: var(--color-ink-secondary);
  background: transparent;
  border: 0;
  border-bottom: 2px solid transparent;
  cursor: pointer;
}

.notice-detail-tab--active {
  color: var(--color-brand);
  font-weight: 650;
  border-bottom-color: var(--color-brand);
}

.notice-detail-facts {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  gap: 10px;
  margin-top: 12px;
}

.notice-detail-facts dt {
  font-size: 11px;
  color: var(--color-ink-muted);
}

.notice-detail-facts dd {
  margin: 2px 0 0;
  font-size: 12.5px;
}

.notice-detail-section-title {
  margin: 0 0 8px;
  font-size: 13px;
}

.notice-detail-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin: 12px 0;
}

.notice-case-link {
  margin-left: 8px;
  color: var(--color-brand);
  font-size: 12px;
}

.notice-read-stats {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 8px;
}

.notice-read-list,
.notice-template-list {
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding: 0;
  margin: 10px 0 0;
  list-style: none;
}

.notice-read-list li {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  padding: 7px 9px;
  font-size: 12.5px;
  background: var(--color-canvas);
  border-radius: var(--radius-sm);
}

.notice-template-list > li {
  padding: 9px 10px;
  border: 1px solid var(--color-line);
  border-radius: var(--radius-sm);
}

.notice-template-list ul {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding-left: 16px;
  margin: 6px 0 0;
  font-size: 12px;
}

.notice-template-form {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-top: 16px;
  padding-top: 12px;
  border-top: 1px solid var(--color-line);
}

.notice-template-form label {
  display: flex;
  flex-direction: column;
  gap: 4px;
  font-size: 11.5px;
  color: var(--color-ink-secondary);
}

.notice-template-form input,
.notice-template-form textarea {
  padding: 7px 9px;
  font: inherit;
  color: var(--color-ink);
  border: 1px solid var(--color-line);
  border-radius: var(--radius-sm);
}

.notice-ack {
  padding: 3px 9px;
  color: #1d5b43;
  font-size: 11px;
  background: #f1faf5;
  border: 1px solid #bfe3d0;
  border-radius: 999px;
}

.notice-export-table {
  width: 100%;
  margin-top: 8px;
  font-size: 12px;
  border-collapse: collapse;
}

.notice-export-table th,
.notice-export-table td {
  padding: 5px 8px;
  text-align: left;
  border-bottom: 1px solid var(--color-line);
}

.notice-success,
.notice-error {
  margin: 8px 0;
  padding: 8px 10px;
  font-size: 12px;
  border-radius: var(--radius-sm);
}

.notice-success {
  color: #1d5b43;
  background: #f1faf5;
  border: 1px solid #bfe3d0;
}

.notice-error {
  color: #8a2c2c;
  background: #fff4f2;
  border: 1px solid #f0c7c0;
}

.plain-chip--warning {
  color: #8a5a00;
  background: #fff6e5;
  border-color: #ead49f;
}

@media (max-width: 720px) {
  .notice-detail-drawer__header {
    flex-direction: column;
  }
}
</style>
