<script setup lang="ts">
import PlatformWorkspace from '../components/PlatformWorkspace.vue'
import { platformPageConfig } from '../catalog'

const config = platformPageConfig('reports/payroll')
if (config === null) throw new Error("Missing platform page config for reports/payroll")
</script>

<template>
  <PlatformWorkspace :config="config" />
</template>
