<script setup lang="ts">
import { formatLeaveNumber, leaveUnitLabel, type LeaveBalance } from '../api'

const props = withDefaults(
  defineProps<{
    balances: LeaveBalance[]
    loading?: boolean
  }>(),
  { loading: false },
)

function unit(unitValue: LeaveBalance['unit']): string {
  return leaveUnitLabel(unitValue)
}

function value(amount: number, unitValue: LeaveBalance['unit']): string {
  return `${formatLeaveNumber(amount)} ${unit(unitValue)}`
}
</script>

<template>
  <section class="leave-balances" aria-label="假期余额">
    <div class="leave-balances__heading">
      <div>
        <h2 class="leave-balances__title">假期余额</h2>
        <p class="leave-balances__subtitle">
          余额由服务端账本汇总，展示应享、已用、待占用与可用；业务日期以服务端返回的截至日期为准。
        </p>
      </div>
      <span v-if="loading" class="leave-balances__loading" role="status">正在刷新余额…</span>
    </div>

    <div v-if="props.balances.length > 0" class="leave-balances__grid">
      <article
        v-for="balance in props.balances"
        :key="balance.leaveTypeCode"
        class="leave-balance-card"
        :data-testid="`leave-balance-${balance.leaveTypeCode}`"
      >
        <header class="leave-balance-card__header">
          <div>
            <h3 class="leave-balance-card__name">{{ balance.leaveTypeName }}</h3>
            <p class="leave-balance-card__code">{{ balance.leaveTypeCode }}</p>
          </div>
          <span class="leave-balance-card__unit">{{ unit(balance.unit) }}</span>
        </header>

        <p class="leave-balance-card__available">
          <span class="leave-balance-card__available-label">可用</span>
          <strong>{{ value(balance.available, balance.unit) }}</strong>
        </p>

        <dl class="leave-balance-card__facts">
          <div>
            <dt>应享</dt>
            <dd>{{ value(balance.entitled, balance.unit) }}</dd>
          </div>
          <div>
            <dt>已用</dt>
            <dd>{{ value(balance.used, balance.unit) }}</dd>
          </div>
          <div>
            <dt>待占用</dt>
            <dd>{{ value(balance.pending, balance.unit) }}</dd>
          </div>
        </dl>

        <footer class="leave-balance-card__footer">
          <span v-if="balance.asOf" class="leave-balance-card__asof" data-testid="leave-balance-asof">
            截至 {{ balance.asOf }}
          </span>
          <span v-else class="leave-balance-card__asof leave-balance-card__asof--unknown">
            服务端未返回截至日期
          </span>
          <span v-if="balance.expiresOn" class="leave-balance-card__expiry">
            有效至 {{ balance.expiresOn }}
          </span>
        </footer>
      </article>
    </div>

    <p v-else-if="!loading" class="leave-balances__empty">
      服务端暂未返回可用的假期余额。若已入职，请联系人力资源确认假期账户。
    </p>
  </section>
</template>

<style scoped>
.leave-balances {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.leave-balances__heading {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
}

.leave-balances__title {
  margin: 0;
  font-size: 15px;
  font-weight: 680;
}

.leave-balances__subtitle {
  max-width: 720px;
  margin: 4px 0 0;
  color: var(--color-ink-muted);
  font-size: 11.5px;
  line-height: 1.55;
}

.leave-balances__loading {
  flex: none;
  color: var(--color-ink-muted);
  font-size: 11px;
}

.leave-balances__grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
  gap: 12px;
}

.leave-balance-card {
  display: flex;
  flex-direction: column;
  min-width: 0;
  padding: 14px 16px;
  background: #fff;
  border: 1px solid var(--color-line);
  border-radius: var(--radius-md);
  box-shadow: var(--shadow-soft);
}

.leave-balance-card__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 10px;
}

.leave-balance-card__name {
  margin: 0;
  font-size: 13px;
  font-weight: 660;
}

.leave-balance-card__code {
  margin: 2px 0 0;
  color: var(--color-ink-muted);
  font-family: var(--font-mono, monospace);
  font-size: 9.5px;
  letter-spacing: 0.04em;
}

.leave-balance-card__unit {
  flex: none;
  padding: 2px 7px;
  color: var(--color-ink-muted);
  font-size: 10px;
  background: var(--color-canvas);
  border: 1px solid var(--color-line);
  border-radius: 999px;
}

.leave-balance-card__available {
  display: flex;
  align-items: baseline;
  gap: 7px;
  margin: 12px 0 10px;
}

.leave-balance-card__available-label {
  color: var(--color-ink-muted);
  font-size: 10.5px;
}

.leave-balance-card__available strong {
  font-size: 24px;
  font-weight: 700;
  letter-spacing: -0.02em;
  font-variant-numeric: tabular-nums;
}

.leave-balance-card__facts {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 6px;
  margin: 0;
  padding: 9px 0;
  border-top: 1px solid #edf1ef;
  border-bottom: 1px solid #edf1ef;
}

.leave-balance-card__facts div {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}

.leave-balance-card__facts dt {
  color: var(--color-ink-muted);
  font-size: 9.5px;
}

.leave-balance-card__facts dd {
  margin: 0;
  overflow: hidden;
  font-size: 11px;
  font-weight: 600;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-variant-numeric: tabular-nums;
}

.leave-balance-card__footer {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  gap: 6px;
  margin-top: 9px;
  color: var(--color-ink-muted);
  font-size: 9.5px;
}

.leave-balance-card__asof--unknown {
  color: var(--color-warning);
}

.leave-balances__empty {
  margin: 0;
  padding: 22px 18px;
  color: var(--color-ink-muted);
  font-size: 12px;
  text-align: center;
  background: #fff;
  border: 1px dashed var(--color-line-strong);
  border-radius: var(--radius-md);
}
</style>
