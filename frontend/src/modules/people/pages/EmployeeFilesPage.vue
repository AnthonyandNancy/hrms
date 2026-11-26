<script setup lang="ts">
import { peoplePageConfig } from '../catalog'
import PeopleWorkspace from '../components/PeopleWorkspace.vue'

defineOptions({ name: 'EmployeeFilesPage' })

const config = peoplePageConfig('hr/employees/by-id/files')!
</script>

<template>
  <PeopleWorkspace :config="config" />
</template>
