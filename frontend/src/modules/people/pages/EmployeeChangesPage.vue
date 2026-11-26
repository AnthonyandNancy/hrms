<script setup lang="ts">
import { peoplePageConfig } from '../catalog'
import PeopleWorkspace from '../components/PeopleWorkspace.vue'

defineOptions({ name: 'EmployeeChangesPage' })

const config = peoplePageConfig('hr/employees/by-id/changes')!
</script>

<template>
  <PeopleWorkspace :config="config" />
</template>
