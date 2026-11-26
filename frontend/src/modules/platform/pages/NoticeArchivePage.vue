<script setup lang="ts">
import PlatformWorkspace from '../components/PlatformWorkspace.vue'
import { platformPageConfig } from '../catalog'

const config = platformPageConfig('notices/archive')
if (config === null) throw new Error("Missing platform page config for notices/archive")
</script>

<template>
  <PlatformWorkspace :config="config" />
</template>
