<script setup lang="ts">
import { computed } from 'vue'
import { formatBusinessDate } from '@/utils/format'
import { formatMinutes, type TimeoffBalancesResult } from '../api'

/**
 * TIMEOFF balance projection. The backend derives the balance from TIMEOFF
 * settlements; this panel never invents an opening, usage or expiry row.
 */
const props = defineProps<{
  timeoff: TimeoffBalancesResult | null
  canRead: boolean
}>()

const items = computed(() => props.timeoff?.items ?? [])
const available = computed(() => props.timeoff?.availableMinutes ?? 0)
</script>

<template>
  <section class="overtime-timeoff-panel" data-testid="overtime-panel-timeoff" aria-labelledby="overtime-timeoff-title">
    <header class="overtime-timeoff-panel__header">
      <div>
        <h2 id="overtime-timeoff-title">调休余额</h2>
        <p>额度仅来自已确认加班记录的 TIMEOFF 结算；服务端没有余额表，也不把工资折算计入调休。</p>
      </div>
      <span class="overtime-timeoff-panel__badge">{{ props.timeoff?.settlementCount ?? 0 }} 笔</span>
    </header>

    <p v-if="!props.canRead" class="overtime-timeoff-panel__empty" role="status">
      当前账号没有 <code>time.timeoff.read</code> 权限。
    </p>
    <template v-else>
      <dl class="overtime-timeoff-panel__summary">
        <div>
          <dt>可用调休</dt>
          <dd data-testid="overtime-timeoff-available">{{ formatMinutes(available) }}</dd>
        </div>
        <div>
          <dt>流水笔数</dt>
          <dd>{{ props.timeoff?.settlementCount ?? 0 }}</dd>
        </div>
        <div>
          <dt>截至业务日期</dt>
          <dd>{{ props.timeoff?.asOf || '—' }}</dd>
        </div>
      </dl>

      <p v-if="items.length === 0" class="overtime-timeoff-panel__empty" data-testid="overtime-timeoff-empty" role="status">
        暂无调休结算流水。确认加班后选择“调休”结算即可产生一笔真实流水。
      </p>
      <div v-else class="overtime-timeoff-panel__table-wrap">
        <table class="overtime-timeoff-panel__table">
          <thead>
            <tr>
              <th scope="col">业务日期</th>
              <th scope="col">流水类型</th>
              <th scope="col">分钟</th>
              <th scope="col">原因</th>
              <th scope="col">到期日</th>
              <th scope="col">记录时间</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in items" :key="item.settlementId" :data-testid="`overtime-timeoff-row-${item.settlementId}`">
              <td>{{ item.businessDate !== null ? formatBusinessDate(item.businessDate) : '—' }}</td>
              <td>获取</td>
              <td class="mono">+{{ formatMinutes(item.minutes) }}</td>
              <td>{{ item.reason }}</td>
              <td>服务端未返回</td>
              <td>{{ item.recordedAt ?? '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </section>
</template>

<style scoped>
.overtime-timeoff-panel {
  display: grid;
  gap: 12px;
  padding: 16px;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
}

.overtime-timeoff-panel__header {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  align-items: flex-start;
}

.overtime-timeoff-panel__header h2 {
  margin: 0;
  font-size: 16px;
}

.overtime-timeoff-panel__header p {
  margin: 4px 0 0;
  color: #64748b;
  font-size: 11.5px;
}

.overtime-timeoff-panel__badge {
  padding: 3px 8px;
  color: #1d4ed8;
  font-size: 11px;
  background: #eff6ff;
  border-radius: 999px;
}

.overtime-timeoff-panel__summary {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: 10px;
  margin: 0;
  padding: 12px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
}

.overtime-timeoff-panel__summary dt {
  color: #64748b;
  font-size: 10.5px;
}

.overtime-timeoff-panel__summary dd {
  margin: 3px 0 0;
  color: #0f172a;
  font-size: 15px;
}

.overtime-timeoff-panel__empty {
  margin: 0;
  padding: 14px;
  color: #64748b;
  font-size: 12px;
  background: #f8fafc;
  border-radius: 7px;
}

.overtime-timeoff-panel__table-wrap {
  overflow-x: auto;
}

.overtime-timeoff-panel__table {
  width: 100%;
  font-size: 11.5px;
  border-collapse: collapse;
}

.overtime-timeoff-panel__table th,
.overtime-timeoff-panel__table td {
  padding: 8px 9px;
  text-align: left;
  vertical-align: top;
  border-bottom: 1px solid #f1f5f9;
}

.overtime-timeoff-panel__table th {
  color: #64748b;
  font-weight: 500;
  background: #f8fafc;
}

.mono {
  font-family: ui-monospace, monospace;
}
</style>
