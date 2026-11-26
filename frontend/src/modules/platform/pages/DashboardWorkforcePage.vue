<script setup lang="ts">
import PlatformWorkspace from '../components/PlatformWorkspace.vue'
import { platformPageConfig } from '../catalog'

const config = platformPageConfig('dashboard/workforce')
if (config === null) throw new Error("Missing platform page config for dashboard/workforce")
</script>

<template>
  <PlatformWorkspace :config="config" />
</template>
