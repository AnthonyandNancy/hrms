<script setup lang="ts">
import PlatformWorkspace from '../components/PlatformWorkspace.vue'
import { platformPageConfig } from '../catalog'

const config = platformPageConfig('notifications')
if (config === null) throw new Error("Missing platform page config for notifications")
</script>

<template>
  <PlatformWorkspace :config="config" />
</template>
