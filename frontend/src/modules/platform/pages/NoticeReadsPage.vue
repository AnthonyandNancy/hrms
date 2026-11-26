<script setup lang="ts">
import PlatformWorkspace from '../components/PlatformWorkspace.vue'
import { platformPageConfig } from '../catalog'

const config = platformPageConfig('notices/reads')
if (config === null) throw new Error("Missing platform page config for notices/reads")
</script>

<template>
  <PlatformWorkspace :config="config" />
</template>
