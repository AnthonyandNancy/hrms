<script setup lang="ts">
import PlatformWorkspace from '../components/PlatformWorkspace.vue'
import { platformPageConfig } from '../catalog'

const config = platformPageConfig('reports/exports')
if (config === null) throw new Error("Missing platform page config for reports/exports")
</script>

<template>
  <PlatformWorkspace :config="config" />
</template>
