<script setup lang="ts">
import PlatformWorkspace from '../components/PlatformWorkspace.vue'
import { platformPageConfig } from '../catalog'

const config = platformPageConfig('reports/headcount')
if (config === null) throw new Error("Missing platform page config for reports/headcount")
</script>

<template>
  <PlatformWorkspace :config="config" />
</template>
