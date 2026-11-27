<script setup lang="ts">
import { computed } from 'vue'
import { formatBusinessDate } from '@/utils/format'
import type { ContractVersion } from '../api'

const props = withDefaults(
  defineProps<{
    versions: ContractVersion[]
    loading?: boolean
    error?: string
    partialReason?: string
  }>(),
  { loading: false, error: '', partialReason: '' },
)

defineOptions({ name: 'ContractVersionList' })

const hasRows = computed(() => props.versions.length > 0)

function snapshotValue(version: ContractVersion, key: string): string {
  const value = version.snapshot[key]
  if (typeof value === 'string' && value !== '') return value
  if (typeof value === 'number') return String(value)
  return '—'
}

function dateRange(version: ContractVersion): string {
  const to = version.effective_to === '9999-12-31' ? '无固定期限' : formatBusinessDate(version.effective_to)
  return `${formatBusinessDate(version.effective_from)} 至 ${to}`
}
</script>

<template>
  <section class="panel contract-versions" data-testid="contract-version-list">
    <header class="panel__header">
      <div>
        <h2 class="panel__title">版本时间线</h2>
        <p class="panel__subtitle">签订、续签、变更与终止均追加版本；历史记录不可改写。</p>
      </div>
      <span class="status-badge status-badge--sm status-badge--neutral">{{ versions.length }} 个版本</span>
    </header>

    <div class="panel__body">
      <p v-if="loading" class="contract-versions__state" role="status">正在加载版本历史…</p>
      <p v-else-if="error !== ''" class="contract-versions__state contract-versions__state--error" role="alert">
        {{ error }}
      </p>
      <p v-else-if="partialReason !== ''" class="contract-versions__state contract-versions__state--partial">
        部分版本：{{ partialReason }}
      </p>
      <p v-else-if="!hasRows" class="contract-versions__state" data-testid="contract-versions-empty">
        接口未返回版本历史。合同行仍可查看，但不会在此虚构版本。
      </p>

      <ol v-else class="contract-versions__timeline">
        <li v-for="version in versions" :key="version.id" class="contract-versions__item" :data-testid="`contract-version-${version.id}`">
          <div class="contract-versions__marker" aria-hidden="true" />
          <div class="contract-versions__content">
            <header class="contract-versions__head">
              <strong>{{ version.change_type_label }}</strong>
              <span class="status-badge status-badge--sm status-badge--neutral">{{ version.status_label }}</span>
              <span class="contract-versions__version">第 {{ version.terms_version }} 版</span>
            </header>
            <p class="contract-versions__dates">{{ dateRange(version) }}</p>
            <dl class="contract-versions__facts">
              <div>
                <dt>签署人</dt>
                <dd>{{ version.signer ?? '接口未返回' }}</dd>
              </div>
              <div>
                <dt>合同编号</dt>
                <dd class="mono">{{ snapshotValue(version, 'contract_no') }}</dd>
              </div>
              <div>
                <dt>签署日期</dt>
                <dd>{{ formatBusinessDate(typeof version.snapshot.signed_on === 'string' ? version.snapshot.signed_on : null) }}</dd>
              </div>
              <div>
                <dt>工作地点</dt>
                <dd>{{ snapshotValue(version, 'work_location') }}</dd>
              </div>
              <div>
                <dt>试用期结束</dt>
                <dd>{{ formatBusinessDate(typeof version.snapshot.probation_end_date === 'string' ? version.snapshot.probation_end_date : null) }}</dd>
              </div>
              <div>
                <dt>记录时间</dt>
                <dd>{{ version.recorded_at ?? '—' }}</dd>
              </div>
            </dl>
            <p v-if="version.reason" class="contract-versions__reason">原因：{{ version.reason }}</p>
          </div>
        </li>
      </ol>
    </div>
  </section>
</template>

<style scoped>
.contract-versions__state {
  padding: 10px 0;
  color: var(--color-ink-muted);
  font-size: 12px;
}
.contract-versions__state--error {
  color: var(--color-danger);
}
.contract-versions__state--partial {
  color: #8a5a00;
}
.contract-versions__timeline {
  margin: 0;
  padding: 0;
  list-style: none;
}
.contract-versions__item {
  position: relative;
  display: grid;
  grid-template-columns: 18px minmax(0, 1fr);
  gap: 8px;
  padding-bottom: 16px;
}
.contract-versions__item:not(:last-child)::before {
  position: absolute;
  top: 14px;
  bottom: 0;
  left: 6px;
  width: 1px;
  background: var(--color-line-strong);
  content: "";
}
.contract-versions__marker {
  width: 9px;
  height: 9px;
  margin-top: 5px;
  background: var(--color-brand);
  border: 2px solid #fff;
  border-radius: 50%;
  box-shadow: 0 0 0 1px var(--color-brand);
}
.contract-versions__content {
  min-width: 0;
}
.contract-versions__head {
  display: flex;
  flex-wrap: wrap;
  gap: 7px;
  align-items: center;
  font-size: 13px;
}
.contract-versions__version {
  color: var(--color-ink-muted);
  font-size: 11px;
}
.contract-versions__dates {
  margin: 4px 0 8px;
  color: var(--color-ink-secondary);
  font-size: 12px;
}
.contract-versions__facts {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 7px 12px;
  margin: 0;
}
.contract-versions__facts dt {
  color: var(--color-ink-muted);
  font-size: 10px;
}
.contract-versions__facts dd {
  margin: 1px 0 0;
  font-size: 11.5px;
  overflow-wrap: anywhere;
}
.contract-versions__reason {
  margin: 7px 0 0;
  color: var(--color-ink-muted);
  font-size: 11px;
}
@media (max-width: 720px) {
  .contract-versions__facts {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
</style>
