<script setup lang="ts">
import { peoplePageConfig } from '../catalog'
import PeopleWorkspace from '../components/PeopleWorkspace.vue'

defineOptions({ name: 'EmployeeHistoryPage' })

const config = peoplePageConfig('hr/employees/by-id/history')!
</script>

<template>
  <PeopleWorkspace :config="config" />
</template>
