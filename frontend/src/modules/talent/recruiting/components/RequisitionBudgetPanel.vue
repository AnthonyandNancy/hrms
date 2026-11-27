<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ApiError } from '@/api/errors'
import { formatMoney } from '@/utils/format'
import { listRecruitmentBudget, type RecruitmentBudgetRow } from '../api'

const rows = ref<RecruitmentBudgetRow[]>([])
const loading = ref(false)
const error = ref('')

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    rows.value = await listRecruitmentBudget()
  } catch (failure) {
    rows.value = []
    error.value = failure instanceof ApiError && failure.message !== '' ? failure.message : '招聘预算加载失败。'
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <section data-testid="requisition-tab-budget">
    <h3>预算与编制</h3>
    <p v-if="loading" class="panel-muted">正在加载预算与编制。</p>
    <p v-else-if="error !== ''" class="panel-error" role="alert">{{ error }}</p>
    <p v-else-if="rows.length === 0" class="panel-muted">还没有招聘需求，预算没有可展示的数据。</p>
    <table v-else class="panel-table">
      <thead>
        <tr><th>需求</th><th>编制</th><th>预算</th><th>已承诺 Offer</th><th>差异</th><th>已入职</th><th>缺口</th></tr>
      </thead>
      <tbody>
        <tr v-for="row in rows" :key="row.requisitionId">
          <td>{{ row.requisitionNo }} · {{ row.title }}</td>
          <td>{{ row.headcount }}</td>
          <td>{{ row.budgetMinor === null ? '未设置' : formatMoney(row.budgetMinor) }}</td>
          <td>{{ formatMoney(row.committedOfferMinor) }}</td>
          <td>{{ row.budgetVarianceMinor === null ? '—' : formatMoney(row.budgetVarianceMinor) }}</td>
          <td>{{ row.filledHeadcount }}</td>
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
