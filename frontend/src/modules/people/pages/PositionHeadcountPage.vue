<script setup lang="ts">
import { peoplePageConfig } from '../catalog'
import PeopleWorkspace from '../components/PeopleWorkspace.vue'

defineOptions({ name: 'PositionHeadcountPage' })

const config = peoplePageConfig('hr/positions/headcount')!
</script>

<template>
  <PeopleWorkspace :config="config" />
</template>
