<script setup lang="ts">
import { peoplePageConfig } from '../catalog'
import PeopleWorkspace from '../components/PeopleWorkspace.vue'

defineOptions({ name: 'MovementSalaryChangePage' })

const config = peoplePageConfig('hr/movements/salary-change')!
</script>

<template>
  <PeopleWorkspace :config="config" />
</template>
