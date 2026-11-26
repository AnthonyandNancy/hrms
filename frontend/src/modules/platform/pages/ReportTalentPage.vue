<script setup lang="ts">
import PlatformWorkspace from '../components/PlatformWorkspace.vue'
import { platformPageConfig } from '../catalog'

const config = platformPageConfig('reports/talent')
if (config === null) throw new Error("Missing platform page config for reports/talent")
</script>

<template>
  <PlatformWorkspace :config="config" />
</template>
