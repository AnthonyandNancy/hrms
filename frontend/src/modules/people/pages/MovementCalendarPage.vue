<script setup lang="ts">
import { peoplePageConfig } from '../catalog'
import PeopleWorkspace from '../components/PeopleWorkspace.vue'

defineOptions({ name: 'MovementCalendarPage' })

const config = peoplePageConfig('hr/movements/calendar')!
</script>

<template>
  <PeopleWorkspace :config="config" />
</template>
