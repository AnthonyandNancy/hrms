<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  acknowledgeNotice,
  archiveNotice,
  audienceText,
  decideNotice,
  getNotice,
  listNotices,
  listOrgUnits,
  noticeStatusText,
  publishApprovedNotice,
  restoreNotice,
  submitNotice,
  type Notice,
  type OrgUnitOption,
  type NoticeDetailTab,
  type RoleOption,
} from '../components/noticeApi'
import { errorCopy, ApiError } from '@/api/errors'
import { formatDateTimeInTimezone, todayBusinessDate } from '@/utils/format'
import { requestIdFromError } from '@/utils/renderErrors'
import { useSessionStore } from '@/stores/session'
import { resourceGet } from '@/api/resourceClient'
import LoadingState from '@/components/LoadingState.vue'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import NoticeComposer from '../components/NoticeComposer.vue'
import NoticeDetailDrawer from '../components/NoticeDetailDrawer.vue'

type NoticeFilter = 'all' | 'unread' | 'managed'

const DETAIL_TABS: ReadonlyArray<NoticeDetailTab> = ['content', 'approval', 'audience', 'reads', 'templates']

const session = useSessionStore()
const route = useRoute()
const router = useRouter()
const hasRouter = typeof route === 'object' && route !== null && typeof router === 'object' && router !== null
const filter = ref<NoticeFilter>('all')
const notices = ref<Notice[]>([])
const loading = ref(false)
const loadError = ref<unknown>(null)
const partialReason = ref('')
const selected = ref<Notice | null>(null)
const archivingId = ref<number | null>(null)
const acknowledgingId = ref<number | null>(null)
const actionMessage = ref('')
const actionError = ref('')
const detailLoading = ref(false)
const detailError = ref<string>('')
const orgUnits = ref<OrgUnitOption[]>([])
const successMessage = ref('')
const detailRequestId = ref<string | null>(null)
const actionBusy = ref(false)
const roleOptions = ref<RoleOption[]>([])
const rolesUnavailable = ref(false)
const activeTab = ref<NoticeDetailTab>(initialTab())
const assignedCaseId = ref<number | null>(null)
const templatesView = ref(false)

const canRead = computed(() => session.can('engagement.notice.read'))
const canManage = computed(() => session.can('engagement.notice.manage'))
const canPublish = computed(() => session.can('engagement.notice.publish'))
const canExport = computed(() => session.can('engagement.notice.export'))
const errorMessage = computed(() => (loadError.value === null ? '' : errorCopy(loadError.value)))
const errorRequestId = computed(() => (loadError.value === null ? null : requestIdFromError(loadError.value)))
const unacknowledgedCount = computed(
  () => notices.value.filter((notice) => notice.requiresAck && !notice.acknowledged).length,
)
const filters = computed(() => [
  { key: 'all' as const, label: '全部公告' },
  { key: 'unread' as const, label: '待我确认' },
  ...(canManage.value ? [{ key: 'managed' as const, label: '管理视图' }] : []),
])

function initialTab(): NoticeDetailTab {
  const query = route?.query ?? {}
  const tab = Array.isArray(query.tab) ? query.tab[0] : query.tab
  return DETAIL_TABS.includes(tab as NoticeDetailTab) ? (tab as NoticeDetailTab) : 'content'
}

function queryNoticeId(): number | null {
  const raw = Array.isArray(route?.query.notice) ? route.query.notice[0] : route?.query.notice
  if (typeof raw !== 'string' || !/^\d+$/.test(raw)) return null
  const id = Number(raw)
  return Number.isSafeInteger(id) && id > 0 ? id : null
}

function syncQuery(patch: Record<string, string | undefined>): void {
  const query: Record<string, string> = {}
  for (const [key, value] of Object.entries({ ...(route?.query ?? {}), ...patch })) {
    if (typeof value === 'string' && value !== '') query[key] = value
  }
  if (patch.tab === undefined) delete query.tab
  void router?.replace({ query })
}

