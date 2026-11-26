<script setup lang="ts">
import { peoplePageConfig } from '../catalog'
import PeopleWorkspace from '../components/PeopleWorkspace.vue'

defineOptions({ name: 'MovementDetailPage' })

const config = peoplePageConfig('hr/movements/by-id')!
</script>

<template>
  <PeopleWorkspace :config="config" />
</template>
