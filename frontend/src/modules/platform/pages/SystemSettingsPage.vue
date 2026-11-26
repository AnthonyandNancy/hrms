<script setup lang="ts">
import PlatformWorkspace from '../components/PlatformWorkspace.vue'
import { platformPageConfig } from '../catalog'

const config = platformPageConfig('system/system-settings')
if (config === null) throw new Error("Missing platform page config for system/system-settings")
</script>

<template>
  <PlatformWorkspace :config="config" />
</template>
