<script setup lang="ts">
import { peoplePageConfig } from '../catalog'
import PeopleWorkspace from '../components/PeopleWorkspace.vue'

defineOptions({ name: 'EmployeeCredentialsPage' })

const config = peoplePageConfig('hr/employees/by-id/credentials')!
</script>

<template>
  <PeopleWorkspace :config="config" />
</template>
