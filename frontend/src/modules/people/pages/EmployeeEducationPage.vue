<script setup lang="ts">
import { peoplePageConfig } from '../catalog'
import PeopleWorkspace from '../components/PeopleWorkspace.vue'

defineOptions({ name: 'EmployeeEducationPage' })

const config = peoplePageConfig('hr/employees/by-id/education')!
</script>

<template>
  <PeopleWorkspace :config="config" />
</template>