async function loadRoleCatalog(): Promise<void> {
  if (!canManage.value) return
  if (!session.can('iam.role.manage')) {
    // The only existing role directory is IAM-governed. Do not invent role
    // codes for the audience selector; say the directory is unavailable.
    roleOptions.value = []
    rolesUnavailable.value = true
    return
  }
  try {
    const payload = await resourceGet<unknown>('/v1/roles')
    const record = payload !== null && typeof payload === 'object' && !Array.isArray(payload)
      ? payload as Record<string, unknown>
      : {}
    const rows = Array.isArray(record.roles) ? record.roles : []
    roleOptions.value = rows
      .filter((row): row is Record<string, unknown> => row !== null && typeof row === 'object' && !Array.isArray(row))
      .map((row) => ({
        code: typeof row.code === 'string' ? row.code : '',
        name: typeof row.name === 'string' ? row.name : '',
      }))
      .filter((role) => role.code !== '' && role.name !== '')
    rolesUnavailable.value = false
  } catch {
    roleOptions.value = []
    rolesUnavailable.value = true
  }
}

/** The Workflow case detail is the only truthful source for the assignee. */
async function loadAssignedCase(caseId: number | null): Promise<void> {
  assignedCaseId.value = null
  if (caseId === null) return
  try {
    const raw = await resourceGet<unknown>(`/v1/cases/${caseId}`)
    const row = raw !== null && typeof raw === 'object' && !Array.isArray(raw)
      ? raw as Record<string, unknown>
      : {}
    const context = row.decision_context !== null && typeof row.decision_context === 'object' && !Array.isArray(row.decision_context)
      ? row.decision_context as Record<string, unknown>
      : {}
    assignedCaseId.value = row.is_awaiting_me === true || context.is_awaiting_me === true ? caseId : null
  } catch {
    assignedCaseId.value = null
  }
}

async function loadDetail(id: number): Promise<void> {
  if (selected.value?.id === id) return
  detailLoading.value = true
  detailError.value = ''
  detailRequestId.value = null
  try {
    const notice = await getNotice(id)
    selected.value = notice
    await loadAssignedCase(notice.approvalCaseId ?? null)
  } catch (reason) {
    selected.value = null
    assignedCaseId.value = null
    detailError.value = reason instanceof ApiError && reason.code === 'NOT_FOUND'
      ? '该公告不可见，可能已撤回或不在你的受众范围内。'
      : errorCopy(reason)
    detailRequestId.value = requestIdFromError(reason)
  } finally {
    detailLoading.value = false
  }
}

async function loadFromQuery(): Promise<void> {
  const id = queryNoticeId()
  const queryTab = Array.isArray(route?.query.tab) ? route.query.tab[0] : route?.query.tab
  activeTab.value = DETAIL_TABS.includes(queryTab as NoticeDetailTab)
    ? (queryTab as NoticeDetailTab)
    : 'content'
  templatesView.value = queryTab === 'templates' && id === null
  if (id === null) {
    selected.value = null
    assignedCaseId.value = null
    return
  }
  await loadDetail(id)
}

async function load(): Promise<void> {
  if (!canRead.value) return
  loading.value = true
  loadError.value = null
  partialReason.value = ''
  try {
    const page = await listNotices(filter.value)
    notices.value = page.items
    if (page.partial) partialReason.value = page.partialReason ?? '公告列表只返回了部分结果。'
    if (canManage.value) {
      orgUnits.value = await listOrgUnits(todayBusinessDate())
    }
  } catch (reason) {
    notices.value = []
    loadError.value = reason
  } finally {
    loading.value = false
  }
}

/**
 * Archiving and restoring are authoring actions, so they live in the same
 * workspace that publishes notices — the page that hosts these actions must
 * actually offer the controls (V3-B5).
 */
