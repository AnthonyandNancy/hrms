<script setup lang="ts">
import PlatformWorkspace from '../components/PlatformWorkspace.vue'
import { platformPageConfig } from '../catalog'

const config = platformPageConfig('notices/templates')
if (config === null) throw new Error("Missing platform page config for notices/templates")
</script>

<template>
  <PlatformWorkspace :config="config" />
</template>
