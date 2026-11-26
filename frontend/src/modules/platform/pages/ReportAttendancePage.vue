<script setup lang="ts">
import PlatformWorkspace from '../components/PlatformWorkspace.vue'
import { platformPageConfig } from '../catalog'

const config = platformPageConfig('reports/attendance')
if (config === null) throw new Error("Missing platform page config for reports/attendance")
</script>

<template>
  <PlatformWorkspace :config="config" />
</template>
