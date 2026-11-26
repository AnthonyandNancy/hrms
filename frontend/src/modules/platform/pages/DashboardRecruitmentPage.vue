<script setup lang="ts">
import PlatformWorkspace from '../components/PlatformWorkspace.vue'
import { platformPageConfig } from '../catalog'

const config = platformPageConfig('dashboard/recruitment')
if (config === null) throw new Error("Missing platform page config for dashboard/recruitment")
</script>

<template>
  <PlatformWorkspace :config="config" />
</template>
