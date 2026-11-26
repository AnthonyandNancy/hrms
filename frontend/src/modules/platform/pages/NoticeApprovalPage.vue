<script setup lang="ts">
import PlatformWorkspace from '../components/PlatformWorkspace.vue'
import { platformPageConfig } from '../catalog'

const config = platformPageConfig('notices/approval')
if (config === null) throw new Error("Missing platform page config for notices/approval")
</script>

<template>
  <PlatformWorkspace :config="config" />
</template>
