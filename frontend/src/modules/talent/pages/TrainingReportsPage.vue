<script setup lang="ts">
import TalentWorkspacePage from '../components/TalentWorkspacePage.vue'
import { talentPageConfig } from '../catalog'

const config = talentPageConfig('training/reports')
if (config === null) throw new Error('Missing Talent page config for training/reports')
</script>

<template>
  <TalentWorkspacePage :config="config" />
</template>
