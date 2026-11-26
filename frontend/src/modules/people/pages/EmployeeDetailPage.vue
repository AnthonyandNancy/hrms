<script setup lang="ts">
import { peoplePageConfig } from '../catalog'
import PeopleWorkspace from '../components/PeopleWorkspace.vue'

defineOptions({ name: 'EmployeeDetailPage' })

const config = peoplePageConfig('hr/employees/by-id')!
</script>

<template>
  <PeopleWorkspace :config="config" />
</template>
