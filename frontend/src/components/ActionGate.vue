<script setup lang="ts">
import Can from '@/components/Can.vue'

withDefaults(
  defineProps<{
    capability: string
    mode?: 'default' | 'disable'
  }>(),
  { mode: 'default' },
)
</script>

<template>
  <Can :capability="capability">
    <slot />
    <template #fallback>
      <span
        v-if="mode === 'disable'"
        class="action-gate__denied"
        role="note"
      >缺少动作能力 {{ capability }}</span>
      <slot v-else name="fallback" />
    </template>
  </Can>
</template>

<style scoped>
.action-gate__denied {
  color: var(--color-ink-muted);
  font-size: 12px;
}
</style>