async function changeArchiveState(notice: Notice, archive: boolean): Promise<void> {
  archivingId.value = notice.id
  actionMessage.value = ''
  actionError.value = ''

  const reason = archive ? '公告工作台归档' : '公告工作台恢复'

  try {
    const updated = archive
      ? await archiveNotice(notice.id, reason)
      : await restoreNotice(notice.id, reason)

    notices.value = notices.value.map((row) => (row.id === updated.id ? updated : row))
    if (selected.value?.id === updated.id) selected.value = updated
    actionMessage.value = archive ? '公告已归档。' : '公告已恢复。'
  } catch (reason) {
    actionError.value = errorCopy(reason)
  } finally {
    archivingId.value = null
  }
}

async function openDetail(notice: Notice): Promise<void> {
  activeTab.value = 'content'
  templatesView.value = false
  syncQuery({ notice: String(notice.id), tab: 'content' })
  await loadDetail(notice.id)
}

function changeTab(tab: NoticeDetailTab): void {
  activeTab.value = tab
  syncQuery({ notice: selected.value?.id.toString(), tab })
}

function closeDetail(): void {
  selected.value = null
  assignedCaseId.value = null
  syncQuery({ notice: undefined, tab: undefined })
}

async function acknowledge(notice: Notice): Promise<void> {
  if (acknowledgingId.value !== null) return
  acknowledgingId.value = notice.id
  detailError.value = ''
  try {
    const updated = await acknowledgeNotice(notice.id)
    applyAcknowledged(updated)
  } catch (reason) {
    actionError.value = actionCopy(reason, '确认阅读未完成')
  } finally {
    acknowledgingId.value = null
  }
}

function applyAcknowledged(updated: Notice): void {
  replaceNotice(updated)
  selected.value = updated
  successMessage.value = '已确认阅读，确认记录已保存。'
}

function replaceNotice(updated: Notice): void {
  notices.value = notices.value.map((notice) => (notice.id === updated.id ? updated : notice))
}

function onPublished(created: Notice): void {
  successMessage.value = created.status === 'PENDING_APPROVAL'
    ? `公告已提交审批：${audienceText(created)}。`
    : `公告已发布：${audienceText(created)}可见。`
  filter.value = 'managed'
  void load()
}

async function submitApproval(notice: Notice): Promise<void> {
  if (actionBusy.value) return
  actionBusy.value = true
  actionError.value = ''
  try {
    const result = await submitNotice(notice.id)
    applyActionNotice(result.notice)
    actionMessage.value = result.caseId !== null
      ? `公告已提交审批，审批案例 ${result.caseId}。`
      : '公告已提交审批。'
    await loadAssignedCase(result.notice.approvalCaseId ?? null)
    activeTab.value = 'approval'
    syncQuery({ notice: String(notice.id), tab: 'approval' })
  } catch (reason) {
    actionError.value = actionCopy(reason, '提交审批未完成')
  } finally {
    actionBusy.value = false
  }
}

async function decide(notice: Notice, action: 'APPROVE' | 'REJECT'): Promise<void> {
  if (actionBusy.value) return
  actionBusy.value = true
  actionError.value = ''
  try {
    const result = await decideNotice(notice.id, action, action === 'APPROVE' ? '公告审批通过' : '公告审批驳回')
    applyActionNotice(result.notice)
    actionMessage.value = action === 'APPROVE' ? '公告审批已通过。' : '公告审批已驳回。'
    await loadAssignedCase(null)
  } catch (reason) {
    actionError.value = actionCopy(reason, '审批决定未完成')
  } finally {
    actionBusy.value = false
  }
}

async function publishApproved(notice: Notice): Promise<void> {
  if (actionBusy.value) return
  actionBusy.value = true
  actionError.value = ''
  try {
    const updated = await publishApprovedNotice(notice.id)
    applyActionNotice(updated)
    actionMessage.value = '公告已发布，通知只携带公告编号。'
    await load()
  } catch (reason) {
    actionError.value = actionCopy(reason, '公告发布未完成')
  } finally {
    actionBusy.value = false
  }
}

