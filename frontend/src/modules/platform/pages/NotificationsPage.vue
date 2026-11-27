<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { RouterLink } from 'vue-router'
import PageShell from '@/shared/ui/PageShell.vue'
import { useSessionStore } from '@/stores/session'
import { formatDateTimeInTimezone } from '@/utils/format'
import { isForbidden, platformErrorCopy } from '../api'
import {
  listInboxNotifications,
  markInboxNotificationRead,
  type InboxNotification,
} from '../api/notifications'
import { notificationTypeLabel } from '../labels'

type InboxFilter = 'all' | 'unread' | 'read'

const session = useSessionStore()
const canRead = computed(() => session.can('notification.read'))

const loading = ref(true)
const loadError = ref<unknown>(null)
const notifications = ref<InboxNotification[]>([])
const filter = ref<InboxFilter>('all')
const selectedType = ref('')
const marking = ref<number | null>(null)
const markingAll = ref(false)
const partial = ref(false)
const partialReason = ref('')
const actionError = ref('')
const actionMessage = ref('')

const unreadCount = computed(() => notifications.value.filter((item) => !item.read).length)

const typeOptions = computed(() => {
  const seen = new Map<string, { type: string; label: string; count: number }>()
  for (const item of notifications.value) {
    const current = seen.get(item.type)
    if (current === undefined) {
      seen.set(item.type, { type: item.type, label: notificationTypeLabel(item.type), count: 1 })
    } else {
      current.count += 1
    }
  }
  return [...seen.values()]
})

const rows = computed(() => {
  const filtered = notifications.value.filter((item) => {
    if (filter.value === 'unread' && item.read) return false
    if (filter.value === 'read' && !item.read) return false
    if (selectedType.value !== '' && item.type !== selectedType.value) return false
    return true
  })
  return filtered.map((item) => ({
    ...item,
    label: notificationTypeLabel(item.type),
    summary: notificationSummary(item),
    link: notificationLink(item),
    statusLabel: item.read ? '已读' : '未读',
    statusTone: item.read ? 'plain-chip--muted' : 'plain-chip--warning',
    time: formatDateTimeInTimezone(item.createdAt),
  }))
})

const isEmpty = computed(
  () => !loading.value && loadError.value === null && rows.value.length === 0,
)

const emptyTitle = computed(() => {
  if (notifications.value.length === 0) return '当前没有站内通知'
  if (filter.value === 'unread') return '没有未读通知'
  if (selectedType.value !== '') return `没有${notificationTypeLabel(selectedType.value)}通知`
  return '当前筛选下没有通知'
})

function notificationSummary(item: InboxNotification): string {
  if (item.type === 'NOTICE_PUBLISHED') {
    const requiresAck = item.payload.requires_ack === true
    return requiresAck ? '公司公告已发布，需要阅读并确认。' : '公司公告已发布，点击查看详情。'
  }
  if (item.type === 'APPROVAL_PENDING') return '有新的审批任务等待处理。'
  if (item.type === 'CASE_APPROVED') return '你发起的审批已通过，请查看业务结果。'
  if (item.type === 'CASE_REJECTED') return '你发起的审批被驳回，请查看审批意见。'
  if (item.type === 'CASE_ASSIGNED') return '有审批案例指派给你。'
  return '有新的业务通知，请查看关联对象。'
}

function notificationLink(item: InboxNotification): string | null {
  const noticeId = item.payload.notice_id
  if (item.type === 'NOTICE_PUBLISHED' && typeof noticeId === 'number') {
    return '/notices'
  }
  const caseId = item.payload.case_id
  if (typeof caseId === 'number' && item.type !== 'NOTICE_PUBLISHED') {
    return `/workflow/cases/${caseId}`
  }
  return null
}

async function loadNotifications(): Promise<void> {
  loading.value = true
  loadError.value = null
  partial.value = false
  partialReason.value = ''
  actionError.value = ''
  actionMessage.value = ''
  try {
    notifications.value = await listInboxNotifications()
  } catch (error) {
    notifications.value = []
    loadError.value = error
  } finally {
    loading.value = false
  }
}

function changeFilter(next: InboxFilter): void {
  filter.value = next
}

function selectType(next: string): void {
  selectedType.value = next
}

async function markRead(item: InboxNotification): Promise<void> {
  if (item.read || marking.value !== null || markingAll.value) return
  marking.value = item.id
  actionError.value = ''
  actionMessage.value = ''
  try {
    const updated = await markInboxNotificationRead(item.id)
    notifications.value = notifications.value.map((notification) =>
      notification.id === updated.id ? updated : notification,
    )
    actionMessage.value = '已标记为已读。'
    ElMessage.success(actionMessage.value)
  } catch (error) {
    actionError.value = platformErrorCopy(error)
    ElMessage.error(actionError.value)
  } finally {
    marking.value = null
  }
}

