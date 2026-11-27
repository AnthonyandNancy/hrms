<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ApiError } from '@/api/errors'
import { listRecruitmentReports, type RecruitmentReportRow } from '../api'

const rows = ref<RecruitmentReportRow[]>([])
const loading = ref(false)
const error = ref('')

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    rows.value = await listRecruitmentReports()
  } catch (failure) {
    rows.value = []
    error.value = failure instanceof ApiError && failure.message !== '' ? failure.message : '招聘统计加载失败。'
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <section data-testid="requisition-tab-reports">
    <h3>招聘统计</h3>
    <p class="panel-muted">统计口径来自服务端招聘事实；完整招聘转化报表在报表中心“招聘”类型查看。</p>
    <a href="/reports?type=RECRUITMENT_FUNNEL" data-testid="requisition-reports-link">打开报表中心 · 招聘漏斗</a>
    <p v-if="loading" class="panel-muted">正在加载招聘统计。</p>
    <p v-else-if="error !== ''" class="panel-error" role="alert">{{ error }}</p>
    <p v-else-if="rows.length === 0" class="panel-muted">还没有招聘需求，统计没有可展示的数据。</p>
    <table v-else class="panel-table">
      <thead>
        <tr><th>需求</th><th>编制</th><th>候选人</th><th>面试</th><th>Offer</th><th>已接受</th><th>已入职</th><th>填补率</th><th>招聘时长</th></tr>
      </thead>
      <tbody>
        <tr v-for="row in rows" :key="row.requisitionId">
          <td>{{ row.requisitionNo }} · {{ row.title }}</td>
          <td>{{ row.headcount }}</td>
          <td>{{ row.candidateCount }}</td>
          <td>{{ row.interviewCount }}</td>
          <td>{{ row.offerCount }}</td>
          <td>{{ row.acceptedOffers }}</td>
          <td>{{ row.hiredCount }}</td>
          <td>{{ (row.fillRateBps / 100).toFixed(2) }}%</td>
          <td>{{ row.timeToHireDays === null ? '—' : `${row.timeToHireDays} 天` }}</td>
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