async function archiveFromDetail(notice: Notice): Promise<void> {
  await changeArchiveState(notice, true)
}

async function restoreFromDetail(notice: Notice): Promise<void> {
  await changeArchiveState(notice, false)
}

function applyActionNotice(updated: Notice): void {
  replaceNotice(updated)
  selected.value = updated
}

function actionCopy(reason: unknown, prefix: string): string {
  if (reason instanceof ApiError && (reason.code === 'FORBIDDEN' || reason.code === 'NOT_FOUND')) {
    return `${prefix}：当前账号不是该公告的指派审批人，或公告已不在可操作状态。`
  }
  if (reason instanceof ApiError && reason.code === 'CONFLICT') {
    return `${prefix}：公告状态已变化，请刷新后重试。`
  }
  return `${prefix}：${errorCopy(reason)}`
}
function selectFilter(next: NoticeFilter): void {
  filter.value = next
  void load()
}

onMounted(() => {
  if (!canRead.value) return
  void load()
  void loadRoleCatalog()
  void loadFromQuery()
})

watch(
  () => [route?.query.notice, route?.query.tab],
  () => {
    if (!canRead.value) return
    void loadFromQuery()
  },
)

function openTemplatesView(): void {
  templatesView.value = true
  selected.value = null
  activeTab.value = 'templates'
  syncQuery({ notice: undefined, tab: 'templates' })
}

function closeTemplatesView(): void {
  templatesView.value = false
  syncQuery({ tab: undefined })
}
</script>