async function markAllRead(): Promise<void> {
  const pending = notifications.value.filter((item) => !item.read)
  if (pending.length === 0 || markingAll.value) return
  markingAll.value = true
  actionError.value = ''
  actionMessage.value = ''
  partial.value = false
  partialReason.value = ''
  let completed = 0
  for (const item of pending) {
    try {
      const updated = await markInboxNotificationRead(item.id)
      notifications.value = notifications.value.map((notification) =>
        notification.id === updated.id ? updated : notification,
      )
      completed += 1
    } catch (error) {
      actionError.value = platformErrorCopy(error)
      partial.value = true
      partialReason.value = completed === 0
        ? '全部标记已读未完成，未读通知保持不变。'
        : `已标记 ${completed} 条，剩余未读通知未能标记；请稍后重试。`
      ElMessage.error(actionError.value)
      markingAll.value = false
      return
    }
  }
  actionMessage.value = '全部未读通知已标记为已读。'
  ElMessage.success(actionMessage.value)
  markingAll.value = false
}

function retry(): void {
  void loadNotifications()
}

onMounted(() => {
  if (!canRead.value) {
    loading.value = false
    return
  }
  void loadNotifications()
})
</script>

<template>
  <PageShell
    data-testid="notifications-page"
    title="消息与通知中心"
    description="查看当前账号自己的站内业务通知，按业务类型和阅读状态筛选，并标记已读；列表只返回当前账号的消息。"
  >
    <template #actions>
      <el-button plain :disabled="loading || markingAll" @click="retry">刷新</el-button>
      <el-button
        v-if="canRead && unreadCount > 0 && filter !== 'read'"
        type="primary"
        plain
        :loading="markingAll"
        data-testid="notification-mark-all-read"
        @click="markAllRead"
      >
        全部标记已读
      </el-button>
    </template>

    <section v-if="!canRead" class="panel" data-testid="notification-forbidden">
      <div class="panel__body">
        <h2 class="panel__title">无权查看消息通知</h2>
        <p class="muted">当前账号没有站内通知查看权限。请联系系统管理员授予通知查看能力。</p>
      </div>
    </section>

    <template v-else>
      <p v-if="actionMessage" class="inline-success" data-testid="notification-action-message">{{ actionMessage }}</p>
      <p v-if="actionError" class="inline-error" role="alert" data-testid="notification-action-error">{{ actionError }}</p>
      <p v-if="partial" class="inline-warning" data-testid="notification-partial">{{ partialReason }}</p>

      <section class="panel" data-testid="notification-filters">
        <div class="panel__body notification-toolbar">
          <div class="notification-filter-group" role="group" aria-label="按阅读状态筛选">
            <span class="notification-filter-label">阅读状态</span>
            <button
              v-for="option in ([
                { key: 'all', label: `全部 ${notifications.length}` },
                { key: 'unread', label: `未读 ${unreadCount}` },
                { key: 'read', label: '已读' },
              ] as const)"
              :key="option.key"
              type="button"
              class="notification-tab"
              :class="{ 'notification-tab--active': filter === option.key }"
              :aria-pressed="filter === option.key"
              :data-testid="`notification-filter-${option.key}`"
              @click="changeFilter(option.key)"
            >
              {{ option.label }}
            </button>
          </div>
          <div v-if="typeOptions.length > 0" class="notification-filter-group" role="group" aria-label="按业务类型筛选">
            <span class="notification-filter-label">业务类型</span>
            <button
              type="button"
              class="notification-tab"
              :class="{ 'notification-tab--active': selectedType === '' }"
              :aria-pressed="selectedType === ''"
              data-testid="notification-type-all"
              @click="selectType('')"
            >
              全部类型
            </button>
            <button
              v-for="option in typeOptions"
              :key="option.type"
              type="button"
              class="notification-tab"
              :class="{ 'notification-tab--active': selectedType === option.type }"
              :aria-pressed="selectedType === option.type"
              :data-testid="`notification-type-${option.type}`"
              @click="selectType(option.type)"
            >
              {{ option.label }} {{ option.count }}
            </button>
          </div>
        </div>
        <div class="panel__body notification-preference">
          <span class="muted small" data-testid="notification-channel-unavailable">
            渠道偏好设置：该功能暂不可用。当前仅支持站内消息查看与已读标记，邮件、短信等通知渠道无法在此页面配置。
          </span>
        </div>
      </section>

      <section v-if="loading" class="panel" data-testid="notification-loading">
        <div class="panel__body">
          <el-skeleton :rows="4" animated />
          <span class="visually-hidden">正在加载站内通知</span>
        </div>
      </section>

      <section
        v-else-if="loadError !== null && isForbidden(loadError)"
        class="panel"
        data-testid="notification-forbidden"
      >
        <div class="panel__body">
          <h2 class="panel__title">消息通知不可查看</h2>
          <p class="muted">当前账号没有站内通知查看权限，或该账号已被停用。请联系系统管理员确认。</p>
        </div>
      </section>

      <section v-else-if="loadError !== null" class="panel" data-testid="notification-error">
        <div class="panel__body">
          <h2 class="panel__title">站内通知加载失败</h2>
          <p class="muted">{{ platformErrorCopy(loadError) }}</p>
          <el-button type="primary" plain @click="retry">重试</el-button>
        </div>
      </section>

      <section v-else-if="isEmpty" class="panel" data-testid="notification-empty">
        <div class="panel__body">
          <h2 class="panel__title">{{ emptyTitle }}</h2>
          <p class="muted">
            {{ notifications.length === 0
              ? '新的审批、公告与业务提醒会出现在这里。'
              : '调整阅读状态或业务类型筛选后再查看。' }}
          </p>
        </div>
      </section>

      <section v-else class="panel">
        <div class="panel__header">
          <div>
            <h2 class="panel__title">通知列表</h2>
            <p class="panel__subtitle">未读 {{ unreadCount }} 条 · 共 {{ notifications.length }} 条 · 当前筛选 {{ rows.length }} 条</p>
          </div>
        </div>
        <ul class="notification-list">
          <li
            v-for="item in rows"
            :key="item.id"
            class="notification-row"
            :class="{ 'notification-row--unread': !item.read }"
            :data-testid="`notification-row-${item.id}`"
          >
            <div class="notification-row__main">
              <div class="notification-row__heading">
                <strong>{{ item.label }}</strong>
                <span v-if="!item.read" class="plain-chip plain-chip--warning" :data-testid="`notification-unread-${item.id}`">未读</span>
                <span v-else class="plain-chip plain-chip--muted">{{ item.statusLabel }}</span>
                <time :datetime="item.createdAt ?? undefined">{{ item.time }}</time>
              </div>
              <p class="notification-row__summary">{{ item.summary }}</p>
            </div>
            <div class="notification-row__actions">
              <RouterLink v-if="item.link !== null" :to="item.link" class="notification-link">查看关联对象</RouterLink>
              <el-button
                v-if="!item.read"
                text
                size="small"
                :loading="marking === item.id"
                :disabled="markingAll"
                :data-testid="`notification-mark-read-${item.id}`"
                @click="markRead(item)"
              >
                标记已读
              </el-button>
            </div>
          </li>
        </ul>
      </section>
    </template>
  </PageShell>
