<script setup lang="ts">
import TalentWorkspacePage from '../components/TalentWorkspacePage.vue'
import { talentPageConfig } from '../catalog'

const config = talentPageConfig('recruitment/requests/approval')
if (config === null) throw new Error('Missing Talent page config for recruitment/requests/approval')
</script>

<template>
  <TalentWorkspacePage :config="config" />
</template>
