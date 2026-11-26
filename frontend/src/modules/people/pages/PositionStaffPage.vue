<script setup lang="ts">
import { peoplePageConfig } from '../catalog'
import PeopleWorkspace from '../components/PeopleWorkspace.vue'

defineOptions({ name: 'PositionStaffPage' })

const config = peoplePageConfig('hr/positions/staff')!
</script>

<template>
  <PeopleWorkspace :config="config" />
</template>
