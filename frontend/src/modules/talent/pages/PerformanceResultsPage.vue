<script setup lang="ts">
import TalentWorkspacePage from '../components/TalentWorkspacePage.vue'
import { talentPageConfig } from '../catalog'

const config = talentPageConfig('performance/results')
if (config === null) throw new Error('Missing Talent page config for performance/results')
</script>

<template>
  <TalentWorkspacePage :config="config" />
</template>
