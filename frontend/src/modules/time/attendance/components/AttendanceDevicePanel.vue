<script setup lang="ts">
import EmptyState from '@/components/EmptyState.vue'
import type { AttendanceDevice } from '../api'

const props = defineProps<{
  devices: AttendanceDevice[]
}>()

function healthLabel(health: string): string {
  const labels: Record<string, string> = {
    ONLINE: '在线',
    DEGRADED: '降级',
    OFFLINE: '离线',
    UNKNOWN: '未知',
  }
  return labels[health] ?? health
}
</script>

<template>
  <section class="attendance-devices" data-testid="attendance-devices-tab" aria-labelledby="attendance-devices-title">
    <header class="attendance-devices__header">
      <div>
        <h2 id="attendance-devices-title">设备对接</h2>
        <p class="muted">设备健康由适配器流水回报；页面只读真实行，不写入或伪造设备状态。</p>
      </div>
      <span class="attendance-devices__count">{{ props.devices.length }} 台</span>
    </header>

    <EmptyState
      v-if="props.devices.length === 0"
      title="暂无已配置的考勤设备"
      description="服务端未返回真实设备行。该 Tab 默认隐藏；接入适配器并产生设备记录后才会出现。"
    />

    <div v-else class="attendance-devices__scroller" role="region" aria-label="考勤设备，可横向滚动" tabindex="0">
      <table class="attendance-devices__table">
        <caption class="visually-hidden">考勤设备清单</caption>
        <thead>
          <tr>
            <th scope="col">设备编码</th>
            <th scope="col">名称</th>
            <th scope="col">适配器</th>
            <th scope="col">健康</th>
            <th scope="col">最近心跳</th>
            <th scope="col">启用</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="device in props.devices" :key="device.id" :data-testid="`attendance-device-${device.id}`">
            <td class="mono">{{ device.code }}</td>
            <td>{{ device.name }}</td>
            <td>{{ device.adapter }}</td>
            <td>
              <span class="attendance-devices__health" :class="`attendance-devices__health--${device.health.toLowerCase()}`">
                {{ healthLabel(device.health) }}
              </span>
            </td>
            <td>{{ device.lastSeenAt ?? '——' }}</td>
            <td>{{ device.active ? '是' : '否' }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <p class="attendance-devices__hint">设备配置与健康状态没有 HTTP 写接口；如需接入，请由运维通过设备适配器流程处理。</p>
  </section>
</template>

<style scoped>
.attendance-devices {
  background: #fff;
  border: 1px solid var(--color-line);
  border-radius: var(--radius-md);
}

.attendance-devices__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  padding: 14px 16px;
  border-bottom: 1px solid var(--color-line);
}

.attendance-devices__header h2 {
  font-size: 15px;
}

.attendance-devices__header p {
  margin-top: 3px;
  font-size: 11.5px;
}

.attendance-devices__count {
  padding: 3px 8px;
  color: var(--color-ink-muted);
  font-size: 11px;
  background: #f5f8f7;
  border-radius: 999px;
}

.attendance-devices__scroller {
  overflow-x: auto;
}

.attendance-devices__table {
  width: 100%;
  border-collapse: collapse;
  font-size: 12px;
}

.attendance-devices__table th,
.attendance-devices__table td {
  padding: 9px 11px;
  text-align: left;
  border-bottom: 1px solid var(--color-line);
}

.attendance-devices__table th {
  color: var(--color-ink-muted);
  font-size: 10.5px;
  font-weight: 650;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  background: #fafcfb;
}

.attendance-devices__health {
  padding: 2px 7px;
  font-size: 10.5px;
  border-radius: 999px;
}

.attendance-devices__health--online {
  color: #1f6b3a;
  background: #eef8f1;
}

.attendance-devices__health--degraded {
  color: #8f5b00;
  background: #fff5df;
}

.attendance-devices__health--offline {
  color: #9f1c12;
  background: #feecea;
}

.attendance-devices__health--unknown {
  color: var(--color-ink-muted);
  background: #f5f8f7;
}

.attendance-devices__hint {
  padding: 9px 16px;
  color: var(--color-ink-muted);
  font-size: 11px;
}
</style>
