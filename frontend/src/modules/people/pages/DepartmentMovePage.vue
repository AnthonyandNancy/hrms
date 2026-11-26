<script setup lang="ts">
import { peoplePageConfig } from '../catalog'
import PeopleWorkspace from '../components/PeopleWorkspace.vue'

defineOptions({ name: 'DepartmentMovePage' })

const config = peoplePageConfig('hr/departments/move')!
</script>

<template>
  <PeopleWorkspace :config="config" />
</template>
