<script setup lang="ts">
defineProps<{ title: string; description?: string }>()
</script>

<template>
  <section class="page-shell">
    <header class="page-shell__header">
      <div>
        <h1>{{ title }}</h1>
        <p v-if="description" class="muted">{{ description }}</p>
      </div>
      <div class="page-shell__actions"><slot name="actions" /></div>
    </header>
    <div class="page-shell__body"><slot /></div>
  </section>
</template>

<style scoped>
.page-shell { display: flex; flex-direction: column; gap: 16px; }
.page-shell__header { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; }
.page-shell__actions { display: flex; gap: 8px; flex-wrap: wrap; }
</style>
