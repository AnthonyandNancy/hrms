<script setup lang="ts">
import PlatformWorkspace from '../components/PlatformWorkspace.vue'
import { platformPageConfig } from '../catalog'

const config = platformPageConfig('reports/subscriptions')
if (config === null) throw new Error("Missing platform page config for reports/subscriptions")
</script>

<template>
  <PlatformWorkspace :config="config" />
</template>
