<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ApiError } from '@/api/errors'
import { listRecruitmentBoard, type RecruitmentBoardRow } from '../api'

const rows = ref<RecruitmentBoardRow[]>([])
const loading = ref(false)
const error = ref('')

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    rows.value = await listRecruitmentBoard()
  } catch (failure) {
    rows.value = []
    error.value = failure instanceof ApiError && failure.message !== '' ? failure.message : '招聘进度看板加载失败。'
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <section data-testid="requisition-tab-board">
    <h3>招聘进度看板</h3>
    <p v-if="loading" class="panel-muted">正在加载招聘进度。</p>
    <p v-else-if="error !== ''" class="panel-error" role="alert">{{ error }}</p>
    <p v-else-if="rows.length === 0" class="panel-muted">还没有招聘需求，看板没有可展示的数据。</p>
    <table v-else class="panel-table">
      <thead>
        <tr>
          <th>需求</th><th>状态</th><th>编制</th><th>候选人</th><th>面试</th><th>Offer</th><th>已入职</th><th>缺口</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="row in rows" :key="row.requisitionId">
          <td>{{ row.requisitionNo }} · {{ row.title }}</td>
          <td>{{ row.status }}</td>
          <td>{{ row.headcount }}</td>
          <td>{{ row.candidateCount }}</td>
          <td>{{ row.interviewCount }}</td>
          <td>{{ row.offerCount }}</td>
          <td>{{ row.hiredCount }}</td>
          <td>{{ row.vacancyCount }}</td>
        </tr>
      </tbody>
    </table>
  </section>
</template>

<style scoped>
.panel-muted { color: #64748b; font-size: 12px; }
.panel-error { color: #b91c1c; font-size: 12px; }
.panel-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.panel-table th, .panel-table td { padding: 7px 8px; border-bottom: 1px solid #e2e8f0; text-align: left; }
</style>
