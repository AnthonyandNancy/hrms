<script setup lang="ts">
import TalentWorkspacePage from '../components/TalentWorkspacePage.vue'
import { talentPageConfig } from '../catalog'

const config = talentPageConfig('training/courses')
if (config === null) throw new Error('Missing Talent page config for training/courses')
</script>

<template>
  <TalentWorkspacePage :config="config" />
</template>
