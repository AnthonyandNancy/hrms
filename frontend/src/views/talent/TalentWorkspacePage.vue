<script setup lang="ts">
import TalentWorkspacePage from '@/modules/talent/components/TalentWorkspacePage.vue'
import type { TalentPageConfig } from '@/modules/talent/types'

defineProps<{
  config: TalentPageConfig
}>()
</script>

<template>
  <TalentWorkspacePage :config="config" />
</template>
