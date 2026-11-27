<script setup lang="ts">
import { computed } from 'vue'
import { errorCopy } from '@/api/errors'
import { formatDateTimeInTimezone } from '@/utils/format'
import type { AccountSession } from '../api/account'

const props = withDefaults(
  defineProps<{
    sessions: AccountSession[]
    loading?: boolean
    error?: unknown
    revokingId?: string | null
    canManage?: boolean
  }>(),
  { loading: false, revokingId: null, canManage: true },
)

const emit = defineEmits<{
  revoke: [session: AccountSession]
  revokeAll: []
  retry: []
}>()

const hasError = computed(() => props.error !== null && props.error !== undefined)
const errorMessage = computed(() => (hasError.value ? errorCopy(props.error) : ''))
const hasSessions = computed(() => !props.loading && !hasError.value && props.sessions.length > 0)
const isEmpty = computed(() => !props.loading && !hasError.value && props.sessions.length === 0)

function seenAt(session: AccountSession): string {
  return formatDateTimeInTimezone(session.lastSeenAt)
}

function deviceIcon(session: AccountSession): string {
  const device = session.device.toLowerCase()
  if (device.includes('ios') || device.includes('android')) return '手机'
  if (device.includes('命令行')) return '终端'
  return '电脑'
}
</script>

<template>
  <section class="session-list" aria-labelledby="session-list-title" data-testid="session-list">
    <header class="session-list__header">
      <div>
        <h3 id="session-list-title" class="session-list__title">登录设备与会话</h3>
        <p class="session-list__copy">
          标记为“当前”的会话来自本浏览器。撤销后该设备需要重新登录；其他用户无法查看或撤销你的会话。
        </p>
      </div>
      <el-button
        v-if="sessions.length > 0 && canManage"
        type="danger"
        plain
        data-testid="revoke-all-sessions"
        @click="emit('revokeAll')"
      >
        退出全部会话
      </el-button>
    </header>

    <div v-if="loading" class="session-list__loading" role="status" aria-live="polite">
      <span v-for="index in 3" :key="index" class="session-list__skeleton" />
      <span class="visually-hidden">正在加载登录会话</span>
    </div>

    <el-alert
      v-else-if="hasError"
      type="error"
      :closable="false"
      show-icon
      title="登录会话加载失败"
      data-testid="sessions-error"
    >
      <p class="session-list__error-copy">{{ errorMessage }}</p>
      <el-button text size="small" data-testid="sessions-retry" @click="emit('retry')">重试</el-button>
    </el-alert>

    <div v-else-if="isEmpty" class="session-list__empty" role="status" data-testid="sessions-empty">
      <p>当前没有可查看的登录会话。</p>
      <p class="session-list__muted">重新登录后可在此查看设备、IP 与最近活动时间。</p>
    </div>

    <ul v-else-if="hasSessions" class="session-list__items">
      <li
        v-for="session in sessions"
        :key="session.id"
        class="session-list__item"
        :class="{ 'session-list__item--current': session.current }"
        :data-testid="`session-row-${session.id}`"
      >
        <div class="session-list__device">
          <span class="session-list__badge" aria-hidden="true">{{ deviceIcon(session) }}</span>
          <div>
            <p class="session-list__device-name">
              {{ session.device }}
              <span v-if="session.current" class="plain-chip plain-chip--success" data-testid="current-session-marker">
                当前会话
              </span>
            </p>
            <p class="session-list__meta">
              IP：{{ session.ip ?? '未记录' }} · 最近活动：{{ seenAt(session) }}
            </p>
            <p class="session-list__meta">
              二次验证：{{ session.mfaVerified ? '本次会话已通过' : '未进行' }}
            </p>
          </div>
        </div>

        <div class="session-list__actions">
          <el-button
            v-if="canManage"
            text
            type="danger"
            :loading="revokingId === session.id"
            :disabled="revokingId !== null"
            :data-testid="`revoke-session-${session.id}`"
            @click="emit('revoke', session)"
          >
            {{ session.current ? '退出此会话' : '撤销会话' }}
          </el-button>
          <span v-else class="session-list__muted">无撤销权限</span>
        </div>
      </li>
    </ul>
  </section>
</template>

<style scoped>
.session-list {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.session-list__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
}

.session-list__title {
  margin: 0 0 4px;
  font-size: 15px;
}

.session-list__copy,
.session-list__muted,
.session-list__meta {
  margin: 0;
  color: var(--color-ink-secondary);
  font-size: 12px;
  line-height: 1.7;
}

.session-list__muted {
  color: var(--muted);
}

.session-list__items {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 0;
  margin: 0;
  list-style: none;
}

.session-list__item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 12px 14px;
  border: 1px solid var(--color-border, #dde3e1);
  border-radius: 8px;
  background: var(--color-surface, #fff);
}

.session-list__item--current {
  border-color: var(--color-brand, #0f4956);
  background: var(--color-brand-soft, #eef5f3);
}

.session-list__device {
  display: flex;
  gap: 12px;
  align-items: flex-start;
}

.session-list__badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 34px;
  height: 34px;
  color: var(--color-brand-deep, #0f4956);
  font-size: 11px;
  border-radius: 8px;
  background: var(--color-brand-soft, #eef5f3);
}

.session-list__device-name {
  display: flex;
  gap: 8px;
  align-items: center;
  margin: 0 0 2px;
  font-weight: 600;
}

.session-list__actions {
  display: flex;
  flex-shrink: 0;
}

.session-list__empty {
  padding: 20px 16px;
  text-align: center;
  border: 1px dashed var(--color-border, #dde3e1);
  border-radius: 8px;
}

.session-list__loading {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.session-list__skeleton {
  height: 56px;
  border-radius: 8px;
  background: linear-gradient(90deg, #f2f5f4 25%, #e8edeb 37%, #f2f5f4 63%);
  background-size: 400% 100%;
  animation: session-shimmer 1.4s ease infinite;
}

@keyframes session-shimmer {
  0% { background-position: 100% 50%; }
  100% { background-position: 0 50%; }
}
</style>
