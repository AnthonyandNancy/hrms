<script setup lang="ts">
import TalentWorkspacePage from '../components/TalentWorkspacePage.vue'
import { talentPageConfig } from '../catalog'

const config = talentPageConfig('training/sessions')
if (config === null) throw new Error('Missing Talent page config for training/sessions')
</script>

<template>
  <TalentWorkspacePage :config="config" />
</template>
