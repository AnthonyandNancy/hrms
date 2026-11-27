<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ApiError } from '@/api/errors'
import { formatDateTimeInTimezone } from '@/utils/format'
import { getReadiness, type ReadinessCheck } from '../api/audit'
import { platformErrorCopy } from '../api'

const loading = ref(true)
const error = ref<unknown>(null)
const readiness = ref<ReadinessCheck | null>(null)

const requestId = computed(() => error.value instanceof ApiError ? error.value.requestId : null)
const ready = computed(() => readiness.value?.status === 'ready')
const checks = computed(() => Object.entries(readiness.value?.checks ?? {}))

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    readiness.value = await getReadiness()
  } catch (failure) {
    // A 503 readiness response still carries the checks payload in this app,
    // but the HTTP adapter surfaces it as an error; show the failure plainly.
    readiness.value = null
    error.value = failure
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <section class="panel" data-testid="audit-health-panel" aria-labelledby="audit-health-title">
    <div class="panel__header">
      <div>
        <h2 id="audit-health-title" class="panel__title">系统健康</h2>
        <p class="panel__subtitle">健康状态来自服务端只读就绪探针，不伪造缓存、数据库或存储状态。</p>
      </div>
      <el-button plain :disabled="loading" @click="load">刷新健康状态</el-button>
    </div>

    <div v-if="loading" class="panel__body" data-testid="audit-health-loading">
      <el-skeleton :rows="2" animated />
      <span class="visually-hidden">正在读取健康状态</span>
    </div>

    <div v-else-if="error !== null" class="panel__body" data-testid="audit-health-error">
      <p class="muted">{{ platformErrorCopy(error) }}</p>
      <p v-if="requestId" class="muted small">请求编号：<span class="mono">{{ requestId }}</span></p>
      <el-button type="primary" plain @click="load">重试</el-button>
    </div>

    <div v-else-if="readiness !== null" class="panel__body" data-testid="audit-health-ready">
      <div class="health-summary">
        <span class="plain-chip" :class="ready ? 'plain-chip--success' : 'plain-chip--danger'" data-testid="audit-health-status">
          {{ ready ? '就绪' : '未就绪' }}
        </span>
        <span class="muted small">版本 {{ readiness.version || '未提供' }}</span>
        <span v-if="readiness.time" class="muted small">探针时间 {{ formatDateTimeInTimezone(readiness.time) }}</span>
      </div>
      <ul class="health-checks">
        <li v-for="[name, healthy] in checks" :key="name" :data-testid="`audit-health-check-${name}`">
          <span>{{ name === 'db' ? '数据库' : name === 'cache' ? '缓存' : name === 'storage' ? '存储' : name }}</span>
          <span class="plain-chip" :class="healthy ? 'plain-chip--success' : 'plain-chip--danger'">{{ healthy ? '正常' : '异常' }}</span>
        </li>
      </ul>
    </div>

    <div class="panel__body" data-testid="audit-backup-unavailable">
      <h3 class="panel__title">备份恢复</h3>
      <p class="muted">备份恢复由外部运维平台承担，本系统未提供备份状态 API；本页不显示“最近备份成功”等无法验证的状态。</p>
    </div>
  </section>
</template>

<style scoped>
.health-summary {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  align-items: center;
}
.health-checks {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  padding: 0;
  margin: 10px 0 0;
  list-style: none;
}
.health-checks li {
  display: flex;
  gap: 7px;
  align-items: center;
  padding: 7px 10px;
  font-size: 12px;
  background: var(--color-canvas);
  border-radius: 6px;
}
.plain-chip--success {
  color: #146c43;
  background: #e8f6ee;
  border-color: #b7dcc7;
}
.plain-chip--danger {
  color: #a12828;
  background: #fdecec;
  border-color: #efc2c2;
}
</style>
