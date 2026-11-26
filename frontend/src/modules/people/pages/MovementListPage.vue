<script setup lang="ts">
import { peoplePageConfig } from '../catalog'
import PeopleWorkspace from '../components/PeopleWorkspace.vue'

defineOptions({ name: 'MovementListPage' })

const config = peoplePageConfig('hr/movements/index')!
</script>

<template>
  <PeopleWorkspace :config="config" />
</template>
