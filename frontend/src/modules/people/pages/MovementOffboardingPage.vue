<script setup lang="ts">
import { peoplePageConfig } from '../catalog'
import PeopleWorkspace from '../components/PeopleWorkspace.vue'

defineOptions({ name: 'MovementOffboardingPage' })

const config = peoplePageConfig('hr/movements/offboarding')!
</script>

<template>
  <PeopleWorkspace :config="config" />
</template>
