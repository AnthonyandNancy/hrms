<script setup lang="ts">
import TalentWorkspacePage from '../components/TalentWorkspacePage.vue'
import { talentPageConfig } from '../catalog'

const config = talentPageConfig('recruitment/candidates/offers')
if (config === null) throw new Error('Missing Talent page config for recruitment/candidates/offers')
</script>

<template>
  <TalentWorkspacePage :config="config" />
</template>
