<script setup lang="ts">
import { peoplePageConfig } from '../catalog'
import PeopleWorkspace from '../components/PeopleWorkspace.vue'

defineOptions({ name: 'PositionRequirementsPage' })

const config = peoplePageConfig('hr/positions/requirements')!
</script>

<template>
  <PeopleWorkspace :config="config" />
</template>
