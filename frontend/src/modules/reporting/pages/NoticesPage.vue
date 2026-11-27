<script setup lang="ts">
import { computed, ref } from 'vue'
import {
  acknowledgeNotice,
  archiveNotice,
  restoreNotice,
  audienceText,
  getNotice,
  listNotices,
  listOrgUnits,
  noticeStatusText,
  type Notice,
  type OrgUnitOption,
} from '../api'
import { errorCopy, ApiError } from '@/api/errors'
import { formatDateTimeInTimezone, todayBusinessDate } from '@/utils/format'
import { requestIdFromError } from '@/utils/renderErrors'
import { useSessionStore } from '@/stores/session'
import LoadingState from '@/components/LoadingState.vue'
import EmptyState from '@/components/EmptyState.vue'
import ErrorState from '@/components/ErrorState.vue'
import NoticeComposer from '../components/NoticeComposer.vue'

type NoticeFilter = 'all' | 'unread' | 'managed'

const session = useSessionStore()
const filter = ref<NoticeFilter>('all')
const notices = ref<Notice[]>([])
const loading = ref(false)
const loadError = ref<unknown>(null)
const partialReason = ref('')
const selected = ref<Notice | null>(null)
const archivingId = ref<number | null>(null)
const actionMessage = ref('')
const actionError = ref('')
const detailLoading = ref(false)
const detailError = ref<string>('')
const acknowledgingId = ref<number | null>(null)
const orgUnits = ref<OrgUnitOption[]>([])
const successMessage = ref('')
const detailRequestId = ref<string | null>(null)

const canRead = computed(() => session.can('engagement.notice.read'))
const canManage = computed(() => session.can('engagement.notice.manage'))
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

async function load(): Promise<void> {
  if (!canRead.value) return
  loading.value = true
  loadError.value = null
  partialReason.value = ''
  selected.value = null
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
  detailLoading.value = true
  detailError.value = ''
  detailRequestId.value = null
  selected.value = notice
  try {
    selected.value = await getNotice(notice.id)
  } catch (reason) {
    if (reason instanceof ApiError && reason.code === 'NOT_FOUND') {
      detailError.value = '该公告不可见，可能已撤回或不在你的受众范围内。'
    } else {
      detailError.value = errorCopy(reason)
    }
    detailRequestId.value = requestIdFromError(reason)
  } finally {
    detailLoading.value = false
  }
}

async function acknowledge(notice: Notice): Promise<void> {
  acknowledgingId.value = notice.id
  successMessage.value = ''
  try {
    const updated = await acknowledgeNotice(notice.id)
    replaceNotice(updated)
    if (selected.value?.id === updated.id) selected.value = updated
    successMessage.value = '已确认阅读，确认记录已保存。'
  } catch (reason) {
    detailError.value = errorCopy(reason)
  } finally {
    acknowledgingId.value = null
  }
}

function replaceNotice(updated: Notice): void {
  notices.value = notices.value.map((notice) => (notice.id === updated.id ? updated : notice))
}

function onPublished(created: Notice): void {
  successMessage.value = `公告已发布：${audienceText(created)}可见。`
  filter.value = 'managed'
  void load()
}

function selectFilter(next: NoticeFilter): void {
  filter.value = next
  void load()
}

function bodyLines(notice: Notice): string[] {
  return notice.body
}

void load()
</script>

<template>
  <div class="page notices-page" data-testid="notices-page">
    <header class="page-header">
      <div class="page-header__copy">
        <p class="page-header__breadcrumb">公告通知</p>
        <h1 class="page-header__title">公告工作台</h1>
        <p class="page-header__description">
          公告按组织范围发布；需要确认的公告在列表和详情中标记阅读状态。页面只展示接口实际返回的公告。
        </p>
      </div>
      <div class="page-header__actions">
        <span v-if="unacknowledgedCount > 0" class="plain-chip">{{ unacknowledgedCount }} 条待确认</span>
      </div>
    </header>

    <section v-if="!canRead" class="panel notices-forbidden" data-testid="notices-forbidden">
      <h2 class="panel__title">无权查看公告</h2>
      <p class="panel__subtitle">当前账号缺少公告读取能力。如确需访问，请提交授权申请。</p>
    </section>

    <template v-else>
      <section v-if="canManage" data-testid="notice-composer-host">
        <NoticeComposer :org-units="orgUnits" @created="onPublished" />
      </section>

      <div class="notice-toolbar">
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

        <EmptyState
          v-if="notices.length === 0"
          :title="filter === 'unread' ? '没有待确认的公告' : '暂无公告'"
          :description="filter === 'unread' ? '所有需要确认的公告都已完成阅读确认。' : '有新的公告发布时会出现在这里。'"
          data-testid="notices-empty"
        />

        <div v-else class="notice-layout">
          <section class="stack notice-feed" aria-label="公告列表">
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

          <section v-if="selected" class="panel notice-detail" data-testid="notice-detail">
            <div class="panel__header">
              <div>
                <h2 class="panel__title">{{ selected.title }}</h2>
                <p class="panel__subtitle">
                  {{ audienceText(selected) }} · {{ formatDateTimeInTimezone(selected.publishedAt) }}
                </p>
              </div>
              <span class="plain-chip">{{ noticeStatusText(selected.status) }}</span>
            </div>

            <LoadingState v-if="detailLoading" :rows="4" label="正在加载公告详情" />
            <p v-else-if="detailError" class="notice-error" role="alert" data-testid="notice-detail-error">
              {{ detailError }}
              <span v-if="detailRequestId" class="mono small">（请求编号：{{ detailRequestId }}）</span>
            </p>
            <div v-else class="panel__body notice-detail__body">
              <p v-for="(line, index) in bodyLines(selected)" :key="index">{{ line }}</p>

              <div v-if="selected.requiresAck" class="notice-detail__ack">
                <span v-if="selected.acknowledged" class="notice-ack" data-testid="notice-detail-acknowledged">
                  ✓ 已确认阅读
                </span>
                <template v-else>
                  <p class="small muted">该公告需要阅读确认，确认后将记录你的账号与时间。</p>
                  <el-button
                    type="primary"
                    :loading="acknowledgingId === selected.id"
                    :data-testid="`notice-acknowledge-${selected.id}`"
                    @click="acknowledge(selected)"
                  >
                    确认已读
                  </el-button>
                </template>
              </div>
              <p v-else class="small muted">该公告不需要阅读确认。</p>
            </div>
          </section>
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
