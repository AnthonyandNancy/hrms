<script setup lang="ts">
import ModulePeopleWorkspace from '@/modules/people/components/PeopleWorkspace.vue'
import type { PeoplePageConfig } from '@/modules/people/types'

defineOptions({ name: 'PeopleWorkspaceCompatibilityView' })

defineProps<{
  config: PeoplePageConfig
}>()
</script>

<template>
  <ModulePeopleWorkspace :config="config" />
</template>
