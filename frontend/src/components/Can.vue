<script setup lang="ts">
import { computed } from 'vue'
import { useSessionStore } from '@/stores/session'

const props = defineProps<{ capability: string }>()
const session = useSessionStore()
const allowed = computed(() => session.can(props.capability))
</script>

<template>
  <slot v-if="allowed" />
  <slot v-else name="fallback" />
</template>
