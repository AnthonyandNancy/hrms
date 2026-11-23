<script setup lang="ts">
import { computed } from 'vue'

const props = defineProps<{
  capability?: string
  capabilities: readonly string[]
}>()

const allowed = computed(() => !props.capability || props.capabilities.includes(props.capability))
</script>

<template>
  <slot v-if="allowed" />
</template>
