<script setup lang="ts">
import PlatformWorkspace from '../components/PlatformWorkspace.vue'
import { platformPageConfig } from '../catalog'

const config = platformPageConfig('system/system-data-scopes')
if (config === null) throw new Error("Missing platform page config for system/system-data-scopes")
</script>

<template>
  <PlatformWorkspace :config="config" />
</template>
