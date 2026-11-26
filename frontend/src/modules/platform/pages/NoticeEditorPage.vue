<script setup lang="ts">
import PlatformWorkspace from '../components/PlatformWorkspace.vue'
import { platformPageConfig } from '../catalog'

const config = platformPageConfig('notices/editor')
if (config === null) throw new Error("Missing platform page config for notices/editor")
</script>

<template>
  <PlatformWorkspace :config="config" />
</template>
