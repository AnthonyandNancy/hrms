<script setup lang="ts">
import { peoplePageConfig } from '../catalog'
import PeopleWorkspace from '../components/PeopleWorkspace.vue'

defineOptions({ name: 'MovementProbationPage' })

const config = peoplePageConfig('hr/movements/probation')!
</script>

<template>
  <PeopleWorkspace :config="config" />
</template>
