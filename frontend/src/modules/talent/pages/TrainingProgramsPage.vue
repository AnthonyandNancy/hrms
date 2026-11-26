<script setup lang="ts">
import TalentWorkspacePage from '../components/TalentWorkspacePage.vue'
import { talentPageConfig } from '../catalog'

const config = talentPageConfig('training/index')
if (config === null) throw new Error('Missing Talent page config for training/index')
</script>

<template>
  <TalentWorkspacePage :config="config" />
</template>
