<script setup lang="ts">
import { peoplePageConfig } from '../catalog'
import PeopleWorkspace from '../components/PeopleWorkspace.vue'

defineOptions({ name: 'EmployeeImportsPage' })

const config = peoplePageConfig('hr/employees/imports')!
</script>

<template>
  <PeopleWorkspace :config="config" />
</template>
