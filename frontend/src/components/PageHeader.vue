<script setup lang="ts">
withDefaults(
  defineProps<{
    title: string
    description?: string
    breadcrumb?: string
  }>(),
  { description: '', breadcrumb: '' },
)
</script>

<template>
  <header class="page-header">
    <div class="page-header__copy">
      <p v-if="breadcrumb" class="page-header__breadcrumb">{{ breadcrumb }}</p>
      <h1 class="page-header__title">{{ title }}</h1>
      <p v-if="description" class="page-header__description">{{ description }}</p>
    </div>
    <div v-if="$slots.actions" class="page-header__actions">
      <slot name="actions" />
    </div>
  </header>
</template>
