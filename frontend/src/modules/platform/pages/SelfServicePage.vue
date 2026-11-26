<script setup lang="ts">
import PlatformWorkspace from '../components/PlatformWorkspace.vue'
import { platformPageConfig } from '../catalog'

const config = platformPageConfig('self-service')
if (config === null) throw new Error("Missing platform page config for self-service")
</script>

<template>
  <PlatformWorkspace :config="config" />
</template>
