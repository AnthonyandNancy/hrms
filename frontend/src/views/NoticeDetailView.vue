<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { getApi } from '@/api'
import type { Notice } from '@/api/contracts'
import { usePageError } from '@/composables/usePageError'
import { formatDateTimeInTimezone } from '@/utils/format'
import LoadingState from '@/components/LoadingState.vue'
import ErrorState from '@/components/ErrorState.vue'

const props = defineProps<{ id: string }>()
const router = useRouter()
const loading = ref(true)
const pageError = usePageError()
const notice = ref<Notice | null>(null)
const acknowledging = ref(false)

async function load(): Promise<void> {
  loading.value = true
  pageError.clear()
  try {
    notice.value = await getApi().notices.getNotice(Number(props.id))
  } catch (reason) {
    pageError.capture(reason)
  } finally {
    loading.value = false
  }
}

async function acknowledge(): Promise<void> {
  if (!notice.value) return
  acknowledging.value = true
  try {
    notice.value = await getApi().notices.acknowledgeNotice(notice.value.id)
    ElMessage.success('已确认阅读。')
  } catch (reason) {
    pageError.reject(reason)
  } finally {
    acknowledging.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="page notice-detail-page">
    <div class="row">
      <el-button text @click="router.push('/notices')">← 返回公告中心</el-button>
    </div>

    <LoadingState v-if="loading" :rows="8" label="正在加载公告" />
    <ErrorState v-else-if="pageError.message.value" title="无法打开公告" :description="pageError.message.value" @retry="load" />

    <article v-else-if="notice" class="panel notice-article">
      <header class="notice-article__header">
        <div class="notice-article__meta">
          <span class="plain-chip">{{ notice.audienceLabel }}</span>
          <span>{{ notice.authorName }}</span>
          <span aria-hidden="true">·</span>
          <time :datetime="notice.publishedAt ?? undefined">{{ formatDateTimeInTimezone(notice.publishedAt) }}</time>
        </div>
        <h1>{{ notice.title }}</h1>
        <div class="row">
          <span v-if="notice.requiresAck" class="plain-chip">{{ notice.acknowledged ? '已确认阅读' : '需要阅读确认' }}</span>
          <span v-if="notice.publishedAt" class="small muted">
            受众 {{ notice.acknowledgementCount }} / {{ notice.audienceCount }} 已确认
          </span>
        </div>
      </header>
      <div class="notice-article__body">
        <p v-for="(paragraph, index) in notice.body" :key="index">{{ paragraph }}</p>
      </div>
      <footer v-if="notice.requiresAck" class="notice-article__footer">
        <template v-if="notice.acknowledged">
          <p class="small muted">你已确认阅读本公告。确认时间已记录在审计日志中。</p>
        </template>
        <template v-else>
          <p class="small">阅读完毕后请确认，以便行政部门统计完成情况。</p>
          <el-button type="primary" :loading="acknowledging" @click="acknowledge">确认已阅读</el-button>
        </template>
      </footer>
    </article>
  </div>
</template>

<style scoped>
.notice-article {
  max-width: 820px;
  margin: 0 auto;
  padding: 28px 32px;
}

.notice-article__meta {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 7px;
  color: var(--color-ink-muted);
  font-size: 11px;
}

.notice-article h1 {
  margin: 10px 0 12px;
  font-size: 25px;
  font-weight: 680;
  letter-spacing: -0.025em;
  line-height: 1.35;
}

.notice-article__body {
  margin-top: 22px;
  padding-top: 20px;
  border-top: 1px solid var(--color-line);
  color: var(--color-ink-secondary);
  font-size: 13.5px;
  line-height: 1.9;
}

.notice-article__body p {
  margin-bottom: 12px;
  white-space: pre-wrap;
}

.notice-article__footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  margin-top: 24px;
  padding-top: 16px;
  border-top: 1px solid var(--color-line);
}

@media (max-width: 640px) {
  .notice-article {
    padding: 20px;
  }

  .notice-article__footer {
    flex-direction: column;
    align-items: flex-start;
  }
}
</style>
