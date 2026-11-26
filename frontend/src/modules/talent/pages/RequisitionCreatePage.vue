<script setup lang="ts">
import TalentWorkspacePage from '../components/TalentWorkspacePage.vue'
import { talentPageConfig } from '../catalog'

const config = talentPageConfig('recruitment/requests/create')
if (config === null) throw new Error('Missing Talent page config for recruitment/requests/create')
</script>

<template>
  <TalentWorkspacePage :config="config" />
</template>
