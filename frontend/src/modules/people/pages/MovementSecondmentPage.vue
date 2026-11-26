<script setup lang="ts">
import { peoplePageConfig } from '../catalog'
import PeopleWorkspace from '../components/PeopleWorkspace.vue'

defineOptions({ name: 'MovementSecondmentPage' })

const config = peoplePageConfig('hr/movements/secondment')!
</script>

<template>
  <PeopleWorkspace :config="config" />
</template>
