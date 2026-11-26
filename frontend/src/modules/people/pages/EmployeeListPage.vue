<script setup lang="ts">
import { peoplePageConfig } from '../catalog'
import PeopleWorkspace from '../components/PeopleWorkspace.vue'

defineOptions({ name: 'EmployeeListPage' })

const config = peoplePageConfig('hr/employees/index')!
</script>

<template>
  <PeopleWorkspace :config="config" />
</template>
