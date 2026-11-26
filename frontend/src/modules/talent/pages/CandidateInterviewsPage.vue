<script setup lang="ts">
import TalentWorkspacePage from '../components/TalentWorkspacePage.vue'
import { talentPageConfig } from '../catalog'

const config = talentPageConfig('recruitment/candidates/interviews')
if (config === null) throw new Error('Missing Talent page config for recruitment/candidates/interviews')
</script>

<template>
  <TalentWorkspacePage :config="config" />
</template>
