<script setup lang="ts">
import { peoplePageConfig } from '../catalog'
import PeopleWorkspace from '../components/PeopleWorkspace.vue'

defineOptions({ name: 'DepartmentEditorPage' })

const config = peoplePageConfig('hr/departments/editor')!
</script>

<template>
  <PeopleWorkspace :config="config" />
</template>
