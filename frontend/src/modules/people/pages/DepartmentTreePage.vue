<script setup lang="ts">
import { peoplePageConfig } from '../catalog'
import PeopleWorkspace from '../components/PeopleWorkspace.vue'

defineOptions({ name: 'DepartmentTreePage' })

const config = peoplePageConfig('hr/departments/index')!
</script>

<template>
  <PeopleWorkspace :config="config" />
</template>
