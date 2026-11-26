<script setup lang="ts">
import TalentWorkspacePage from '../components/TalentWorkspacePage.vue'
import { talentPageConfig } from '../catalog'

const config = talentPageConfig('recruitment/requests/openings')
if (config === null) throw new Error('Missing Talent page config for recruitment/requests/openings')
</script>

<template>
  <TalentWorkspacePage :config="config" />
</template>
