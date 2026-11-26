<script setup lang="ts">
import PlatformWorkspace from '../components/PlatformWorkspace.vue'
import { platformPageConfig } from '../catalog'

const config = platformPageConfig('dashboard/index')
if (config === null) throw new Error("Missing platform page config for dashboard/index")
</script>

<template>
  <PlatformWorkspace :config="config" />
</template>
