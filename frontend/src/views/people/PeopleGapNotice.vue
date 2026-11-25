<script setup lang="ts">
import type { PeopleGap } from './types'

defineProps<{
  gap: PeopleGap
}>()
</script>

<template>
  <section class="panel people-gap-panel" data-testid="people-api-gap">
    <header class="panel__header">
      <div>
        <h2 class="panel__title">{{ gap.label }}：接口缺口</h2>
        <p class="panel__subtitle">计划路径 <span class="mono">{{ gap.plannedPath }}</span> 尚未实现</p>
      </div>
      <span class="people-gap-panel__badge">只读</span>
    </header>
    <div class="panel__body">
      <p class="people-gap-panel__detail">{{ gap.detail }}</p>
      <p class="people-gap-panel__rule">
        此区域不会展示推算、占位或演示数据；后端接口上线后再接入真实读取与动作。
      </p>
    </div>
  </section>
</template>