<template>
  <div class="page notices-page" data-testid="notices-page">
    <header class="page-header">
      <div class="page-header__copy">
        <p class="page-header__breadcrumb">公告通知</p>
        <h1 class="page-header__title">公告工作台</h1>
        <p class="page-header__description">
          公告、审批、受众、阅读回执与模板统一在此工作台完成。通知只携带公告编号，详情始终以公告接口为准。
        </p>
      </div>
      <div class="page-header__actions">
        <span v-if="unacknowledgedCount > 0" class="plain-chip">{{ unacknowledgedCount }} 条待确认</span>
        <el-button v-if="canRead" plain data-testid="notice-templates-entry" @click="openTemplatesView">
          模板设置
        </el-button>
      </div>
    </header>

    <section v-if="!canRead" class="panel notices-forbidden" data-testid="notices-forbidden">
      <h2 class="panel__title">无权查看公告</h2>
      <p class="panel__subtitle">当前账号缺少公告读取能力。如确需访问，请提交授权申请。</p>
    </section>

    <template v-else>
      <section v-if="templatesView" class="panel" data-testid="notice-templates-view">
        <div class="panel__header">
          <div>
            <h2 class="panel__title">公告模板</h2>
            <p class="panel__subtitle">模板按代码追加版本，历史版本不被改写。</p>
          </div>
          <el-button text data-testid="notice-templates-close" @click="closeTemplatesView">返回公告列表</el-button>
        </div>
        <div class="panel__body">
          <NoticeDetailDrawer
            :notice="null"
            :active-tab="'templates'"
            :can-manage="canManage"
            :can-publish="canPublish"
            :can-export="canExport"
            :org-units="orgUnits"
            :role-options="roleOptions"
            :assigned-case-id="assignedCaseId"
            :busy="actionBusy"
            standalone-templates
            @tab-change="changeTab"
            @closed="closeTemplatesView"
          />
        </div>
      </section>

      <section v-if="canManage && !templatesView" data-testid="notice-composer-host">
        <NoticeComposer :org-units="orgUnits" :roles="roleOptions" :roles-unavailable="rolesUnavailable" @created="onPublished" />
      </section>

      <div v-if="!templatesView" class="notice-toolbar">
        <div class="notice-filters" role="group" aria-label="公告筛选">
          <button
            v-for="item in filters"
            :key="item.key"
            type="button"
            class="notice-filter"
            :class="{ 'notice-filter--active': filter === item.key }"
            :aria-pressed="filter === item.key"
            @click="selectFilter(item.key)"
          >
            {{ item.label }}
          </button>
        </div>
      </div>

      <p v-if="successMessage" class="notice-success" role="status" data-testid="notice-list-success">{{ successMessage }}</p>
      <p v-if="actionMessage" class="notice-success" role="status" data-testid="notice-action-success">{{ actionMessage }}</p>
      <p v-if="actionError" class="notice-error" role="alert" data-testid="notice-action-error">{{ actionError }}</p>

      <LoadingState v-if="loading" :rows="6" label="正在加载公告" data-testid="notices-loading" />

      <ErrorState
        v-else-if="loadError"
        description="公告暂时无法加载。"
        :code="errorMessage"
        :request-id="errorRequestId"
        data-testid="notices-error"
        @retry="load"
      />

      <template v-else>
        <el-alert v-if="partialReason" type="warning" :closable="false" show-icon data-testid="notices-partial">
          <template #title>部分公告：{{ partialReason }}</template>
        </el-alert>

        <p v-if="detailError && selected === null" class="notice-error" role="alert" data-testid="notice-detail-error">
          {{ detailError }}
          <span v-if="detailRequestId" class="mono small">（请求编号：{{ detailRequestId }}）</span>
        </p>

        <div v-if="!templatesView" class="notice-layout">
          <section class="stack notice-feed" aria-label="公告列表">
            <EmptyState
              v-if="notices.length === 0"
              :title="filter === 'unread' ? '没有待确认的公告' : '暂无公告'"
              :description="filter === 'unread' ? '所有需要确认的公告都已完成阅读确认。' : '有新的公告发布时会出现在这里。'"
              data-testid="notices-empty"
            />
            <article
              v-for="notice in notices"
              :key="notice.id"
              class="panel notice-card"
              data-testid="notice-item"
            >
              <div class="notice-card__top">
                <span class="plain-chip">{{ noticeStatusText(notice.status) }}</span>
                <span v-if="notice.requiresAck && notice.acknowledged" class="notice-ack" data-testid="notice-acknowledged">
                  ✓ 已确认
                </span>
                <span v-else-if="notice.requiresAck" class="notice-pending">待确认</span>
                <span class="small muted">{{ audienceText(notice) }}</span>
              </div>
              <h2 class="notice-card__title">{{ notice.title }}</h2>
              <p class="notice-card__summary">{{ notice.summary }}</p>
              <div class="notice-card__footer">
                <span class="small muted">{{ formatDateTimeInTimezone(notice.publishedAt) }}</span>
                <span v-if="notice.requiresAck" class="small muted">
                  已确认 {{ notice.acknowledgementCount }} / {{ notice.audienceCount }}
                </span>
                <el-button
                  size="small"
                  plain
                  :data-testid="`notice-detail-${notice.id}`"
                  @click="openDetail(notice)"
                >
                  查看详情
                </el-button>
                <el-button
                  v-if="canManage && notice.status !== 'ARCHIVED'"
                  size="small"
                  type="warning"
                  plain
                  :loading="archivingId === notice.id"
                  :data-testid="`notice-archive-${notice.id}`"
                  @click="changeArchiveState(notice, true)"
                >
                  归档
                </el-button>
                <el-button
                  v-if="canManage && notice.status === 'ARCHIVED'"
                  size="small"
                  type="success"
                  plain
                  :loading="archivingId === notice.id"
                  :data-testid="`notice-restore-${notice.id}`"
                  @click="changeArchiveState(notice, false)"
                >
                  恢复
                </el-button>
              </div>
            </article>
          </section>

          <LoadingState v-if="detailLoading && selected === null" :rows="4" label="正在加载公告详情" data-testid="notice-detail-loading" />
          <aside
            v-else-if="selected && !hasRouter"
            class="panel notice-detail"
            data-testid="notice-detail"
          >
            <div class="panel__header">
              <div>
                <p class="page-header__breadcrumb">公告详情</p>
                <h2 class="panel__title">{{ selected.title }}</h2>
              </div>
              <el-button text data-testid="notice-detail-close" @click="closeDetail">关闭</el-button>
            </div>
            <div class="panel__body notice-detail__body">
              <p v-for="(line, index) in selected.body" :key="index">{{ line }}</p>
              <div v-if="selected.requiresAck" class="notice-detail__ack">
                <span v-if="selected.acknowledged" class="notice-ack" data-testid="notice-detail-acknowledged">
                  ✓ 已确认阅读
                </span>
                <el-button
                  v-else
                  type="primary"
                  :loading="acknowledgingId === selected.id"
                  :data-testid="`notice-acknowledge-${selected.id}`"
                  @click="acknowledge(selected)"
                >
                  确认已读
                </el-button>
              </div>
            </div>
          </aside>
          <NoticeDetailDrawer
            v-else-if="selected"
            :notice="selected"
            :active-tab="activeTab"
            :can-manage="canManage"
            :can-publish="canPublish"
            :can-export="canExport"
            :org-units="orgUnits"
            :role-options="roleOptions"
            :assigned-case-id="assignedCaseId"
            :busy="actionBusy || detailLoading"
            @tab-change="changeTab"
            @submitted="submitApproval"
            @decided="(notice, decision) => decide(notice, decision)"
            @published="publishApproved"
            @archived="archiveFromDetail"
            @restored="restoreFromDetail"
            @acknowledged="applyAcknowledged"
            @closed="closeDetail"
          />
        </div>
      </template>
    </template>
  </div>
