<script setup lang="ts">
import { peoplePageConfig } from '../catalog'
import PeopleWorkspace from '../components/PeopleWorkspace.vue'

defineOptions({ name: 'DepartmentListPage' })

const config = peoplePageConfig('hr/departments/list')!
</script>

<template>
  <PeopleWorkspace :config="config" />
</template>
