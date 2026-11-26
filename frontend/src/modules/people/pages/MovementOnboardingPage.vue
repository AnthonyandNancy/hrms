<script setup lang="ts">
import { peoplePageConfig } from '../catalog'
import PeopleWorkspace from '../components/PeopleWorkspace.vue'

defineOptions({ name: 'MovementOnboardingPage' })

const config = peoplePageConfig('hr/movements/onboarding')!
</script>

<template>
  <PeopleWorkspace :config="config" />
</template>