</template>

<style scoped>
.notice-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.notice-filters {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.notice-filter {
  padding: 6px 12px;
  font: inherit;
  font-size: 11.5px;
  color: var(--color-ink-secondary);
  background: #fff;
  border: 1px solid var(--color-line);
  border-radius: 999px;
  cursor: pointer;
}

.notice-filter--active {
  color: #fff;
  font-weight: 600;
  background: var(--color-brand);
  border-color: var(--color-brand);
}

.notice-layout {
  display: grid;
  grid-template-columns: minmax(0, 1.5fr) minmax(300px, 1fr);
  gap: 16px;
  align-items: start;
}

.notice-feed {
  gap: 10px;
}

.notice-card {
  padding: 14px 16px;
}

.notice-card__top {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
}

.notice-card__title {
  margin: 8px 0 4px;
  font-size: 14px;
}

.notice-card__summary {
  margin: 0;
  color: var(--color-ink-secondary);
  font-size: 12px;
  line-height: 1.55;
}

.notice-card__footer {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  margin-top: 10px;
}

.notice-ack {
  padding: 2px 8px;
  color: #1d5b43;
  font-size: 11px;
  font-weight: 600;
  background: #f1faf5;
  border: 1px solid #bfe3d0;
  border-radius: 999px;
}

.notice-pending {
  padding: 2px 8px;
  color: #8a5a12;
  font-size: 11px;
  font-weight: 600;
  background: #fffbf0;
  border: 1px solid #e7c98a;
  border-radius: 999px;
}

.notice-detail__body {
  display: flex;
  flex-direction: column;
  gap: 8px;
  font-size: 12.5px;
  line-height: 1.65;
}

.notice-detail__body p {
  margin: 0;
}

.notice-detail__ack {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 8px;
  padding-top: 10px;
  border-top: 1px solid var(--color-line);
}

.notice-error,
.notice-success {
  margin: 0;
  padding: 9px 11px;
  font-size: 12px;
  border-radius: var(--radius-sm);
}

.notice-error {
  color: #8a2c2c;
  background: #fff4f2;
  border: 1px solid #f0c7c0;
}

.notice-success {
  color: #1d5b43;
  background: #f1faf5;
  border: 1px solid #bfe3d0;
}

.notices-forbidden {
  max-width: 560px;
}

@media (max-width: 980px) {
  .notice-layout {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
