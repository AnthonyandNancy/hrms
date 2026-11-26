<script setup lang="ts">
import { peoplePageConfig } from '../catalog'
import PeopleWorkspace from '../components/PeopleWorkspace.vue'

defineOptions({ name: 'EmployeeCreatePage' })

const config = peoplePageConfig('hr/employees/create')!
</script>

<template>
  <PeopleWorkspace :config="config" />
</template>
