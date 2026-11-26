<script setup lang="ts">
import { peoplePageConfig } from '../catalog'
import PeopleWorkspace from '../components/PeopleWorkspace.vue'

defineOptions({ name: 'PositionCatalogPage' })

const config = peoplePageConfig('hr/positions/index')!
</script>

<template>
  <PeopleWorkspace :config="config" />
</template>
