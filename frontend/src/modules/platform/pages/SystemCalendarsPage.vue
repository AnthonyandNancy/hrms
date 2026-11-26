<script setup lang="ts">
import PlatformWorkspace from '../components/PlatformWorkspace.vue'
import { platformPageConfig } from '../catalog'

const config = platformPageConfig('system/system-calendars')
if (config === null) throw new Error("Missing platform page config for system/system-calendars")
</script>

<template>
  <PlatformWorkspace :config="config" />
</template>
