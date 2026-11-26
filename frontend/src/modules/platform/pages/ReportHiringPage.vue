<script setup lang="ts">
import PlatformWorkspace from '../components/PlatformWorkspace.vue'
import { platformPageConfig } from '../catalog'

const config = platformPageConfig('reports/hiring')
if (config === null) throw new Error("Missing platform page config for reports/hiring")
</script>

<template>
  <PlatformWorkspace :config="config" />
</template>