</template>

<style scoped>
.notification-toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px 18px;
}
.notification-filter-group {
  display: inline-flex;
  flex-wrap: wrap;
  gap: 4px;
  align-items: center;
  padding: 3px;
  background: var(--color-canvas);
  border: 1px solid var(--color-line);
  border-radius: 8px;
}
.notification-filter-label {
  padding: 0 7px;
  color: var(--color-ink-muted);
  font-size: 11px;
}
.notification-tab {
  padding: 6px 11px;
  color: var(--color-ink-secondary);
  font: inherit;
  font-size: 12px;
  cursor: pointer;
  background: transparent;
  border: 0;
  border-radius: 6px;
}
.notification-tab--active {
  color: var(--color-brand-deep, #0f4956);
  font-weight: 650;
  background: #fff;
  box-shadow: 0 1px 3px rgb(14 55 65 / 12%);
}
.notification-preference {
  padding-top: 0;
}
.notification-list {
  display: flex;
  flex-direction: column;
  gap: 0;
  padding: 0;
  margin: 0;
  list-style: none;
}
.notification-row {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  padding: 13px 16px;
  border-top: 1px solid var(--color-line);
}
.notification-row--unread {
  background: #f9fcfd;
}
.notification-row__main {
  flex: 1 1 380px;
  min-width: 260px;
}
.notification-row__heading {
  display: flex;
  flex-wrap: wrap;
  gap: 7px;
  align-items: center;
  font-size: 13px;
}
.notification-row__heading time {
  color: var(--color-ink-muted);
  font-size: 11px;
}
.notification-row__summary {
  margin: 5px 0 0;
  color: var(--color-ink-secondary);
  font-size: 12px;
  line-height: 1.6;
}
.notification-row__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
}
.notification-link {
  color: var(--color-brand);
  font-size: 12px;
  text-decoration: none;
}
.notification-link:hover,
.notification-link:focus-visible {
  text-decoration: underline;
}
.plain-chip--warning {
  color: #8a5a00;
  background: #fff6e5;
  border-color: #ead49f;
}
.plain-chip--muted {
  color: var(--color-ink-muted);
}
.inline-warning {
  padding: 9px 12px;
  color: #8a5a00;
  font-size: 12px;
  background: #fff6e5;
  border: 1px solid #ead49f;
  border-radius: 7px;
}
</style>
