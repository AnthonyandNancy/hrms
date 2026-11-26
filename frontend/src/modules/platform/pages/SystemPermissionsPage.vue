<script setup lang="ts">
import PlatformWorkspace from '../components/PlatformWorkspace.vue'
import { platformPageConfig } from '../catalog'

const config = platformPageConfig('system/system-permissions')
if (config === null) throw new Error("Missing platform page config for system/system-permissions")
</script>

<template>
  <PlatformWorkspace :config="config" />
</template>
