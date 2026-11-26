<script setup lang="ts">
import PlatformWorkspace from '../components/PlatformWorkspace.vue'
import { platformPageConfig } from '../catalog'

const config = platformPageConfig('workflow')
if (config === null) throw new Error("Missing platform page config for workflow")
</script>

<template>
  <PlatformWorkspace :config="config" />
</template>
