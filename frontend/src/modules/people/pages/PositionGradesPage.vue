<script setup lang="ts">
import { peoplePageConfig } from '../catalog'
import PeopleWorkspace from '../components/PeopleWorkspace.vue'

defineOptions({ name: 'PositionGradesPage' })

const config = peoplePageConfig('hr/positions/grades')!
</script>

<template>
  <PeopleWorkspace :config="config" />
</template>
